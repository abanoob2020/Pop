<?php
// =============================================================================
// Xcamp Gym — لوحة الإدارة (KPIs + المهام + الأعضاء المعرّضون للخطر + التجديدات)
// التشغيل:
//   sudo apt install -y php-cli php-mysql
//   cd dashboard && DB_USER=xcamp_admin DB_PASS='MyPass123' php -S 0.0.0.0:8000
//   ثم افتح:  http://localhost:8000
// =============================================================================
require __DIR__ . '/db.php';
require_role(['admin', 'manager', 'reception']); // المدراء فقط؛ الكابتن يُحوَّل لواجهته

$error = null;
$flash = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

// ---- إضافة عضو جديد (POST) — يشغّل أتمتة الأونبوردنج عبر الـ triggers ----
if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_member') {
    try {
        csrf_check();
        $name    = trim($_POST['full_name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '') ?: null;
        $email   = trim($_POST['email'] ?? '') ?: null;
        $coachId = (int)($_POST['coach_id'] ?? 0) ?: null;
        $planId  = (int)($_POST['plan_id'] ?? 0);
        if ($name === '' || $planId <= 0) throw new RuntimeException('الاسم والخطة مطلوبان.');
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO members (full_name, phone, email, join_date, status, coach_id)
                              VALUES (?, ?, ?, CURDATE(), 'new', ?)");
        $ins->execute([$name, $phone, $email, $coachId]);
        $memberId = (int)$pdo->lastInsertId();
        $insM = $pdo->prepare("INSERT INTO memberships (member_id, plan_id, start_date, end_date, renewal_status, payment_status)
                               SELECT ?, plan_id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL duration_days DAY), 'pending', 'unpaid'
                               FROM plans WHERE plan_id = ?");
        $insM->execute([$memberId, $planId]);
        $pdo->commit();
        $flash = "تمت إضافة العضو (#$memberId) وفتح مهام الأونبوردنج تلقائيًا.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'تعذّرت إضافة العضو: ' . $e->getMessage();
    }
}

$kpis = $queue = $risk = $renewals = [];
$plans = $coaches = [];
if ($pdo && !$error) {
    try {
        $kpis     = $pdo->query("SELECT * FROM vw_dashboard_kpis")->fetch() ?: [];
        $queue    = $pdo->query("SELECT * FROM vw_daily_coach_queue")->fetchAll();
        $risk     = $pdo->query("SELECT * FROM vw_at_risk_members ORDER BY risk_score DESC")->fetchAll();
        $renewals = $pdo->query("SELECT * FROM vw_dashboard_renewals ORDER BY days_left ASC")->fetchAll();
        $plans    = $pdo->query("SELECT plan_id, plan_name FROM plans WHERE active = 1 ORDER BY plan_id")->fetchAll();
        $coaches  = $pdo->query("SELECT coach_id, full_name FROM coaches WHERE active = 1 ORDER BY coach_id")->fetchAll();
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$kpiCards = [
    ['إجمالي الأعضاء', 'total_members', '#2563eb'], ['نشط', 'active_members', '#16a34a'],
    ['معرّض للخطر', 'at_risk_members', '#f59e0b'], ['موقوف', 'paused_members', '#6b7280'],
    ['اشتراكات منتهية', 'expired_memberships', '#dc2626'], ['مهام مفتوحة', 'open_tasks', '#7c3aed'],
    ['إنذارات مفتوحة', 'open_flags', '#db2777'], ['مدفوعات فاشلة', 'failed_payments', '#dc2626'],
];
$prBadge = ['urgent' => '#dc2626', 'high' => '#f59e0b', 'medium' => '#2563eb', 'low' => '#6b7280'];

page_head('لوحة الإدارة', 'dash');

if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
if ($error) { db_error_box($error); page_foot(); exit; }
?>

<div class="kpis">
  <?php foreach ($kpiCards as [$label, $key, $color]): ?>
    <div class="card" style="--c:<?=$color?>">
      <div class="n" style="color:<?=$color?>"><?=h($kpis[$key] ?? 0)?></div>
      <div class="l"><?=h($label)?></div>
    </div>
  <?php endforeach; ?>
</div>

<section>
  <h2>➕ إضافة عضو جديد</h2>
  <form class="frm" method="post">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="add_member">
    <div><label>الاسم الكامل *</label><input name="full_name" required></div>
    <div><label>الهاتف</label><input name="phone"></div>
    <div><label>البريد</label><input name="email" type="email"></div>
    <div><label>الخطة *</label>
      <select name="plan_id" required><option value="">— اختر —</option>
        <?php foreach ($plans as $p): ?><option value="<?=h($p['plan_id'])?>"><?=h($p['plan_name'])?></option><?php endforeach; ?>
      </select></div>
    <div><label>المدرب</label>
      <select name="coach_id"><option value="">— بدون —</option>
        <?php foreach ($coaches as $c): ?><option value="<?=h($c['coach_id'])?>"><?=h($c['full_name'])?></option><?php endforeach; ?>
      </select></div>
    <div><button type="submit">إضافة</button></div>
  </form>
  <p class="muted" style="font-size:13px;margin:12px 0 0">تُنشأ للعضو الجديد مهام أونبوردنج ورسالة ترحيب تلقائيًا عبر الـ triggers.</p>
</section>

<section>
  <h2>📋 طابور مهام المدربين اليوم</h2>
  <?php if (!$queue): ?><div class="empty">لا توجد مهام مفتوحة.</div><?php else: ?>
  <table>
    <tr><th>#</th><th>العضو</th><th>المدرب</th><th>النوع</th><th>الأولوية</th><th>الإنذار</th><th>الاستحقاق</th></tr>
    <?php foreach ($queue as $t): ?>
      <tr><td><?=h($t['task_id'])?></td><td><?=h($t['member_name'] ?? '—')?></td><td><?=h($t['coach_name'] ?? '—')?></td>
        <td><?=h($t['task_type'])?></td>
        <td><span class="badge" style="background:<?=$prBadge[$t['priority']] ?? '#6b7280'?>"><?=h($t['priority'])?></span></td>
        <td><?=h($t['flag_type'])?></td><td class="muted"><?=h($t['due_at'] ?? '')?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<section>
  <h2>⚠️ الأعضاء المعرّضون للخطر</h2>
  <?php if (!$risk): ?><div class="empty">لا يوجد.</div><?php else: ?>
  <table>
    <tr><th>#</th><th>العضو</th><th>الحالة</th><th>درجة الخطر</th><th>التصنيف</th><th>آخر دفعة</th></tr>
    <?php foreach ($risk as $r): ?>
      <tr><td><?=h($r['member_id'])?></td><td><?=h($r['full_name'])?></td><td><?=h($r['status'])?></td>
        <td><strong><?=h($r['risk_score'])?></strong></td><td><?=h($r['classification'])?></td><td><?=h($r['last_payment_status'])?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<section>
  <h2>🔔 تجديدات ومدفوعات تحتاج متابعة</h2>
  <?php if (!$renewals): ?><div class="empty">لا يوجد.</div><?php else: ?>
  <table>
    <tr><th>#</th><th>العضو</th><th>نهاية الاشتراك</th><th>الأيام المتبقية</th><th>حالة الدفع</th><th>حالة التجديد</th></tr>
    <?php foreach ($renewals as $r): ?>
      <tr><td><?=h($r['member_id'])?></td><td><?=h($r['member_name'])?></td><td><?=h($r['end_date'])?></td>
        <td style="<?= ((int)$r['days_left'] < 0) ? 'color:#dc2626;font-weight:600' : '' ?>"><?=h($r['days_left'])?></td>
        <td><?=h($r['payment_status'])?></td><td><?=h($r['renewal_status'])?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<?php page_foot();

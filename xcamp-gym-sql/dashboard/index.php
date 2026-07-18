<?php
// =============================================================================
// Xcamp Gym — لوحة تحكم الموظفين (Staff Dashboard)
// تطبيق ويب بسيط بصفحة واحدة يعرض مؤشرات ولوحات قاعدة xcamp_gym.
// التشغيل:
//   sudo apt install -y php-cli php-mysql
//   cd dashboard && php -S 0.0.0.0:8000
//   ثم افتح المتصفح على:  http://localhost:8000
//
// إعدادات الاتصال (يمكن تمريرها كمتغيرات بيئة، وإلا تُستخدم القيم الافتراضية):
//   DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
// =============================================================================

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'xcamp_gym';
$DB_USER = getenv('DB_USER') ?: 'xcamp_admin';
$DB_PASS = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'ChangeThisPass123';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$error = null;
$flash = null;
$pdo   = null;

try {
    $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// ---- إضافة عضو جديد (POST) — يشغّل أتمتة الأونبوردنج عبر الـ triggers ----
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_member') {
    try {
        $name    = trim($_POST['full_name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '') ?: null;
        $email   = trim($_POST['email'] ?? '') ?: null;
        $coachId = (int)($_POST['coach_id'] ?? 0) ?: null;
        $planId  = (int)($_POST['plan_id'] ?? 0);
        if ($name === '' || $planId <= 0) {
            throw new RuntimeException('الاسم والخطة مطلوبان.');
        }
        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            "INSERT INTO members (full_name, phone, email, join_date, status, coach_id)
             VALUES (?, ?, ?, CURDATE(), 'new', ?)"
        );
        $ins->execute([$name, $phone, $email, $coachId]);
        $memberId = (int)$pdo->lastInsertId();
        $insM = $pdo->prepare(
            "INSERT INTO memberships (member_id, plan_id, start_date, end_date, renewal_status, payment_status)
             SELECT ?, plan_id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL duration_days DAY), 'pending', 'unpaid'
             FROM plans WHERE plan_id = ?"
        );
        $insM->execute([$memberId, $planId]);
        $pdo->commit();
        $flash = "تمت إضافة العضو (#$memberId) وفتح مهام الأونبوردنج تلقائيًا.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'تعذّرت إضافة العضو: ' . $e->getMessage();
    }
}

// ---- جلب البيانات ----
$kpis = $queue = $risk = $renewals = $overdue = [];
$plans = $coaches = [];
if ($pdo && !$error) {
    try {
        $kpis     = $pdo->query("SELECT * FROM vw_dashboard_kpis")->fetch() ?: [];
        $queue    = $pdo->query("SELECT * FROM vw_daily_coach_queue")->fetchAll();
        $risk     = $pdo->query("SELECT * FROM vw_at_risk_members ORDER BY risk_score DESC")->fetchAll();
        $renewals = $pdo->query("SELECT * FROM vw_dashboard_renewals ORDER BY days_left ASC")->fetchAll();
        $overdue  = $pdo->query("SELECT * FROM vw_overdue_payments")->fetchAll();
        $plans    = $pdo->query("SELECT plan_id, plan_name FROM plans WHERE active = 1 ORDER BY plan_id")->fetchAll();
        $coaches  = $pdo->query("SELECT coach_id, full_name FROM coaches WHERE active = 1 ORDER BY coach_id")->fetchAll();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$kpiCards = [
    ['إجمالي الأعضاء', 'total_members', '#2563eb'],
    ['نشط', 'active_members', '#16a34a'],
    ['معرّض للخطر', 'at_risk_members', '#f59e0b'],
    ['موقوف', 'paused_members', '#6b7280'],
    ['اشتراكات منتهية', 'expired_memberships', '#dc2626'],
    ['مهام مفتوحة', 'open_tasks', '#7c3aed'],
    ['إنذارات مفتوحة', 'open_flags', '#db2777'],
    ['مدفوعات فاشلة', 'failed_payments', '#dc2626'],
];
$prBadge = ['urgent' => '#dc2626', 'high' => '#f59e0b', 'medium' => '#2563eb', 'low' => '#6b7280'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Xcamp Gym — لوحة الموظفين</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background:#f1f5f9; color:#0f172a; }
  header { background:#0f172a; color:#fff; padding:18px 24px; display:flex; align-items:center; justify-content:space-between; }
  header h1 { margin:0; font-size:20px; }
  header .sub { color:#94a3b8; font-size:13px; }
  main { padding:24px; max-width:1200px; margin:0 auto; }
  .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:26px; }
  .card { background:#fff; border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.08); border-top:4px solid var(--c); }
  .card .n { font-size:30px; font-weight:800; line-height:1; }
  .card .l { color:#64748b; font-size:13px; margin-top:8px; }
  section { background:#fff; border-radius:12px; padding:18px 20px; margin-bottom:22px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  section h2 { margin:0 0 14px; font-size:16px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { text-align:right; padding:9px 10px; border-bottom:1px solid #eef2f7; }
  th { color:#64748b; font-weight:600; font-size:12px; text-transform:uppercase; }
  tr:hover td { background:#f8fafc; }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; color:#fff; font-size:12px; font-weight:600; }
  .muted { color:#94a3b8; }
  .neg { color:#dc2626; font-weight:600; }
  form.add { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; align-items:end; }
  form.add label { font-size:13px; color:#475569; display:block; margin-bottom:4px; }
  form.add input, form.add select { width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; }
  form.add button { background:#2563eb; color:#fff; border:0; padding:10px 18px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; }
  form.add button:hover { background:#1d4ed8; }
  .flash { background:#dcfce7; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:18px; }
  .err { background:#fee2e2; color:#991b1b; padding:16px; border-radius:10px; margin-bottom:18px; }
  .err code { display:block; margin-top:8px; font-size:13px; opacity:.85; }
  .empty { color:#94a3b8; padding:10px 0; }
</style>
</head>
<body>
<header>
  <div><h1>🏋️ Xcamp Gym — لوحة الموظفين</h1><div class="sub">قاعدة البيانات: <?=h($DB_NAME)?> · <?=date('Y-m-d H:i')?></div></div>
</header>
<main>

<?php if ($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>

<?php if ($error): ?>
  <div class="err">
    <strong>تعذّر الاتصال بقاعدة البيانات أو تنفيذ استعلام.</strong>
    <code><?=h($error)?></code>
    <div style="margin-top:10px;font-size:13px">
      تأكد من إنشاء مستخدم وصلاحياته (مرة واحدة):<br>
      <code>sudo mysql -e "CREATE USER IF NOT EXISTS 'xcamp_admin'@'%' IDENTIFIED BY 'ChangeThisPass123'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'%'; FLUSH PRIVILEGES;"</code>
      ثم عدّل بيانات الاتصال أعلى ملف <code>index.php</code> أو مرّرها كمتغيّرات بيئة.
    </div>
  </div>
<?php endif; ?>

<?php if (!$error): ?>
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
  <form class="add" method="post">
    <input type="hidden" name="action" value="add_member">
    <div><label>الاسم الكامل *</label><input name="full_name" required></div>
    <div><label>الهاتف</label><input name="phone"></div>
    <div><label>البريد</label><input name="email" type="email"></div>
    <div><label>الخطة *</label>
      <select name="plan_id" required>
        <option value="">— اختر —</option>
        <?php foreach ($plans as $p): ?><option value="<?=h($p['plan_id'])?>"><?=h($p['plan_name'])?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label>المدرب</label>
      <select name="coach_id">
        <option value="">— بدون —</option>
        <?php foreach ($coaches as $c): ?><option value="<?=h($c['coach_id'])?>"><?=h($c['full_name'])?></option><?php endforeach; ?>
      </select>
    </div>
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
      <tr>
        <td><?=h($t['task_id'])?></td>
        <td><?=h($t['member_name'] ?? '—')?></td>
        <td><?=h($t['coach_name'] ?? '—')?></td>
        <td><?=h($t['task_type'])?></td>
        <td><span class="badge" style="background:<?=$prBadge[$t['priority']] ?? '#6b7280'?>"><?=h($t['priority'])?></span></td>
        <td><?=h($t['flag_type'])?></td>
        <td class="muted"><?=h($t['due_at'] ?? '')?></td>
      </tr>
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
      <tr>
        <td><?=h($r['member_id'])?></td>
        <td><?=h($r['full_name'])?></td>
        <td><?=h($r['status'])?></td>
        <td><strong><?=h($r['risk_score'])?></strong></td>
        <td><?=h($r['classification'])?></td>
        <td><?=h($r['last_payment_status'])?></td>
      </tr>
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
      <tr>
        <td><?=h($r['member_id'])?></td>
        <td><?=h($r['member_name'])?></td>
        <td><?=h($r['end_date'])?></td>
        <td class="<?= ((int)$r['days_left'] < 0) ? 'neg' : '' ?>"><?=h($r['days_left'])?></td>
        <td><?=h($r['payment_status'])?></td>
        <td><?=h($r['renewal_status'])?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<?php endif; /* !error */ ?>

</main>
</body>
</html>

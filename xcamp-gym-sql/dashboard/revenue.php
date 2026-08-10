<?php
// =============================================================================
// Xcamp Gym — التجديدات والإيرادات: MRR + الإيراد المتوقّع + المدفوعات المتعثّرة
// المدير يرى الجميع؛ الكابتن يرى أعضاءه فقط. يستفيد من نظام التبويبات (المرحلة 4).
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$isCoach = ($me['role'] === 'coach');
$myCoach = (int)($me['coach_id'] ?? 0);
$RENEWALS = ['pending','renewed','expired','cancelled'];
$PAYMENTS = ['unpaid','partial','paid','failed','refunded'];

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        if (($_POST['action'] ?? '') === 'set_renewal') {
            $mid = (int)$_POST['membership_id'];
            // نطاق: الكابتن لأعضائه فقط
            $q = $pdo->prepare("SELECT m.coach_id FROM memberships ms JOIN members m ON m.member_id = ms.member_id WHERE ms.membership_id = ?");
            $q->execute([$mid]);
            $mc = $q->fetchColumn();
            if ($mc === false) throw new RuntimeException('الاشتراك غير موجود.');
            if ($isCoach && (int)$mc !== $myCoach) throw new RuntimeException('غير مسموح — العضو ليس ضمن أعضائك.');
            $rn = in_array($_POST['renewal_status'] ?? '', $RENEWALS, true) ? $_POST['renewal_status'] : 'pending';
            $py = in_array($_POST['payment_status'] ?? '', $PAYMENTS, true) ? $_POST['payment_status'] : 'unpaid';
            $pdo->prepare("UPDATE memberships SET renewal_status = ?, payment_status = ? WHERE membership_id = ?")
                ->execute([$rn, $py, $mid]);
        }
        header('Location: revenue.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('الإيرادات', 'revenue');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح.</div>';

// كل الاشتراكات مع سعر الخطة + العضو (منظورة حسب النطاق)
$scope = $isCoach ? ' WHERE m.coach_id = ' . $myCoach : '';
$rows = $pdo->query("SELECT ms.membership_id, ms.member_id, ms.end_date, ms.renewal_status, ms.payment_status, ms.auto_renew,
                            p.plan_name, p.price, p.duration_days, m.full_name, m.coach_id, m.status AS member_status, c.full_name AS coach_name
                     FROM memberships ms
                     JOIN plans p   ON p.plan_id = ms.plan_id
                     JOIN members m ON m.member_id = ms.member_id
                     LEFT JOIN coaches c ON c.coach_id = m.coach_id
                     $scope ORDER BY ms.end_date")->fetchAll();

$today = new DateTimeImmutable('today');
$mrr = 0.0; $activeValue = 0.0; $outstanding = 0.0;
$proj = ['30' => 0.0, '60' => 0.0, '90' => 0.0];
$renewPipe = [];    // اشتراكات تنتهي/منتهية بانتظار قرار التجديد
foreach ($rows as &$r) {
    $price = (float)$r['price'];
    $end   = date_create_immutable($r['end_date']);
    $days  = $end ? (int)$today->diff($end)->format('%r%a') : 0;
    $r['days_left'] = $days;
    $r['monthly']   = monthlyize($price, (int)$r['duration_days']);
    $r['likelihood']= renewal_likelihood($r['renewal_status'], $r['payment_status'], (bool)$r['auto_renew'], $days);
    $r['owed']      = payment_outstanding($price, $r['payment_status']);
    // العقود السارية (لم تنتهِ) تُسهم في MRR والقيمة النشطة
    $isCurrent = $days >= 0 && $r['renewal_status'] !== 'cancelled';
    if ($isCurrent) { $mrr += $r['monthly']; $activeValue += $price; }
    $outstanding += $r['owed'];
    // الإيراد المتوقّع من التجديدات القادمة (نافذة الأيام)
    if ($days >= 0) {
        foreach ([30, 60, 90] as $win) if ($days <= $win) $proj[(string)$win] += $price * $r['likelihood'];
    } elseif ($r['renewal_status'] !== 'renewed' && $r['renewal_status'] !== 'cancelled') {
        // منتهٍ بانتظار التجديد → يدخل نافذة الاسترجاع القريبة
        foreach ([30, 60, 90] as $win) $proj[(string)$win] += $price * $r['likelihood'];
    }
    // خط أنابيب التجديد: ما ينتهي خلال 30 يومًا أو منتهٍ ولم يُجدَّد/يُلغَ
    if (($days <= 30 && $r['renewal_status'] !== 'renewed' && $r['renewal_status'] !== 'cancelled')) $renewPipe[] = $r;
}
unset($r);

// ── المحصّل النقدي (BIZ-001 / Model B): الحقيقة المالية من جدول payments، لا من علم
// memberships.payment_status. «المحصّل» = مجموع المبالغ المدفوعة فعليًا (status='paid')
// ضمن نفس نطاق التقرير (أعضاء الكابتن أو الكل). يشمل الدفعات الجزئية/المتعدّدة/الزائدة
// بمبالغها الحقيقية، ويستبعد ما عُلّم «paid» يدويًا بلا صف دفع (نقدي/مجاملة/معفى).
// ملاحظة: علم payment_status يبقى كما هو للاستخدام التشغيلي (بوّابة الدخول/الاحتفاظ/
// المتعثّرات/التجديد) — هذا الإصلاح لا يمسّه.
$collScope = $isCoach ? ' AND m.coach_id = ' . $myCoach : '';
$collected = (float)$pdo->query(
    "SELECT COALESCE(SUM(pay.amount), 0)
       FROM payments pay
       JOIN memberships ms ON ms.membership_id = pay.membership_id
       JOIN members m      ON m.member_id = ms.member_id
      WHERE pay.status = 'paid'" . $collScope
)->fetchColumn();

usort($renewPipe, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
$outRows = array_values(array_filter($rows, fn($r) => $r['owed'] > 0));
usort($outRows, fn($a, $b) => $b['owed'] <=> $a['owed']);

$payColor = ['paid'=>'#16a34a','partial'=>'#f59e0b','unpaid'=>'#dc2626','failed'=>'#dc2626','refunded'=>'#6b7280'];
$renColor = ['renewed'=>'#16a34a','pending'=>'#f59e0b','expired'=>'#dc2626','cancelled'=>'#6b7280'];
function mlink($r) { return 'captains.php?coach=' . (int)($r['coach_id'] ?? 0) . '&member=' . (int)$r['member_id']; }
?>
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px">
  <h2 style="margin:0">💰 التجديدات والإيرادات</h2>
  <span class="muted" style="font-size:13px"><?= $isCoach ? 'أعضاؤك فقط' : 'كل الأعضاء' ?></span>
</div>

<nav class="tabbar" aria-label="أقسام الإيرادات">
  <button type="button" data-tabtarget="dash">📊 اللوحة</button>
  <button type="button" data-tabtarget="pipe">🔄 خط أنابيب التجديد<?= $renewPipe ? ' (' . count($renewPipe) . ')' : '' ?></button>
  <button type="button" data-tabtarget="due">⚠️ مدفوعات متعثّرة<?= $outRows ? ' (' . count($outRows) . ')' : '' ?></button>
</nav>

<!-- ===== لوحة الإيرادات ===== -->
<section data-tab="dash">
  <h2>📊 مؤشرات الإيراد</h2>
  <div class="kpis">
    <div class="card" style="--c:#2563eb"><div class="n" style="color:#2563eb;font-size:24px"><?=money($mrr)?></div><div class="l">💳 الإيراد الشهري المتكرّر (MRR)</div></div>
    <div class="card" style="--c:#16a34a"><div class="n" style="color:#16a34a;font-size:24px"><?=money($activeValue)?></div><div class="l">📦 قيمة العقود السارية (متعاقَد)</div></div>
    <div class="card" style="--c:#dc2626"><div class="n" style="color:#dc2626;font-size:24px"><?=money($outstanding)?></div><div class="l">⚠️ مستحقّات مقدّرة (مستحقّ)</div></div>
    <div class="card" style="--c:#0891b2"><div class="n" style="color:#0891b2;font-size:24px"><?=money($collected)?></div><div class="l">✅ محصّل نقدًا (من المدفوعات)</div></div>
  </div>
  <p class="muted" style="font-size:11px;margin:8px 0 0">
    تمييز المفاهيم: <strong>المتعاقَد</strong> (MRR/قيمة العقود) = قيمة تعاقدية متوقّعة ·
    <strong>المستحقّ</strong> = تقدير تشغيلي لما لم يُحصَّل (من حالة الدفع) ·
    <strong>المحصّل نقدًا</strong> = مبالغ فعلية من جدول <code>payments</code> (لا يُحسب من علم الحالة).
  </p>
  <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px;margin-top:8px">
    <strong style="font-size:13px;color:#334155">الإيراد المتوقّع من التجديدات (مرجّح باحتمال التجديد)</strong>
    <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:10px">
      <div><div style="font-size:22px;font-weight:800;color:#16a34a"><?=money($proj['30'])?></div><div class="muted" style="font-size:12px">خلال ٣٠ يومًا</div></div>
      <div><div style="font-size:22px;font-weight:800;color:#16a34a"><?=money($proj['60'])?></div><div class="muted" style="font-size:12px">خلال ٦٠ يومًا</div></div>
      <div><div style="font-size:22px;font-weight:800;color:#16a34a"><?=money($proj['90'])?></div><div class="muted" style="font-size:12px">خلال ٩٠ يومًا</div></div>
    </div>
    <p class="muted" style="font-size:11px;margin:10px 0 0">الاحتمال يُحسب من التجديد التلقائي + حالة الدفع + قرب الانتهاء. MRR = مجموع (سعر الخطة ÷ مدّتها × ٣٠) للعقود السارية.</p>
  </div>
</section>

<!-- ===== خط أنابيب التجديد ===== -->
<section data-tab="pipe">
  <h2>🔄 خط أنابيب التجديد (٣٠ يومًا)</h2>
  <?php if (!$renewPipe): ?><div class="empty">لا توجد تجديدات مستحقّة قريبًا 🎉</div><?php else: ?>
  <table>
    <tr><th>العضو</th><?php if (!$isCoach): ?><th>الكابتن</th><?php endif; ?><th>الخطة</th><th>القيمة</th><th>ينتهي</th><th>المتبقّي</th><th>الاحتمال</th><th>التجديد</th><th>الدفع</th><th></th></tr>
    <?php foreach ($renewPipe as $r):
      $d = (int)$r['days_left']; $dc = $d < 0 ? '#dc2626' : ($d <= 7 ? '#f59e0b' : '#334155');
      $lk = (int)round($r['likelihood'] * 100); ?>
      <tr>
        <td><a class="link" href="<?=h(mlink($r))?>"><?=h($r['full_name'])?></a></td>
        <?php if (!$isCoach): ?><td class="muted"><?=h($r['coach_name'])?></td><?php endif; ?>
        <td><?=h($r['plan_name'])?><?= $r['auto_renew'] ? ' 🔁' : '' ?></td>
        <td><strong><?=money((float)$r['price'])?></strong></td>
        <td><?=h($r['end_date'])?></td>
        <td style="color:<?=$dc?>;font-weight:700"><?= $d < 0 ? 'منتهٍ ' . abs($d) . 'ي' : $d . ' يوم' ?></td>
        <td><span style="font-weight:700;color:<?= $lk >= 70 ? '#16a34a' : ($lk >= 40 ? '#f59e0b' : '#dc2626') ?>"><?=$lk?>%</span></td>
        <td><span class="badge" style="background:<?=$renColor[$r['renewal_status']] ?? '#6b7280'?>"><?=h($r['renewal_status'])?></span></td>
        <td><span style="color:<?=$payColor[$r['payment_status']] ?? '#64748b'?>;font-weight:600"><?=h($r['payment_status'])?></span></td>
        <td>
          <details><summary class="link" style="cursor:pointer;font-size:12px">تحديث</summary>
            <form class="frm" method="post" style="margin-top:6px"><?=csrf_field()?>
              <input type="hidden" name="action" value="set_renewal">
              <input type="hidden" name="membership_id" value="<?=(int)$r['membership_id']?>">
              <div><label>التجديد</label><select name="renewal_status"><?php foreach ($RENEWALS as $s): ?><option <?=$s===$r['renewal_status']?'selected':''?>><?=h($s)?></option><?php endforeach; ?></select></div>
              <div><label>الدفع</label><select name="payment_status"><?php foreach ($PAYMENTS as $s): ?><option <?=$s===$r['payment_status']?'selected':''?>><?=h($s)?></option><?php endforeach; ?></select></div>
              <div><button type="submit">حفظ</button></div>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:10px 0 0">🔁 = تجديد تلقائي. يشمل ما ينتهي خلال ٣٠ يومًا + المنتهي بانتظار قرار (عدا المُجدَّد/الملغى).</p>
  <?php endif; ?>
</section>

<!-- ===== مدفوعات متعثّرة ===== -->
<section data-tab="due">
  <h2>⚠️ المدفوعات المتعثّرة</h2>
  <?php if (!$outRows): ?><div class="empty">لا توجد مستحقّات غير محصّلة 🎉</div><?php else: ?>
  <table>
    <tr><th>العضو</th><?php if (!$isCoach): ?><th>الكابتن</th><?php endif; ?><th>الخطة</th><th>الحالة</th><th>المستحقّ</th><th></th></tr>
    <?php foreach ($outRows as $r): ?>
      <tr>
        <td><a class="link" href="<?=h(mlink($r))?>"><?=h($r['full_name'])?></a></td>
        <?php if (!$isCoach): ?><td class="muted"><?=h($r['coach_name'])?></td><?php endif; ?>
        <td><?=h($r['plan_name'])?></td>
        <td><span class="badge" style="background:<?=$payColor[$r['payment_status']] ?? '#6b7280'?>"><?=h($r['payment_status'])?></span></td>
        <td style="font-weight:700;color:#dc2626"><?=money((float)$r['owed'])?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('تعليم دفع «<?=h($r['full_name'])?>» كمدفوع؟')"><?=csrf_field()?>
            <input type="hidden" name="action" value="set_renewal">
            <input type="hidden" name="membership_id" value="<?=(int)$r['membership_id']?>">
            <input type="hidden" name="renewal_status" value="<?=h($r['renewal_status'])?>">
            <input type="hidden" name="payment_status" value="paid">
            <button type="submit" style="background:#16a34a;color:#fff;border:0;padding:6px 12px;border-radius:8px;font-size:12px;cursor:pointer">✓ تعليم كمدفوع</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <tr style="border-top:2px solid #e2e8f0"><td colspan="<?= $isCoach ? 3 : 4 ?>" style="font-weight:700">الإجمالي المستحقّ</td><td style="font-weight:800;color:#dc2626"><?=money($outstanding)?></td><td></td></tr>
  </table>
  <p class="muted" style="font-size:12px;margin:10px 0 0">التقدير: غير مدفوع/فشل = القيمة كاملة · جزئي = نصف القيمة.</p>
  <?php endif; ?>
</section>
<?php page_foot();

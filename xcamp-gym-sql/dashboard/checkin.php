<?php
// =============================================================================
// Xcamp Gym — الحضور بالـ QR (شاشة الاستقبال/الكشك): امسح رمز العضو أو اكتب الهاتف
// → تسجيل حضور اليوم في daily_attendance (تعمل أتمتة المتابعة) + بطاقة تأكيد.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null; $result = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $code = trim($_POST['code'] ?? '');
        if ($code === '') throw new RuntimeException('امسح الرمز أو اكتب الهاتف.');
        // ابحث بالرمز (QR) أولًا، ثم بالهاتف أو رقم العضو
        $m = $pdo->prepare("SELECT m.member_id, m.full_name, m.status, m.coach_id
                            FROM member_qr q JOIN members m ON m.member_id = q.member_id WHERE q.token = ?");
        $m->execute([$code]); $mem = $m->fetch();
        if (!$mem) {
            $m = $pdo->prepare("SELECT member_id, full_name, status, coach_id FROM members WHERE phone = ? OR member_id = ?");
            $m->execute([$code, ctype_digit($code) ? (int)$code : 0]); $mem = $m->fetch();
        }
        if (!$mem) throw new RuntimeException('لم يُعثر على عضو مطابق للرمز/الهاتف.');
        $mid = (int)$mem['member_id'];
        // اشتراك العضو (لتنبيه الانتهاء)
        $ms = $pdo->prepare("SELECT ms.end_date, ms.payment_status, DATEDIFF(ms.end_date, CURDATE()) days_left
                             FROM memberships ms WHERE ms.member_id = ? ORDER BY ms.end_date DESC LIMIT 1");
        $ms->execute([$mid]); $ms = $ms->fetch();
        // سجّل حضور اليوم إن لم يكن مسجّلًا (يشغّل sp_handle_attendance_event عبر الـ trigger)
        $dup = $pdo->prepare("SELECT check_in_time FROM daily_attendance WHERE member_id = ? AND attendance_date = CURDATE()");
        $dup->execute([$mid]); $already = $dup->fetchColumn();
        if ($already === false) {
            $pdo->prepare("INSERT INTO daily_attendance (member_id, coach_id, attendance_date, check_in_time, attended, session_type, notes)
                           VALUES (?,?,CURDATE(),CURTIME(),1,'training','دخول عبر QR')")
                ->execute([$mid, (int)$mem['coach_id'] ?: null]);
            $checkTime = date('H:i');
        } else {
            $checkTime = substr((string)$already, 0, 5);
        }
        $result = ['name' => $mem['full_name'], 'status' => $mem['status'], 'time' => $checkTime,
                   'repeat' => $already !== false, 'ms' => $ms];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// آخر عمليات الدخول اليوم
$today = [];
if ($pdo && !$error) {
    $today = $pdo->query("SELECT da.check_in_time, m.full_name FROM daily_attendance da
                          JOIN members m ON m.member_id = da.member_id
                          WHERE da.attendance_date = CURDATE() AND da.attended = 1
                          ORDER BY da.check_in_time DESC LIMIT 12")->fetchAll();
}

page_head('الحضور (QR)', 'checkin');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
$stColor = ['active'=>'#16a34a','new'=>'#0891b2','onboarding'=>'#0891b2','at_risk'=>'#f59e0b','corrective'=>'#f59e0b','paused'=>'#6b7280','expired'=>'#dc2626'];
?>
<section>
  <h2>📷 تسجيل الحضور</h2>
  <?php if ($error): ?><div class="err" style="margin:0 0 14px"><?=h($error)?></div><?php endif; ?>
  <?php if ($result):
    $ms = $result['ms']; $expired = $ms && $ms['days_left'] !== null && (int)$ms['days_left'] < 0;
    $soon = $ms && $ms['days_left'] !== null && (int)$ms['days_left'] >= 0 && (int)$ms['days_left'] <= 7;
    $bg = $expired ? '#fef2f2' : '#f0fdf4'; $bd = $expired ? '#fecaca' : '#bbf7d0'; ?>
    <div style="background:<?=$bg?>;border:2px solid <?=$bd?>;border-radius:14px;padding:20px;margin-bottom:16px;text-align:center">
      <div style="font-size:44px;line-height:1">✅</div>
      <div style="font-size:22px;font-weight:800;margin-top:6px"><?= $result['repeat'] ? 'مسجّل مسبقًا' : 'أهلًا' ?> <?=h($result['name'])?></div>
      <div style="margin-top:6px">
        <span class="badge" style="background:<?=$stColor[$result['status']] ?? '#6b7280'?>"><?=h($result['status'])?></span>
        <span class="muted" style="font-size:13px">· دخول <?=h($result['time'])?></span>
      </div>
      <?php if ($expired): ?>
        <div style="margin-top:10px;color:#991b1b;font-weight:700">⚠️ الاشتراك منتهٍ منذ <?=abs((int)$ms['days_left'])?> يوم — يرجى التجديد.</div>
      <?php elseif ($soon): ?>
        <div style="margin-top:10px;color:#92400e;font-weight:700">⏰ يتبقّى <?=(int)$ms['days_left']?> يوم على انتهاء الاشتراك.</div>
      <?php elseif ($ms && ($ms['payment_status'] ?? '') !== 'paid'): ?>
        <div style="margin-top:10px;color:#92400e">💳 حالة الدفع: <?=h($ms['payment_status'])?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <form method="post" data-no-ajax style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><?=csrf_field()?>
    <div style="flex:1;min-width:220px">
      <label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">امسح رمز العضو (QR) أو اكتب الهاتف</label>
      <input name="code" autofocus autocomplete="off" placeholder="امسح الكود أو اكتب الهاتف…"
             style="width:100%;padding:14px;border:2px solid #2563eb;border-radius:10px;font-size:18px">
    </div>
    <button type="submit" style="background:#16a34a;color:#fff;border:0;padding:14px 22px;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer">تسجيل</button>
  </form>
  <p class="muted" style="font-size:12px;margin:10px 0 0">قارئ الـ QR/الباركود يكتب الرمز في الخانة تلقائيًا ويُرسل — الحقل يبقى مركّزًا لمسح متتالٍ. العضو يجد رمزه في بوابته.</p>
</section>

<section>
  <h2>🕒 دخول اليوم (<?=count($today)?>)</h2>
  <?php if (!$today): ?><div class="empty">لا حضور مسجّل اليوم بعد.</div><?php else: ?>
  <table><tr><th>الوقت</th><th>العضو</th></tr>
    <?php foreach ($today as $t): ?><tr><td><?=h(substr((string)$t['check_in_time'],0,5))?></td><td><?=h($t['full_name'])?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>
<script>
// أبقِ حقل المسح مركّزًا دائمًا لتجربة كشك سلسة
(function(){ var i=document.querySelector('input[name=code]'); if(i){ i.focus(); i.select && i.select(); } })();
</script>
<?php page_foot();

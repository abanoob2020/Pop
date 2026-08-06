<?php
// =============================================================================
// Xcamp Gym — جلسات التدريب الشخصي (PT): إدارة مواعيد الكباتن مع الأعضاء.
// الكابتن يرى/يحجز لأعضائه فقط؛ المدير يتصفّح كل الكباتن. المواعيد تُشتقّ من
// ورديات الكابتن (coach_shifts)، مع دورة حالة وإيراد من الجلسات المكتملة.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$isCoach = ($me['role'] === 'coach');
$myCoachId = $isCoach ? (int)($me['coach_id'] ?? 0) : 0;

/** يتأكّد أن الكابتن يتعامل مع عضو من أعضائه فقط (المدير: الكل). */
function pt_member_coach(PDO $pdo, int $memberId): ?int {
    $q = $pdo->prepare("SELECT coach_id FROM members WHERE member_id = ?");
    $q->execute([$memberId]);
    $c = $q->fetchColumn();
    return $c !== false && $c !== null ? (int)$c : null;
}

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'book') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $date = $_POST['session_date'] ?? '';
            $start = $_POST['start_time'] ?? '';
            if (!$memberId || !$date || !$start) throw new RuntimeException('أكمل بيانات الحجز.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d'))
                throw new RuntimeException('اختر تاريخًا اليوم أو لاحقًا.');
            $coachId = pt_member_coach($pdo, $memberId);
            if (!$coachId) throw new RuntimeException('لا كابتن مُسنَد لهذا العضو.');
            if ($isCoach && $coachId !== $myCoachId) throw new RuntimeException('غير مسموح — العضو ليس ضمن أعضائك.');
            // تحقّق أن الموعد ضمن مواعيد متاحة فعلًا (وردية + غير محجوز)
            $ok = false; $endT = null;
            foreach (pt_slots_for($pdo, $coachId, $date) as $s)
                if ($s['start'] === (strlen($start) === 5 ? $start . ':00' : $start) && $s['free']) { $ok = true; $endT = $s['end']; break; }
            if (!$ok) throw new RuntimeException('الموعد غير متاح — جرّب موعدًا آخر.');
            $price = $_POST['price'] !== '' ? max(0, (float)$_POST['price']) : PT_PRICE;
            $pdo->prepare("INSERT INTO pt_sessions (member_id, coach_id, session_date, start_time, end_time, status, booked_by, price, notes)
                           VALUES (?,?,?,?,?, 'booked', 'staff', ?, ?)")
                ->execute([$memberId, $coachId, $date, (strlen($start) === 5 ? $start . ':00' : $start), $endT, $price,
                           trim($_POST['notes'] ?? '') ?: null]);
        } elseif ($act === 'set_status') {
            $pid = (int)$_POST['pt_id'];
            $ns  = in_array($_POST['status'] ?? '', ['completed','cancelled','no_show','booked'], true) ? $_POST['status'] : null;
            if (!$ns) throw new RuntimeException('حالة غير صحيحة.');
            // قيد الكابتن على جلساته
            $own = "UPDATE pt_sessions SET status = ? WHERE pt_id = ?" . ($isCoach ? " AND coach_id = " . $myCoachId : "");
            $pdo->prepare($own)->execute([$ns, $pid]);
        }
        header('Location: pt.php?ok=1' . (isset($_POST['ret_date']) ? '&d=' . urlencode($_POST['ret_date']) . '&m=' . (int)($_POST['member_id'] ?? 0) : ''));
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('جلسات PT', 'pt');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح ✓</div>';

$scope = $isCoach ? " AND pt.coach_id = " . $myCoachId : "";

// ---- KPIs ----
$kpiWeek = (int)$pdo->query("SELECT COUNT(*) FROM pt_sessions pt WHERE pt.status='booked'
    AND pt.session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)$scope")->fetchColumn();
$kpiDone = (int)$pdo->query("SELECT COUNT(*) FROM pt_sessions pt WHERE pt.status='completed'
    AND YEAR(pt.session_date)=YEAR(CURDATE()) AND MONTH(pt.session_date)=MONTH(CURDATE())$scope")->fetchColumn();
$kpiRev  = (float)$pdo->query("SELECT COALESCE(SUM(pt.price),0) FROM pt_sessions pt WHERE pt.status='completed'
    AND YEAR(pt.session_date)=YEAR(CURDATE()) AND MONTH(pt.session_date)=MONTH(CURDATE())$scope")->fetchColumn();
$kpiNoShow = (int)$pdo->query("SELECT COUNT(*) FROM pt_sessions pt WHERE pt.status='no_show'
    AND pt.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)$scope")->fetchColumn();

// ---- القادمة ----
$upcoming = $pdo->query("SELECT pt.*, m.full_name mname, c.full_name cname FROM pt_sessions pt
    JOIN members m ON m.member_id=pt.member_id JOIN coaches c ON c.coach_id=pt.coach_id
    WHERE pt.status='booked' AND pt.session_date >= CURDATE()$scope
    ORDER BY pt.session_date, pt.start_time")->fetchAll();

// ---- السجل (آخر ما جرى) ----
$history = $pdo->query("SELECT pt.*, m.full_name mname, c.full_name cname FROM pt_sessions pt
    JOIN members m ON m.member_id=pt.member_id JOIN coaches c ON c.coach_id=pt.coach_id
    WHERE (pt.status <> 'booked' OR pt.session_date < CURDATE())$scope
    ORDER BY pt.session_date DESC, pt.start_time DESC LIMIT 40")->fetchAll();

// ---- الأعضاء المتاحون للحجز ----
$memWhere = $isCoach ? "WHERE coach_id = " . $myCoachId . " AND coach_id IS NOT NULL" : "WHERE coach_id IS NOT NULL AND status <> 'expired'";
$members = $pdo->query("SELECT member_id, full_name FROM members $memWhere ORDER BY full_name")->fetchAll();

// ---- حالة نموذج الحجز (اختيار عضو + تاريخ ⇒ عرض المواعيد) ----
$selMember = (int)($_GET['m'] ?? 0);
$selDate   = $_GET['d'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$selDate)) $selDate = '';
$slots = []; $slotCoach = null; $slotErr = null;
if ($selMember && $selDate) {
    $slotCoach = pt_member_coach($pdo, $selMember);
    if ($isCoach && $slotCoach !== $myCoachId) $slotErr = 'العضو ليس ضمن أعضائك.';
    elseif (!$slotCoach) $slotErr = 'لا كابتن مُسنَد لهذا العضو.';
    elseif ($selDate < date('Y-m-d')) $slotErr = 'اختر تاريخًا اليوم أو لاحقًا.';
    else {
        $slots = pt_slots_for($pdo, $slotCoach, $selDate);
        if (!$slots) $slotErr = 'لا ورديات لهذا الكابتن في هذا اليوم — أضِف وردية من «شؤون الكباتن».';
    }
}

$stMeta = ['booked'=>['محجوز','#2563eb'],'completed'=>['مكتملة','#16a34a'],'cancelled'=>['ملغاة','#6b7280'],'no_show'=>['تخلّف','#dc2626']];
$WD = [0=>'السبت',1=>'الأحد',2=>'الاثنين',3=>'الثلاثاء',4=>'الأربعاء',5=>'الخميس',6=>'الجمعة'];
$hm = fn($t) => substr((string)$t, 0, 5);
?>
<div style="margin-bottom:6px"><h2 style="margin:0">🏋️‍♂️ جلسات التدريب الشخصي (PT)</h2></div>
<div class="kpis">
  <div class="card" style="--c:#2563eb"><div class="n"><?=$kpiWeek?></div><div class="l">محجوزة هذا الأسبوع</div></div>
  <div class="card" style="--c:#16a34a"><div class="n"><?=$kpiDone?></div><div class="l">مكتملة هذا الشهر</div></div>
  <div class="card" style="--c:#0891b2"><div class="n" style="font-size:22px"><?=money($kpiRev)?></div><div class="l">إيراد الجلسات (الشهر)</div></div>
  <div class="card" style="--c:#dc2626"><div class="n"><?=$kpiNoShow?></div><div class="l">تخلّف (٣٠ يومًا)</div></div>
</div>

<nav class="tabbar" aria-label="أقسام الجلسات">
  <button type="button" data-tabtarget="up">📅 القادمة</button>
  <button type="button" data-tabtarget="new">➕ حجز جلسة</button>
  <button type="button" data-tabtarget="hist">🗂️ السجل</button>
</nav>

<!-- ===== القادمة ===== -->
<section data-tab="up">
  <h2>📅 المواعيد القادمة (<?=count($upcoming)?>)</h2>
  <?php if (!$upcoming): ?><div class="empty">لا مواعيد قادمة — احجز من تبويب «حجز جلسة».</div><?php else: ?>
  <table>
    <tr><th>التاريخ</th><th>اليوم</th><th>الوقت</th><th>العضو</th><?php if(!$isCoach):?><th>الكابتن</th><?php endif;?><th>السعر</th><th>الإجراء</th></tr>
    <?php foreach ($upcoming as $r): $wd = pt_weekday_for($r['session_date']); ?>
      <tr>
        <td><?=h($r['session_date'])?></td>
        <td class="muted"><?=$WD[$wd]?></td>
        <td><?=trange($r['start_time'],$r['end_time'],true)?></td>
        <td><?=h($r['mname'])?></td>
        <?php if(!$isCoach):?><td class="muted"><?=h($r['cname'])?></td><?php endif;?>
        <td><?=money((float)$r['price'])?></td>
        <td style="white-space:nowrap">
          <?php foreach (['completed'=>['✓ تمّت','#16a34a'],'no_show'=>['✗ تخلّف','#dc2626'],'cancelled'=>['إلغاء','#6b7280']] as $s=>$b): ?>
            <form method="post" style="display:inline;margin:0 1px"><?=csrf_field()?>
              <input type="hidden" name="action" value="set_status"><input type="hidden" name="pt_id" value="<?=(int)$r['pt_id']?>"><input type="hidden" name="status" value="<?=$s?>">
              <button type="submit" style="background:<?=$b[1]?>;color:#fff;border:0;padding:4px 8px;border-radius:6px;font-size:11px;cursor:pointer"><?=$b[0]?></button>
            </form>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<!-- ===== حجز جلسة ===== -->
<section data-tab="new">
  <h2>➕ حجز جلسة</h2>
  <?php if (!$members): ?><div class="empty">لا أعضاء متاحون للحجز.</div><?php else: ?>
  <form method="get" data-no-ajax class="frm" style="align-items:end">
    <div><label>العضو</label><select name="m" required>
      <option value="">— اختر —</option>
      <?php foreach ($members as $mm): ?><option value="<?=(int)$mm['member_id']?>" <?=$selMember===(int)$mm['member_id']?'selected':''?>><?=h($mm['full_name'])?></option><?php endforeach; ?>
    </select></div>
    <div><label>التاريخ</label><input type="date" name="d" value="<?=h($selDate ?: date('Y-m-d'))?>" min="<?=date('Y-m-d')?>" required></div>
    <div><button type="submit">🔎 اعرض المواعيد المتاحة</button></div>
  </form>

  <?php if ($selMember && $selDate): ?>
    <?php if ($slotErr): ?>
      <div class="err" style="margin-top:12px"><?=h($slotErr)?></div>
    <?php else: ?>
      <h3><?=$WD[pt_weekday_for($selDate)]?> <?=h($selDate)?> — المواعيد المتاحة</h3>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
        <?php foreach ($slots as $s): $free = $s['free']; ?>
          <?php if ($free): ?>
            <form method="post" style="margin:0"><?=csrf_field()?>
              <input type="hidden" name="action" value="book">
              <input type="hidden" name="member_id" value="<?=$selMember?>">
              <input type="hidden" name="session_date" value="<?=h($selDate)?>">
              <input type="hidden" name="start_time" value="<?=h($s['start'])?>">
              <input type="hidden" name="price" value="<?=PT_PRICE?>">
              <input type="hidden" name="ret_date" value="<?=h($selDate)?>">
              <button type="submit" title="احجز <?=$hm($s['start'])?>" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-weight:700;cursor:pointer"><?=trange($s['start'],$s['end'])?></button>
            </form>
          <?php else: ?>
            <span style="background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-decoration:line-through"><?=trange($s['start'],$s['end'])?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <p class="muted" style="font-size:12px">اضغط موعدًا لحجزه (سعر الجلسة الافتراضي <?=money(PT_PRICE)?>). المشطوب محجوز مسبقًا.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted" style="font-size:13px;margin-top:10px">اختر عضوًا وتاريخًا لعرض المواعيد المتاحة من ورديات كابتنه.</p>
  <?php endif; ?>
  <?php endif; ?>
</section>

<!-- ===== السجل ===== -->
<section data-tab="hist">
  <h2>🗂️ سجلّ الجلسات</h2>
  <?php if (!$history): ?><div class="empty">لا سجلّ بعد.</div><?php else: ?>
  <table>
    <tr><th>التاريخ</th><th>الوقت</th><th>العضو</th><?php if(!$isCoach):?><th>الكابتن</th><?php endif;?><th>السعر</th><th>الحالة</th></tr>
    <?php foreach ($history as $r): [$lbl,$col] = $stMeta[$r['status']]; ?>
      <tr>
        <td><?=h($r['session_date'])?></td>
        <td><?=trange($r['start_time'],$r['end_time'])?></td>
        <td><?=h($r['mname'])?></td>
        <?php if(!$isCoach):?><td class="muted"><?=h($r['cname'])?></td><?php endif;?>
        <td><?= $r['status']==='completed' ? '<strong style="color:#16a34a">'.money((float)$r['price']).'</strong>' : '<span class="muted">'.money((float)$r['price']).'</span>' ?></td>
        <td><span class="badge" style="background:<?=$col?>"><?=$lbl?></span></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>
<?php page_foot();

<?php
// =============================================================================
// Xcamp Gym — بوابة العضو: يشوف برنامجه/تغذيته/تقدّمه، يسجّل تمارينه والتزامه،
// يضيف قياساته، ويطلب التجديد. كل شيء مقصور على بيانات العضو نفسه (من الجلسة).
// =============================================================================
require __DIR__ . '/db.php';
require_once __DIR__ . '/qr.php';
$member = require_member();
$meId   = (int)$member['member_id'];

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

// معرّف الكابتن (لإسناد مهمة التجديد)
$myCoach = null;
if ($pdo && !$error) {
    $c = $pdo->prepare("SELECT coach_id FROM members WHERE member_id = ?");
    $c->execute([$meId]);
    $myCoach = $c->fetchColumn() ?: null;
}

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'member_log_exercise') {
            $xid = (int)$_POST['session_exercise_id'];
            // ملكية صارمة: التمرين لازم يخصّ جلسة في برنامج هذا العضو
            $own = $pdo->prepare("SELECT se.exercise_id FROM session_exercises se
                                  JOIN workout_sessions ws ON ws.session_id = se.session_id
                                  JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                                  WHERE se.session_exercise_id = ? AND wp.member_id = ?");
            $own->execute([$xid, $meId]);
            $exId = $own->fetchColumn();
            if ($exId === false) throw new RuntimeException('غير مسموح.');
            $load = $_POST['load_kg'] !== '' ? (float)$_POST['load_kg'] : null;
            if ($load !== null) {   // اكتشاف رقم قياسي (مستثنيًا هذا الصف)
                $pm = $pdo->prepare("SELECT MAX(se.load_kg) FROM session_exercises se
                                     JOIN workout_sessions ws ON ws.session_id = se.session_id
                                     JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                                     WHERE wp.member_id = ? AND se.exercise_id = ? AND se.session_exercise_id <> ?");
                $pm->execute([$meId, (int)$exId, $xid]);
                $prev = $pm->fetchColumn();
                if ($prev !== null && $prev !== false && $load > (float)$prev) {
                    $exName = $pdo->query("SELECT name FROM exercises WHERE exercise_id = " . (int)$exId)->fetchColumn();
                    $pdo->prepare("INSERT INTO milestones (member_id, milestone_date, milestone_type, description, reward_status)
                                   VALUES (?, CURDATE(), 'strength_gain', ?, 'badge')")
                        ->execute([$meId, "🏆 رقم قياسي جديد في {$exName}: {$load} كجم (السابق: {$prev})"]);
                }
            }
            $pdo->prepare("UPDATE session_exercises SET reps = ?, load_kg = ?, rpe = ?, notes = ? WHERE session_exercise_id = ?")
                ->execute([
                    trim($_POST['reps'] ?? '') ?: null, $load,
                    $_POST['rpe'] !== '' ? (int)$_POST['rpe'] : null,
                    trim($_POST['notes'] ?? '') ?: null, $xid,
                ]);
        } elseif ($act === 'member_log_nutrition') {
            $ad = in_array($_POST['adherence'] ?? '', ['on_plan','partial','off_plan'], true) ? $_POST['adherence'] : 'on_plan';
            $pdo->prepare("INSERT INTO nutrition_logs (member_id, log_date, adherence, notes)
                           VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE adherence = VALUES(adherence), notes = VALUES(notes)")
                ->execute([$meId, $_POST['log_date'] ?: date('Y-m-d'), $ad, trim($_POST['notes'] ?? '') ?: null]);
        } elseif ($act === 'member_add_progress') {
            $num = fn($k) => $_POST[$k] !== '' ? $_POST[$k] : null;
            $pdo->prepare("INSERT INTO progress_tracking (member_id, record_date, weight, body_fat, muscle_mass, waist, performance_note)
                           VALUES (?,?,?,?,?,?,?)")
                ->execute([$meId, $_POST['record_date'] ?: date('Y-m-d'), $num('weight'), $num('body_fat'),
                           $num('muscle_mass'), $num('waist'), trim($_POST['performance_note'] ?? '') ?: null]);
        } elseif ($act === 'member_book_pt') {
            if (!$myCoach) throw new RuntimeException('لا كابتن مُسنَد لك بعد — تواصل مع الاستقبال.');
            $date = $_POST['session_date'] ?? '';
            $start = $_POST['start_time'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d'))
                throw new RuntimeException('اختر تاريخًا اليوم أو لاحقًا.');
            // تأكيد أن الموعد متاح فعلًا ضمن ورديات الكابتن وغير محجوز
            $ok = false; $endT = null; $want = strlen($start) === 5 ? $start . ':00' : $start;
            foreach (pt_slots_for($pdo, (int)$myCoach, $date) as $s)
                if ($s['start'] === $want && $s['free']) { $ok = true; $endT = $s['end']; break; }
            if (!$ok) throw new RuntimeException('الموعد لم يعد متاحًا — اختر موعدًا آخر.');
            $pdo->prepare("INSERT INTO pt_sessions (member_id, coach_id, session_date, start_time, end_time, status, booked_by, price)
                           VALUES (?,?,?,?,?, 'booked', 'member', ?)")
                ->execute([$meId, (int)$myCoach, $date, $want, $endT, PT_PRICE]);
        } elseif ($act === 'member_cancel_pt') {
            // العضو يلغي جلسته المحجوزة المستقبلية فقط
            $pdo->prepare("UPDATE pt_sessions SET status='cancelled'
                           WHERE pt_id = ? AND member_id = ? AND status='booked' AND session_date >= CURDATE()")
                ->execute([(int)$_POST['pt_id'], $meId]);
        } elseif ($act === 'member_request_renewal') {
            $pdo->prepare("INSERT INTO tasks (member_id, coach_id, task_type, priority, status, due_at, notes)
                           VALUES (?,?,'renewal','high','open', DATE_ADD(NOW(), INTERVAL 3 DAY), 'طلب تجديد من العضو عبر البوابة')")
                ->execute([$meId, $myCoach]);
        }
        $ret = isset($_POST['ret_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ret_date']) ? '&ptd=' . urlencode($_POST['ret_date']) : '';
        header('Location: portal.php?ok=1' . $ret);
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

portal_head('بوابتي');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح ✓</div>';

// ---- بيانات العضو (كلها مقصورة على $meId) ----
$m = $pdo->prepare("SELECT * FROM members WHERE member_id = ?");
$m->execute([$meId]); $m = $m->fetch();

$mem = $pdo->prepare("SELECT ms.*, p.plan_name, p.price, p.access_level, DATEDIFF(ms.end_date, CURDATE()) AS days_left
                      FROM memberships ms JOIN plans p ON p.plan_id = ms.plan_id
                      WHERE ms.member_id = ? ORDER BY ms.end_date DESC LIMIT 1");
$mem->execute([$meId]); $mem = $mem->fetch();

$comp = $pdo->prepare("SELECT SUM(ws.completion_status='completed') d, COUNT(*) t
                       FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                       WHERE wp.member_id = ? AND ws.session_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()");
$comp->execute([$meId]); $comp = $comp->fetch();
$compPct = ($comp && (int)$comp['t']) ? round((int)$comp['d'] / (int)$comp['t'] * 100) : null;

$next = $pdo->prepare("SELECT ws.session_id, ws.session_date, ws.muscle_group FROM workout_sessions ws
                       JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                       WHERE wp.member_id = ? AND ws.session_date >= CURDATE() AND ws.completion_status = 'planned'
                       ORDER BY ws.session_date LIMIT 1");
$next->execute([$meId]); $next = $next->fetch();

// البرنامج النشط + جلساته
$plan = $pdo->prepare("SELECT * FROM workout_plans WHERE member_id = ? AND status = 'active' ORDER BY start_date DESC, workout_plan_id DESC LIMIT 1");
$plan->execute([$meId]); $plan = $plan->fetch();
$sessions = [];
if ($plan) {
    $ss = $pdo->prepare("SELECT ws.*, (SELECT COUNT(*) FROM session_exercises se WHERE se.session_id = ws.session_id) ex_cnt
                         FROM workout_sessions ws WHERE ws.workout_plan_id = ? ORDER BY ws.session_date DESC LIMIT 20");
    $ss->execute([$plan['workout_plan_id']]);
    $sessions = $ss->fetchAll();
}
// جلسة مفتوحة للتسجيل (بتأكيد الملكية)
$openSid = (int)($_GET['sid'] ?? 0);
$openSession = null; $openExs = [];
if ($openSid) {
    $chk = $pdo->prepare("SELECT ws.session_id, ws.session_date, ws.muscle_group, ws.completion_status
                          FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                          WHERE ws.session_id = ? AND wp.member_id = ?");
    $chk->execute([$openSid, $meId]);
    $openSession = $chk->fetch();
    if ($openSession) {
        $ex = $pdo->prepare("SELECT se.*, e.name ex_name, e.muscle_group ex_group FROM session_exercises se
                             JOIN exercises e ON e.exercise_id = se.exercise_id WHERE se.session_id = ? ORDER BY se.sort_order");
        $ex->execute([$openSid]); $openExs = $ex->fetchAll();
    }
}

// التغذية النشطة + سجلّ الالتزام
$nutri = $pdo->prepare("SELECT * FROM nutrition_plans WHERE member_id = ? AND status = 'active' ORDER BY nutrition_plan_id DESC LIMIT 1");
$nutri->execute([$meId]); $nutri = $nutri->fetch();
$nlogs = $pdo->prepare("SELECT * FROM nutrition_logs WHERE member_id = ? ORDER BY log_date DESC LIMIT 10");
$nlogs->execute([$meId]); $nlogs = $nlogs->fetchAll();
$nComp = nutrition_compliance($pdo->query("SELECT adherence FROM nutrition_logs WHERE member_id = $meId AND log_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)")->fetchAll());

// التقدّم
$prog = $pdo->prepare("SELECT * FROM progress_tracking WHERE member_id = ? ORDER BY record_date");
$prog->execute([$meId]); $prog = $prog->fetchAll();
// إنجازات
$mile = $pdo->prepare("SELECT * FROM milestones WHERE member_id = ? ORDER BY milestone_date DESC, milestone_id DESC LIMIT 8");
$mile->execute([$meId]); $mile = $mile->fetchAll();

// جلسات PT: اسم الكابتن + مواعيد اليوم المختار + جلسات العضو
$ptCoachName = null;
if ($myCoach) { $cn = $pdo->prepare("SELECT full_name FROM coaches WHERE coach_id = ?"); $cn->execute([(int)$myCoach]); $ptCoachName = $cn->fetchColumn() ?: null; }
$ptSelDate = $_GET['ptd'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ptSelDate)) $ptSelDate = '';
$ptSlots = []; $ptSlotErr = null;
if ($myCoach && $ptSelDate) {
    if ($ptSelDate < date('Y-m-d')) $ptSlotErr = 'اختر تاريخًا اليوم أو لاحقًا.';
    else { $ptSlots = pt_slots_for($pdo, (int)$myCoach, $ptSelDate);
           if (!$ptSlots) $ptSlotErr = 'لا مواعيد متاحة لكابتنك في هذا اليوم — جرّب يومًا آخر.'; }
}
$myPt = $pdo->prepare("SELECT pt.*, c.full_name cname FROM pt_sessions pt JOIN coaches c ON c.coach_id=pt.coach_id
                       WHERE pt.member_id = ? ORDER BY (pt.status='booked' AND pt.session_date>=CURDATE()) DESC, pt.session_date DESC, pt.start_time DESC LIMIT 20");
$myPt->execute([$meId]); $myPt = $myPt->fetchAll();
$ptStMeta = ['booked'=>['محجوز','#2563eb'],'completed'=>['مكتملة','#16a34a'],'cancelled'=>['ملغاة','#6b7280'],'no_show'=>['تخلّف','#dc2626']];
$hm = fn($t) => substr((string)$t, 0, 5);

$stColor = ['planned'=>'#2563eb','completed'=>'#16a34a','partial'=>'#f59e0b','missed'=>'#dc2626'];
$payColor = ['paid'=>'#16a34a','partial'=>'#f59e0b','unpaid'=>'#dc2626','failed'=>'#dc2626','refunded'=>'#6b7280'];
?>
<div style="margin-bottom:6px"><h2 style="margin:0">👋 أهلًا <?=h($m['full_name'])?></h2></div>

<nav class="tabbar" aria-label="أقسام بوابتي">
  <button type="button" data-tabtarget="over">🏠 نظرة عامة</button>
  <button type="button" data-tabtarget="prog">🏋️ برنامجي</button>
  <button type="button" data-tabtarget="nutr">🥗 تغذيتي</button>
  <button type="button" data-tabtarget="track">📈 تقدّمي</button>
  <button type="button" data-tabtarget="pt">🏋️‍♂️ جلسات PT</button>
  <button type="button" data-tabtarget="sub">🎫 اشتراكي</button>
</nav>

<!-- ===== نظرة عامة ===== -->
<section data-tab="over">
  <h2>🏠 نظرة عامة</h2>
  <div class="kpis">
    <div class="card" style="--c:#2563eb"><div class="n" style="color:#2563eb;font-size:22px"><?= $compPct===null ? '—' : $compPct.'%' ?></div><div class="l">التزام التمرين (٣٠ يومًا)</div></div>
    <div class="card" style="--c:<?=$nComp['color']?>"><div class="n" style="color:<?=$nComp['color']?>;font-size:22px"><?= $nComp['pct']===null ? '—' : $nComp['pct'].'%' ?></div><div class="l">التزام التغذية (١٤ يومًا)</div></div>
    <div class="card" style="--c:#16a34a"><div class="n" style="color:#16a34a;font-size:22px"><?= $prog ? h(end($prog)['weight']).' كجم' : '—' ?></div><div class="l">آخر وزن</div></div>
    <div class="card" style="--c:#f59e0b"><div class="n" style="color:#f59e0b;font-size:22px"><?= $mem && $mem['days_left']!==null ? (int)$mem['days_left'].' يوم' : '—' ?></div><div class="l">متبقٍّ على الاشتراك</div></div>
  </div>
  <?php if ($next): ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin-top:6px">
      ⏭️ جلستك القادمة: <strong><?=h($next['session_date'])?></strong> — <?=h($next['muscle_group'] ?? 'تمرين')?>
      · <a class="link" href="portal.php?sid=<?=(int)$next['session_id']?>#prog">افتحها وسجّل أداءك ←</a>
    </div>
  <?php endif; ?>
  <div style="border:1px solid #eef2f7;border-radius:12px;padding:16px;margin-top:8px;text-align:center;max-width:280px">
    <strong style="font-size:14px;color:#334155">🎫 رمز دخولك</strong>
    <div style="margin:10px auto;width:180px"><?= qr_svg(member_qr_token($pdo, $meId), 5) ?></div>
    <p class="muted" style="font-size:12px;margin:0">اعرض هذا الرمز عند الاستقبال لتسجيل حضورك.</p>
  </div>
  <?php if ($mile): ?>
    <h3>🏆 إنجازاتك</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach ($mile as $ml): ?>
        <span style="background:#fef9c3;color:#854d0e;border:1px solid #fde68a;border-radius:999px;padding:5px 12px;font-size:12px"><?=h($ml['description'])?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- ===== برنامجي ===== -->
<section data-tab="prog">
  <h2>🏋️ برنامجي</h2>
  <?php if (!$plan): ?><div class="empty">لا يوجد برنامج نشط بعد — سيُسنده كابتنك قريبًا.</div>
  <?php elseif ($openSession): ?>
    <div class="crumb"><a class="link" href="portal.php#prog">→ كل الجلسات</a> / <strong>جلسة <?=h($openSession['session_date'])?></strong></div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <strong style="font-size:16px">▶️ <?=h($openSession['muscle_group'] ?? 'تمرين')?></strong>
      <span class="badge" style="background:<?=$stColor[$openSession['completion_status']] ?? '#6b7280'?>"><?=h($openSession['completion_status'])?></span>
    </div>
    <?php if (!$openExs): ?><div class="empty">لا توجد تمارين في هذه الجلسة.</div><?php endif; ?>
    <?php foreach ($openExs as $i => $x): ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:12px;margin-bottom:10px">
        <strong style="font-size:15px"><?=($i+1)?>. <?=h($x['ex_name'])?></strong>
        <span class="muted" style="font-size:12px">· <?=h($x['ex_group'])?><?= $x['sets'] ? ' · '.h($x['sets']).' مجموعات' : '' ?></span>
        <form class="frm" method="post" style="margin-top:8px"><?=csrf_field()?>
          <input type="hidden" name="action" value="member_log_exercise">
          <input type="hidden" name="session_exercise_id" value="<?=$x['session_exercise_id']?>">
          <div><label>تكرارات</label><input name="reps" value="<?=h($x['reps'])?>"></div>
          <div><label>الحمل الفعلي (كجم)</label><input type="number" step="0.5" name="load_kg" value="<?=h($x['load_kg'])?>" style="border:2px solid #2563eb;font-weight:700"></div>
          <div><label>RPE (الجهد ١-١٠)</label><input type="number" name="rpe" min="1" max="10" value="<?=h($x['rpe'])?>"></div>
          <div><label>ملاحظة</label><input name="notes" value="<?=h($x['notes'])?>"></div>
          <div><button type="submit">💾 حفظ</button></div>
        </form>
      </div>
    <?php endforeach; ?>
    <p class="muted" style="font-size:12px">تسجيل حمل أعلى من رقمك السابق يمنحك إنجاز 🏆 تلقائيًا.</p>
  <?php else: ?>
    <div class="muted" style="font-size:13px;margin-bottom:10px"><?=h($plan['goal_type'])?> · <?=h($plan['phase'])?> · <?=h($plan['start_date'])?> ← <?=h($plan['end_date'] ?? '')?></div>
    <table>
      <tr><th>التاريخ</th><th>المجموعة</th><th>تمارين</th><th>الحالة</th><th></th></tr>
      <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?=h($s['session_date'])?></td><td><?=h($s['muscle_group'] ?? '—')?></td><td><?=h($s['ex_cnt'])?></td>
          <td><span class="badge" style="background:<?=$stColor[$s['completion_status']] ?? '#6b7280'?>"><?=h($s['completion_status'])?></span></td>
          <td><a class="link" href="portal.php?sid=<?=(int)$s['session_id']?>#prog">سجّل أداءك ←</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</section>

<!-- ===== تغذيتي ===== -->
<section data-tab="nutr">
  <h2>🥗 تغذيتي</h2>
  <?php if (!$nutri): ?><div class="empty">لا توجد خطة تغذية نشطة بعد.</div>
  <?php else: ?>
    <div class="kpis" style="margin-bottom:8px">
      <div class="card" style="--c:#2563eb"><div class="n" style="font-size:22px"><?=h($nutri['calories'] ?? '—')?></div><div class="l">سعرات/يوم</div></div>
      <div class="card" style="--c:#16a34a"><div class="n" style="font-size:22px"><?=h($nutri['protein_g'] ?? '—')?></div><div class="l">بروتين (g)</div></div>
      <div class="card" style="--c:#f59e0b"><div class="n" style="font-size:22px"><?=h($nutri['carbs_g'] ?? '—')?></div><div class="l">كارب (g)</div></div>
      <div class="card" style="--c:#dc2626"><div class="n" style="font-size:22px"><?=h($nutri['fat_g'] ?? '—')?></div><div class="l">دهون (g)</div></div>
    </div>
    <?php if ($nutri['meal_timing']): ?><p class="muted" style="font-size:12px">🍽️ <?=h($nutri['meal_timing'])?></p><?php endif; ?>
  <?php endif; ?>
  <h3>➕ سجّل التزامك اليوم</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="member_log_nutrition">
    <div><label>التاريخ</label><input type="date" name="log_date" value="<?=date('Y-m-d')?>" required></div>
    <div><label>الالتزام</label><select name="adherence"><option value="on_plan">ملتزم</option><option value="partial">جزئي</option><option value="off_plan">خارج الخطة</option></select></div>
    <div style="grid-column:1/-1"><label>ملاحظة</label><input name="notes"></div>
    <div><button type="submit">حفظ</button></div>
  </form>
  <?php if ($nlogs): ?>
    <h3>آخر أيامك (<?= $nComp['pct']!==null ? 'التزام '.$nComp['pct'].'%' : '' ?>)</h3>
    <table><tr><th>التاريخ</th><th>الالتزام</th><th>ملاحظة</th></tr>
      <?php $al=['on_plan'=>['ملتزم','#16a34a'],'partial'=>['جزئي','#f59e0b'],'off_plan'=>['خارج الخطة','#dc2626']];
      foreach ($nlogs as $lg): [$l,$c]=$al[$lg['adherence']]; ?>
        <tr><td><?=h($lg['log_date'])?></td><td><span class="badge" style="background:<?=$c?>"><?=$l?></span></td><td class="muted"><?=h($lg['notes'])?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</section>

<!-- ===== تقدّمي ===== -->
<section data-tab="track">
  <h2>📈 تقدّمي</h2>
  <?php if ($prog): ?>
    <?php
    $wS = ['label'=>'الوزن (kg)','color'=>'#2563eb','points'=>array_map(fn($r)=>[$r['record_date'],$r['weight']],$prog)];
    $mS = ['label'=>'الكتلة العضلية (kg)','color'=>'#16a34a','points'=>array_map(fn($r)=>[$r['record_date'],$r['muscle_mass']],$prog)];
    $fS = ['label'=>'الدهون (%)','color'=>'#f59e0b','points'=>array_map(fn($r)=>[$r['record_date'],$r['body_fat']],$prog)];
    ?>
    <div class="grid2">
      <div><strong style="font-size:13px;color:#334155">الوزن والكتلة</strong><?=line_chart([$wS,$mS])?></div>
      <div><strong style="font-size:13px;color:#334155">نسبة الدهون</strong><?=line_chart([$fS])?></div>
    </div>
  <?php else: ?><div class="empty">لا توجد قياسات بعد — أضِف أول قياس بالأسفل.</div><?php endif; ?>
  <h3>➕ أضِف قياسًا</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="member_add_progress">
    <div><label>التاريخ</label><input type="date" name="record_date" value="<?=date('Y-m-d')?>" required></div>
    <div><label>الوزن (kg)</label><input type="number" step="0.1" name="weight"></div>
    <div><label>نسبة الدهون (%)</label><input type="number" step="0.1" name="body_fat"></div>
    <div><label>الكتلة العضلية (kg)</label><input type="number" step="0.1" name="muscle_mass"></div>
    <div><label>الخصر (cm)</label><input type="number" step="0.1" name="waist"></div>
    <div style="grid-column:1/-1"><label>ملاحظة</label><input name="performance_note"></div>
    <div><button type="submit">حفظ القياس</button></div>
  </form>
</section>

<!-- ===== جلسات PT ===== -->
<section data-tab="pt">
  <h2>🏋️‍♂️ جلسات التدريب الشخصي</h2>
  <?php if (!$myCoach): ?>
    <div class="empty">لا كابتن مُسنَد لك بعد — تواصل مع الاستقبال لحجز جلسة شخصية.</div>
  <?php else: ?>
    <p class="muted" style="font-size:13px;margin-top:0">كابتنك: <strong><?=h($ptCoachName)?></strong> · سعر الجلسة <?=money(PT_PRICE)?></p>
    <h3>📆 احجز موعدًا</h3>
    <form method="get" data-no-ajax class="frm" style="align-items:end">
      <div><label>اختر اليوم</label><input type="date" name="ptd" value="<?=h($ptSelDate ?: date('Y-m-d'))?>" min="<?=date('Y-m-d')?>" required></div>
      <div><button type="submit">🔎 اعرض المواعيد</button></div>
    </form>
    <?php if ($ptSelDate): ?>
      <?php if ($ptSlotErr): ?>
        <div class="err" style="margin-top:10px"><?=h($ptSlotErr)?></div>
      <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0">
          <?php foreach ($ptSlots as $s): ?>
            <?php if ($s['free']): ?>
              <form method="post" style="margin:0"><?=csrf_field()?>
                <input type="hidden" name="action" value="member_book_pt">
                <input type="hidden" name="session_date" value="<?=h($ptSelDate)?>">
                <input type="hidden" name="start_time" value="<?=h($s['start'])?>">
                <input type="hidden" name="ret_date" value="<?=h($ptSelDate)?>">
                <button type="submit" onclick="return confirm('حجز موعد <?=$hm($s['start'])?> يوم <?=h($ptSelDate)?>؟')" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:8px;padding:9px 14px;font-weight:700;cursor:pointer"><?=trange($s['start'],$s['end'])?></button>
              </form>
            <?php else: ?>
              <span style="background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;border-radius:8px;padding:9px 14px;text-decoration:line-through"><?=trange($s['start'],$s['end'])?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <h3>🗓️ جلساتي</h3>
    <?php if (!$myPt): ?><div class="empty">لا جلسات بعد — احجز أول جلسة بالأعلى.</div><?php else: ?>
    <table>
      <tr><th>التاريخ</th><th>الوقت</th><th>الكابتن</th><th>الحالة</th><th></th></tr>
      <?php foreach ($myPt as $r): [$lbl,$col] = $ptStMeta[$r['status']];
        $future = $r['status']==='booked' && $r['session_date'] >= date('Y-m-d'); ?>
        <tr>
          <td><?=h($r['session_date'])?></td>
          <td><?=trange($r['start_time'],$r['end_time'])?></td>
          <td class="muted"><?=h($r['cname'])?></td>
          <td><span class="badge" style="background:<?=$col?>"><?=$lbl?></span></td>
          <td>
            <?php if ($future): ?>
              <form method="post" style="margin:0" onsubmit="return confirm('إلغاء هذه الجلسة؟')"><?=csrf_field()?>
                <input type="hidden" name="action" value="member_cancel_pt"><input type="hidden" name="pt_id" value="<?=(int)$r['pt_id']?>">
                <button type="submit" style="background:none;border:1px solid #fecaca;color:#dc2626;border-radius:6px;padding:3px 10px;font-size:11px;cursor:pointer">إلغاء</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</section>

<!-- ===== اشتراكي ===== -->
<section data-tab="sub">
  <h2>🎫 اشتراكي</h2>
  <?php if (!$mem): ?><div class="empty">لا يوجد اشتراك مسجّل.</div>
  <?php else:
    $dl = (int)$mem['days_left']; ?>
    <table>
      <tr><td>الخطة</td><td><strong><?=h($mem['plan_name'])?></strong> (<?=h($mem['access_level'])?>)</td></tr>
      <tr><td>تبدأ</td><td><?=h($mem['start_date'])?></td></tr>
      <tr><td>تنتهي</td><td><strong style="color:<?= $dl<0?'#dc2626':($dl<=7?'#f59e0b':'#334155') ?>"><?=h($mem['end_date'])?> (<?= $dl<0 ? 'منتهٍ' : $dl.' يوم' ?>)</strong></td></tr>
      <tr><td>حالة التجديد</td><td><?=h($mem['renewal_status'])?></td></tr>
      <tr><td>حالة الدفع</td><td><span style="color:<?=$payColor[$mem['payment_status']] ?? '#64748b'?>;font-weight:600"><?=h($mem['payment_status'])?></span></td></tr>
    </table>
    <?php if ($mem['renewal_status'] !== 'renewed'): ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('إرسال طلب تجديد لفريق الجيم؟')"><?=csrf_field()?>
        <input type="hidden" name="action" value="member_request_renewal">
        <button type="submit" style="background:#16a34a;color:#fff;border:0;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer">🔄 اطلب تجديد اشتراكي</button>
      </form>
      <p class="muted" style="font-size:12px;margin-top:8px">سيصل طلبك لكابتنك/الاستقبال كمهمة تجديد.</p>
    <?php else: ?>
      <div style="background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-top:12px">✅ اشتراكك مُجدَّد. استمتع بتمرينك!</div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php page_foot();

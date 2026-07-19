<?php
// =============================================================================
// Xcamp Gym — واجهة الكباتن: برامج التمرين + التغذية + متابعة التقدّم لكل عضو.
// الكابتن يرى أعضاءه فقط؛ المدير يتصفّح كل الكباتن.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$GOALS   = ['fat_loss','muscle_gain','strength','rehab','performance','general_fitness'];
$PHASES  = ['corrective','stabilization','hypertrophy','strength','power','maintenance'];
$PSTATUS = ['active','paused','completed','cancelled'];
$CSTATUS = ['planned','partial','completed','missed'];
$CLASSIF   = ['excellent','good','moderate','high_risk','critical'];
$FREASONS  = ['no_show','low_attendance','payment_issue','injury','motivation','progress_review','other'];
$FCHANNELS = ['call','whatsapp','sms','email','in_person','other'];
$FRESP     = ['no_response','replied','booked','converted','escalated'];
$MCHANNELS = ['whatsapp','sms','email','call','in_person','other'];
$MTYPES    = ['welcome','followup','reminder','winback','renewal','progress','warning','other'];
$MSTATUS   = ['sent','delivered','failed','replied'];
$MILTYPES  = ['first_week','first_month','weight_loss','strength_gain','attendance_streak','program_completion','renewal','upgrade'];
$REWARDS   = ['none','badge','gift','promotion','discount'];

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$coachId  = isset($_GET['coach'])  ? (int)$_GET['coach']  : 0;
$memberId = isset($_GET['member']) ? (int)$_GET['member'] : 0;

// الكابتن مقيَّد بأعضائه هو فقط
$isCoach = ($me['role'] === 'coach');
if ($isCoach) $coachId = (int)($me['coach_id'] ?? 0);

/** يتحقّق أن العضو ضمن صلاحية المستخدم (المدير: الكل؛ الكابتن: أعضاؤه فقط) */
function member_allowed(PDO $pdo, array $me, int $memberId): bool {
    if ($me['role'] !== 'coach') return true;
    $st = $pdo->prepare("SELECT 1 FROM members WHERE member_id = ? AND coach_id = ?");
    $st->execute([$memberId, (int)$me['coach_id']]);
    return (bool)$st->fetchColumn();
}

// ---- معالجة النماذج (POST) ثم إعادة توجيه (PRG) ----
if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $act = $_POST['action'] ?? '';

        // إغلاق مهمة: الصلاحية على المهمة نفسها (قد لا ترتبط بعضو)
        if ($act === 'complete_task') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $st = $pdo->prepare("SELECT coach_id FROM tasks WHERE task_id = ? AND status IN ('open','doing')");
            $st->execute([$taskId]);
            $row = $st->fetch();
            if (!$row) throw new RuntimeException('المهمة غير موجودة أو مغلقة بالفعل.');
            if ($me['role'] === 'coach' && (int)$row['coach_id'] !== (int)$me['coach_id']) {
                throw new RuntimeException('غير مسموح بإغلاق مهمة كابتن آخر.');
            }
            $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = NOW() WHERE task_id = ?")->execute([$taskId]);
            header("Location: captains.php?coach={$coachId}&member={$memberId}&ok=1");
            exit;
        }

        // تحديد العضو المستهدف والتحقّق من الصلاحية (للكيانات الفرعية نستنتجه من الكيان نفسه)
        $targetMember = (int)($_POST['member_id'] ?? 0);
        if ($act === 'add_workout_session') {
            $targetMember = (int)$pdo->query("SELECT member_id FROM workout_plans WHERE workout_plan_id = " . (int)$_POST['workout_plan_id'])->fetchColumn();
        } elseif (in_array($act, ['set_session_status','delete_session'], true)) {
            $targetMember = (int)$pdo->query("SELECT wp.member_id FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id WHERE ws.session_id = " . (int)$_POST['session_id'])->fetchColumn();
        } elseif (in_array($act, ['set_wplan_status','delete_wplan'], true)) {
            $targetMember = (int)$pdo->query("SELECT member_id FROM workout_plans WHERE workout_plan_id = " . (int)$_POST['workout_plan_id'])->fetchColumn();
        } elseif (in_array($act, ['set_nplan_status','delete_nplan'], true)) {
            $targetMember = (int)$pdo->query("SELECT member_id FROM nutrition_plans WHERE nutrition_plan_id = " . (int)$_POST['nutrition_plan_id'])->fetchColumn();
        } elseif (in_array($act, ['toggle_supplement','delete_supplement'], true)) {
            $targetMember = (int)$pdo->query("SELECT member_id FROM supplements WHERE supplement_id = " . (int)$_POST['supplement_id'])->fetchColumn();
        } elseif ($act === 'delete_progress') {
            $targetMember = (int)$pdo->query("SELECT member_id FROM progress_tracking WHERE progress_id = " . (int)$_POST['progress_id'])->fetchColumn();
        }
        if (!member_allowed($pdo, $me, $targetMember)) throw new RuntimeException('غير مسموح بتعديل هذا العضو.');

        if ($act === 'add_workout_plan') {
            $pdo->prepare("INSERT INTO workout_plans (member_id, coach_id, goal_type, phase, start_date, end_date, status, notes)
                           VALUES (?,?,?,?,?,?,?,?)")->execute([
                $targetMember, (int)$_POST['coach_id'] ?: null, $_POST['goal_type'], $_POST['phase'],
                $_POST['start_date'], $_POST['end_date'] ?: null, $_POST['status'], trim($_POST['notes'] ?? '') ?: null,
            ]);
        } elseif ($act === 'add_workout_session') {
            $pdo->prepare("INSERT INTO workout_sessions (workout_plan_id, session_date, muscle_group, exercises, sets_info, reps_info, load_info, intensity_info, completion_status, notes)
                           VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
                (int)$_POST['workout_plan_id'], $_POST['session_date'],
                trim($_POST['muscle_group'] ?? '') ?: null, trim($_POST['exercises'] ?? '') ?: null,
                trim($_POST['sets_info'] ?? '') ?: null, trim($_POST['reps_info'] ?? '') ?: null,
                trim($_POST['load_info'] ?? '') ?: null, trim($_POST['intensity_info'] ?? '') ?: null,
                $_POST['completion_status'], trim($_POST['notes'] ?? '') ?: null,
            ]);
        } elseif ($act === 'add_nutrition_plan') {
            $pdo->prepare("INSERT INTO nutrition_plans (member_id, coach_id, calories, protein_g, fat_g, carbs_g, hydration_target_l, meal_timing, status)
                           VALUES (?,?,?,?,?,?,?,?,?)")->execute([
                $targetMember, (int)$_POST['coach_id'] ?: null,
                $_POST['calories'] !== '' ? (int)$_POST['calories'] : null,
                $_POST['protein_g'] !== '' ? $_POST['protein_g'] : null,
                $_POST['fat_g'] !== '' ? $_POST['fat_g'] : null,
                $_POST['carbs_g'] !== '' ? $_POST['carbs_g'] : null,
                $_POST['hydration_target_l'] !== '' ? $_POST['hydration_target_l'] : null,
                trim($_POST['meal_timing'] ?? '') ?: null, $_POST['status'],
            ]);
        } elseif ($act === 'add_supplement') {
            $pdo->prepare("INSERT INTO supplements (member_id, supplement_name, dose, timing, purpose, active)
                           VALUES (?,?,?,?,?,1)")->execute([
                $targetMember, trim($_POST['supplement_name']),
                trim($_POST['dose'] ?? '') ?: null, trim($_POST['timing'] ?? '') ?: null, trim($_POST['purpose'] ?? '') ?: null,
            ]);
        } elseif ($act === 'add_assessment') {
            // إدخال التقييم يشغّل sp_handle_assessment_event تلقائيًا عبر الـ trigger
            // (risk_score >= 60: at_risk + إنذار ومهمة؛ >= 80: corrective + إنذار حرج)
            $pdo->prepare("INSERT INTO assessments (member_id, coach_id, assessment_date, parq_risk_count, overhead_squat_score, posture_score, movement_score, risk_score, classification, recommendation, next_review_date)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $targetMember, (int)$_POST['coach_id'] ?: null, $_POST['assessment_date'],
                (int)($_POST['parq_risk_count'] ?? 0),
                $_POST['overhead_squat_score'] !== '' ? $_POST['overhead_squat_score'] : null,
                $_POST['posture_score'] !== '' ? $_POST['posture_score'] : null,
                $_POST['movement_score'] !== '' ? $_POST['movement_score'] : null,
                $_POST['risk_score'] !== '' ? $_POST['risk_score'] : 0,
                $_POST['classification'],
                trim($_POST['recommendation'] ?? '') ?: null,
                $_POST['next_review_date'] ?: null,
            ]);
        } elseif ($act === 'add_followup') {
            // إدخال المتابعة يشغّل sp_handle_followup_event تلقائيًا عبر الـ trigger
            // (no_response: مهمة اتصال غدًا؛ booked/converted: حلّ الإنذارات المفتوحة)
            $pdo->prepare("INSERT INTO followups (member_id, coach_id, followup_date, reason, contact_channel, response_status, action_taken, next_followup_date)
                           VALUES (?,?,?,?,?,?,?,?)")->execute([
                $targetMember, (int)$_POST['coach_id'] ?: null, $_POST['followup_date'],
                $_POST['reason'], $_POST['contact_channel'], $_POST['response_status'],
                trim($_POST['action_taken'] ?? '') ?: null,
                $_POST['next_followup_date'] ?: null,
            ]);
        } elseif ($act === 'add_progress') {
            $pdo->prepare("INSERT INTO progress_tracking (member_id, record_date, weight, body_fat, muscle_mass, waist, chest, hips, performance_note)
                           VALUES (?,?,?,?,?,?,?,?,?)")->execute([
                $targetMember, $_POST['record_date'],
                $_POST['weight'] !== '' ? $_POST['weight'] : null,
                $_POST['body_fat'] !== '' ? $_POST['body_fat'] : null,
                $_POST['muscle_mass'] !== '' ? $_POST['muscle_mass'] : null,
                $_POST['waist'] !== '' ? $_POST['waist'] : null,
                $_POST['chest'] !== '' ? $_POST['chest'] : null,
                $_POST['hips'] !== '' ? $_POST['hips'] : null,
                trim($_POST['performance_note'] ?? '') ?: null,
            ]);
        } elseif ($act === 'set_session_status') {
            $pdo->prepare("UPDATE workout_sessions SET completion_status = ? WHERE session_id = ?")
                ->execute([$_POST['completion_status'], (int)$_POST['session_id']]);
        } elseif ($act === 'delete_session') {
            $pdo->prepare("DELETE FROM workout_sessions WHERE session_id = ?")->execute([(int)$_POST['session_id']]);
        } elseif ($act === 'set_wplan_status') {
            $pdo->prepare("UPDATE workout_plans SET status = ? WHERE workout_plan_id = ?")
                ->execute([$_POST['status'], (int)$_POST['workout_plan_id']]);
        } elseif ($act === 'delete_wplan') {
            // جلسات البرنامج تُحذف تلقائيًا (FK CASCADE)
            $pdo->prepare("DELETE FROM workout_plans WHERE workout_plan_id = ?")->execute([(int)$_POST['workout_plan_id']]);
        } elseif ($act === 'set_nplan_status') {
            $pdo->prepare("UPDATE nutrition_plans SET status = ? WHERE nutrition_plan_id = ?")
                ->execute([$_POST['status'], (int)$_POST['nutrition_plan_id']]);
        } elseif ($act === 'delete_nplan') {
            $pdo->prepare("DELETE FROM nutrition_plans WHERE nutrition_plan_id = ?")->execute([(int)$_POST['nutrition_plan_id']]);
        } elseif ($act === 'toggle_supplement') {
            $pdo->prepare("UPDATE supplements SET active = 1 - active WHERE supplement_id = ?")->execute([(int)$_POST['supplement_id']]);
        } elseif ($act === 'delete_supplement') {
            $pdo->prepare("DELETE FROM supplements WHERE supplement_id = ?")->execute([(int)$_POST['supplement_id']]);
        } elseif ($act === 'delete_progress') {
            $pdo->prepare("DELETE FROM progress_tracking WHERE progress_id = ?")->execute([(int)$_POST['progress_id']]);
        } elseif ($act === 'add_attendance') {
            // سجلّ واحد لكل عضو في اليوم (فحص تطبيقي — المخطط لا يفرض قيد تفرّد)
            $dup = $pdo->prepare("SELECT 1 FROM daily_attendance WHERE member_id = ? AND attendance_date = ?");
            $dup->execute([$targetMember, $_POST['attendance_date']]);
            if ($dup->fetchColumn()) throw new RuntimeException('يوجد تسجيل حضور/غياب لهذا العضو في هذا اليوم بالفعل.');
            // تسجيل الحضور يشغّل sp_handle_attendance_event تلقائيًا عبر الـ trigger
            // (حضور: onboarding->active / at_risk->reactivated + حلّ إنذارات الحضور؛
            //  غياب: بعد 3 غيابات في 7 أيام -> إنذار + مهمة + at_risk)
            $pdo->prepare("INSERT INTO daily_attendance (member_id, coach_id, attendance_date, check_in_time, check_out_time, attended, session_type, notes)
                           VALUES (?,?,?,?,?,?,?,?)")->execute([
                $targetMember, (int)$_POST['coach_id'] ?: null, $_POST['attendance_date'],
                $_POST['check_in_time'] ?: null, $_POST['check_out_time'] ?: null,
                (int)$_POST['attended'], trim($_POST['session_type'] ?? '') ?: null,
                trim($_POST['notes'] ?? '') ?: null,
            ]);
        } elseif ($act === 'add_message') {
            $pdo->prepare("INSERT INTO messages_log (member_id, coach_id, channel, message_type, content, sent_at, status)
                           VALUES (?,?,?,?,?,NOW(),?)")->execute([
                $targetMember, (int)$_POST['coach_id'] ?: null, $_POST['channel'], $_POST['message_type'],
                trim($_POST['content']), $_POST['status'],
            ]);
        } elseif ($act === 'add_milestone') {
            $pdo->prepare("INSERT INTO milestones (member_id, milestone_date, milestone_type, description, reward_status)
                           VALUES (?,?,?,?,?)")->execute([
                $targetMember, $_POST['milestone_date'], $_POST['milestone_type'],
                trim($_POST['description']), $_POST['reward_status'],
            ]);
        }
        header("Location: captains.php?coach={$coachId}&member={$memberId}&ok=1");
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('واجهة الكباتن', 'captains');
if ($error) { db_error_box($error); page_foot(); exit; }
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح.</div>';

function sel(string $name, array $opts, string $cur = ''): string {
    $h = "<select name=\"" . h($name) . "\">";
    foreach ($opts as $o) $h .= "<option" . ($o === $cur ? " selected" : "") . ">" . h($o) . "</option>";
    return $h . "</select>";
}

// ===================== 1) قائمة الكباتن (للمدير فقط) =====================
if (!$coachId) {
    $coaches = $pdo->query("SELECT c.coach_id, c.full_name, c.specialty, COUNT(m.member_id) AS members
                            FROM coaches c LEFT JOIN members m ON m.coach_id = c.coach_id
                            WHERE c.active = 1 GROUP BY c.coach_id, c.full_name, c.specialty ORDER BY c.coach_id")->fetchAll();
    echo '<section><h2>👨‍🏫 اختر الكابتن</h2><table>';
    echo '<tr><th>#</th><th>الاسم</th><th>التخصص</th><th>عدد الأعضاء</th><th></th></tr>';
    foreach ($coaches as $c) {
        echo '<tr><td>' . h($c['coach_id']) . '</td><td>' . h($c['full_name']) . '</td><td>' . h($c['specialty']) .
             '</td><td>' . h($c['members']) . '</td><td><a class="link" href="captains.php?coach=' . (int)$c['coach_id'] . '">عرض الأعضاء ←</a></td></tr>';
    }
    echo '</table></section>'; page_foot(); exit;
}

$coach = $pdo->prepare("SELECT * FROM coaches WHERE coach_id = ?");
$coach->execute([$coachId]);
$coach = $coach->fetch();
if (!$coach) { echo '<div class="err">الكابتن غير موجود.</div>'; page_foot(); exit; }

// ===================== 2) قائمة أعضاء الكابتن =====================
if (!$memberId) {
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $members = $pdo->prepare("SELECT member_id, full_name, status, goal_summary FROM members
                                  WHERE coach_id = ? AND (full_name LIKE ? OR phone LIKE ?) ORDER BY member_id");
        $members->execute([$coachId, "%$q%", "%$q%"]);
    } else {
        $members = $pdo->prepare("SELECT member_id, full_name, status, goal_summary FROM members WHERE coach_id = ? ORDER BY member_id");
        $members->execute([$coachId]);
    }
    $members = $members->fetchAll();
    if (!$isCoach) echo '<div class="crumb"><a class="link" href="captains.php">الكباتن</a> / <strong>' . h($coach['full_name']) . '</strong></div>';
    echo '<section><h2>أعضاء الكابتن ' . h($coach['full_name']) . '</h2>';
    echo '<form method="get" style="display:flex;gap:8px;margin-bottom:14px">'
       . '<input type="hidden" name="coach" value="' . $coachId . '">'
       . '<input name="q" value="' . h($q) . '" placeholder="🔎 بحث بالاسم أو الهاتف…" style="flex:1;max-width:320px;padding:8px 11px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px">'
       . '<button type="submit" style="background:#2563eb;color:#fff;border:0;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer">بحث</button>'
       . ($q !== '' ? '<a class="link" href="captains.php?coach=' . $coachId . '" style="align-self:center;font-size:13px">إلغاء</a>' : '')
       . '</form>';
    if (!$members) echo '<div class="empty">' . ($q !== '' ? 'لا نتائج مطابقة للبحث.' : 'لا يوجد أعضاء مسنَدون.') . '</div>';
    else {
        echo '<table><tr><th>#</th><th>الاسم</th><th>الحالة</th><th>الهدف</th><th></th></tr>';
        foreach ($members as $m) {
            echo '<tr><td>' . h($m['member_id']) . '</td><td>' . h($m['full_name']) . '</td><td><span class="badge">' . h($m['status']) . '</span></td><td class="muted">' . h($m['goal_summary']) .
                 '</td><td><a class="link" href="captains.php?coach=' . $coachId . '&member=' . (int)$m['member_id'] . '">البرامج والتغذية والتقدّم ←</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';

    // ---- مهام الكابتن المفتوحة (تُغلق من هنا) ----
    $ctasks = $pdo->prepare("SELECT t.task_id, t.task_type, t.priority, t.status, t.due_at, t.notes, m.full_name AS member_name
                             FROM tasks t LEFT JOIN members m ON m.member_id = t.member_id
                             WHERE t.coach_id = ? AND t.status IN ('open','doing')
                             ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.due_at");
    $ctasks->execute([$coachId]);
    $ctasks = $ctasks->fetchAll();
    $prB = ['urgent'=>'#dc2626','high'=>'#f59e0b','medium'=>'#2563eb','low'=>'#6b7280'];
    echo '<section><h2>📋 مهام الكابتن المفتوحة</h2>';
    if (!$ctasks) echo '<div class="empty">لا توجد مهام مفتوحة. 🎉</div>';
    else {
        echo '<table><tr><th>#</th><th>العضو</th><th>النوع</th><th>الأولوية</th><th>الاستحقاق</th><th>ملاحظات</th><th></th></tr>';
        foreach ($ctasks as $t) {
            echo '<tr><td>' . h($t['task_id']) . '</td><td>' . h($t['member_name'] ?? '—') . '</td><td>' . h($t['task_type']) .
                 '</td><td><span class="badge" style="background:' . ($prB[$t['priority']] ?? '#6b7280') . '">' . h($t['priority']) . '</span></td>' .
                 '<td class="muted">' . h($t['due_at'] ?? '') . '</td><td class="muted">' . h($t['notes']) . '</td>' .
                 '<td><form method="post" style="margin:0"><input type="hidden" name="action" value="complete_task">' .
                 '<input type="hidden" name="task_id" value="' . (int)$t['task_id'] . '">' .
                 '<button type="submit" style="background:#16a34a;color:#fff;border:0;padding:5px 12px;border-radius:7px;font-size:12px;cursor:pointer">✓ إنهاء</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';
    page_foot(); exit;
}

// ===================== 3) صفحة العضو =====================
$member = $pdo->prepare("SELECT * FROM members WHERE member_id = ? AND coach_id = ?");
$member->execute([$memberId, $coachId]);
$member = $member->fetch();
if (!$member) { echo '<div class="err">العضو غير موجود أو غير مسنَد لهذا الكابتن.</div>'; page_foot(); exit; }

$wplans = $pdo->prepare("SELECT * FROM workout_plans WHERE member_id = ? ORDER BY start_date DESC, workout_plan_id DESC");
$wplans->execute([$memberId]); $wplans = $wplans->fetchAll();
$sessByPlan = [];
if ($wplans) {
    $ids = implode(',', array_map(fn($p) => (int)$p['workout_plan_id'], $wplans));
    foreach ($pdo->query("SELECT * FROM workout_sessions WHERE workout_plan_id IN ($ids) ORDER BY session_date") as $s)
        $sessByPlan[$s['workout_plan_id']][] = $s;
}
$nplans = $pdo->prepare("SELECT * FROM nutrition_plans WHERE member_id = ? ORDER BY nutrition_plan_id DESC");
$nplans->execute([$memberId]); $nplans = $nplans->fetchAll();
$supps = $pdo->prepare("SELECT * FROM supplements WHERE member_id = ? ORDER BY supplement_id DESC");
$supps->execute([$memberId]); $supps = $supps->fetchAll();
$prog = $pdo->prepare("SELECT * FROM progress_tracking WHERE member_id = ? ORDER BY record_date");
$prog->execute([$memberId]); $prog = $prog->fetchAll();
$assessments = $pdo->prepare("SELECT * FROM assessments WHERE member_id = ? ORDER BY assessment_date DESC");
$assessments->execute([$memberId]); $assessments = $assessments->fetchAll();
$fups = $pdo->prepare("SELECT * FROM followups WHERE member_id = ? ORDER BY followup_date DESC");
$fups->execute([$memberId]); $fups = $fups->fetchAll();
$mtasks = $pdo->prepare("SELECT * FROM tasks WHERE member_id = ? AND status IN ('open','doing')
                         ORDER BY FIELD(priority,'urgent','high','medium','low'), due_at");
$mtasks->execute([$memberId]); $mtasks = $mtasks->fetchAll();
$mflags = $pdo->prepare("SELECT * FROM retention_flags WHERE member_id = ? AND status IN ('open','in_progress') ORDER BY detected_at DESC");
$mflags->execute([$memberId]); $mflags = $mflags->fetchAll();
$att = $pdo->prepare("SELECT * FROM daily_attendance WHERE member_id = ? ORDER BY attendance_date DESC LIMIT 15");
$att->execute([$memberId]); $att = $att->fetchAll();
$msgs = $pdo->prepare("SELECT * FROM messages_log WHERE member_id = ? ORDER BY sent_at DESC LIMIT 15");
$msgs->execute([$memberId]); $msgs = $msgs->fetchAll();
$miles = $pdo->prepare("SELECT * FROM milestones WHERE member_id = ? ORDER BY milestone_date DESC");
$miles->execute([$memberId]); $miles = $miles->fetchAll();

$crumb = '<a class="link" href="captains.php?coach=' . $coachId . '">' . h($coach['full_name']) . '</a> / <strong>' . h($member['full_name']) . '</strong>';
echo '<div class="crumb">' . ($isCoach ? '' : '<a class="link" href="captains.php">الكباتن</a> / ') . $crumb . '</div>';
?>

<div class="grid2">
  <!-- ===== برامج التمرين ===== -->
  <section>
    <h2>🏋️ برامج التمرين</h2>
    <?php if (!$wplans): ?><div class="empty">لا توجد برامج بعد.</div><?php endif; ?>
    <?php foreach ($wplans as $p): ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:12px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <strong><?=h($p['goal_type'])?></strong> · <span class="muted"><?=h($p['phase'])?></span>
          <span class="badge" style="background:<?= $p['status']==='active'?'#16a34a':'#6b7280' ?>"><?=h($p['status'])?></span>
          <form method="post" style="margin:0;display:flex;gap:5px;margin-inline-start:auto">
            <input type="hidden" name="action" value="set_wplan_status">
            <input type="hidden" name="workout_plan_id" value="<?=$p['workout_plan_id']?>">
            <?=sel('status',$PSTATUS,$p['status'])?>
            <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">تحديث</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('حذف البرنامج وكل جلساته؟')">
            <input type="hidden" name="action" value="delete_wplan">
            <input type="hidden" name="workout_plan_id" value="<?=$p['workout_plan_id']?>">
            <button type="submit" style="background:#dc2626;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">🗑 حذف</button>
          </form>
        </div>
        <div class="muted" style="font-size:12px;margin:4px 0"><?=h($p['start_date'])?> → <?=h($p['end_date'] ?? '—')?></div>
        <?php $ss = $sessByPlan[$p['workout_plan_id']] ?? []; if ($ss): ?>
          <table style="margin-top:6px"><tr><th>التاريخ</th><th>المجموعة</th><th>تمارين</th><th>الحالة</th><th></th></tr>
            <?php foreach ($ss as $s): ?>
              <tr><td><?=h($s['session_date'])?></td><td><?=h($s['muscle_group'])?></td><td class="muted"><?=h($s['exercises'])?></td>
                <td><form method="post" style="margin:0;display:flex;gap:4px">
                  <input type="hidden" name="action" value="set_session_status">
                  <input type="hidden" name="session_id" value="<?=$s['session_id']?>">
                  <?=sel('completion_status',$CSTATUS,$s['completion_status'])?>
                  <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:3px 8px;border-radius:6px;font-size:11px;cursor:pointer">✓</button>
                </form></td>
                <td><form method="post" style="margin:0" onsubmit="return confirm('حذف الجلسة؟')">
                  <input type="hidden" name="action" value="delete_session">
                  <input type="hidden" name="session_id" value="<?=$s['session_id']?>">
                  <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:13px">🗑</button>
                </form></td></tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <details style="margin-top:6px"><summary class="link" style="cursor:pointer;font-size:13px">➕ إضافة جلسة</summary>
          <form class="frm" method="post">
            <input type="hidden" name="action" value="add_workout_session">
            <input type="hidden" name="workout_plan_id" value="<?=$p['workout_plan_id']?>">
            <div><label>التاريخ</label><input type="date" name="session_date" required></div>
            <div><label>المجموعة العضلية</label><input name="muscle_group"></div>
            <div><label>تمارين</label><input name="exercises"></div>
            <div><label>مجموعات</label><input name="sets_info"></div>
            <div><label>تكرارات</label><input name="reps_info"></div>
            <div><label>الحمل</label><input name="load_info"></div>
            <div><label>الشدة</label><input name="intensity_info"></div>
            <div><label>الحالة</label><?=sel('completion_status',$CSTATUS,'planned')?></div>
            <div><button type="submit">حفظ الجلسة</button></div>
          </form>
        </details>
      </div>
    <?php endforeach; ?>
    <h3>➕ إضافة برنامج تمرين جديد</h3>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_workout_plan">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <input type="hidden" name="coach_id" value="<?=$coachId?>">
      <div><label>الهدف</label><?=sel('goal_type',$GOALS,'general_fitness')?></div>
      <div><label>المرحلة</label><?=sel('phase',$PHASES,'stabilization')?></div>
      <div><label>البداية</label><input type="date" name="start_date" required></div>
      <div><label>النهاية</label><input type="date" name="end_date"></div>
      <div><label>الحالة</label><?=sel('status',$PSTATUS,'active')?></div>
      <div><label>ملاحظات</label><input name="notes"></div>
      <div><button type="submit">إضافة البرنامج</button></div>
    </form>
  </section>

  <!-- ===== التغذية ===== -->
  <section>
    <h2>🥗 خطط التغذية</h2>
    <?php if (!$nplans): ?><div class="empty">لا توجد خطط تغذية بعد.</div><?php endif; ?>
    <?php foreach ($nplans as $n): ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:12px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <strong><?=h($n['calories'] ?? '—')?> سعرة</strong>
          <span class="badge" style="background:<?= $n['status']==='active'?'#16a34a':'#6b7280' ?>"><?=h($n['status'])?></span>
          <form method="post" style="margin:0;display:flex;gap:5px;margin-inline-start:auto">
            <input type="hidden" name="action" value="set_nplan_status">
            <input type="hidden" name="nutrition_plan_id" value="<?=$n['nutrition_plan_id']?>">
            <?=sel('status',$PSTATUS,$n['status'])?>
            <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">تحديث</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('حذف خطة التغذية؟')">
            <input type="hidden" name="action" value="delete_nplan">
            <input type="hidden" name="nutrition_plan_id" value="<?=$n['nutrition_plan_id']?>">
            <button type="submit" style="background:#dc2626;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">🗑 حذف</button>
          </form>
        </div>
        <div class="muted" style="font-size:13px;margin-top:4px">بروتين <?=h($n['protein_g'] ?? '—')?>g · دهون <?=h($n['fat_g'] ?? '—')?>g · كارب <?=h($n['carbs_g'] ?? '—')?>g · ماء <?=h($n['hydration_target_l'] ?? '—')?>ل</div>
        <?php if ($n['meal_timing']): ?><div class="muted" style="font-size:12px;margin-top:4px"><?=h($n['meal_timing'])?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <h3>➕ إضافة خطة تغذية</h3>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_nutrition_plan">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <input type="hidden" name="coach_id" value="<?=$coachId?>">
      <div><label>السعرات</label><input type="number" name="calories"></div>
      <div><label>بروتين (g)</label><input type="number" step="0.1" name="protein_g"></div>
      <div><label>دهون (g)</label><input type="number" step="0.1" name="fat_g"></div>
      <div><label>كارب (g)</label><input type="number" step="0.1" name="carbs_g"></div>
      <div><label>ماء (لتر)</label><input type="number" step="0.1" name="hydration_target_l"></div>
      <div><label>توقيت الوجبات</label><input name="meal_timing"></div>
      <div><label>الحالة</label><?=sel('status',$PSTATUS,'active')?></div>
      <div><button type="submit">إضافة الخطة</button></div>
    </form>
    <h3>💊 المكمّلات</h3>
    <?php if (!$supps): ?><div class="empty">لا توجد مكمّلات.</div><?php else: ?>
      <table><tr><th>الاسم</th><th>الجرعة</th><th>التوقيت</th><th>الغرض</th><th>الحالة</th><th></th></tr>
      <?php foreach ($supps as $s): ?>
        <tr style="<?= $s['active'] ? '' : 'opacity:.5' ?>">
          <td><?=h($s['supplement_name'])?></td><td><?=h($s['dose'])?></td><td><?=h($s['timing'])?></td><td class="muted"><?=h($s['purpose'])?></td>
          <td><form method="post" style="margin:0">
            <input type="hidden" name="action" value="toggle_supplement">
            <input type="hidden" name="supplement_id" value="<?=$s['supplement_id']?>">
            <button type="submit" style="background:<?= $s['active'] ? '#f59e0b' : '#16a34a' ?>;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><?= $s['active'] ? '⏸ إيقاف' : '▶ تفعيل' ?></button>
          </form></td>
          <td><form method="post" style="margin:0" onsubmit="return confirm('حذف المكمّل؟')">
            <input type="hidden" name="action" value="delete_supplement">
            <input type="hidden" name="supplement_id" value="<?=$s['supplement_id']?>">
            <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:13px">🗑</button>
          </form></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_supplement">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <div><label>اسم المكمّل</label><input name="supplement_name" required></div>
      <div><label>الجرعة</label><input name="dose"></div>
      <div><label>التوقيت</label><input name="timing"></div>
      <div><label>الغرض</label><input name="purpose"></div>
      <div><button type="submit">إضافة مكمّل</button></div>
    </form>
  </section>
</div>

<div class="grid2">
  <!-- ===== التقييمات ===== -->
  <section>
    <h2>🧪 التقييمات (تشغّل أتمتة الخطر)</h2>
    <?php if (!$assessments): ?><div class="empty">لا توجد تقييمات بعد.</div><?php else: ?>
      <table><tr><th>التاريخ</th><th>خطر</th><th>التصنيف</th><th>توصية</th><th>مراجعة قادمة</th></tr>
      <?php foreach ($assessments as $a): ?>
        <tr><td><?=h(substr($a['assessment_date'],0,10))?></td>
          <td><strong style="color:<?= $a['risk_score']>=80?'#dc2626':($a['risk_score']>=60?'#f59e0b':'#16a34a') ?>"><?=h($a['risk_score'])?></strong></td>
          <td><?=h($a['classification'])?></td><td class="muted"><?=h($a['recommendation'])?></td><td class="muted"><?=h($a['next_review_date'])?></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h3>➕ تسجيل تقييم جديد</h3>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_assessment">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <input type="hidden" name="coach_id" value="<?=$coachId?>">
      <div><label>التاريخ</label><input type="date" name="assessment_date" required></div>
      <div><label>PAR-Q (عوامل خطر)</label><input type="number" name="parq_risk_count" min="0" value="0"></div>
      <div><label>Overhead Squat</label><input type="number" step="0.1" name="overhead_squat_score"></div>
      <div><label>القوام</label><input type="number" step="0.1" name="posture_score"></div>
      <div><label>الحركة</label><input type="number" step="0.1" name="movement_score"></div>
      <div><label>درجة الخطر (0-100)</label><input type="number" step="0.1" name="risk_score" required></div>
      <div><label>التصنيف</label><?=sel('classification',$CLASSIF,'moderate')?></div>
      <div><label>التوصية</label><input name="recommendation"></div>
      <div><label>مراجعة قادمة</label><input type="date" name="next_review_date"></div>
      <div><button type="submit">حفظ التقييم</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin:10px 0 0">درجة ≥ 60: يتحوّل العضو لـ at_risk مع إنذار ومهمة تلقائيًا · ≥ 80: corrective مع إنذار حرج ومهمة عاجلة.</p>
  </section>

  <!-- ===== المتابعات ===== -->
  <section>
    <h2>☎️ المتابعات</h2>
    <?php if (!$fups): ?><div class="empty">لا توجد متابعات بعد.</div><?php else: ?>
      <table><tr><th>التاريخ</th><th>السبب</th><th>القناة</th><th>الرد</th><th>الإجراء</th></tr>
      <?php foreach ($fups as $f): ?>
        <tr><td><?=h(substr($f['followup_date'],0,10))?></td><td><?=h($f['reason'])?></td><td><?=h($f['contact_channel'])?></td>
          <td><span class="badge" style="background:<?= in_array($f['response_status'],['booked','converted'])?'#16a34a':($f['response_status']==='no_response'?'#dc2626':'#6b7280') ?>"><?=h($f['response_status'])?></span></td>
          <td class="muted"><?=h($f['action_taken'])?></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h3>➕ تسجيل متابعة</h3>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_followup">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <input type="hidden" name="coach_id" value="<?=$coachId?>">
      <div><label>التاريخ</label><input type="date" name="followup_date" required></div>
      <div><label>السبب</label><?=sel('reason',$FREASONS,'other')?></div>
      <div><label>القناة</label><?=sel('contact_channel',$FCHANNELS,'whatsapp')?></div>
      <div><label>الرد</label><?=sel('response_status',$FRESP,'no_response')?></div>
      <div><label>الإجراء المتّخذ</label><input name="action_taken"></div>
      <div><label>متابعة قادمة</label><input type="date" name="next_followup_date"></div>
      <div><button type="submit">حفظ المتابعة</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin:10px 0 0">no_response: تُفتح مهمة اتصال تلقائيًا · booked/converted: تُحلّ الإنذارات المفتوحة تلقائيًا.</p>
  </section>
</div>

<!-- ===== مهام وإنذارات العضو ===== -->
<section>
  <h2>🗂️ المهام والإنذارات المفتوحة لهذا العضو</h2>
  <div class="grid2">
    <div>
      <h3 style="margin-top:0">المهام</h3>
      <?php if (!$mtasks): ?><div class="empty">لا توجد مهام مفتوحة.</div><?php else: ?>
        <table><tr><th>النوع</th><th>الأولوية</th><th>الاستحقاق</th><th></th></tr>
        <?php $prB2 = ['urgent'=>'#dc2626','high'=>'#f59e0b','medium'=>'#2563eb','low'=>'#6b7280'];
        foreach ($mtasks as $t): ?>
          <tr><td><?=h($t['task_type'])?></td>
            <td><span class="badge" style="background:<?=$prB2[$t['priority']] ?? '#6b7280'?>"><?=h($t['priority'])?></span></td>
            <td class="muted"><?=h($t['due_at'])?></td>
            <td><form method="post" style="margin:0"><input type="hidden" name="action" value="complete_task">
              <input type="hidden" name="task_id" value="<?=(int)$t['task_id']?>">
              <button type="submit" style="background:#16a34a;color:#fff;border:0;padding:5px 12px;border-radius:7px;font-size:12px;cursor:pointer">✓ إنهاء</button></form></td></tr>
        <?php endforeach; ?></table>
      <?php endif; ?>
    </div>
    <div>
      <h3 style="margin-top:0">الإنذارات</h3>
      <?php if (!$mflags): ?><div class="empty">لا توجد إنذارات مفتوحة.</div><?php else: ?>
        <table><tr><th>النوع</th><th>الشدة</th><th>الحالة</th><th>الإجراء المطلوب</th></tr>
        <?php foreach ($mflags as $fl): ?>
          <tr><td><?=h($fl['flag_type'])?></td>
            <td><span class="badge" style="background:<?= in_array($fl['severity'],['critical','high'])?'#dc2626':'#f59e0b' ?>"><?=h($fl['severity'])?></span></td>
            <td><?=h($fl['status'])?></td><td class="muted"><?=h($fl['action_required'])?></td></tr>
        <?php endforeach; ?></table>
        <p class="muted" style="font-size:12px">الإنذارات تُحلّ تلقائيًا عند تسجيل متابعة بردّ booked/converted أو عند حضور العضو.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ===== متابعة التقدّم ===== -->
<section>
  <h2>📈 متابعة التقدّم</h2>
  <?php
    $wSeries = ['label'=>'الوزن (kg)','color'=>'#2563eb','points'=>array_map(fn($r)=>[$r['record_date'],$r['weight']],$prog)];
    $mSeries = ['label'=>'الكتلة العضلية (kg)','color'=>'#16a34a','points'=>array_map(fn($r)=>[$r['record_date'],$r['muscle_mass']],$prog)];
    $fSeries = ['label'=>'نسبة الدهون (%)','color'=>'#f59e0b','points'=>array_map(fn($r)=>[$r['record_date'],$r['body_fat']],$prog)];
  ?>
  <div class="grid2">
    <div><strong style="font-size:13px;color:#334155">الوزن والكتلة العضلية</strong><?=line_chart([$wSeries,$mSeries])?></div>
    <div><strong style="font-size:13px;color:#334155">نسبة الدهون</strong><?=line_chart([$fSeries])?></div>
  </div>

  <?php if ($prog): ?>
  <h3>السجلّ</h3>
  <table>
    <tr><th>التاريخ</th><th>الوزن</th><th>دهون %</th><th>كتلة عضلية</th><th>خصر</th><th>صدر</th><th>أرداف</th><th>ملاحظة</th><th></th></tr>
    <?php foreach (array_reverse($prog) as $r): ?>
      <tr><td><?=h($r['record_date'])?></td><td><?=h($r['weight'])?></td><td><?=h($r['body_fat'])?></td><td><?=h($r['muscle_mass'])?></td>
        <td><?=h($r['waist'])?></td><td><?=h($r['chest'])?></td><td><?=h($r['hips'])?></td><td class="muted"><?=h($r['performance_note'])?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('حذف هذا القياس؟')">
          <input type="hidden" name="action" value="delete_progress">
          <input type="hidden" name="progress_id" value="<?=$r['progress_id']?>">
          <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:13px">🗑</button>
        </form></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h3>➕ تسجيل قياس جديد</h3>
  <form class="frm" method="post">
    <input type="hidden" name="action" value="add_progress">
    <input type="hidden" name="member_id" value="<?=$memberId?>">
    <div><label>التاريخ</label><input type="date" name="record_date" required></div>
    <div><label>الوزن (kg)</label><input type="number" step="0.1" name="weight"></div>
    <div><label>نسبة الدهون (%)</label><input type="number" step="0.1" name="body_fat"></div>
    <div><label>الكتلة العضلية (kg)</label><input type="number" step="0.1" name="muscle_mass"></div>
    <div><label>الخصر (cm)</label><input type="number" step="0.1" name="waist"></div>
    <div><label>الصدر (cm)</label><input type="number" step="0.1" name="chest"></div>
    <div><label>الأرداف (cm)</label><input type="number" step="0.1" name="hips"></div>
    <div><label>ملاحظة</label><input name="performance_note"></div>
    <div><button type="submit">حفظ القياس</button></div>
  </form>
</section>

<!-- ===== الحضور اليومي ===== -->
<section>
  <h2>📅 الحضور اليومي (يشغّل أتمتة المتابعة)</h2>
  <?php if (!$att): ?><div class="empty">لا توجد سجلّات حضور بعد.</div><?php else: ?>
    <table><tr><th>التاريخ</th><th>الحالة</th><th>دخول</th><th>خروج</th><th>النوع</th><th>ملاحظات</th></tr>
    <?php foreach ($att as $a2): ?>
      <tr><td><?=h($a2['attendance_date'])?></td>
        <td><span class="badge" style="background:<?= $a2['attended'] ? '#16a34a' : '#dc2626' ?>"><?= $a2['attended'] ? 'حضر' : 'غاب' ?></span></td>
        <td class="muted"><?=h($a2['check_in_time'])?></td><td class="muted"><?=h($a2['check_out_time'])?></td>
        <td class="muted"><?=h($a2['session_type'])?></td><td class="muted"><?=h($a2['notes'])?></td></tr>
    <?php endforeach; ?></table>
  <?php endif; ?>
  <h3>➕ تسجيل حضور / غياب</h3>
  <form class="frm" method="post">
    <input type="hidden" name="action" value="add_attendance">
    <input type="hidden" name="member_id" value="<?=$memberId?>">
    <input type="hidden" name="coach_id" value="<?=$coachId?>">
    <div><label>التاريخ</label><input type="date" name="attendance_date" value="<?=date('Y-m-d')?>" required></div>
    <div><label>الحالة</label><select name="attended"><option value="1">حضر</option><option value="0">غاب</option></select></div>
    <div><label>وقت الدخول</label><input type="time" name="check_in_time"></div>
    <div><label>وقت الخروج</label><input type="time" name="check_out_time"></div>
    <div><label>نوع الجلسة</label><input name="session_type" placeholder="training"></div>
    <div><label>ملاحظات</label><input name="notes"></div>
    <div><button type="submit">تسجيل</button></div>
  </form>
  <p class="muted" style="font-size:12px;margin:10px 0 0">حضور: onboarding→active و at_risk→reactivated مع حلّ إنذارات الحضور تلقائيًا · غياب: بعد 3 غيابات في 7 أيام يُفتح إنذار ومهمة اتصال تلقائيًا.</p>
</section>

<div class="grid2">
  <!-- ===== الرسائل ===== -->
  <section>
    <h2>💬 سجلّ الرسائل</h2>
    <?php if (!$msgs): ?><div class="empty">لا توجد رسائل بعد.</div><?php else: ?>
      <table><tr><th>التاريخ</th><th>القناة</th><th>النوع</th><th>المحتوى</th><th>الحالة</th></tr>
      <?php foreach ($msgs as $mg): ?>
        <tr><td class="muted"><?=h(substr($mg['sent_at'],0,10))?></td><td><?=h($mg['channel'])?></td><td><?=h($mg['message_type'])?></td>
          <td class="muted"><?=h(mb_strimwidth($mg['content'],0,60,'…'))?></td>
          <td><span class="badge" style="background:<?= $mg['status']==='replied'?'#16a34a':($mg['status']==='failed'?'#dc2626':'#6b7280') ?>"><?=h($mg['status'])?></span></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h3>➕ تسجيل رسالة</h3>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_message">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <input type="hidden" name="coach_id" value="<?=$coachId?>">
      <div><label>القناة</label><?=sel('channel',$MCHANNELS,'whatsapp')?></div>
      <div><label>النوع</label><?=sel('message_type',$MTYPES,'followup')?></div>
      <div><label>الحالة</label><?=sel('status',$MSTATUS,'sent')?></div>
      <div style="grid-column:1/-1"><label>المحتوى</label><textarea name="content" rows="2" required></textarea></div>
      <div><button type="submit">حفظ الرسالة</button></div>
    </form>
  </section>

  <!-- ===== الإنجازات ===== -->
  <section>
    <h2>🏆 الإنجازات</h2>
    <?php if (!$miles): ?><div class="empty">لا توجد إنجازات بعد.</div><?php else: ?>
      <table><tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المكافأة</th></tr>
      <?php foreach ($miles as $ml): ?>
        <tr><td><?=h($ml['milestone_date'])?></td><td><?=h($ml['milestone_type'])?></td><td class="muted"><?=h($ml['description'])?></td>
          <td><span class="badge" style="background:<?= $ml['reward_status']==='none'?'#6b7280':'#7c3aed' ?>"><?=h($ml['reward_status'])?></span></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <h3>➕ تسجيل إنجاز</h3>
    <form class="frm" method="post">
      <input type="hidden" name="action" value="add_milestone">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <div><label>التاريخ</label><input type="date" name="milestone_date" value="<?=date('Y-m-d')?>" required></div>
      <div><label>النوع</label><?=sel('milestone_type',$MILTYPES,'attendance_streak')?></div>
      <div><label>المكافأة</label><?=sel('reward_status',$REWARDS,'none')?></div>
      <div style="grid-column:1/-1"><label>الوصف</label><input name="description" required></div>
      <div><button type="submit">حفظ الإنجاز</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin:10px 0 0">إنجازات فقدان الوزن تُسجَّل تلقائيًا أيضًا عند تسجيل قياس بانخفاض أكبر من 2كجم.</p>
  </section>
</div>

<?php page_foot();

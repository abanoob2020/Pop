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
$ISEVER    = ['low','medium','high','critical'];
$ISTAT     = ['active','recovering','resolved','unknown'];
$MSTATUSES = ['new','onboarding','active','corrective','at_risk','paused','expired','reactivated','upgraded'];
$GENDERS   = ['male','female','other'];

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
        csrf_check();
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
        } elseif ($act === 'set_injury_status') {
            $targetMember = (int)$pdo->query("SELECT member_id FROM injury_history WHERE injury_id = " . (int)$_POST['injury_id'])->fetchColumn();
        } elseif ($act === 'add_session_exercise') {
            $targetMember = (int)$pdo->query("SELECT wp.member_id FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id WHERE ws.session_id = " . (int)$_POST['session_id'])->fetchColumn();
        } elseif ($act === 'delete_session_exercise') {
            $targetMember = (int)$pdo->query("SELECT wp.member_id FROM session_exercises se JOIN workout_sessions ws ON ws.session_id = se.session_id JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id WHERE se.session_exercise_id = " . (int)$_POST['session_exercise_id'])->fetchColumn();
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
            // صورة اختيارية (jpg/png/webp حتى 5MB) تُحفظ في uploads/progress
            $photoRef = null;
            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                if (($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('حجم الصورة يتجاوز 5MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extMap[$mime])) throw new RuntimeException('صيغة الصورة غير مدعومة (jpg/png/webp فقط).');
                $dir = __DIR__ . '/uploads/progress';
                if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('تعذّر إنشاء مجلد الصور.');
                $fname = 'm' . $targetMember . '_' . preg_replace('/[^0-9-]/', '', $_POST['record_date']) . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
                if (!move_uploaded_file($_FILES['photo']['tmp_name'], "$dir/$fname")) throw new RuntimeException('تعذّر حفظ الصورة.');
                $photoRef = 'uploads/progress/' . $fname;
            }
            $pdo->prepare("INSERT INTO progress_tracking (member_id, record_date, weight, body_fat, muscle_mass, waist, chest, hips, performance_note, photo_ref)
                           VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
                $targetMember, $_POST['record_date'],
                $_POST['weight'] !== '' ? $_POST['weight'] : null,
                $_POST['body_fat'] !== '' ? $_POST['body_fat'] : null,
                $_POST['muscle_mass'] !== '' ? $_POST['muscle_mass'] : null,
                $_POST['waist'] !== '' ? $_POST['waist'] : null,
                $_POST['chest'] !== '' ? $_POST['chest'] : null,
                $_POST['hips'] !== '' ? $_POST['hips'] : null,
                trim($_POST['performance_note'] ?? '') ?: null,
                $photoRef,
            ]);
        } elseif ($act === 'update_member') {
            $name = trim($_POST['full_name'] ?? '');
            if ($name === '') throw new RuntimeException('اسم العضو مطلوب.');
            $pdo->prepare("UPDATE members SET full_name=?, gender=?, birth_date=?, phone=?, email=?, address=?, job_title=?, preferred_time=?, goal_summary=?, status=?, notes=? WHERE member_id=?")
                ->execute([
                    $name,
                    $_POST['gender'] !== '' ? $_POST['gender'] : null,
                    $_POST['birth_date'] ?: null,
                    trim($_POST['phone'] ?? '') ?: null,
                    trim($_POST['email'] ?? '') ?: null,
                    trim($_POST['address'] ?? '') ?: null,
                    trim($_POST['job_title'] ?? '') ?: null,
                    trim($_POST['preferred_time'] ?? '') ?: null,
                    trim($_POST['goal_summary'] ?? '') ?: null,
                    $_POST['status'],
                    trim($_POST['notes'] ?? '') ?: null,
                    $targetMember,
                ]);
        } elseif ($act === 'add_injury') {
            // إدخال الإصابة يشغّل sp_handle_injury_event عبر الـ trigger
            // (high/critical: إيقاف برامج العضو + العضو paused + إنذار injury + مهمة إحالة طبية عاجلة)
            $pdo->prepare("INSERT INTO injury_history (member_id, injury_date, body_area, injury_type, severity, current_status, doctor_clearance, notes)
                           VALUES (?,?,?,?,?,?,?,?)")->execute([
                $targetMember, $_POST['injury_date'], trim($_POST['body_area']),
                trim($_POST['injury_type'] ?? '') ?: null, $_POST['severity'], $_POST['current_status'],
                isset($_POST['doctor_clearance']) ? 1 : 0, trim($_POST['notes'] ?? '') ?: null,
            ]);
        } elseif ($act === 'set_injury_status') {
            $pdo->prepare("UPDATE injury_history SET current_status = ?, doctor_clearance = ? WHERE injury_id = ?")
                ->execute([$_POST['current_status'], isset($_POST['doctor_clearance']) ? 1 : 0, (int)$_POST['injury_id']]);
        } elseif ($act === 'add_session_exercise') {
            $exId = (int)$_POST['exercise_id'];
            $load = $_POST['load_kg'] !== '' ? (float)$_POST['load_kg'] : null;
            // أقصى حمل سابق لهذا العضو/التمرين — لاكتشاف الرقم القياسي (PR)
            $prev = null;
            if ($load !== null) {
                $pm = $pdo->prepare("SELECT MAX(se.load_kg) FROM session_exercises se
                                     JOIN workout_sessions ws ON ws.session_id = se.session_id
                                     JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                                     WHERE wp.member_id = ? AND se.exercise_id = ?");
                $pm->execute([$targetMember, $exId]);
                $prev = $pm->fetchColumn();
                $prev = ($prev !== null && $prev !== false) ? (float)$prev : null;
            }
            $pdo->prepare("INSERT INTO session_exercises (session_id, exercise_id, sort_order, sets, reps, load_kg, rest_sec, rpe, notes)
                           VALUES (?,?,?,?,?,?,?,?,?)")->execute([
                (int)$_POST['session_id'], $exId, max(1, (int)($_POST['sort_order'] ?: 1)),
                $_POST['sets'] !== '' ? (int)$_POST['sets'] : null,
                trim($_POST['reps'] ?? '') ?: null, $load,
                $_POST['rest_sec'] !== '' ? (int)$_POST['rest_sec'] : null,
                $_POST['rpe'] !== '' ? (int)$_POST['rpe'] : null,
                trim($_POST['notes'] ?? '') ?: null,
            ]);
            if ($load !== null && $prev !== null && $load > $prev) {
                // 🏆 رقم قياسي جديد → إنجاز تلقائي
                $exName = $pdo->query("SELECT name FROM exercises WHERE exercise_id = $exId")->fetchColumn();
                $pdo->prepare("INSERT INTO milestones (member_id, milestone_date, milestone_type, description, reward_status)
                               VALUES (?, CURDATE(), 'strength_gain', ?, 'badge')")
                    ->execute([$targetMember, "🏆 رقم قياسي جديد في {$exName}: {$load} كجم (السابق: {$prev})"]);
            }
        } elseif ($act === 'delete_session_exercise') {
            $pdo->prepare("DELETE FROM session_exercises WHERE session_exercise_id = ?")->execute([(int)$_POST['session_exercise_id']]);
        } elseif ($act === 'assign_template') {
            $tpl = $pdo->prepare("SELECT * FROM program_templates WHERE template_id = ? AND active = 1");
            $tpl->execute([(int)$_POST['template_id']]);
            $tpl = $tpl->fetch();
            if (!$tpl) throw new RuntimeException('القالب غير موجود أو موقوف.');
            $start = $_POST['start_date'];
            $end = date('Y-m-d', strtotime($start) + ($tpl['duration_weeks'] * 7 - 1) * 86400);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO workout_plans (member_id, coach_id, goal_type, phase, start_date, end_date, status, notes)
                           VALUES (?,?,?,?,?,?,'active',?)")->execute([
                $targetMember, $coachId ?: null, $tpl['goal_type'], $tpl['phase'], $start, $end,
                'من قالب: ' . $tpl['title'],
            ]);
            $planId = (int)$pdo->lastInsertId();
            $tss = $pdo->prepare("SELECT * FROM template_sessions WHERE template_id = ? ORDER BY day_offset");
            $tss->execute([$tpl['template_id']]);
            $tss = $tss->fetchAll();
            $insS = $pdo->prepare("INSERT INTO workout_sessions (workout_plan_id, session_date, muscle_group, completion_status, notes)
                                   VALUES (?,?,?,'planned',?)");
            $insX = $pdo->prepare("INSERT INTO session_exercises (session_id, exercise_id, sort_order, sets, reps, rest_sec, notes)
                                   VALUES (?,?,?,?,?,?,?)");
            foreach ($tss as $ts) {
                $tse = $pdo->prepare("SELECT * FROM template_session_exercises WHERE template_session_id = ? ORDER BY sort_order");
                $tse->execute([$ts['template_session_id']]);
                $tse = $tse->fetchAll();
                for ($w = 0; $w < (int)$tpl['duration_weeks']; $w++) {
                    $date = date('Y-m-d', strtotime($start) + ($w * 7 + (int)$ts['day_offset']) * 86400);
                    $insS->execute([$planId, $date, $ts['muscle_group'], $ts['title']]);
                    $sid = (int)$pdo->lastInsertId();
                    foreach ($tse as $x) {
                        $insX->execute([$sid, $x['exercise_id'], $x['sort_order'], $x['sets'], $x['reps'], $x['rest_sec'], $x['load_note']]);
                    }
                }
            }
            $pdo->commit();
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
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $error = str_contains($e->getMessage(), 'Duplicate entry')
            ? 'قيمة مكررة: الهاتف أو البريد مستخدم لعضو آخر بالفعل.'
            : 'تعذّر الحفظ: ' . $e->getMessage();
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
    $sql = "SELECT m.member_id, m.full_name, m.status, m.goal_summary,
                   (SELECT MAX(a.attendance_date) FROM daily_attendance a WHERE a.member_id = m.member_id AND a.attended = 1) AS last_att,
                   (SELECT a2.risk_score FROM assessments a2 WHERE a2.member_id = m.member_id ORDER BY a2.assessment_date DESC LIMIT 1) AS risk,
                   (SELECT SUM(ws.completion_status = 'completed') FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                     WHERE wp.member_id = m.member_id AND ws.session_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()) AS comp_done,
                   (SELECT COUNT(*) FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                     WHERE wp.member_id = m.member_id AND ws.session_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()) AS comp_total
            FROM members m WHERE m.coach_id = ?";
    $args = [$coachId];
    if ($q !== '') { $sql .= " AND (m.full_name LIKE ? OR m.phone LIKE ?)"; $args[] = "%$q%"; $args[] = "%$q%"; }
    $members = $pdo->prepare($sql . " ORDER BY m.member_id");
    $members->execute($args);
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
        echo '<table><tr><th>#</th><th>الاسم</th><th>الحالة</th><th>درجة الخطر</th><th>الالتزام (30ي)</th><th>آخر حضور</th><th>الهدف</th><th></th></tr>';
        foreach ($members as $m) {
            $riskColor = $m['risk'] === null ? '#94a3b8' : ($m['risk'] >= 80 ? '#dc2626' : ($m['risk'] >= 60 ? '#f59e0b' : '#16a34a'));
            if ((int)$m['comp_total'] > 0) {
                $pct = round(100 * (int)$m['comp_done'] / (int)$m['comp_total']);
                $cColor = $pct >= 75 ? '#16a34a' : ($pct >= 50 ? '#f59e0b' : '#dc2626');
                $compCell = '<strong style="color:' . $cColor . '">' . $pct . '%</strong> <span class="muted" style="font-size:11px">(' . (int)$m['comp_done'] . '/' . (int)$m['comp_total'] . ')</span>';
            } else {
                $compCell = '<span class="muted">—</span>';
            }
            echo '<tr><td>' . h($m['member_id']) . '</td><td>' . h($m['full_name']) . '</td><td><span class="badge">' . h($m['status']) . '</span></td>' .
                 '<td><strong style="color:' . $riskColor . '">' . h($m['risk'] ?? '—') . '</strong></td>' .
                 '<td>' . $compCell . '</td>' .
                 '<td class="muted">' . h($m['last_att'] ?? '—') . '</td><td class="muted">' . h($m['goal_summary']) .
                 '</td><td><a class="link" href="captains.php?coach=' . $coachId . '&member=' . (int)$m['member_id'] . '">فتح الملف ←</a></td></tr>';
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
                 '<td><form method="post" style="margin:0">' . csrf_field() . '<input type="hidden" name="action" value="complete_task">' .
                 '<input type="hidden" name="task_id" value="' . (int)$t['task_id'] . '">' .
                 '<button type="submit" style="background:#16a34a;color:#fff;border:0;padding:5px 12px;border-radius:7px;font-size:12px;cursor:pointer">✓ إنهاء</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';

    // ---- 📊 تقرير الكابتن ----
    $rep = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN m.status IN ('active','onboarding','reactivated','upgraded') THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN m.status IN ('at_risk','corrective') THEN 1 ELSE 0 END) AS risk_cnt,
        (SELECT COUNT(*) FROM retention_flags rf JOIN members m2 ON m2.member_id = rf.member_id
          WHERE m2.coach_id = c.cid AND rf.status IN ('open','in_progress')) AS open_flags,
        (SELECT COUNT(*) FROM daily_attendance a JOIN members m3 ON m3.member_id = a.member_id
          WHERE m3.coach_id = c.cid AND a.attended = 1 AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS visits_7d,
        (SELECT COUNT(*) FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
          WHERE wp.coach_id = c.cid AND ws.completion_status = 'completed'
            AND ws.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS done_sessions_7d
        FROM (SELECT ? AS cid) c LEFT JOIN members m ON m.coach_id = c.cid GROUP BY c.cid");
    $rep->execute([$coachId]);
    $rep = $rep->fetch() ?: [];
    $reviews = $pdo->prepare("SELECT m.member_id, m.full_name, MAX(a.next_review_date) AS review_date
                              FROM assessments a JOIN members m ON m.member_id = a.member_id
                              WHERE m.coach_id = ? AND a.next_review_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                              GROUP BY m.member_id, m.full_name ORDER BY review_date");
    $reviews->execute([$coachId]);
    $reviews = $reviews->fetchAll();
    $comp = $pdo->prepare("SELECT SUM(ws.completion_status = 'completed') AS d, COUNT(*) AS t
                           FROM workout_sessions ws
                           JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                           JOIN members m ON m.member_id = wp.member_id
                           WHERE m.coach_id = ? AND ws.session_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()");
    $comp->execute([$coachId]);
    $comp = $comp->fetch();
    $compPct = ((int)($comp['t'] ?? 0)) > 0 ? round(100 * (int)$comp['d'] / (int)$comp['t']) . '%' : '—';
    $repCards = [
        ['أعضاء مسنَدون', $rep['total'] ?? 0, '#2563eb'],
        ['نشطون', $rep['active_cnt'] ?? 0, '#16a34a'],
        ['معرّضون للخطر', $rep['risk_cnt'] ?? 0, '#f59e0b'],
        ['مهام مفتوحة', count($ctasks), '#7c3aed'],
        ['إنذارات مفتوحة', $rep['open_flags'] ?? 0, '#db2777'],
        ['زيارات آخر 7 أيام', $rep['visits_7d'] ?? 0, '#0891b2'],
        ['جلسات مكتملة (7 أيام)', $rep['done_sessions_7d'] ?? 0, '#16a34a'],
        ['الالتزام (30 يوم)', $compPct, '#0891b2'],
    ];
    echo '<section><h2>📊 تقرير الكابتن</h2><div class="kpis" style="margin-bottom:8px">';
    foreach ($repCards as [$l, $n, $clr]) {
        echo '<div class="card" style="--c:' . $clr . '"><div class="n" style="color:' . $clr . '">' . h($n) . '</div><div class="l">' . h($l) . '</div></div>';
    }
    echo '</div>';
    echo '<h3>📆 مراجعات تقييم قادمة (14 يومًا)</h3>';
    if (!$reviews) echo '<div class="empty">لا توجد مراجعات مستحقة قريبًا.</div>';
    else {
        echo '<table><tr><th>العضو</th><th>موعد المراجعة</th><th></th></tr>';
        foreach ($reviews as $rv) {
            echo '<tr><td>' . h($rv['full_name']) . '</td><td>' . h($rv['review_date']) . '</td>' .
                 '<td><a class="link" href="captains.php?coach=' . $coachId . '&member=' . (int)$rv['member_id'] . '">فتح الملف ←</a></td></tr>';
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
$injuries = $pdo->prepare("SELECT * FROM injury_history WHERE member_id = ? ORDER BY injury_date DESC");
$injuries->execute([$memberId]); $injuries = $injuries->fetchAll();
// التمارين المُهيكلة لكل جلسات العضو
$sessEx = [];
$sx = $pdo->prepare("SELECT se.*, e.name AS ex_name FROM session_exercises se
                     JOIN exercises e ON e.exercise_id = se.exercise_id
                     JOIN workout_sessions ws ON ws.session_id = se.session_id
                     JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                     WHERE wp.member_id = ? ORDER BY se.sort_order");
$sx->execute([$memberId]);
foreach ($sx as $r) $sessEx[$r['session_id']][] = $r;
$exList = $pdo->query("SELECT exercise_id, name FROM exercises WHERE active = 1 ORDER BY name")->fetchAll();
// تقدّم الأحمال: أقصى حمل لكل تمرين في كل تاريخ جلسة
$loadSeries = [];
$lp = $pdo->prepare("SELECT e.name, ws.session_date, MAX(se.load_kg) AS mx
                     FROM session_exercises se
                     JOIN exercises e ON e.exercise_id = se.exercise_id
                     JOIN workout_sessions ws ON ws.session_id = se.session_id
                     JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                     WHERE wp.member_id = ? AND se.load_kg IS NOT NULL
                     GROUP BY e.exercise_id, e.name, ws.session_date ORDER BY ws.session_date");
$lp->execute([$memberId]);
foreach ($lp as $r) $loadSeries[$r['name']][] = [$r['session_date'], $r['mx']];
$loadSeries = array_filter($loadSeries, fn($pts) => count($pts) >= 2);
$loadSeries = array_slice($loadSeries, 0, 4, true);
// الحجم التدريبي التقريبي (طن) لكل مجموعة عضلية: آخر 7 أيام مقابل الأسبوع السابق
$tonnage = $pdo->prepare("SELECT e.muscle_group,
        ROUND(SUM(CASE WHEN ws.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              THEN COALESCE(se.sets,0)*CAST(se.reps AS UNSIGNED)*COALESCE(se.load_kg,0) ELSE 0 END)/1000, 2) AS t_now,
        ROUND(SUM(CASE WHEN ws.session_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              THEN COALESCE(se.sets,0)*CAST(se.reps AS UNSIGNED)*COALESCE(se.load_kg,0) ELSE 0 END)/1000, 2) AS t_prev
    FROM session_exercises se
    JOIN exercises e ON e.exercise_id = se.exercise_id
    JOIN workout_sessions ws ON ws.session_id = se.session_id
    JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
    WHERE wp.member_id = ? AND ws.session_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY e.muscle_group HAVING t_now > 0 OR t_prev > 0 ORDER BY t_now DESC");
$tonnage->execute([$memberId]);
$tonnage = $tonnage->fetchAll();
// ذكاء الأحمال: كل الأحمال المسجّلة لكل تمرين (الأحدث أولًا) لحساب الاتجاه/1RM/الثبات/المقترح
$liRows = $pdo->prepare("SELECT e.exercise_id, e.name, se.load_kg, se.reps, se.rpe, ws.session_date
                         FROM session_exercises se
                         JOIN exercises e ON e.exercise_id = se.exercise_id
                         JOIN workout_sessions ws ON ws.session_id = se.session_id
                         JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                         WHERE wp.member_id = ? AND se.load_kg IS NOT NULL
                         ORDER BY e.name, ws.session_date DESC, se.session_exercise_id DESC");
$liRows->execute([$memberId]);
$loadIntel = [];    // exercise_id => ['name','rows'=>[...]]
foreach ($liRows as $r) {
    $eid = (int)$r['exercise_id'];
    if (!isset($loadIntel[$eid])) $loadIntel[$eid] = ['name' => $r['name'], 'rows' => []];
    $loadIntel[$eid]['rows'][] = $r;
}
// إنذار تخفيف حمل (deload): متوسط آخر ~5 قيم RPE ≥ 9
$rpeAvg = $pdo->prepare("SELECT AVG(rpe) FROM (
                            SELECT se.rpe FROM session_exercises se
                            JOIN workout_sessions ws ON ws.session_id = se.session_id
                            JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                            WHERE wp.member_id = ? AND se.rpe IS NOT NULL
                            ORDER BY ws.session_date DESC, se.session_exercise_id DESC LIMIT 5) t");
$rpeAvg->execute([$memberId]);
$rpeAvg = $rpeAvg->fetchColumn();
$deload = $rpeAvg !== null && $rpeAvg !== false && (float)$rpeAvg >= 9;
// قوالب نشطة + اقتراح حسب آخر تقييم
$tplsActive = $pdo->query("SELECT template_id, title, goal_type, phase, duration_weeks FROM program_templates WHERE active = 1 ORDER BY template_id")->fetchAll();
$lastRisk = $pdo->prepare("SELECT risk_score FROM assessments WHERE member_id = ? ORDER BY assessment_date DESC LIMIT 1");
$lastRisk->execute([$memberId]);
$lastRisk = $lastRisk->fetchColumn();
$suggestPhase = $lastRisk === false ? null : ($lastRisk >= 80 ? 'corrective' : ($lastRisk >= 60 ? 'stabilization' : null));
// التزام العضو خلال 30 يومًا (جلسات مكتملة ÷ إجمالي الجلسات المستحقة)
$mComp = $pdo->prepare("SELECT SUM(ws.completion_status = 'completed') AS d, COUNT(*) AS t
                        FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                        WHERE wp.member_id = ? AND ws.session_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()");
$mComp->execute([$memberId]);
$mComp = $mComp->fetch();

$crumb = '<a class="link" href="captains.php?coach=' . $coachId . '">' . h($coach['full_name']) . '</a> / <strong>' . h($member['full_name']) . '</strong>';
echo '<div class="crumb">' . ($isCoach ? '' : '<a class="link" href="captains.php">الكباتن</a> / ') . $crumb . '</div>';
?>

<!-- ===== بيانات العضو ===== -->
<section>
  <h2>👤 <?=h($member['full_name'])?>
    <span class="badge" style="margin-inline-start:6px"><?=h($member['status'])?></span>
  </h2>
  <div class="muted" style="font-size:13px;line-height:1.9">
    📞 <?=h($member['phone'] ?? '—')?> · ✉️ <?=h($member['email'] ?? '—')?> · 🗓 انضم: <?=h($member['join_date'])?>
    · 🎯 <?=h($member['goal_summary'] ?? '—')?> · ⏰ <?=h($member['preferred_time'] ?? '—')?>
    <?php if ($member['notes']): ?><br>📝 <?=h($member['notes'])?><?php endif; ?>
  </div>
  <details style="margin-top:10px"><summary class="link" style="cursor:pointer;font-size:13px">✏️ تعديل بيانات العضو</summary>
    <form class="frm" method="post"><?=csrf_field()?>
      <input type="hidden" name="action" value="update_member">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <div><label>الاسم الكامل *</label><input name="full_name" value="<?=h($member['full_name'])?>" required></div>
      <div><label>النوع</label><select name="gender"><option value="">—</option>
        <?php foreach ($GENDERS as $g): ?><option <?= $member['gender']===$g?'selected':'' ?>><?=h($g)?></option><?php endforeach; ?></select></div>
      <div><label>تاريخ الميلاد</label><input type="date" name="birth_date" value="<?=h($member['birth_date'])?>"></div>
      <div><label>الهاتف</label><input name="phone" value="<?=h($member['phone'])?>"></div>
      <div><label>البريد</label><input type="email" name="email" value="<?=h($member['email'])?>"></div>
      <div><label>العنوان</label><input name="address" value="<?=h($member['address'])?>"></div>
      <div><label>الوظيفة</label><input name="job_title" value="<?=h($member['job_title'])?>"></div>
      <div><label>الوقت المفضّل</label><input name="preferred_time" value="<?=h($member['preferred_time'])?>"></div>
      <div><label>الهدف</label><input name="goal_summary" value="<?=h($member['goal_summary'])?>"></div>
      <div><label>الحالة</label><?=sel('status',$MSTATUSES,$member['status'])?></div>
      <div style="grid-column:1/-1"><label>ملاحظات</label><input name="notes" value="<?=h($member['notes'])?>"></div>
      <div><button type="submit">حفظ التعديلات</button></div>
    </form>
  </details>
</section>

<div class="grid2">
  <!-- ===== برامج التمرين ===== -->
  <section>
    <h2>🏋️ برامج التمرين
      <?php if ((int)($mComp['t'] ?? 0) > 0):
        $mp = round(100 * (int)$mComp['d'] / (int)$mComp['t']);
        $mpc = $mp >= 75 ? '#16a34a' : ($mp >= 50 ? '#f59e0b' : '#dc2626'); ?>
        <span class="badge" style="background:<?=$mpc?>;margin-inline-start:6px">الالتزام <?=$mp?>% (<?=(int)$mComp['d']?>/<?=(int)$mComp['t']?>)</span>
      <?php endif; ?>
    </h2>
    <?php if (!$wplans): ?><div class="empty">لا توجد برامج بعد.</div><?php endif; ?>
    <?php foreach ($wplans as $p): ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:12px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <strong><?=h($p['goal_type'])?></strong> · <span class="muted"><?=h($p['phase'])?></span>
          <span class="badge" style="background:<?= $p['status']==='active'?'#16a34a':'#6b7280' ?>"><?=h($p['status'])?></span>
          <form method="post" style="margin:0;display:flex;gap:5px;margin-inline-start:auto"><?=csrf_field()?>
            <input type="hidden" name="action" value="set_wplan_status">
            <input type="hidden" name="workout_plan_id" value="<?=$p['workout_plan_id']?>">
            <?=sel('status',$PSTATUS,$p['status'])?>
            <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">تحديث</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('حذف البرنامج وكل جلساته؟')"><?=csrf_field()?>
            <input type="hidden" name="action" value="delete_wplan">
            <input type="hidden" name="workout_plan_id" value="<?=$p['workout_plan_id']?>">
            <button type="submit" style="background:#dc2626;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">🗑 حذف</button>
          </form>
        </div>
        <div class="muted" style="font-size:12px;margin:4px 0"><?=h($p['start_date'])?> → <?=h($p['end_date'] ?? '—')?></div>
        <?php $ss = $sessByPlan[$p['workout_plan_id']] ?? []; if ($ss): ?>
          <table style="margin-top:6px"><tr><th>التاريخ</th><th>المجموعة</th><th>تمارين</th><th>الحالة</th><th></th></tr>
            <?php foreach ($ss as $s): ?>
              <tr><td><a class="link" href="session.php?id=<?=$s['session_id']?>" title="فتح وضع بدء الجلسة">▶ <?=h($s['session_date'])?></a></td><td><?=h($s['muscle_group'])?></td><td class="muted"><?=h($s['exercises'])?></td>
                <td><form method="post" style="margin:0;display:flex;gap:4px"><?=csrf_field()?>
                  <input type="hidden" name="action" value="set_session_status">
                  <input type="hidden" name="session_id" value="<?=$s['session_id']?>">
                  <?=sel('completion_status',$CSTATUS,$s['completion_status'])?>
                  <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:3px 8px;border-radius:6px;font-size:11px;cursor:pointer">✓</button>
                </form></td>
                <td><form method="post" style="margin:0" onsubmit="return confirm('حذف الجلسة؟')"><?=csrf_field()?>
                  <input type="hidden" name="action" value="delete_session">
                  <input type="hidden" name="session_id" value="<?=$s['session_id']?>">
                  <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:13px">🗑</button>
                </form></td></tr>
              <tr><td colspan="5" style="background:#fbfcfe;padding:4px 10px">
                <?php foreach ($sessEx[$s['session_id']] ?? [] as $x): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;background:#eef2ff;border-radius:999px;padding:2px 10px;font-size:12px;margin:2px">
                    <?=h($x['sort_order'])?>. <strong><?=h($x['ex_name'])?></strong>
                    <?=h($x['sets'])?>×<?=h($x['reps'])?><?= $x['load_kg'] !== null ? ' @' . h($x['load_kg']) . 'كجم' : ($x['notes'] ? ' (' . h($x['notes']) . ')' : '') ?><?= $x['rpe'] ? ' RPE' . h($x['rpe']) : '' ?>
                    <form method="post" style="margin:0;display:inline"><?=csrf_field()?>
                      <input type="hidden" name="action" value="delete_session_exercise">
                      <input type="hidden" name="session_exercise_id" value="<?=$x['session_exercise_id']?>">
                      <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:11px">✕</button>
                    </form>
                  </span>
                <?php endforeach; ?>
                <details style="display:inline-block;vertical-align:middle"><summary class="link" style="cursor:pointer;font-size:12px">➕ تمرين مُهيكل</summary>
                  <form class="frm" method="post"><?=csrf_field()?>
                    <input type="hidden" name="action" value="add_session_exercise">
                    <input type="hidden" name="session_id" value="<?=$s['session_id']?>">
                    <div><label>التمرين</label><select name="exercise_id"><?php foreach ($exList as $e2): ?><option value="<?=$e2['exercise_id']?>"><?=h($e2['name'])?></option><?php endforeach; ?></select></div>
                    <div><label>ترتيب</label><input type="number" name="sort_order" value="1" min="1"></div>
                    <div><label>مجموعات</label><input type="number" name="sets" min="1"></div>
                    <div><label>تكرارات</label><input name="reps" placeholder="8-12"></div>
                    <div><label>الحمل (كجم)</label><input type="number" step="0.5" name="load_kg"></div>
                    <div><label>راحة (ث)</label><input type="number" name="rest_sec"></div>
                    <div><label>RPE</label><input type="number" name="rpe" min="1" max="10"></div>
                    <div><label>ملاحظة</label><input name="notes"></div>
                    <div><button type="submit">حفظ</button></div>
                  </form>
                </details>
              </td></tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
        <details style="margin-top:6px"><summary class="link" style="cursor:pointer;font-size:13px">➕ إضافة جلسة</summary>
          <form class="frm" method="post"><?=csrf_field()?>
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
    <h3>📋 إسناد قالب جاهز (يولّد الجلسات والتمارين تلقائيًا)</h3>
    <?php if (!$tplsActive): ?><div class="empty">لا توجد قوالب نشطة — أنشئ واحدًا من صفحة "التمارين والقوالب".</div><?php else: ?>
    <form class="frm" method="post"><?=csrf_field()?>
      <input type="hidden" name="action" value="assign_template">
      <input type="hidden" name="member_id" value="<?=$memberId?>">
      <div style="grid-column:span 2"><label>القالب</label><select name="template_id">
        <?php foreach ($tplsActive as $tp): $sug = $suggestPhase !== null && $tp['phase'] === $suggestPhase; ?>
          <option value="<?=$tp['template_id']?>" <?= $sug ? 'selected' : '' ?>><?=h($tp['title'])?> — <?=h($tp['phase'])?> (<?=h($tp['duration_weeks'])?> أسابيع)<?= $sug ? ' ⭐ مقترح' : '' ?></option>
        <?php endforeach; ?></select></div>
      <div><label>تاريخ البداية</label><input type="date" name="start_date" value="<?=date('Y-m-d')?>" required></div>
      <div><button type="submit">إسناد وتوليد</button></div>
    </form>
    <?php if ($suggestPhase !== null): ?><p class="muted" style="font-size:12px;margin:6px 0 0">بناءً على آخر تقييم (خطر <?=h($lastRisk)?>) المرحلة المقترحة: <strong><?=h($suggestPhase)?></strong> ⭐</p><?php endif; ?>
    <?php endif; ?>

    <h3>➕ إضافة برنامج تمرين جديد</h3>
    <form class="frm" method="post"><?=csrf_field()?>
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
          <form method="post" style="margin:0;display:flex;gap:5px;margin-inline-start:auto"><?=csrf_field()?>
            <input type="hidden" name="action" value="set_nplan_status">
            <input type="hidden" name="nutrition_plan_id" value="<?=$n['nutrition_plan_id']?>">
            <?=sel('status',$PSTATUS,$n['status'])?>
            <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:4px 10px;border-radius:7px;font-size:12px;cursor:pointer">تحديث</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('حذف خطة التغذية؟')"><?=csrf_field()?>
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
    <form class="frm" method="post"><?=csrf_field()?>
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
          <td><form method="post" style="margin:0"><?=csrf_field()?>
            <input type="hidden" name="action" value="toggle_supplement">
            <input type="hidden" name="supplement_id" value="<?=$s['supplement_id']?>">
            <button type="submit" style="background:<?= $s['active'] ? '#f59e0b' : '#16a34a' ?>;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><?= $s['active'] ? '⏸ إيقاف' : '▶ تفعيل' ?></button>
          </form></td>
          <td><form method="post" style="margin:0" onsubmit="return confirm('حذف المكمّل؟')"><?=csrf_field()?>
            <input type="hidden" name="action" value="delete_supplement">
            <input type="hidden" name="supplement_id" value="<?=$s['supplement_id']?>">
            <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:13px">🗑</button>
          </form></td></tr>
      <?php endforeach; ?></table>
    <?php endif; ?>
    <form class="frm" method="post"><?=csrf_field()?>
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
    <form class="frm" method="post"><?=csrf_field()?>
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
    <form class="frm" method="post"><?=csrf_field()?>
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
            <td><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="action" value="complete_task">
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

<!-- ===== سجل الإصابات ===== -->
<section>
  <h2>🩹 سجل الإصابات (يشغّل أتمتة الإيقاف)</h2>
  <?php if (!$injuries): ?><div class="empty">لا توجد إصابات مسجّلة.</div><?php else: ?>
    <table><tr><th>التاريخ</th><th>المنطقة</th><th>النوع</th><th>الشدة</th><th>الحالة + التصريح الطبي</th><th>ملاحظات</th></tr>
    <?php foreach ($injuries as $inj): ?>
      <tr><td><?=h($inj['injury_date'])?></td><td><?=h($inj['body_area'])?></td><td class="muted"><?=h($inj['injury_type'])?></td>
        <td><span class="badge" style="background:<?= in_array($inj['severity'],['high','critical'])?'#dc2626':($inj['severity']==='medium'?'#f59e0b':'#6b7280') ?>"><?=h($inj['severity'])?></span></td>
        <td><form method="post" style="margin:0;display:flex;gap:5px;align-items:center;flex-wrap:wrap"><?=csrf_field()?>
          <input type="hidden" name="action" value="set_injury_status">
          <input type="hidden" name="injury_id" value="<?=$inj['injury_id']?>">
          <?=sel('current_status',$ISTAT,$inj['current_status'])?>
          <label style="font-size:11px;display:flex;align-items:center;gap:3px"><input type="checkbox" name="doctor_clearance" <?= $inj['doctor_clearance']?'checked':'' ?>>تصريح طبي</label>
          <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer">تحديث</button>
        </form></td>
        <td class="muted"><?=h($inj['notes'])?></td></tr>
    <?php endforeach; ?></table>
  <?php endif; ?>
  <h3>➕ تسجيل إصابة</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_injury">
    <input type="hidden" name="member_id" value="<?=$memberId?>">
    <div><label>التاريخ</label><input type="date" name="injury_date" value="<?=date('Y-m-d')?>" required></div>
    <div><label>المنطقة *</label><input name="body_area" required placeholder="knee / lower back"></div>
    <div><label>النوع</label><input name="injury_type"></div>
    <div><label>الشدة</label><?=sel('severity',$ISEVER,'low')?></div>
    <div><label>الحالة</label><?=sel('current_status',$ISTAT,'active')?></div>
    <div><label style="display:flex;align-items:center;gap:5px;margin-top:18px"><input type="checkbox" name="doctor_clearance">تصريح طبي</label></div>
    <div style="grid-column:1/-1"><label>ملاحظات</label><input name="notes"></div>
    <div><button type="submit">حفظ الإصابة</button></div>
  </form>
  <p class="muted" style="font-size:12px;margin:10px 0 0">شدة high/critical: توقَف برامج العضو النشطة ويتحوّل لـ paused مع إنذار injury ومهمة إحالة طبية عاجلة — تلقائيًا.</p>
</section>

<!-- ===== ذكاء الأحمال ===== -->
<section>
  <h2>🧠 ذكاء الأحمال</h2>
  <?php if ($deload): ?>
    <div style="background:#fef3c7;color:#92400e;padding:12px 16px;border-radius:10px;margin-bottom:14px;font-weight:600">
      ⚠️ إنذار تحميل زائد — متوسط RPE لآخر جلسات = <?=h(round((float)$rpeAvg,1))?>/10. يُنصح بأسبوع تخفيف حمل (Deload): قلّل الأحمال ~10–20% أو عدد المجموعات.
    </div>
  <?php endif; ?>
  <?php if (!$loadIntel): ?>
    <div class="empty">لا توجد أحمال مسجّلة بعد. سجّل الأداء الفعلي من <strong>وضع بدء الجلسة</strong> لتظهر التحليلات والمقترحات.</div>
  <?php else: ?>
    <table>
      <tr><th>التمرين</th><th>آخر حمل</th><th>المنطقة</th><th>1RM تقديري</th><th>الاتجاه</th><th>الحمل المقترح</th><th>ملاحظة</th></tr>
      <?php foreach ($loadIntel as $li):
        $rows = $li['rows']; $last = $rows[0];
        $lastLd = (float)$last['load_kg'];
        $lastRp = $last['rpe'] !== null ? (int)$last['rpe'] : null;
        $repsI  = reps_to_int($last['reps']);
        $orm    = epley_1rm($lastLd, $repsI);
        $loadsDesc = array_map(fn($r) => (float)$r['load_kg'], $rows);
        $best   = max($loadsDesc);
        [$tArrow, $tColor, $tLabel] = load_trend($lastLd, $best);
        $sug    = suggest_next_load($lastLd, $lastRp);
        $plateau = is_plateau($loadsDesc);
      ?>
        <tr>
          <td><strong><?=h($li['name'])?></strong></td>
          <td><?=h($lastLd)?>كجم × <?=h($last['reps'])?><?= $lastRp !== null ? ' <span class="muted">@RPE '.$lastRp.'</span>' : '' ?></td>
          <td><span class="muted"><?=h(load_zone($repsI))?></span></td>
          <td><?= $orm !== null ? '<strong>'.h($orm).'</strong> كجم' : '<span class="muted">—</span>' ?></td>
          <td style="color:<?=$tColor?>;font-weight:700"><?=$tArrow?> <span style="font-weight:400;font-size:12px"><?=h($tLabel)?></span></td>
          <td><?php if ($sug['load'] !== null): ?><span style="background:<?=$sug['color']?>;color:#fff;padding:2px 10px;border-radius:999px;font-weight:700"><?=h($sug['load'])?>كجم</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td style="font-size:12px;color:<?=$sug['color']?>"><?=h($sug['reason'])?><?php if ($plateau): ?> <span style="background:#fef3c7;color:#92400e;padding:1px 8px;border-radius:999px;font-weight:700">⚠️ ثبات</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:12px;margin:10px 0 0">القواعد: 1RM بمعادلة Epley · المقترح من آخر RPE (≤6 زد ~5% · 7 ‎+2.5% · 8 ثبّت · ≥9 خفّف ~5%) · الثبات = 3 جلسات بلا زيادة.</p>
  <?php endif; ?>
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

  <?php if ($loadSeries):
    $palette = ['#2563eb','#16a34a','#f59e0b','#db2777']; $pi = 0; $ser = [];
    foreach ($loadSeries as $nm => $pts) $ser[] = ['label' => $nm, 'color' => $palette[$pi++ % 4], 'points' => $pts];
  ?>
  <h3>🏋️ تقدّم الأحمال (أقصى حمل بالكيلو لكل جلسة)</h3>
  <?=line_chart($ser)?>
  <?php endif; ?>

  <?php if ($tonnage): ?>
  <h3>📦 الحجم التدريبي التقريبي (طن) — آخر 7 أيام مقابل الأسبوع السابق</h3>
  <table><tr><th>المجموعة العضلية</th><th>هذا الأسبوع</th><th>الأسبوع السابق</th><th>التغير</th></tr>
  <?php foreach ($tonnage as $tn): $d = $tn['t_now'] - $tn['t_prev']; ?>
    <tr><td><?=h($tn['muscle_group'])?></td><td><strong><?=h($tn['t_now'])?></strong></td><td class="muted"><?=h($tn['t_prev'])?></td>
      <td style="color:<?= $d >= 0 ? '#16a34a' : '#dc2626' ?>;font-weight:600"><?= $d >= 0 ? '▲' : '▼' ?> <?=h(round(abs($d), 2))?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>

  <?php if ($prog): ?>
  <h3>السجلّ</h3>
  <table>
    <tr><th>التاريخ</th><th>الوزن</th><th>دهون %</th><th>كتلة عضلية</th><th>خصر</th><th>صدر</th><th>أرداف</th><th>ملاحظة</th><th>📷</th><th></th></tr>
    <?php foreach (array_reverse($prog) as $r): ?>
      <tr><td><?=h($r['record_date'])?></td><td><?=h($r['weight'])?></td><td><?=h($r['body_fat'])?></td><td><?=h($r['muscle_mass'])?></td>
        <td><?=h($r['waist'])?></td><td><?=h($r['chest'])?></td><td><?=h($r['hips'])?></td><td class="muted"><?=h($r['performance_note'])?></td>
        <td><?php if ($r['photo_ref']): ?><a class="link" href="<?=h($r['photo_ref'])?>" target="_blank">📷 عرض</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('حذف هذا القياس؟')"><?=csrf_field()?>
          <input type="hidden" name="action" value="delete_progress">
          <input type="hidden" name="progress_id" value="<?=$r['progress_id']?>">
          <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:13px">🗑</button>
        </form></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h3>➕ تسجيل قياس جديد</h3>
  <form class="frm" method="post" enctype="multipart/form-data"><?=csrf_field()?>
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
    <div><label>📷 صورة (اختياري، حتى 5MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
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
  <form class="frm" method="post"><?=csrf_field()?>
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
    <form class="frm" method="post"><?=csrf_field()?>
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
    <form class="frm" method="post"><?=csrf_field()?>
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

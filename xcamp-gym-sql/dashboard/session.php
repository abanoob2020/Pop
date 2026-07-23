<?php
// =============================================================================
// Xcamp Gym — وضع "بدء الجلسة" الحي: تسجيل الأداء الفعلي تمرينًا بتمرين
// أثناء التمرين، ثم إنهاء الجلسة (مع تسجيل حضور تلقائي عند الإكمال).
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$sid = (int)($_GET['id'] ?? 0);

// جلب الجلسة + العضو والتحقق من الصلاحية
$sess = null;
if ($pdo && !$error && $sid) {
    $q = $pdo->prepare("SELECT ws.*, wp.member_id, wp.goal_type, wp.phase, m.full_name, m.coach_id AS member_coach
                        FROM workout_sessions ws
                        JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                        JOIN members m ON m.member_id = wp.member_id
                        WHERE ws.session_id = ?");
    $q->execute([$sid]);
    $sess = $q->fetch();
}
$denied = $sess && $me['role'] === 'coach' && (int)$sess['member_coach'] !== (int)($me['coach_id'] ?? 0);

if ($pdo && !$error && $sess && !$denied && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'save_ex') {
            $xid  = (int)$_POST['session_exercise_id'];
            // تأكد أن التمرين تابع لهذه الجلسة
            $own = $pdo->prepare("SELECT exercise_id FROM session_exercises WHERE session_exercise_id = ? AND session_id = ?");
            $own->execute([$xid, $sid]);
            $exId = $own->fetchColumn();
            if ($exId === false) throw new RuntimeException('التمرين غير تابع لهذه الجلسة.');
            $load = $_POST['load_kg'] !== '' ? (float)$_POST['load_kg'] : null;
            // اكتشاف الرقم القياسي (مستثنيًا هذا الصف نفسه)
            if ($load !== null) {
                $pm = $pdo->prepare("SELECT MAX(se.load_kg) FROM session_exercises se
                                     JOIN workout_sessions ws ON ws.session_id = se.session_id
                                     JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                                     WHERE wp.member_id = ? AND se.exercise_id = ? AND se.session_exercise_id <> ?");
                $pm->execute([(int)$sess['member_id'], (int)$exId, $xid]);
                $prev = $pm->fetchColumn();
                if ($prev !== null && $prev !== false && $load > (float)$prev) {
                    $exName = $pdo->query("SELECT name FROM exercises WHERE exercise_id = " . (int)$exId)->fetchColumn();
                    $pdo->prepare("INSERT INTO milestones (member_id, milestone_date, milestone_type, description, reward_status)
                                   VALUES (?, CURDATE(), 'strength_gain', ?, 'badge')")
                        ->execute([(int)$sess['member_id'], "🏆 رقم قياسي جديد في {$exName}: {$load} كجم (السابق: {$prev})"]);
                }
            }
            $pdo->prepare("UPDATE session_exercises SET sets = ?, reps = ?, load_kg = ?, rpe = ?, notes = ? WHERE session_exercise_id = ?")
                ->execute([
                    $_POST['sets'] !== '' ? (int)$_POST['sets'] : null,
                    trim($_POST['reps'] ?? '') ?: null, $load,
                    $_POST['rpe'] !== '' ? (int)$_POST['rpe'] : null,
                    trim($_POST['notes'] ?? '') ?: null, $xid,
                ]);
        } elseif ($act === 'finish') {
            $st = $_POST['status'] ?? '';
            if (!in_array($st, ['completed','partial','missed'], true)) throw new RuntimeException('حالة غير صالحة.');
            $pdo->prepare("UPDATE workout_sessions SET completion_status = ? WHERE session_id = ?")->execute([$st, $sid]);
            // عند الإكمال (كليًا/جزئيًا): سجّل حضور اليوم تلقائيًا إن لم يكن مسجّلًا
            if (in_array($st, ['completed','partial'], true)) {
                $dup = $pdo->prepare("SELECT 1 FROM daily_attendance WHERE member_id = ? AND attendance_date = ?");
                $dup->execute([(int)$sess['member_id'], $sess['session_date']]);
                if (!$dup->fetchColumn()) {
                    $pdo->prepare("INSERT INTO daily_attendance (member_id, coach_id, attendance_date, check_in_time, attended, session_type, notes)
                                   VALUES (?,?,?,CURTIME(),1,'training','سُجّل تلقائيًا من وضع بدء الجلسة')")
                        ->execute([(int)$sess['member_id'], (int)$sess['member_coach'] ?: null, $sess['session_date']]);
                }
            }
        }
        header("Location: session.php?id={$sid}&ok=1");
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('بدء الجلسة', 'calendar');
if ($error && !$sess) { db_error_box($error); page_foot(); exit; }
if (!$sess)  { echo '<div class="err">الجلسة غير موجودة.</div>'; page_foot(); exit; }
if ($denied) { echo '<div class="err">غير مسموح — الجلسة لعضو كابتن آخر.</div>'; page_foot(); exit; }
if ($error)  { echo '<div class="err">' . h($error) . '</div>'; }
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ.</div>';

// إعادة الجلب بعد أي حفظ
$exs = $pdo->prepare("SELECT se.*, e.name AS ex_name, e.muscle_group AS ex_group
                      FROM session_exercises se JOIN exercises e ON e.exercise_id = se.exercise_id
                      WHERE se.session_id = ? ORDER BY se.sort_order");
$exs->execute([$sid]);
$exs = $exs->fetchAll();
$q = $pdo->prepare("SELECT completion_status FROM workout_sessions WHERE session_id = ?");
$q->execute([$sid]);
$curStatus = $q->fetchColumn();
$stColor = ['planned' => '#2563eb', 'completed' => '#16a34a', 'partial' => '#f59e0b', 'missed' => '#dc2626'];
$memberCoach = (int)$sess['member_coach'];
?>
<div class="crumb">
  <a class="link" href="calendar.php<?= $me['role'] === 'coach' ? '' : '?coach=' . $memberCoach ?>">التقويم</a> /
  <a class="link" href="captains.php?coach=<?=$memberCoach?>&member=<?=(int)$sess['member_id']?>"><?=h($sess['full_name'])?></a> /
  <strong>جلسة <?=h($sess['session_date'])?></strong>
</div>

<section>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">▶️ <?=h($sess['full_name'])?> — <?=h($sess['muscle_group'] ?? 'جلسة')?></h2>
    <span class="badge" style="background:<?=$stColor[$curStatus] ?? '#6b7280'?>"><?=h($curStatus)?></span>
    <span class="muted" style="font-size:13px"><?=h($sess['session_date'])?> · <?=h($sess['goal_type'])?> / <?=h($sess['phase'])?></span>
  </div>
</section>

<?php if (!$exs): ?>
  <section><div class="empty">لا توجد تمارين مُهيكلة لهذه الجلسة — أضِفها من صفحة العضو.</div></section>
<?php endif; ?>

<?php foreach ($exs as $i => $x): ?>
<section style="padding:14px 18px">
  <form method="post" style="margin:0"><?=csrf_field()?>
    <input type="hidden" name="action" value="save_ex">
    <input type="hidden" name="session_exercise_id" value="<?=$x['session_exercise_id']?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
      <strong style="font-size:16px"><?=($i+1)?>. <?=h($x['ex_name'])?></strong>
      <span class="muted" style="font-size:12px"><?=h($x['ex_group'])?><?= $x['rest_sec'] ? ' · راحة ' . h($x['rest_sec']) . 'ث' : '' ?><?= $x['notes'] && $x['load_kg'] === null ? ' · حمل مقترح: ' . h($x['notes']) : '' ?></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px;align-items:end">
      <div><label style="font-size:11px;color:#475569;display:block">مجموعات</label><input type="number" name="sets" value="<?=h($x['sets'])?>" min="1" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px"></div>
      <div><label style="font-size:11px;color:#475569;display:block">تكرارات</label><input name="reps" value="<?=h($x['reps'])?>" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px"></div>
      <div><label style="font-size:11px;color:#475569;display:block">الحمل الفعلي (كجم)</label><input type="number" step="0.5" name="load_kg" value="<?=h($x['load_kg'])?>" style="width:100%;padding:8px;border:2px solid #2563eb;border-radius:8px;font-size:15px;font-weight:700"></div>
      <div><label style="font-size:11px;color:#475569;display:block">RPE</label><input type="number" name="rpe" value="<?=h($x['rpe'])?>" min="1" max="10" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px"></div>
      <div><label style="font-size:11px;color:#475569;display:block">ملاحظة</label><input name="notes" value="<?=h($x['notes'])?>" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px"></div>
      <div><button type="submit" style="background:#2563eb;color:#fff;border:0;padding:10px 16px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;width:100%">💾 حفظ</button></div>
    </div>
  </form>
</section>
<?php endforeach; ?>

<section>
  <h2>🏁 إنهاء الجلسة</h2>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php foreach ([['completed','✅ اكتملت','#16a34a'],['partial','🌓 جزئية','#f59e0b'],['missed','❌ لم يحضر','#dc2626']] as [$stv,$lbl,$clr]): ?>
      <form method="post" style="margin:0;flex:1;min-width:140px"><?=csrf_field()?>
        <input type="hidden" name="action" value="finish">
        <input type="hidden" name="status" value="<?=$stv?>">
        <button type="submit" style="background:<?=$clr?>;color:#fff;border:0;padding:14px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;width:100%"><?=$lbl?></button>
      </form>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">"اكتملت" أو "جزئية": يُسجَّل حضور العضو لليوم تلقائيًا (إن لم يكن مسجّلًا) وتعمل أتمتة المتابعة. تسجيل حمل أعلى من الرقم السابق يولّد إنجاز 🏆 تلقائيًا.</p>
</section>
<?php page_foot();

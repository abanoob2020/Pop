<?php
// =============================================================================
// Xcamp Gym — واجهة الكباتن: إدارة برامج التمرين والتغذية لأعضاء كل كابتن
// =============================================================================
require __DIR__ . '/db.php';

// ثوابت القوائم المنسدلة (مطابقة للـ ENUM في قاعدة البيانات)
$GOALS   = ['fat_loss','muscle_gain','strength','rehab','performance','general_fitness'];
$PHASES  = ['corrective','stabilization','hypertrophy','strength','power','maintenance'];
$PSTATUS = ['active','paused','completed','cancelled'];
$CSTATUS = ['planned','partial','completed','missed'];

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$coachId  = isset($_GET['coach'])  ? (int)$_GET['coach']  : 0;
$memberId = isset($_GET['member']) ? (int)$_GET['member'] : 0;

// ---- معالجة النماذج (POST) ثم إعادة توجيه (PRG) ----
if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'add_workout_plan') {
            $st = $pdo->prepare("INSERT INTO workout_plans (member_id, coach_id, goal_type, phase, start_date, end_date, status, notes)
                                 VALUES (?,?,?,?,?,?,?,?)");
            $st->execute([
                (int)$_POST['member_id'], (int)$_POST['coach_id'] ?: null,
                $_POST['goal_type'], $_POST['phase'], $_POST['start_date'],
                $_POST['end_date'] ?: null, $_POST['status'], trim($_POST['notes'] ?? '') ?: null,
            ]);
        } elseif ($act === 'add_workout_session') {
            $st = $pdo->prepare("INSERT INTO workout_sessions (workout_plan_id, session_date, muscle_group, exercises, sets_info, reps_info, load_info, intensity_info, completion_status, notes)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                (int)$_POST['workout_plan_id'], $_POST['session_date'],
                trim($_POST['muscle_group'] ?? '') ?: null, trim($_POST['exercises'] ?? '') ?: null,
                trim($_POST['sets_info'] ?? '') ?: null, trim($_POST['reps_info'] ?? '') ?: null,
                trim($_POST['load_info'] ?? '') ?: null, trim($_POST['intensity_info'] ?? '') ?: null,
                $_POST['completion_status'], trim($_POST['notes'] ?? '') ?: null,
            ]);
        } elseif ($act === 'add_nutrition_plan') {
            $st = $pdo->prepare("INSERT INTO nutrition_plans (member_id, coach_id, calories, protein_g, fat_g, carbs_g, hydration_target_l, meal_timing, status)
                                 VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([
                (int)$_POST['member_id'], (int)$_POST['coach_id'] ?: null,
                $_POST['calories'] !== '' ? (int)$_POST['calories'] : null,
                $_POST['protein_g'] !== '' ? $_POST['protein_g'] : null,
                $_POST['fat_g'] !== '' ? $_POST['fat_g'] : null,
                $_POST['carbs_g'] !== '' ? $_POST['carbs_g'] : null,
                $_POST['hydration_target_l'] !== '' ? $_POST['hydration_target_l'] : null,
                trim($_POST['meal_timing'] ?? '') ?: null, $_POST['status'],
            ]);
        } elseif ($act === 'add_supplement') {
            $st = $pdo->prepare("INSERT INTO supplements (member_id, supplement_name, dose, timing, purpose, active)
                                 VALUES (?,?,?,?,?,1)");
            $st->execute([
                (int)$_POST['member_id'], trim($_POST['supplement_name']),
                trim($_POST['dose'] ?? '') ?: null, trim($_POST['timing'] ?? '') ?: null,
                trim($_POST['purpose'] ?? '') ?: null,
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

/** helper: قائمة منسدلة */
function sel(string $name, array $opts, string $cur = ''): string {
    $h = "<select name=\"" . h($name) . "\">";
    foreach ($opts as $o) $h .= "<option" . ($o === $cur ? " selected" : "") . ">" . h($o) . "</option>";
    return $h . "</select>";
}

// ===================== 1) لا يوجد كابتن محدد: قائمة الكباتن =====================
if (!$coachId) {
    $coaches = $pdo->query("SELECT c.coach_id, c.full_name, c.specialty,
                                   COUNT(m.member_id) AS members
                            FROM coaches c
                            LEFT JOIN members m ON m.coach_id = c.coach_id
                            WHERE c.active = 1
                            GROUP BY c.coach_id, c.full_name, c.specialty
                            ORDER BY c.coach_id")->fetchAll();
    echo '<section><h2>👨‍🏫 اختر الكابتن</h2><table>';
    echo '<tr><th>#</th><th>الاسم</th><th>التخصص</th><th>عدد الأعضاء</th><th></th></tr>';
    foreach ($coaches as $c) {
        echo '<tr><td>' . h($c['coach_id']) . '</td><td>' . h($c['full_name']) . '</td><td>' . h($c['specialty']) .
             '</td><td>' . h($c['members']) . '</td><td><a class="link" href="captains.php?coach=' . (int)$c['coach_id'] . '">عرض الأعضاء ←</a></td></tr>';
    }
    echo '</table></section>';
    page_foot(); exit;
}

$coach = $pdo->prepare("SELECT * FROM coaches WHERE coach_id = ?");
$coach->execute([$coachId]);
$coach = $coach->fetch();
if (!$coach) { echo '<div class="err">الكابتن غير موجود.</div>'; page_foot(); exit; }

// ===================== 2) كابتن محدد بدون عضو: قائمة أعضائه =====================
if (!$memberId) {
    $members = $pdo->prepare("SELECT member_id, full_name, status, goal_summary FROM members WHERE coach_id = ? ORDER BY member_id");
    $members->execute([$coachId]);
    $members = $members->fetchAll();
    echo '<div class="crumb"><a class="link" href="captains.php">الكباتن</a> / <strong>' . h($coach['full_name']) . '</strong></div>';
    echo '<section><h2>أعضاء الكابتن ' . h($coach['full_name']) . '</h2>';
    if (!$members) echo '<div class="empty">لا يوجد أعضاء مسنَدون لهذا الكابتن.</div>';
    else {
        echo '<table><tr><th>#</th><th>الاسم</th><th>الحالة</th><th>الهدف</th><th></th></tr>';
        foreach ($members as $m) {
            echo '<tr><td>' . h($m['member_id']) . '</td><td>' . h($m['full_name']) . '</td><td><span class="badge">' . h($m['status']) . '</span></td><td class="muted">' . h($m['goal_summary']) .
                 '</td><td><a class="link" href="captains.php?coach=' . $coachId . '&member=' . (int)$m['member_id'] . '">البرامج والتغذية ←</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';
    page_foot(); exit;
}

// ===================== 3) عضو محدد: البرامج + التغذية =====================
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

echo '<div class="crumb"><a class="link" href="captains.php">الكباتن</a> / <a class="link" href="captains.php?coach=' . $coachId . '">' . h($coach['full_name']) . '</a> / <strong>' . h($member['full_name']) . '</strong></div>';
?>

<div class="grid2">
  <!-- ===== برامج التمرين ===== -->
  <section>
    <h2>🏋️ برامج التمرين</h2>
    <?php if (!$wplans): ?><div class="empty">لا توجد برامج بعد.</div><?php endif; ?>
    <?php foreach ($wplans as $p): ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:12px;margin-bottom:12px">
        <strong><?=h($p['goal_type'])?></strong> · <span class="muted"><?=h($p['phase'])?></span>
        <span class="badge" style="background:<?= $p['status']==='active'?'#16a34a':'#6b7280' ?>"><?=h($p['status'])?></span>
        <div class="muted" style="font-size:12px;margin:4px 0"><?=h($p['start_date'])?> → <?=h($p['end_date'] ?? '—')?></div>
        <?php $ss = $sessByPlan[$p['workout_plan_id']] ?? []; ?>
        <?php if ($ss): ?>
          <table style="margin-top:6px">
            <tr><th>التاريخ</th><th>المجموعة</th><th>تمارين</th><th>الحالة</th></tr>
            <?php foreach ($ss as $s): ?>
              <tr><td><?=h($s['session_date'])?></td><td><?=h($s['muscle_group'])?></td><td class="muted"><?=h($s['exercises'])?></td><td><?=h($s['completion_status'])?></td></tr>
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
        <strong><?=h($n['calories'] ?? '—')?> سعرة</strong>
        <span class="badge" style="background:<?= $n['status']==='active'?'#16a34a':'#6b7280' ?>"><?=h($n['status'])?></span>
        <div class="muted" style="font-size:13px;margin-top:4px">
          بروتين <?=h($n['protein_g'] ?? '—')?>g · دهون <?=h($n['fat_g'] ?? '—')?>g · كارب <?=h($n['carbs_g'] ?? '—')?>g · ماء <?=h($n['hydration_target_l'] ?? '—')?>ل
        </div>
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
      <table><tr><th>الاسم</th><th>الجرعة</th><th>التوقيت</th><th>الغرض</th></tr>
      <?php foreach ($supps as $s): ?>
        <tr><td><?=h($s['supplement_name'])?></td><td><?=h($s['dose'])?></td><td><?=h($s['timing'])?></td><td class="muted"><?=h($s['purpose'])?></td></tr>
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

<?php page_foot();

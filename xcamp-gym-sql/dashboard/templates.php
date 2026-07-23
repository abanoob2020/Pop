<?php
// =============================================================================
// Xcamp Gym — مكتبة التمارين وقوالب البرامج (مورد مشترك لكل الكباتن والإدارة)
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$GOALS  = ['fat_loss','muscle_gain','strength','rehab','performance','general_fitness'];
$PHASES = ['corrective','stabilization','hypertrophy','strength','power','maintenance'];

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$openTpl = isset($_GET['tpl']) ? (int)$_GET['tpl'] : 0;

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'add_exercise') {
            $pdo->prepare("INSERT INTO exercises (name, muscle_group, equipment, video_url) VALUES (?,?,?,?)")
                ->execute([trim($_POST['name']), trim($_POST['muscle_group']),
                           trim($_POST['equipment'] ?? '') ?: null, trim($_POST['video_url'] ?? '') ?: null]);
        } elseif ($act === 'toggle_exercise') {
            $pdo->prepare("UPDATE exercises SET active = 1 - active WHERE exercise_id = ?")->execute([(int)$_POST['exercise_id']]);
        } elseif ($act === 'add_template') {
            $pdo->prepare("INSERT INTO program_templates (title, goal_type, phase, duration_weeks, description, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([trim($_POST['title']), $_POST['goal_type'], $_POST['phase'],
                           max(1, (int)$_POST['duration_weeks']), trim($_POST['description'] ?? '') ?: null,
                           $me['role'] === 'coach' ? (int)$me['coach_id'] : null]);
            $openTpl = (int)$pdo->lastInsertId();
        } elseif ($act === 'toggle_template') {
            $pdo->prepare("UPDATE program_templates SET active = 1 - active WHERE template_id = ?")->execute([(int)$_POST['template_id']]);
        } elseif ($act === 'add_template_session') {
            $pdo->prepare("INSERT INTO template_sessions (template_id, day_offset, title, muscle_group) VALUES (?,?,?,?)")
                ->execute([(int)$_POST['template_id'], min(6, max(0, (int)$_POST['day_offset'])),
                           trim($_POST['title'] ?? '') ?: null, trim($_POST['muscle_group'] ?? '') ?: null]);
            $openTpl = (int)$_POST['template_id'];
        } elseif ($act === 'delete_template_session') {
            $openTpl = (int)$pdo->query("SELECT template_id FROM template_sessions WHERE template_session_id = " . (int)$_POST['template_session_id'])->fetchColumn();
            $pdo->prepare("DELETE FROM template_sessions WHERE template_session_id = ?")->execute([(int)$_POST['template_session_id']]);
        } elseif ($act === 'add_tse') {
            $openTpl = (int)$pdo->query("SELECT template_id FROM template_sessions WHERE template_session_id = " . (int)$_POST['template_session_id'])->fetchColumn();
            $pdo->prepare("INSERT INTO template_session_exercises (template_session_id, exercise_id, sort_order, sets, reps, load_note, rest_sec) VALUES (?,?,?,?,?,?,?)")
                ->execute([(int)$_POST['template_session_id'], (int)$_POST['exercise_id'],
                           max(1, (int)($_POST['sort_order'] ?: 1)),
                           $_POST['sets'] !== '' ? (int)$_POST['sets'] : null,
                           trim($_POST['reps'] ?? '') ?: null, trim($_POST['load_note'] ?? '') ?: null,
                           $_POST['rest_sec'] !== '' ? (int)$_POST['rest_sec'] : null]);
        } elseif ($act === 'delete_tse') {
            $openTpl = (int)$pdo->query("SELECT ts.template_id FROM template_session_exercises t JOIN template_sessions ts ON ts.template_session_id = t.template_session_id WHERE t.tse_id = " . (int)$_POST['tse_id'])->fetchColumn();
            $pdo->prepare("DELETE FROM template_session_exercises WHERE tse_id = ?")->execute([(int)$_POST['tse_id']]);
        }
        header("Location: templates.php?" . ($openTpl ? "tpl=$openTpl&" : "") . "ok=1");
        exit;
    } catch (Throwable $e) {
        $error = str_contains($e->getMessage(), 'Duplicate entry')
            ? 'الاسم مستخدم بالفعل.' : 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('التمارين والقوالب', 'templates');
if ($error) { db_error_box($error); page_foot(); exit; }
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح.</div>';

function sel2(string $name, array $opts, string $cur = ''): string {
    $h = "<select name=\"" . h($name) . "\">";
    foreach ($opts as $o) $h .= "<option" . ($o === $cur ? " selected" : "") . ">" . h($o) . "</option>";
    return $h . "</select>";
}

$exs = $pdo->query("SELECT * FROM exercises ORDER BY muscle_group, name")->fetchAll();
$activeExs = array_values(array_filter($exs, fn($e) => $e['active']));
$tpls = $pdo->query("SELECT t.*, c.full_name AS coach_name,
                            (SELECT COUNT(*) FROM template_sessions ts WHERE ts.template_id = t.template_id) AS sess_cnt
                     FROM program_templates t LEFT JOIN coaches c ON c.coach_id = t.created_by
                     ORDER BY t.template_id")->fetchAll();
?>

<section>
  <h2>🏋️ مكتبة التمارين (<?=count($activeExs)?> نشط)</h2>
  <table>
    <tr><th>التمرين</th><th>المجموعة</th><th>الأداة</th><th>فيديو</th><th></th></tr>
    <?php foreach ($exs as $e): ?>
      <tr style="<?= $e['active'] ? '' : 'opacity:.5' ?>">
        <td><strong><?=h($e['name'])?></strong></td><td><?=h($e['muscle_group'])?></td><td class="muted"><?=h($e['equipment'])?></td>
        <td><?php if ($e['video_url']): ?><a class="link" href="<?=h($e['video_url'])?>" target="_blank">▶</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><form method="post" style="margin:0"><?=csrf_field()?>
          <input type="hidden" name="action" value="toggle_exercise"><input type="hidden" name="exercise_id" value="<?=$e['exercise_id']?>">
          <button type="submit" style="background:<?= $e['active'] ? '#f59e0b' : '#16a34a' ?>;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><?= $e['active'] ? '⏸' : '▶' ?></button>
        </form></td></tr>
    <?php endforeach; ?>
  </table>
  <h3>➕ إضافة تمرين</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_exercise">
    <div><label>الاسم *</label><input name="name" required></div>
    <div><label>المجموعة العضلية *</label><input name="muscle_group" required placeholder="legs / back / chest"></div>
    <div><label>الأداة</label><input name="equipment"></div>
    <div><label>رابط فيديو</label><input name="video_url" type="url"></div>
    <div><button type="submit">إضافة</button></div>
  </form>
</section>

<section>
  <h2>📋 قوالب البرامج</h2>
  <?php foreach ($tpls as $t): ?>
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:12px;margin-bottom:12px;<?= $t['active'] ? '' : 'opacity:.6' ?>">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong><?=h($t['title'])?></strong>
        <span class="badge" style="background:#2563eb"><?=h($t['goal_type'])?></span>
        <span class="badge"><?=h($t['phase'])?></span>
        <span class="muted" style="font-size:12px"><?=h($t['duration_weeks'])?> أسابيع · <?=h($t['sess_cnt'])?> جلسات/أسبوع<?= $t['coach_name'] ? ' · ' . h($t['coach_name']) : '' ?></span>
        <a class="link" style="margin-inline-start:auto;font-size:13px" href="templates.php?tpl=<?= $openTpl === (int)$t['template_id'] ? 0 : (int)$t['template_id'] ?>"><?= $openTpl === (int)$t['template_id'] ? 'إغلاق' : 'تفاصيل ⤵' ?></a>
        <form method="post" style="margin:0"><?=csrf_field()?>
          <input type="hidden" name="action" value="toggle_template"><input type="hidden" name="template_id" value="<?=$t['template_id']?>">
          <button type="submit" style="background:<?= $t['active'] ? '#f59e0b' : '#16a34a' ?>;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><?= $t['active'] ? '⏸ إيقاف' : '▶ تفعيل' ?></button>
        </form>
      </div>
      <?php if ($t['description']): ?><div class="muted" style="font-size:12px;margin-top:4px"><?=h($t['description'])?></div><?php endif; ?>

      <?php if ($openTpl === (int)$t['template_id']):
        $tss = $pdo->prepare("SELECT * FROM template_sessions WHERE template_id = ? ORDER BY day_offset");
        $tss->execute([$t['template_id']]); $tss = $tss->fetchAll();
        $tseBy = [];
        if ($tss) {
            $ids = implode(',', array_map(fn($x) => (int)$x['template_session_id'], $tss));
            foreach ($pdo->query("SELECT t.*, e.name AS ex_name FROM template_session_exercises t JOIN exercises e ON e.exercise_id = t.exercise_id WHERE t.template_session_id IN ($ids) ORDER BY t.sort_order") as $r)
                $tseBy[$r['template_session_id']][] = $r;
        }
      ?>
        <?php foreach ($tss as $ts): ?>
          <div style="background:#f8fafc;border-radius:8px;padding:10px;margin-top:10px">
            <div style="display:flex;gap:8px;align-items:center">
              <strong style="font-size:13px"><?=h($ts['title'] ?: 'جلسة')?></strong>
              <span class="muted" style="font-size:12px">اليوم <?=h($ts['day_offset'])?> من الأسبوع · <?=h($ts['muscle_group'])?></span>
              <form method="post" style="margin:0;margin-inline-start:auto" onsubmit="return confirm('حذف جلسة القالب وتمارينها؟')"><?=csrf_field()?>
                <input type="hidden" name="action" value="delete_template_session"><input type="hidden" name="template_session_id" value="<?=$ts['template_session_id']?>">
                <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer">🗑</button>
              </form>
            </div>
            <?php if (!empty($tseBy[$ts['template_session_id']])): ?>
              <table style="margin-top:6px"><tr><th>#</th><th>التمرين</th><th>مجموعات×تكرارات</th><th>الحمل</th><th>راحة</th><th></th></tr>
              <?php foreach ($tseBy[$ts['template_session_id']] as $x): ?>
                <tr><td><?=h($x['sort_order'])?></td><td><?=h($x['ex_name'])?></td>
                  <td><?=h($x['sets'])?> × <?=h($x['reps'])?></td><td class="muted"><?=h($x['load_note'])?></td>
                  <td class="muted"><?=h($x['rest_sec'])?>ث</td>
                  <td><form method="post" style="margin:0"><?=csrf_field()?>
                    <input type="hidden" name="action" value="delete_tse"><input type="hidden" name="tse_id" value="<?=$x['tse_id']?>">
                    <button type="submit" style="background:none;border:0;color:#dc2626;cursor:pointer;font-size:12px">🗑</button>
                  </form></td></tr>
              <?php endforeach; ?></table>
            <?php endif; ?>
            <details style="margin-top:6px"><summary class="link" style="cursor:pointer;font-size:12px">➕ إضافة تمرين للجلسة</summary>
              <form class="frm" method="post"><?=csrf_field()?>
                <input type="hidden" name="action" value="add_tse">
                <input type="hidden" name="template_session_id" value="<?=$ts['template_session_id']?>">
                <div><label>التمرين</label><select name="exercise_id"><?php foreach ($activeExs as $e): ?><option value="<?=$e['exercise_id']?>"><?=h($e['name'])?></option><?php endforeach; ?></select></div>
                <div><label>الترتيب</label><input type="number" name="sort_order" value="1" min="1"></div>
                <div><label>مجموعات</label><input type="number" name="sets" min="1"></div>
                <div><label>تكرارات</label><input name="reps" placeholder="8-12"></div>
                <div><label>ملاحظة الحمل</label><input name="load_note" placeholder="متوسط / 60%"></div>
                <div><label>راحة (ث)</label><input type="number" name="rest_sec"></div>
                <div><button type="submit">إضافة</button></div>
              </form>
            </details>
          </div>
        <?php endforeach; ?>
        <details style="margin-top:10px"><summary class="link" style="cursor:pointer;font-size:13px">➕ إضافة جلسة للقالب</summary>
          <form class="frm" method="post"><?=csrf_field()?>
            <input type="hidden" name="action" value="add_template_session">
            <input type="hidden" name="template_id" value="<?=$t['template_id']?>">
            <div><label>اليوم داخل الأسبوع (0–6)</label><input type="number" name="day_offset" min="0" max="6" value="0" required></div>
            <div><label>العنوان</label><input name="title" placeholder="Push / Full Body A"></div>
            <div><label>المجموعة</label><input name="muscle_group"></div>
            <div><button type="submit">إضافة الجلسة</button></div>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <h3>➕ إنشاء قالب جديد</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_template">
    <div><label>العنوان *</label><input name="title" required></div>
    <div><label>الهدف</label><?=sel2('goal_type',$GOALS,'general_fitness')?></div>
    <div><label>المرحلة</label><?=sel2('phase',$PHASES,'stabilization')?></div>
    <div><label>عدد الأسابيع</label><input type="number" name="duration_weeks" value="4" min="1" max="52"></div>
    <div style="grid-column:1/-1"><label>الوصف</label><input name="description"></div>
    <div><button type="submit">إنشاء القالب</button></div>
  </form>
</section>

<?php page_foot();

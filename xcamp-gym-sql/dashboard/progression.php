<?php
// =============================================================================
// Xcamp Gym — المتتبّع التكيّفي للأحمال: يسجّل أداء مجموعة اختبار (وزن/تكرار/RPE/
// RIR) لكل عضو/تمرين، يحسب 1RM المقدَّر، ويقترح **وزن العمل** القادم. الإصلاح
// المحترف: وزن العمل يُشتقّ من الوزن المُؤدَّى لا من الـ1RM. الكابتن مقيَّد بأعضائه.
// يعتمد adaptive_load_decision() + line_chart() (training.php / db.php).
// =============================================================================
require __DIR__ . '/db.php';   // db.php يحمّل training.php (adaptive_load_decision / line_chart)
$me = require_login();
$isCoach = ($me['role'] === 'coach');
$myCoachId = $isCoach ? (int)($me['coach_id'] ?? 0) : 0;

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

/** يتأكّد أن العضو ضمن صلاحية المستخدم (الكابتن لأعضائه فقط). */
function prog_allowed(PDO $pdo, bool $isCoach, int $coachId, int $memberId): bool {
    if (!$isCoach) return true;
    $q = $pdo->prepare("SELECT 1 FROM members WHERE member_id = ? AND coach_id = ?");
    $q->execute([$memberId, $coachId]);
    return (bool)$q->fetchColumn();
}

$decision = null; // نتيجة آخر تسجيل لعرضها فورًا
if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $memberId   = (int)($_POST['member_id'] ?? 0);
        $exerciseId = (int)($_POST['exercise_id'] ?? 0);
        $performed  = (float)($_POST['performed_weight'] ?? 0);
        $reps       = (int)($_POST['reps'] ?? 0);
        $rpe        = (int)($_POST['rpe'] ?? 0);
        $rir        = (int)($_POST['rir'] ?? 0);
        $formula    = ($_POST['formula'] ?? 'epley') === 'brzycki' ? 'brzycki' : 'epley';

        if (!$memberId || !$exerciseId) throw new RuntimeException('اختر العضو والتمرين.');
        if (!prog_allowed($pdo, $isCoach, $myCoachId, $memberId)) throw new RuntimeException('هذا العضو خارج صلاحيتك.');
        if ($performed <= 0 || $reps < 1) throw new RuntimeException('أدخل وزنًا وتكرارًا صحيحين.');
        if ($rpe < 1 || $rpe > 10) throw new RuntimeException('RPE بين 1 و 10.');
        if ($rir < 0 || $rir > 5) throw new RuntimeException('RIR بين 0 و 5.');
        // اتساق منطقي: الوصول للفشل (RPE 10) يعني لا تكرارات في الاحتياط (RIR 0)
        if ($rpe >= 10 && $rir > 0) throw new RuntimeException('RPE 10 يعني الوصول للفشل — يجب أن يكون RIR = 0.');

        // 1RM السابق (آخر سجلّ لنفس العضو/التمرين) لحماية الانحدار
        $pq = $pdo->prepare("SELECT one_rep_max FROM training_max
                             WHERE member_id = ? AND exercise_id = ? ORDER BY calculated_on DESC, max_id DESC LIMIT 1");
        $pq->execute([$memberId, $exerciseId]);
        $prev1rm = ($v = $pq->fetchColumn()) !== false ? (float)$v : null;

        $d = adaptive_load_decision($performed, $reps, $rpe, $rir, $formula, $prev1rm);

        $pdo->prepare("INSERT INTO training_max
              (member_id, exercise_id, formula, performed_weight, reps, rpe, rir, one_rep_max, next_weight, action)
              VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$memberId, $exerciseId, $d['formula'], $performed, $reps, $rpe, $rir,
                       $d['est_1rm'], $d['next_weight'], $d['action']]);

        header('Location: progression.php?member=' . $memberId . '&ex=' . $exerciseId . '&ok=1');
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر التسجيل: ' . $e->getMessage();
    }
}

page_head('التتبّع التكيّفي', 'progression');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم تسجيل الأداء واحتساب الحمل القادم ✓</div>';

$scope = $isCoach ? " AND m.coach_id = " . $myCoachId : "";
$memberId = (int)($_GET['member'] ?? 0);

// ── وضع القائمة: اختيار عضو ──
if (!$memberId || !prog_allowed($pdo, $isCoach, $myCoachId, $memberId)) {
    $members = $pdo->query("SELECT m.member_id, m.full_name, m.status,
        (SELECT COUNT(*) FROM training_max t WHERE t.member_id=m.member_id) logs,
        (SELECT MAX(t.calculated_on) FROM training_max t WHERE t.member_id=m.member_id) last_log
        FROM members m WHERE 1=1 $scope ORDER BY m.full_name")->fetchAll();
    ?>
    <div style="margin-bottom:6px"><h2 style="margin:0">📈 المتتبّع التكيّفي للأحمال</h2></div>
    <p class="muted" style="font-size:13px;margin-top:0">سجّل مجموعة اختبار لكل عضو/تمرين ليقترح النظام <strong>وزن العمل</strong> القادم آليًا (مشتقًّا من الوزن المُؤدَّى، مع تقدير 1RM للاتجاه).</p>
    <table><tr><th>العضو</th><th>الحالة</th><th>سجلّات</th><th>آخر قياس</th><th></th></tr>
      <?php foreach ($members as $m): ?>
        <tr><td><strong><?=h($m['full_name'])?></strong></td>
          <td><span class="badge" style="background:#6b7280"><?=h($m['status'])?></span></td>
          <td><?=(int)$m['logs']?></td>
          <td class="muted"><?=h($m['last_log'] ? substr($m['last_log'],0,10) : '—')?></td>
          <td><a class="link" href="progression.php?member=<?=(int)$m['member_id']?>">افتح ←</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$members): ?><tr><td colspan="5" class="muted">لا أعضاء.</td></tr><?php endif; ?>
    </table>
    <?php page_foot(); exit;
}

// ── وضع العضو ──
$mem = $pdo->prepare("SELECT full_name, status FROM members WHERE member_id = ?");
$mem->execute([$memberId]); $mem = $mem->fetch();
$exercises = $pdo->query("SELECT exercise_id, name, muscle_group FROM exercises ORDER BY muscle_group, name")->fetchAll();
$selEx = (int)($_GET['ex'] ?? 0);

// أحدث قياس لكل تمرين (وزن العمل الحالي + 1RM) مع الفرق عن القياس السابق
$maxRows = $pdo->prepare("
  SELECT t.*, e.name ex_name, e.muscle_group,
    (SELECT p.one_rep_max FROM training_max p
       WHERE p.member_id=t.member_id AND p.exercise_id=t.exercise_id AND p.calculated_on < t.calculated_on
       ORDER BY p.calculated_on DESC, p.max_id DESC LIMIT 1) prev_1rm
  FROM training_max t
  JOIN exercises e ON e.exercise_id = t.exercise_id
  JOIN (SELECT exercise_id, MAX(calculated_on) mx FROM training_max WHERE member_id = ? GROUP BY exercise_id) last
    ON last.exercise_id = t.exercise_id AND last.mx = t.calculated_on
  WHERE t.member_id = ?
  ORDER BY e.muscle_group, e.name");
$maxRows->execute([$memberId, $memberId]);
$maxRows = $maxRows->fetchAll();

// آخر تسجيل مباشرة (بطاقة القرار) — لتمرين محدَّد إن مُرِّر، وإلا الأحدث عمومًا
$lastLog = null;
if ($selEx) {
    $lq = $pdo->prepare("SELECT t.*, e.name ex_name FROM training_max t JOIN exercises e ON e.exercise_id=t.exercise_id
                         WHERE t.member_id = ? AND t.exercise_id = ? ORDER BY t.calculated_on DESC, t.max_id DESC LIMIT 1");
    $lq->execute([$memberId, $selEx]);
} else {
    $lq = $pdo->prepare("SELECT t.*, e.name ex_name FROM training_max t JOIN exercises e ON e.exercise_id=t.exercise_id
                         WHERE t.member_id = ? ORDER BY t.calculated_on DESC, t.max_id DESC LIMIT 1");
    $lq->execute([$memberId]);
}
$lastLog = $lq->fetch() ?: null;

// اتجاه 1RM للتمرين المختار (أو الأكثر تسجيلًا)
$trendEx = $selEx;
if (!$trendEx && $lastLog) $trendEx = (int)$lastLog['exercise_id'];
$trend = [];
if ($trendEx) {
    $tq = $pdo->prepare("SELECT DATE(calculated_on) d, one_rep_max, next_weight, performed_weight
                         FROM training_max WHERE member_id = ? AND exercise_id = ?
                         ORDER BY calculated_on ASC, max_id ASC");
    $tq->execute([$memberId, $trendEx]);
    $trend = $tq->fetchAll();
}

$actionMeta = [
  'up'   => ['#16a34a', '▲ زِد الحمل'],
  'hold' => ['#f59e0b', '■ ثبّت'],
  'warn' => ['#dc2626', '▼ خفّف'],
];
$fmt = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
?>
<div class="crumb"><a class="link" href="progression.php">→ كل الأعضاء</a> / <strong><?=h($mem['full_name'])?></strong></div>
<div style="margin-bottom:6px"><h2 style="margin:0">📈 التتبّع التكيّفي — <?=h($mem['full_name'])?></h2></div>
<nav class="tabbar" aria-label="أقسام التتبّع">
  <button type="button" data-tabtarget="log">➕ تسجيل أداء</button>
  <button type="button" data-tabtarget="maxes">🏋️ الأحمال الحالية</button>
  <button type="button" data-tabtarget="trend">📉 اتجاه 1RM</button>
</nav>

<!-- ===== تسجيل أداء ===== -->
<section data-tab="log">
  <h2>➕ سجّل مجموعة اختبار</h2>
  <?php if ($lastLog): [$ac,$al] = $actionMeta[$lastLog['action']] ?? ['#6b7280','—']; ?>
    <div class="card" style="--c:<?=$ac?>;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0"><?=h($lastLog['ex_name'])?> — آخر قرار</h3>
        <span class="badge" style="background:<?=$ac?>;font-size:13px"><?=$al?></span>
      </div>
      <div class="kpis" style="margin:10px 0 0">
        <div class="card" style="--c:#0891b2"><div class="n" style="font-size:22px"><?=$fmt($lastLog['performed_weight'])?></div><div class="l">الوزن المُؤدَّى (كجم)</div></div>
        <div class="card" style="--c:#6366f1"><div class="n" style="font-size:22px"><?=$fmt($lastLog['one_rep_max'])?></div><div class="l">1RM المقدَّر (كجم)</div></div>
        <div class="card" style="--c:<?=$ac?>"><div class="n" style="font-size:22px"><?=$fmt($lastLog['next_weight'])?></div><div class="l">وزن العمل القادم (كجم)</div></div>
        <div class="card" style="--c:#6b7280"><div class="n" style="font-size:16px"><?=(int)$lastLog['reps']?>×<?=$fmt($lastLog['performed_weight'])?><br>RPE <?=(int)$lastLog['rpe']?> · RIR <?=(int)$lastLog['rir']?></div><div class="l"><?=h($lastLog['formula'])?></div></div>
      </div>
      <p class="muted" style="font-size:12px;margin:8px 0 0">💡 وزن العمل القادم مشتقّ من <strong>الوزن المُؤدَّى</strong> (<?=$fmt($lastLog['performed_weight'])?> كجم) لا من الـ1RM — الـ1RM حِمل تكرار واحد يُستخدم لتتبّع الاتجاه فقط.</p>
    </div>
  <?php endif; ?>
  <form class="frm" method="post" style="align-items:end"><?=csrf_field()?>
    <input type="hidden" name="member_id" value="<?=$memberId?>">
    <div style="grid-column:span 2"><label>التمرين *</label><select name="exercise_id" required>
      <option value="">— اختر —</option>
      <?php $cg=''; foreach ($exercises as $ex): if ($ex['muscle_group']!==$cg){ if($cg!=='')echo '</optgroup>'; $cg=$ex['muscle_group']; echo '<optgroup label="'.h($cg).'">'; } ?>
        <option value="<?=(int)$ex['exercise_id']?>" <?=$selEx===(int)$ex['exercise_id']?'selected':''?>><?=h($ex['name'])?></option>
      <?php endforeach; if($cg!=='')echo '</optgroup>'; ?>
    </select></div>
    <div><label>الوزن المُؤدَّى (كجم) *</label><input type="number" step="0.5" min="1" name="performed_weight" required placeholder="مثال: 60"></div>
    <div><label>التكرارات *</label><input type="number" step="1" min="1" max="20" name="reps" required placeholder="مثال: 8"></div>
    <div><label>RPE (1-10) *</label><input type="number" step="1" min="1" max="10" name="rpe" required placeholder="مثال: 8"></div>
    <div><label>RIR (0-5)</label><input type="number" step="1" min="0" max="5" name="rir" value="2"></div>
    <div><label>المعادلة</label><select name="formula"><option value="epley">Epley</option><option value="brzycki">Brzycki</option></select></div>
    <div><button type="submit">سجّل واحسب</button></div>
  </form>
  <p class="muted" style="font-size:12px;margin:8px 0 0">
    القرار من الجهد: <strong>RPE ≤ 8 و RIR ≥ 2</strong> → زيادة ~3% (أدنى +2.5) · <strong>RPE 9 / RIR 1</strong> → تثبيت · <strong>RPE 10 / RIR 0</strong> → تخفيف ~5% وتنبيه.
  </p>
</section>

<!-- ===== الأحمال الحالية ===== -->
<section data-tab="maxes">
  <h2>🏋️ الأحمال الحالية لكل تمرين</h2>
  <table>
    <tr><th>التمرين</th><th>المجموعة</th><th>1RM المقدَّر</th><th>الاتجاه</th><th>وزن العمل القادم</th><th>القرار</th><th>آخر قياس</th></tr>
    <?php foreach ($maxRows as $r):
      [$ac,$al] = $actionMeta[$r['action']] ?? ['#6b7280','—'];
      $delta = ($r['prev_1rm']!==null) ? (float)$r['one_rep_max'] - (float)$r['prev_1rm'] : null;
      $arrow = $delta===null ? '<span class="muted">جديد</span>'
             : ($delta > 0.05 ? '<span style="color:#16a34a">▲ '.$fmt($delta).'</span>'
             : ($delta < -0.05 ? '<span style="color:#dc2626">▼ '.$fmt(abs($delta)).'</span>'
             : '<span class="muted">= ثابت</span>')); ?>
      <tr>
        <td><strong><?=h($r['ex_name'])?></strong></td>
        <td class="muted"><?=h($r['muscle_group'])?></td>
        <td><?=$fmt($r['one_rep_max'])?></td>
        <td><?=$arrow?></td>
        <td><strong><?=$fmt($r['next_weight'])?></strong></td>
        <td><span class="badge" style="background:<?=$ac?>"><?=$al?></span></td>
        <td class="muted"><?=h(substr($r['calculated_on'],0,10))?> · <a class="link" href="progression.php?member=<?=$memberId?>&ex=<?=(int)$r['exercise_id']?>#trend">اتجاه</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$maxRows): ?><tr><td colspan="7" class="muted">لا سجلّات بعد — ابدأ بتسجيل مجموعة اختبار.</td></tr><?php endif; ?>
  </table>
</section>

<!-- ===== اتجاه 1RM ===== -->
<section data-tab="trend">
  <h2>📉 اتجاه القوة (1RM ووزن العمل)</h2>
  <?php if (count($trend) >= 1):
    $exName = '';
    foreach ($exercises as $ex) if ((int)$ex['exercise_id']===$trendEx) { $exName=$ex['name']; break; }
    $series = [
      ['label'=>'1RM المقدَّر','color'=>'#6366f1','points'=>array_map(fn($t)=>[$t['d'],(float)$t['one_rep_max']],$trend)],
      ['label'=>'وزن العمل القادم','color'=>'#16a34a','points'=>array_map(fn($t)=>[$t['d'],(float)$t['next_weight']],$trend)],
      ['label'=>'الوزن المُؤدَّى','color'=>'#0891b2','points'=>array_map(fn($t)=>[$t['d'],(float)$t['performed_weight']],$trend)],
    ]; ?>
    <p class="muted" style="font-size:13px;margin-top:0">التمرين: <strong><?=h($exName)?></strong> — لاحظ أن وزن العمل يبقى دون 1RM (كما ينبغي).</p>
    <?=line_chart($series)?>
  <?php else: ?>
    <p class="muted">اختر تمرينًا له سجلّان أو أكثر لعرض الاتجاه (من جدول «الأحمال الحالية» أو بعد التسجيل).</p>
  <?php endif; ?>
</section>
<?php page_foot();

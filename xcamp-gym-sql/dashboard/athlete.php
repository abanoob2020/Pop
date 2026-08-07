<?php
// =============================================================================
// Xcamp Gym — لوحة الرياضي اليومية: مركز قيادة المدرب لكل عضو. تجمع مقاييسه
// الحقيقية (التقييم/التغذية/البرنامج/الجاهزية) في بطاقات + تنبيهات + توصيات ذكية.
// الكابتن مقيَّد بأعضائه؛ المدير يرى الكل. تعتمد athlete_dashboard() في training.php.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();
$isCoach = ($me['role'] === 'coach');
$myCoachId = $isCoach ? (int)($me['coach_id'] ?? 0) : 0;

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

page_head('لوحة الرياضي', 'athlete');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }

$scope = $isCoach ? " AND m.coach_id = " . $myCoachId : "";

/** يتأكّد أن العضو ضمن صلاحية المستخدم. */
function ath_allowed(PDO $pdo, bool $isCoach, int $coachId, int $memberId): bool {
    if (!$isCoach) return true;
    $q = $pdo->prepare("SELECT 1 FROM members WHERE member_id = ? AND coach_id = ?");
    $q->execute([$memberId, $coachId]);
    return (bool)$q->fetchColumn();
}

$memberId = (int)($_GET['member'] ?? 0);

// وضع القائمة
if (!$memberId || !ath_allowed($pdo, $isCoach, $myCoachId, $memberId)) {
    $members = $pdo->query("SELECT m.member_id, m.full_name, m.status,
        (SELECT MAX(a.assessment_date) FROM member_assessments a WHERE a.member_id=m.member_id) last_asm
        FROM members m WHERE 1=1 $scope ORDER BY m.full_name")->fetchAll();
    ?>
    <div style="margin-bottom:6px"><h2 style="margin:0">🎯 لوحة الرياضي اليومية</h2></div>
    <p class="muted" style="font-size:13px;margin-top:0">اختر عضوًا لعرض مركز قيادته: المقاييس + التنبيهات + التوصيات الذكية.</p>
    <table><tr><th>العضو</th><th>الحالة</th><th>آخر تقييم</th><th></th></tr>
      <?php foreach ($members as $m): ?>
        <tr><td><strong><?=h($m['full_name'])?></strong></td>
          <td><span class="badge" style="background:#6b7280"><?=h($m['status'])?></span></td>
          <td class="muted"><?=h($m['last_asm'] ?? '—')?></td>
          <td><a class="link" href="athlete.php?member=<?=(int)$m['member_id']?>">افتح اللوحة ←</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$members): ?><tr><td colspan="4" class="muted">لا أعضاء.</td></tr><?php endif; ?>
    </table>
    <?php page_foot(); exit;
}

// ── تجميع البيانات الحقيقية للعضو ──
$mem = $pdo->prepare("SELECT full_name, status, coach_id FROM members WHERE member_id = ?");
$mem->execute([$memberId]); $mem = $mem->fetch();

// أحدث تقييم + التوصية الإكلينيكية (للخطر والمرحلة)
$asm = $pdo->prepare("SELECT * FROM member_assessments WHERE member_id = ? ORDER BY assessment_date DESC, assessment_id DESC LIMIT 1");
$asm->execute([$memberId]); $asm = $asm->fetch();
$riskNum = 0; $phaseAr = null;
if ($asm) {
    $aid = (int)$asm['assessment_id'];
    $fmsMap = []; foreach ($pdo->query("SELECT movement, score FROM assessment_fms WHERE assessment_id = $aid") as $r) $fmsMap[$r['movement']] = (int)$r['score'];
    $postMap = []; foreach ($pdo->query("SELECT finding FROM assessment_posture WHERE assessment_id = $aid AND present = 1") as $r) $postMap[$r['finding']] = 1;
    $imbMap = []; foreach ($pdo->query("SELECT region, weak, tight FROM assessment_imbalances WHERE assessment_id = $aid") as $r) $imbMap[$r['region']] = ['weak'=>(int)$r['weak'],'tight'=>(int)$r['tight']];
    $jp = (int)($pdo->query("SELECT answer FROM assessment_parq WHERE assessment_id = $aid AND item='joint_back_pain'")->fetchColumn() ?: 0);
    if ($fmsMap || $postMap || $imbMap) {
        $R = assessment_clinical_reco($fmsMap, $postMap, $imbMap, (bool)$jp, $asm['goal_primary']);
        $riskNum = (int)$R['risk']; $phaseAr = $R['phase_ar'];
    }
}

// خطة التغذية النشطة
$nut = $pdo->prepare("SELECT calories, protein_g FROM nutrition_plans WHERE member_id = ? AND status='active' ORDER BY nutrition_plan_id DESC LIMIT 1");
$nut->execute([$memberId]); $nut = $nut->fetch();

// البرنامج النشط
$plan = $pdo->prepare("SELECT goal_type, phase, start_date, end_date FROM workout_plans WHERE member_id = ? AND status='active' ORDER BY start_date DESC, workout_plan_id DESC LIMIT 1");
$plan->execute([$memberId]); $plan = $plan->fetch();

// الجاهزية/الإرهاق من متوسط RPE لآخر ٣٠ يومًا (readiness_score)
$rp = $pdo->prepare("SELECT AVG(se.rpe) FROM session_exercises se
                     JOIN workout_sessions ws ON ws.session_id = se.session_id
                     JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                     WHERE wp.member_id = ? AND se.rpe IS NOT NULL AND ws.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$rp->execute([$memberId]); $avgRpe = $rp->fetchColumn();
$avgRpe = $avgRpe !== null ? (float)$avgRpe : null;
[$readyScore, , ] = readiness_score($avgRpe, 0, 0);
$fatigue = 100 - $readyScore;

// حضور ٣٠ يومًا
$q = $pdo->prepare("SELECT COUNT(*) FROM daily_attendance WHERE member_id = ? AND attended=1 AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$q->execute([$memberId]); $att30 = (int)$q->fetchColumn();

$GOAL_AR = ['fat_loss'=>'خسارة دهون','muscle_gain'=>'بناء عضل','strength'=>'قوة','rehab'=>'تأهيل','performance'=>'أداء','general_fitness'=>'لياقة عامة'];
$programLabel = $plan ? (($GOAL_AR[$plan['goal_type']] ?? $plan['goal_type']) . ($plan['phase'] ? ' · ' . $plan['phase'] : '')) : 'لا برنامج نشط';

$D = athlete_dashboard([
    'name'         => $mem['full_name'],
    'program'      => $programLabel,
    'bmi'          => $asm['bmi'] ?? 0,
    'weight'       => $asm['weight_kg'] ?? 0,
    'body_fat'     => $asm['body_fat_pct'] ?? 0,
    'calories'     => $nut['calories'] ?? 0,
    'protein'      => $nut['protein_g'] ?? 0,
    'risk_num'     => $riskNum,
    'fatigue'      => $fatigue,
    'sleep_hours'  => $asm['sleep_hours'] ?? null,
    'attendance30' => $att30,
]);

$alertBg = ['danger'=>['#fef2f2','#fecaca','#991b1b'],'warning'=>['#fffbeb','#fde68a','#92400e'],'info'=>['#eff6ff','#bfdbfe','#1e40af']];
$prColor = ['high'=>'#dc2626','medium'=>'#f59e0b','low'=>'#16a34a'];
$catAr   = ['training'=>'تدريب','nutrition'=>'تغذية','recovery'=>'تعافٍ'];
?>
<div class="crumb"><a class="link" href="athlete.php">→ كل الأعضاء</a> / <strong><?=h($mem['full_name'])?></strong></div>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px">
  <div><h2 style="margin:0">🎯 <?=h($mem['full_name'])?></h2>
    <span class="muted" style="font-size:12.5px"><?=h($programLabel)?> · حضور ٣٠ي: <?=$att30?><?= $avgRpe!==null ? ' · متوسط RPE: '.round($avgRpe,1) : '' ?></span></div>
  <div style="display:flex;gap:8px">
    <?php if ($asm): ?><a href="assess.php?a=<?=(int)$asm['assessment_id']?>" style="background:#eef2ff;color:#3730a3;border:0;padding:8px 14px;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px">📋 التقييم</a><?php endif; ?>
    <a href="captains.php?member=<?=$memberId?>" style="background:#ecfdf5;color:#065f46;border:0;padding:8px 14px;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px">🏋️ البرنامج</a>
  </div>
</div>

<div style="background:linear-gradient(90deg,#111827,#1f2937);color:#fff;border-radius:12px;padding:14px 18px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div style="font-size:15px;font-weight:800"><?=h($D['summary'])?></div>
  <span class="badge" style="background:<?= $D['ready_to_train']?'#16a34a':($D['needs_rest']?'#dc2626':'#f59e0b') ?>;font-size:13px"><?=h($D['training_reco'])?></span>
</div>

<!-- البطاقات -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px">
  <?php foreach ($D['cards'] as $c): ?>
    <div style="border:1px solid #eef2f7;border-inline-start:5px solid <?=$c['color']?>;border-radius:10px;padding:12px 14px;background:#fff">
      <div style="font-size:12px;color:#64748b"><?=$c['icon']?> <?=h($c['label'])?></div>
      <div style="font-size:24px;font-weight:800;color:<?=$c['color']?>;line-height:1.1;margin-top:4px"><?=h($c['value'])?><span style="font-size:12px;color:#94a3b8;font-weight:600"> <?=h($c['unit'])?></span></div>
      <?php if ($c['sub']): ?><div class="muted" style="font-size:11px;margin-top:2px"><?=h($c['sub'])?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid2">
  <!-- التنبيهات -->
  <div>
    <h3>🔔 التنبيهات (<?=count($D['alerts'])?>)</h3>
    <?php if (!$D['alerts']): ?>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:12px 14px">✅ لا تنبيهات — كل المؤشرات ضمن النطاق.</div>
    <?php else: foreach ($D['alerts'] as $a): [$bg,$bd,$fg] = $alertBg[$a['type']] ?? $alertBg['info']; ?>
      <div style="background:<?=$bg?>;border:1px solid <?=$bd?>;border-radius:10px;padding:10px 12px;margin-bottom:8px">
        <div style="font-weight:700;color:<?=$fg?>;font-size:13px"><?=$a['icon']?> <?=h($a['message'])?></div>
        <div class="muted" style="font-size:12px;margin-top:2px">↳ <?=h($a['action'])?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <!-- التوصيات -->
  <div>
    <h3>🧠 التوصيات (<?=count($D['recommendations'])?>)</h3>
    <?php foreach ($D['recommendations'] as $r): ?>
      <div style="border:1px solid #eef2f7;border-inline-start:4px solid <?=$prColor[$r['priority']] ?? '#94a3b8'?>;border-radius:10px;padding:10px 12px;margin-bottom:8px">
        <span class="badge" style="background:<?=$prColor[$r['priority']] ?? '#94a3b8'?>;font-size:10px"><?=h($catAr[$r['category']] ?? $r['category'])?></span>
        <span style="font-size:13px;margin-inline-start:6px"><?=h($r['text'])?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$asm): ?>
  <div class="muted" style="font-size:12.5px;margin-top:10px">💡 لا يوجد تقييم لهذا العضو بعد — بعض المقاييس (BMI/الخطر/النوم) ستظهر بعد إجراء <a class="link" href="assess.php?member=<?=$memberId?>">تقييم</a>.</div>
<?php endif; ?>
<?php page_foot();

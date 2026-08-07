<?php
// =============================================================================
// Xcamp Gym — التقييم الشامل للعضو: التقاط الفحص الصحي (PAR-Q) + نمط الحياة +
// الأهداف + تركيب الجسم + المحيطات. الكابتن مقيَّد بأعضائه؛ المدير يرى الكل.
// يُطبع بنفس تصميم استمارة XCAMP عبر assessment_print.php.
// =============================================================================
require __DIR__ . '/db.php';
$me  = require_login();
$uid = (int)($me['uid'] ?? 0) ?: null;
$isCoach = ($me['role'] === 'coach');
$myCoachId = $isCoach ? (int)($me['coach_id'] ?? 0) : 0;

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

// قوائم الحقول (المفتاح => التسمية)
$GOALS = ['muscle_gain'=>'بناء عضل','fat_loss'=>'خسارة دهون','recomposition'=>'إعادة تكوين','strength'=>'قوة',
          'athletic'=>'أداء رياضي','health_fitness'=>'صحة ولياقة','rehab'=>'تأهيل','posture'=>'تحسين القوام',
          'flexibility'=>'مرونة','other'=>'أخرى'];
$PARQ = ['heart_disease'=>'أمراض قلب / ضغط','diabetes'=>'سكري','respiratory'=>'مشاكل تنفّس (ربو)',
         'joint_back_pain'=>'ألم مفاصل / ظهر','dizziness'=>'دوخة / إغماء','surgery'=>'جراحة سابقة',
         'medication'=>'أدوية حالية','smoking'=>'تدخين','pregnancy'=>'حمل','allergies'=>'حساسية'];
$SITES = ['neck'=>'الرقبة','shoulder'=>'الكتف','chest'=>'الصدر','upper_chest'=>'أعلى الصدر','waist'=>'الخصر',
          'abdomen'=>'البطن','hip'=>'الورك','glutes'=>'الأرداف','arm_relaxed'=>'الذراع (مرتخٍ)',
          'arm_flexed'=>'الذراع (مشدود)','forearm'=>'الساعد','thigh_mid'=>'الفخذ (وسط)',
          'thigh_upper'=>'الفخذ (أعلى)','calf'=>'السمانة','ankle'=>'الكاحل'];
$SLEEPQ = ['poor'=>'ضعيف','fair'=>'مقبول','good'=>'جيد','excellent'=>'ممتاز'];
$JOBS   = ['sitting'=>'مكتبي','standing'=>'واقف','physical'=>'بدني'];
$LMH    = ['low'=>'منخفض','medium'=>'متوسط','high'=>'مرتفع'];
$CAFF   = ['none'=>'لا','low'=>'قليل','moderate'=>'متوسط','high'=>'كثير'];
$ACT    = ['low'=>'منخفض','moderate'=>'متوسط','high'=>'مرتفع'];
$DOC    = ['na'=>'غير محدَّد','yes'=>'نعم','no'=>'لا'];
// الطبقة الإكلينيكية
$FMS = ['deep_squat'=>'قرفصاء عميقة','hurdle_step'=>'خطوة الحاجز','inline_lunge'=>'طعنة على خط',
        'shoulder_mobility'=>'حركية الكتف','active_slr'=>'رفع الساق النشط','trunk_stability'=>'ثبات الجذع (دفع)',
        'rotary_stability'=>'ثبات دوراني','single_leg_balance'=>'توازن ساق واحدة','overhead_reach'=>'مدّ علوي',
        'hip_hinge'=>'مفصلة الورك','thoracic_rotation'=>'دوران صدري'];
$FMS_SC = [3=>'٣ طبيعي',2=>'٢ تعويض',1=>'١ عاجز',0=>'٠ ألم'];
$POST = ['forward_head'=>'رأس أمامي','rounded_shoulders'=>'كتف مدوّر','kyphosis'=>'تحدّب صدري','lordosis'=>'تقعّر قطني',
         'anterior_pelvic_tilt'=>'ميل حوضي أمامي','posterior_pelvic_tilt'=>'ميل حوضي خلفي','knee_valgus'=>'ركبة للداخل',
         'knee_varus'=>'ركبة للخارج','scapular_winging'=>'جناحية اللوح','leg_length'=>'فرق طول الساقين','foot_arch'=>'قوس القدم'];
$REG = ['chest'=>'الصدر','upper_back'=>'الظهر العلوي','lower_back'=>'أسفل الظهر','shoulders'=>'الأكتاف','biceps'=>'البايسبس',
        'triceps'=>'الترايسبس','glutes'=>'الأرداف','hamstrings'=>'أوتار الركبة','quads'=>'الأمامية','calves'=>'السمانة'];

/** يتأكّد أن العضو ضمن صلاحية المستخدم (المدير: الكل؛ الكابتن: أعضاؤه). */
function asm_member_allowed(PDO $pdo, bool $isCoach, int $coachId, int $memberId): bool {
    if (!$isCoach) return true;
    $q = $pdo->prepare("SELECT 1 FROM members WHERE member_id = ? AND coach_id = ?");
    $q->execute([$memberId, $coachId]);
    return (bool)$q->fetchColumn();
}

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        if (($_POST['action'] ?? '') === 'save_assessment') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if (!$memberId) throw new RuntimeException('اختر عضوًا.');
            if (!asm_member_allowed($pdo, $isCoach, $myCoachId, $memberId)) throw new RuntimeException('غير مسموح — العضو ليس ضمن أعضائك.');
            $aid  = (int)($_POST['assessment_id'] ?? 0);
            $date = ($_POST['assessment_date'] ?? '') ?: date('Y-m-d');
            $status = ($_POST['status'] ?? 'draft') === 'final' ? 'final' : 'draft';
            $enum = fn($k, $set) => isset($set[$_POST[$k] ?? '']) ? $_POST[$k] : null;
            $num  = fn($k) => ($_POST[$k] ?? '') !== '' ? $_POST[$k] : null;
            $fields = [
                'goal_primary'   => $enum('goal_primary', $GOALS),
                'doctor_approval'=> isset($DOC[$_POST['doctor_approval'] ?? '']) ? $_POST['doctor_approval'] : 'na',
                'health_notes'   => trim($_POST['health_notes'] ?? '') ?: null,
                'sleep_hours'    => $num('sleep_hours'), 'sleep_quality' => $enum('sleep_quality', $SLEEPQ),
                'water_liters'   => $num('water_liters'), 'daily_steps' => $num('daily_steps'),
                'sitting_hours'  => $num('sitting_hours'), 'job_type' => $enum('job_type', $JOBS),
                'stress_level'   => $enum('stress_level', $LMH), 'caffeine' => $enum('caffeine', $CAFF),
                'smoking'        => isset($_POST['smoking']) ? 1 : 0, 'alcohol' => isset($_POST['alcohol']) ? 1 : 0,
                'activity_level' => $enum('activity_level', $ACT),
                'weight_kg'      => $num('weight_kg'), 'body_fat_pct' => $num('body_fat_pct'),
                'muscle_mass_kg' => $num('muscle_mass_kg'), 'lean_mass_kg' => $num('lean_mass_kg'),
                'water_pct'      => $num('water_pct'), 'bmi' => $num('bmi'), 'bmr_kcal' => $num('bmr_kcal'),
                'tdee_kcal'      => $num('tdee_kcal'), 'metabolic_age' => $num('metabolic_age'),
                'overall_score'  => $num('overall_score'), 'next_goal' => trim($_POST['next_goal'] ?? '') ?: null,
                'notes'          => trim($_POST['notes'] ?? '') ?: null,
            ];
            $pdo->beginTransaction();
            if ($aid) {
                // تأكيد ملكية التقييم ضمن نفس العضو/الصلاحية
                $chk = $pdo->prepare("SELECT member_id FROM member_assessments WHERE assessment_id = ?");
                $chk->execute([$aid]);
                $owner = $chk->fetchColumn();
                if ($owner === false || (int)$owner !== $memberId) throw new RuntimeException('تقييم غير صالح.');
                $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields)));
                $pdo->prepare("UPDATE member_assessments SET assessment_date=?, status=?, $set WHERE assessment_id=?")
                    ->execute(array_merge([$date, $status], array_values($fields), [$aid]));
            } else {
                $cols = array_keys($fields);
                $ph   = implode(',', array_fill(0, count($cols) + 4, '?'));
                $pdo->prepare("INSERT INTO member_assessments (member_id, assessed_by, assessment_date, status, " . implode(',', $cols) . ") VALUES ($ph)")
                    ->execute(array_merge([$memberId, $uid, $date, $status], array_values($fields)));
                $aid = (int)$pdo->lastInsertId();
            }
            // PAR-Q (استبدال كامل)
            $pdo->prepare("DELETE FROM assessment_parq WHERE assessment_id = ?")->execute([$aid]);
            $insP = $pdo->prepare("INSERT INTO assessment_parq (assessment_id, item, answer) VALUES (?,?,?)");
            foreach (array_keys($PARQ) as $it) $insP->execute([$aid, $it, isset($_POST['parq'][$it]) ? 1 : 0]);
            // المحيطات (استبدال كامل — تُكتب المواضع ذات القيم فقط)
            $pdo->prepare("DELETE FROM assessment_measurements WHERE assessment_id = ?")->execute([$aid]);
            $insM = $pdo->prepare("INSERT INTO assessment_measurements (assessment_id, site, right_cm, left_cm) VALUES (?,?,?,?)");
            foreach (array_keys($SITES) as $s) {
                $r = ($_POST['m_r'][$s] ?? '') !== '' ? (float)$_POST['m_r'][$s] : null;
                $l = ($_POST['m_l'][$s] ?? '') !== '' ? (float)$_POST['m_l'][$s] : null;
                if ($r !== null || $l !== null) $insM->execute([$aid, $s, $r, $l]);
            }
            // FMS (استبدال كامل — تُكتب الحركات ذات الدرجة المُدخَلة)
            $pdo->prepare("DELETE FROM assessment_fms WHERE assessment_id = ?")->execute([$aid]);
            $insF = $pdo->prepare("INSERT INTO assessment_fms (assessment_id, movement, score) VALUES (?,?,?)");
            foreach (array_keys($FMS) as $mv) {
                $s = $_POST['fms'][$mv] ?? '';
                if ($s !== '' && $s >= 0 && $s <= 3) $insF->execute([$aid, $mv, (int)$s]);
            }
            // القوام (الانحرافات المُعلَّمة فقط)
            $pdo->prepare("DELETE FROM assessment_posture WHERE assessment_id = ?")->execute([$aid]);
            $insP2 = $pdo->prepare("INSERT INTO assessment_posture (assessment_id, finding, present) VALUES (?,?,1)");
            foreach (array_keys($POST) as $f) if (isset($_POST['post'][$f])) $insP2->execute([$aid, $f]);
            // الاختلالات (المناطق ذات علامة ضعف/شدّ فقط)
            $pdo->prepare("DELETE FROM assessment_imbalances WHERE assessment_id = ?")->execute([$aid]);
            $insI = $pdo->prepare("INSERT INTO assessment_imbalances (assessment_id, region, weak, tight) VALUES (?,?,?,?)");
            foreach (array_keys($REG) as $rg) {
                $w = isset($_POST['weak'][$rg]) ? 1 : 0; $t = isset($_POST['tight'][$rg]) ? 1 : 0;
                if ($w || $t) $insI->execute([$aid, $rg, $w, $t]);
            }
            $pdo->commit();
            header('Location: assess.php?a=' . $aid . '&ok=1');
            exit;
        } elseif (($_POST['action'] ?? '') === 'gen_program_from_assessment') {
            $aid = (int)($_POST['assessment_id'] ?? 0);
            $q = $pdo->prepare("SELECT member_id, goal_primary FROM member_assessments WHERE assessment_id = ?");
            $q->execute([$aid]); $row = $q->fetch();
            if (!$row) throw new RuntimeException('تقييم غير موجود.');
            $memberId = (int)$row['member_id'];
            if (!asm_member_allowed($pdo, $isCoach, $myCoachId, $memberId)) throw new RuntimeException('غير مسموح — العضو ليس ضمن أعضائك.');
            // أعد حساب التوصية من بيانات القاعدة (لا تثق بالعميل)
            $fmsMap = []; foreach ($pdo->query("SELECT movement, score FROM assessment_fms WHERE assessment_id = $aid") as $r) $fmsMap[$r['movement']] = (int)$r['score'];
            $postMap = []; foreach ($pdo->query("SELECT finding FROM assessment_posture WHERE assessment_id = $aid AND present = 1") as $r) $postMap[$r['finding']] = 1;
            $imbMap = []; foreach ($pdo->query("SELECT region, weak, tight FROM assessment_imbalances WHERE assessment_id = $aid") as $r) $imbMap[$r['region']] = ['weak' => (int)$r['weak'], 'tight' => (int)$r['tight']];
            if (!$fmsMap && !$postMap && !$imbMap) throw new RuntimeException('أدخِل بيانات FMS/القوام/الاختلالات أولًا لتوليد برنامج.');
            $jp = (int)($pdo->query("SELECT answer FROM assessment_parq WHERE assessment_id = $aid AND item = 'joint_back_pain'")->fetchColumn() ?: 0);
            $R = assessment_clinical_reco($fmsMap, $postMap, $imbMap, (bool)$jp, $row['goal_primary']);
            // معطيات التوليد
            $weeks = max(1, min(12, (int)($_POST['weeks'] ?? 4)));
            $start = date('Y-m-d');
            $end   = date('Y-m-d', strtotime($start) + ($weeks * 7 - 1) * 86400);
            $coachId = (int)($pdo->query("SELECT coach_id FROM members WHERE member_id = $memberId")->fetchColumn() ?: 0) ?: null;
            $goalW = $R['goal_workout']; $phase = $R['phase']; $rx = $R['prescription']; $avoid = $R['avoid_raw'];
            $exByGroup = [];
            foreach ($pdo->query("SELECT exercise_id, name, muscle_group FROM exercises WHERE active = 1 ORDER BY exercise_id") as $ex) $exByGroup[$ex['muscle_group']][] = $ex;
            $blueprint = program_blueprint($goalW);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO workout_plans (member_id, coach_id, goal_type, phase, start_date, end_date, status, notes)
                           VALUES (?,?,?,?,?,?,'active',?)")
                ->execute([$memberId, $coachId, $goalW, $phase, $start, $end,
                           'من التقييم #' . $aid . ' — ' . $rx['label'] . ($R['avoid'] ? ' · تجنّب: ' . implode('،', $R['avoid']) : '')]);
            $planId = (int)$pdo->lastInsertId();
            $insS = $pdo->prepare("INSERT INTO workout_sessions (workout_plan_id, session_date, muscle_group, completion_status, notes) VALUES (?,?,?,'planned',?)");
            $insX = $pdo->prepare("INSERT INTO session_exercises (session_id, exercise_id, sort_order, sets, reps, rest_sec, rpe, notes) VALUES (?,?,?,?,?,?,?,?)");
            $gen = 0;
            foreach ($blueprint as $bp) {
                $picks = select_session_exercises($exByGroup, $bp['focus'], $avoid, 5);
                if (!$picks) continue;
                for ($w = 0; $w < $weeks; $w++) {
                    $date = date('Y-m-d', strtotime($start) + ($w * 7 + (int)$bp['day_offset']) * 86400);
                    $insS->execute([$planId, $date, $bp['muscle_group'], $bp['title']]);
                    $sid = (int)$pdo->lastInsertId(); $so = 1;
                    foreach ($picks as $ex) $insX->execute([$sid, $ex['exercise_id'], $so++, $rx['sets'], $rx['reps'], $rx['rest'], $rx['rpe'], 'مقترح — ' . $rx['label']]);
                    $gen++;
                }
            }
            if ($gen === 0) { $pdo->rollBack(); throw new RuntimeException('تعذّر توليد جلسات — قد تكون كل المجموعات مستبعَدة.'); }
            $pdo->commit();
            header('Location: assess.php?a=' . $aid . '&gen=' . $planId . '&mem=' . $memberId);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('التقييم', 'assess');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم حفظ التقييم بنجاح ✓</div>';
if (isset($_GET['gen'])) { $gp = (int)$_GET['gen']; $gm = (int)($_GET['mem'] ?? 0);
    echo '<div class="flash">🏗️ أُنشئ برنامج تدريبي (#' . $gp . ') من هذا التقييم. <a class="link" href="captains.php?member=' . $gm . '">افتح برنامج العضو ←</a></div>'; }

$scope = $isCoach ? " AND m.coach_id = " . $myCoachId : "";

// تحميل تقييم للتحرير
$openId = (int)($_GET['a'] ?? 0);
$A = null; $parqMap = []; $measMap = [];
if ($openId) {
    $q = $pdo->prepare("SELECT a.*, m.full_name, m.coach_id FROM member_assessments a JOIN members m ON m.member_id=a.member_id WHERE a.assessment_id = ?");
    $q->execute([$openId]); $A = $q->fetch();
    if ($A && $isCoach && (int)$A['coach_id'] !== $myCoachId) $A = null;   // خارج الصلاحية
    if ($A) {
        $p = $pdo->prepare("SELECT item, answer FROM assessment_parq WHERE assessment_id = ?"); $p->execute([$openId]);
        foreach ($p as $r) $parqMap[$r['item']] = (int)$r['answer'];
        $mm = $pdo->prepare("SELECT * FROM assessment_measurements WHERE assessment_id = ?"); $mm->execute([$openId]);
        foreach ($mm as $r) $measMap[$r['site']] = $r;
    }
}
// الطبقة الإكلينيكية للتحرير + التوصيات
$fmsMap = []; $postMap = []; $imbMap = []; $reco = null;
if ($A) {
    $f = $pdo->prepare("SELECT movement, score FROM assessment_fms WHERE assessment_id = ?"); $f->execute([$openId]);
    foreach ($f as $r) $fmsMap[$r['movement']] = (int)$r['score'];
    $ps = $pdo->prepare("SELECT finding FROM assessment_posture WHERE assessment_id = ? AND present = 1"); $ps->execute([$openId]);
    foreach ($ps as $r) $postMap[$r['finding']] = 1;
    $im = $pdo->prepare("SELECT region, weak, tight FROM assessment_imbalances WHERE assessment_id = ?"); $im->execute([$openId]);
    foreach ($im as $r) $imbMap[$r['region']] = ['weak' => (int)$r['weak'], 'tight' => (int)$r['tight']];
    // توصيات آلية (تظهر إن وُجدت مدخلات إكلينيكية)
    if ($fmsMap || $postMap || $imbMap) {
        $reco = assessment_clinical_reco($fmsMap, $postMap, $imbMap, (bool)($parqMap['joint_back_pain'] ?? 0), $A['goal_primary']);
    }
}
// عضو جديد محدَّد
$newMember = null;
$preMember = (int)($_GET['member'] ?? 0);
if (!$A && $preMember && asm_member_allowed($pdo, $isCoach, $myCoachId, $preMember)) {
    $q = $pdo->prepare("SELECT member_id, full_name FROM members WHERE member_id = ?"); $q->execute([$preMember]);
    $newMember = $q->fetch();
}

$val = fn($k, $d = '') => $A !== null ? h($A[$k] ?? '') : $d;   // قيمة حقل عند التحرير

// وضع القائمة (لا تقييم مفتوح ولا عضو محدَّد)
if (!$A && !$newMember) {
    $members = $pdo->query("SELECT m.member_id, m.full_name, m.status,
        (SELECT COUNT(*) FROM member_assessments a WHERE a.member_id=m.member_id) cnt,
        (SELECT MAX(a.assessment_date) FROM member_assessments a WHERE a.member_id=m.member_id) last
        FROM members m WHERE 1=1 $scope ORDER BY m.full_name")->fetchAll();
    $recent = $pdo->query("SELECT a.assessment_id, a.assessment_date, a.status, a.overall_score, m.full_name
        FROM member_assessments a JOIN members m ON m.member_id=a.member_id WHERE 1=1 $scope
        ORDER BY a.assessment_id DESC LIMIT 12")->fetchAll();
    ?>
    <div style="margin-bottom:6px"><h2 style="margin:0">📋 التقييم الشامل للأعضاء</h2></div>
    <p class="muted" style="font-size:13px;margin-top:0">اختر عضوًا لبدء تقييم جديد، أو افتح تقييمًا سابقًا. يُطبع بنفس استمارة XCAMP.</p>
    <div class="grid2">
      <div>
        <h3>👥 الأعضاء</h3>
        <table><tr><th>العضو</th><th>تقييمات</th><th>آخر تقييم</th><th></th></tr>
          <?php foreach ($members as $m): ?>
            <tr><td><strong><?=h($m['full_name'])?></strong></td><td><?=(int)$m['cnt']?></td>
              <td class="muted"><?=h($m['last'] ?? '—')?></td>
              <td><a class="link" href="assess.php?member=<?=(int)$m['member_id']?>">＋ تقييم جديد</a></td></tr>
          <?php endforeach; ?>
          <?php if (!$members): ?><tr><td colspan="4" class="muted">لا أعضاء.</td></tr><?php endif; ?>
        </table>
      </div>
      <div>
        <h3>🕒 أحدث التقييمات</h3>
        <table><tr><th>التاريخ</th><th>العضو</th><th>الدرجة</th><th>الحالة</th><th></th></tr>
          <?php foreach ($recent as $r): ?>
            <tr><td><?=h($r['assessment_date'])?></td><td><?=h($r['full_name'])?></td>
              <td><?= $r['overall_score']!==null ? (int)$r['overall_score'].'%' : '—' ?></td>
              <td><span class="badge" style="background:<?= $r['status']==='final'?'#16a34a':'#f59e0b' ?>"><?= $r['status']==='final'?'نهائي':'مسودّة' ?></span></td>
              <td><a class="link" href="assess.php?a=<?=(int)$r['assessment_id']?>">فتح ←</a></td></tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?><tr><td colspan="5" class="muted">لا تقييمات بعد.</td></tr><?php endif; ?>
        </table>
      </div>
    </div>
    <?php page_foot(); exit;
}

// وضع النموذج (تحرير/جديد)
$memId   = $A ? (int)$A['member_id'] : (int)$newMember['member_id'];
$memName = $A ? $A['full_name'] : $newMember['full_name'];
$sel = fn($k, $v) => ($A !== null && ($A[$k] ?? '') === $v) ? 'selected' : '';
?>
<div class="crumb"><a class="link" href="assess.php">→ كل التقييمات</a> / <strong><?=h($memName)?></strong></div>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
  <h2 style="margin:0">📋 تقييم: <?=h($memName)?></h2>
  <?php if ($A): ?><a href="assessment_print.php?a=<?=$openId?>" target="_blank" style="background:#111827;color:#fbbf24;border:0;padding:8px 16px;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px">🖨️ طباعة الاستمارة</a><?php endif; ?>
</div>

<?php if ($reco): ?>
<div style="background:linear-gradient(180deg,#faf5ff,#fff);border:1px solid #e9d5ff;border-radius:12px;padding:14px 16px;margin:10px 0">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <strong style="font-size:15px">🧠 التوصيات الآلية</strong>
    <span class="badge" style="background:<?=$reco['priority_color']?>">أولوية <?=h($reco['priority'])?> · خطر <?=$reco['risk']?>/100</span>
  </div>
  <div class="kpis" style="margin:10px 0">
    <div class="card" style="--c:#7c3aed"><div class="n" style="font-size:18px"><?=h($reco['phase_ar'])?></div><div class="l">المرحلة المقترحة</div></div>
    <div class="card" style="--c:#2563eb"><div class="n"><?=$reco['prescription']['sets']?>×<?=h($reco['prescription']['reps'])?></div><div class="l">مجموعات×تكرار · RPE <?=$reco['prescription']['rpe']?></div></div>
    <div class="card" style="--c:<?= $reco['fms_total']>=14?'#16a34a':($reco['fms_total']>=11?'#f59e0b':'#dc2626') ?>"><div class="n"><?=$reco['fms_total']?>/33</div><div class="l">مجموع FMS</div></div>
  </div>
  <?php if ($reco['avoid']): ?><div class="f" style="font-size:12.5px;color:#991b1b;margin-bottom:6px">⛔ تجنّب مؤقتًا: <?=h(implode('، ', $reco['avoid']))?></div><?php endif; ?>
  <?php if ($reco['corrective']): ?>
    <strong style="font-size:12.5px;color:#334155">تركيز تصحيحي:</strong>
    <ul style="margin:4px 0 8px;padding-inline-start:20px;font-size:12.5px;color:#334155">
      <?php foreach ($reco['corrective'] as $c): ?><li><?=h($c)?></li><?php endforeach; ?>
    </ul>
  <?php else: ?><div class="muted" style="font-size:12px">لا اختلالات مُعلَّمة — تابع البرنامج وفق الهدف.</div><?php endif; ?>
  <div style="border-top:1px dashed #e9d5ff;margin-top:8px;padding-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="post" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('إنشاء برنامج تدريبي بمرحلة <?=h($reco['phase_ar'])?> (<?=$reco['prescription']['sets']?>×<?=h($reco['prescription']['reps'])?>) من هذا التقييم؟')"><?=csrf_field()?>
      <input type="hidden" name="action" value="gen_program_from_assessment">
      <input type="hidden" name="assessment_id" value="<?=$openId?>">
      <label style="font-size:12px;color:#475569">مدّة (أسابيع)</label>
      <input type="number" name="weeks" value="4" min="1" max="12" style="width:64px;padding:6px;border:1px solid #cbd5e1;border-radius:8px">
      <button type="submit" style="background:#7c3aed;color:#fff;border:0;padding:9px 18px;border-radius:9px;font-weight:800;cursor:pointer">🏗️ أنشئ برنامجًا من هذا التقييم</button>
    </form>
    <span class="muted" style="font-size:11.5px">يبني برنامجًا نشطًا بمرحلة/وصفة التوصية، ويتجنّب المجموعات المُشار إليها.</span>
  </div>
</div>
<?php elseif ($A): ?>
  <div class="muted" style="font-size:12.5px;margin:8px 0">💡 أدخِل FMS/القوام/الاختلالات ثم احفظ لتظهر التوصيات الآلية وزرّ توليد البرنامج.</div>
<?php endif; ?>

<form method="post"><?=csrf_field()?>
  <input type="hidden" name="action" value="save_assessment">
  <input type="hidden" name="member_id" value="<?=$memId?>">
  <?php if ($A): ?><input type="hidden" name="assessment_id" value="<?=$openId?>"><?php endif; ?>

  <div class="frm" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
    <div><label>تاريخ التقييم</label><input type="date" name="assessment_date" value="<?= $A ? h($A['assessment_date']) : date('Y-m-d') ?>" required></div>
    <div><label>الحالة</label><select name="status"><option value="draft" <?=$sel('status','draft')?>>مسودّة</option><option value="final" <?=$sel('status','final')?>>نهائي</option></select></div>
    <div><label>الهدف الأساسي</label><select name="goal_primary"><option value="">—</option><?php foreach ($GOALS as $k=>$v): ?><option value="<?=$k?>" <?=$sel('goal_primary',$k)?>><?=h($v)?></option><?php endforeach; ?></select></div>
    <div><label>الدرجة الكلية (٠-١٠٠)</label><input type="number" min="0" max="100" name="overall_score" value="<?=$val('overall_score')?>"></div>
  </div>

  <nav class="tabbar" aria-label="أقسام التقييم">
    <button type="button" data-tabtarget="health">❤️ الصحة (PAR-Q)</button>
    <button type="button" data-tabtarget="life">🌙 نمط الحياة</button>
    <button type="button" data-tabtarget="body">⚖️ تركيب الجسم</button>
    <button type="button" data-tabtarget="meas">📏 المحيطات</button>
    <button type="button" data-tabtarget="fms">🤸 FMS</button>
    <button type="button" data-tabtarget="posture">🧍 القوام</button>
    <button type="button" data-tabtarget="imbalance">⚠️ الاختلالات</button>
  </nav>

  <!-- الصحة -->
  <section data-tab="health">
    <h2>❤️ التاريخ الصحي (PAR-Q)</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:6px">
      <?php foreach ($PARQ as $k=>$v): $on = $A ? ($parqMap[$k] ?? 0) : 0; ?>
        <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #eef2f7;border-radius:8px;cursor:pointer">
          <input type="checkbox" name="parq[<?=$k?>]" value="1" <?=$on?'checked':''?>><span><?=h($v)?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="frm" style="margin-top:10px">
      <div><label>موافقة الطبيب</label><select name="doctor_approval"><?php foreach ($DOC as $k=>$v): ?><option value="<?=$k?>" <?= $A ? $sel('doctor_approval',$k) : ($k==='na'?'selected':'') ?>><?=h($v)?></option><?php endforeach; ?></select></div>
      <div style="grid-column:1/-1"><label>ملاحظات صحية</label><input name="health_notes" value="<?=$val('health_notes')?>"></div>
    </div>
    <p class="muted" style="font-size:12px">أي بند مُعلَّم = علامة تحتاج انتباه الكابتن قبل وصف الأحمال.</p>
  </section>

  <!-- نمط الحياة -->
  <section data-tab="life">
    <h2>🌙 تقييم نمط الحياة</h2>
    <div class="frm">
      <div><label>نوم/ليلة (ساعات)</label><input type="number" step="0.5" name="sleep_hours" value="<?=$val('sleep_hours')?>"></div>
      <div><label>جودة النوم</label><select name="sleep_quality"><option value="">—</option><?php foreach ($SLEEPQ as $k=>$v): ?><option value="<?=$k?>" <?=$sel('sleep_quality',$k)?>><?=h($v)?></option><?php endforeach; ?></select></div>
      <div><label>ماء/يوم (لتر)</label><input type="number" step="0.5" name="water_liters" value="<?=$val('water_liters')?>"></div>
      <div><label>خطوات/يوم</label><input type="number" name="daily_steps" value="<?=$val('daily_steps')?>"></div>
      <div><label>جلوس/يوم (ساعات)</label><input type="number" step="0.5" name="sitting_hours" value="<?=$val('sitting_hours')?>"></div>
      <div><label>نوع العمل</label><select name="job_type"><option value="">—</option><?php foreach ($JOBS as $k=>$v): ?><option value="<?=$k?>" <?=$sel('job_type',$k)?>><?=h($v)?></option><?php endforeach; ?></select></div>
      <div><label>مستوى التوتّر</label><select name="stress_level"><option value="">—</option><?php foreach ($LMH as $k=>$v): ?><option value="<?=$k?>" <?=$sel('stress_level',$k)?>><?=h($v)?></option><?php endforeach; ?></select></div>
      <div><label>الكافيين</label><select name="caffeine"><option value="">—</option><?php foreach ($CAFF as $k=>$v): ?><option value="<?=$k?>" <?=$sel('caffeine',$k)?>><?=h($v)?></option><?php endforeach; ?></select></div>
      <div><label>النشاط الحالي</label><select name="activity_level"><option value="">—</option><?php foreach ($ACT as $k=>$v): ?><option value="<?=$k?>" <?=$sel('activity_level',$k)?>><?=h($v)?></option><?php endforeach; ?></select></div>
      <div style="display:flex;gap:16px;align-items:center;padding-top:20px">
        <label style="display:flex;gap:6px;align-items:center;cursor:pointer"><input type="checkbox" name="smoking" value="1" <?= $A && $A['smoking'] ? 'checked' : '' ?>>تدخين</label>
        <label style="display:flex;gap:6px;align-items:center;cursor:pointer"><input type="checkbox" name="alcohol" value="1" <?= $A && $A['alcohol'] ? 'checked' : '' ?>>كحول</label>
      </div>
    </div>
  </section>

  <!-- تركيب الجسم -->
  <section data-tab="body">
    <h2>⚖️ تركيب الجسم</h2>
    <div class="frm">
      <div><label>الوزن (kg)</label><input type="number" step="0.1" name="weight_kg" value="<?=$val('weight_kg')?>"></div>
      <div><label>نسبة الدهون (%)</label><input type="number" step="0.1" name="body_fat_pct" value="<?=$val('body_fat_pct')?>"></div>
      <div><label>الكتلة العضلية (kg)</label><input type="number" step="0.1" name="muscle_mass_kg" value="<?=$val('muscle_mass_kg')?>"></div>
      <div><label>الكتلة الصافية (kg)</label><input type="number" step="0.1" name="lean_mass_kg" value="<?=$val('lean_mass_kg')?>"></div>
      <div><label>الماء (%)</label><input type="number" step="0.1" name="water_pct" value="<?=$val('water_pct')?>"></div>
      <div><label>BMI</label><input type="number" step="0.1" name="bmi" value="<?=$val('bmi')?>"></div>
      <div><label>BMR (kcal)</label><input type="number" name="bmr_kcal" value="<?=$val('bmr_kcal')?>"></div>
      <div><label>TDEE (kcal)</label><input type="number" name="tdee_kcal" value="<?=$val('tdee_kcal')?>"></div>
      <div><label>العمر الأيضي</label><input type="number" name="metabolic_age" value="<?=$val('metabolic_age')?>"></div>
    </div>
  </section>

  <!-- المحيطات -->
  <section data-tab="meas">
    <h2>📏 المحيطات (سم)</h2>
    <div style="overflow-x:auto">
      <table style="min-width:420px"><tr><th>الموضع</th><th style="width:110px">يمين</th><th style="width:110px">يسار</th></tr>
        <?php foreach ($SITES as $k=>$v): $mr = $measMap[$k]['right_cm'] ?? ''; $ml = $measMap[$k]['left_cm'] ?? ''; ?>
          <tr><td><?=h($v)?></td>
            <td><input type="number" step="0.1" name="m_r[<?=$k?>]" value="<?=h($mr)?>" style="width:100px;padding:6px;border:1px solid #cbd5e1;border-radius:8px"></td>
            <td><input type="number" step="0.1" name="m_l[<?=$k?>]" value="<?=h($ml)?>" style="width:100px;padding:6px;border:1px solid #cbd5e1;border-radius:8px"></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <p class="muted" style="font-size:12px">اترك «يسار» فارغًا للمواضع المفردة (الرقبة/الخصر…).</p>
  </section>

  <!-- FMS -->
  <section data-tab="fms">
    <h2>🤸 فحص الحركة الوظيفية (FMS)</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px">
      <?php foreach ($FMS as $mv=>$lbl): $cur = $fmsMap[$mv] ?? ''; ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #eef2f7;border-radius:8px;padding:6px 10px">
          <span style="font-size:13px"><?=h($lbl)?></span>
          <select name="fms[<?=$mv?>]" style="padding:5px 8px;border:1px solid #cbd5e1;border-radius:7px">
            <option value="">—</option>
            <?php foreach ($FMS_SC as $s=>$sl): ?><option value="<?=$s?>" <?= $cur!==''&&(int)$cur===$s?'selected':'' ?>><?=h($sl)?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:12px">٠ ألم · ١ عاجز · ٢ تعويض · ٣ طبيعي. المجموع الأعلى = حركة أفضل.</p>
  </section>

  <!-- القوام -->
  <section data-tab="posture">
    <h2>🧍 تقييم القوام</h2>
    <p class="muted" style="font-size:12px;margin-top:0">علّم الانحرافات الملاحَظة (أمامي/جانبي/خلفي).</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px">
      <?php foreach ($POST as $f=>$lbl): $on = $postMap[$f] ?? 0; ?>
        <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #eef2f7;border-radius:8px;cursor:pointer">
          <input type="checkbox" name="post[<?=$f?>]" value="1" <?=$on?'checked':''?>><span><?=h($lbl)?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- الاختلالات -->
  <section data-tab="imbalance">
    <h2>⚠️ الاختلالات العضلية</h2>
    <table><tr><th>المنطقة</th><th style="width:90px">ضعيف</th><th style="width:90px">مشدود</th></tr>
      <?php foreach ($REG as $rg=>$lbl): $w = $imbMap[$rg]['weak'] ?? 0; $t = $imbMap[$rg]['tight'] ?? 0; ?>
        <tr><td><?=h($lbl)?></td>
          <td><input type="checkbox" name="weak[<?=$rg?>]" value="1" <?=$w?'checked':''?>></td>
          <td><input type="checkbox" name="tight[<?=$rg?>]" value="1" <?=$t?'checked':''?>></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:12px">تُغذّي هذه العلامات محرّك التوصيات التصحيحية أعلى الصفحة بعد الحفظ.</p>
  </section>

  <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
    <div style="flex:1"><label style="font-size:13px;color:#475569">الهدف التالي</label><input name="next_goal" value="<?=$val('next_goal')?>" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px"></div>
  </div>
  <div style="margin-top:8px"><label style="font-size:13px;color:#475569">ملاحظات عامة</label><input name="notes" value="<?=$val('notes')?>" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px"></div>
  <div style="margin-top:14px"><button type="submit" style="background:#16a34a;color:#fff;border:0;padding:12px 26px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer">💾 حفظ التقييم</button></div>
</form>
<?php page_foot();

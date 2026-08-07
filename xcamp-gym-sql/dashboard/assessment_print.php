<?php
// =============================================================================
// Xcamp Gym — استمارة التقييم القابلة للطباعة: تعرض تقييم عضو بتصميم XCAMP
// (أسود/ذهبي) معبّأً ببياناته + رمز العضو (QR)، مع أقسام فارغة للتعبئة الميدانية
// (FMS/القوام) التي تأتي في الشريحة التالية. مستقلة عن قالب اللوحة (وثيقة طباعة).
// =============================================================================
require __DIR__ . '/db.php';
require_once __DIR__ . '/qr.php';
$me = require_login();
$isCoach = ($me['role'] === 'coach');
$myCoachId = $isCoach ? (int)($me['coach_id'] ?? 0) : 0;

try { $pdo = db(); } catch (Throwable $e) { http_response_code(500); exit('DB error'); }

$aid = (int)($_GET['a'] ?? 0);
$q = $pdo->prepare("SELECT a.*, m.full_name, m.phone, m.email, m.status AS mstatus, m.coach_id,
                      c.full_name AS coach_name
                    FROM member_assessments a
                    JOIN members m ON m.member_id = a.member_id
                    LEFT JOIN coaches c ON c.coach_id = m.coach_id
                    WHERE a.assessment_id = ?");
$q->execute([$aid]);
$A = $q->fetch();
if (!$A || ($isCoach && (int)$A['coach_id'] !== $myCoachId)) { http_response_code(404); exit('Not found'); }
$memId = (int)$A['member_id'];

// الاشتراك الأحدث
$ms = $pdo->prepare("SELECT pl.plan_name, ms.start_date, ms.end_date
                     FROM memberships ms JOIN plans pl ON pl.plan_id=ms.plan_id
                     WHERE ms.member_id = ? ORDER BY ms.end_date DESC LIMIT 1");
$ms->execute([$memId]); $ms = $ms->fetch();

// PAR-Q + المحيطات
$parq = [];
$p = $pdo->prepare("SELECT item, answer FROM assessment_parq WHERE assessment_id = ?"); $p->execute([$aid]);
foreach ($p as $r) $parq[$r['item']] = (int)$r['answer'];
$meas = [];
$mm = $pdo->prepare("SELECT * FROM assessment_measurements WHERE assessment_id = ?"); $mm->execute([$aid]);
foreach ($mm as $r) $meas[$r['site']] = $r;

// الطبقة الإكلينيكية + التوصيات
$fmsMap = []; $postMap = []; $imbMap = [];
$f = $pdo->prepare("SELECT movement, score FROM assessment_fms WHERE assessment_id = ?"); $f->execute([$aid]);
foreach ($f as $r) $fmsMap[$r['movement']] = (int)$r['score'];
$ps = $pdo->prepare("SELECT finding FROM assessment_posture WHERE assessment_id = ? AND present = 1"); $ps->execute([$aid]);
foreach ($ps as $r) $postMap[$r['finding']] = 1;
$im = $pdo->prepare("SELECT region, weak, tight FROM assessment_imbalances WHERE assessment_id = ?"); $im->execute([$aid]);
foreach ($im as $r) $imbMap[$r['region']] = ['weak' => (int)$r['weak'], 'tight' => (int)$r['tight']];
$reco = ($fmsMap || $postMap || $imbMap)
    ? assessment_clinical_reco($fmsMap, $postMap, $imbMap, (bool)($parq['joint_back_pain'] ?? 0), $A['goal_primary'])
    : null;

$qr = qr_svg(member_qr_token($pdo, $memId), 3, 2);

$GOALS = ['muscle_gain'=>'بناء عضل','fat_loss'=>'خسارة دهون','recomposition'=>'إعادة تكوين','strength'=>'قوة',
          'athletic'=>'أداء رياضي','health_fitness'=>'صحة ولياقة','rehab'=>'تأهيل','posture'=>'تحسين القوام',
          'flexibility'=>'مرونة','other'=>'أخرى'];
$PARQ = ['heart_disease'=>'أمراض قلب / ضغط','diabetes'=>'سكري','respiratory'=>'مشاكل تنفّس','joint_back_pain'=>'ألم مفاصل / ظهر',
         'dizziness'=>'دوخة / إغماء','surgery'=>'جراحة سابقة','medication'=>'أدوية حالية','smoking'=>'تدخين',
         'pregnancy'=>'حمل','allergies'=>'حساسية'];
$SITES = ['neck'=>'الرقبة','shoulder'=>'الكتف','chest'=>'الصدر','waist'=>'الخصر','abdomen'=>'البطن','hip'=>'الورك',
          'arm_relaxed'=>'ذراع (مرتخٍ)','arm_flexed'=>'ذراع (مشدود)','forearm'=>'الساعد','thigh_mid'=>'الفخذ','calf'=>'السمانة'];
$SLEEPQ = ['poor'=>'ضعيف','fair'=>'مقبول','good'=>'جيد','excellent'=>'ممتاز'];
$JOBS = ['sitting'=>'مكتبي','standing'=>'واقف','physical'=>'بدني'];
$LMH = ['low'=>'منخفض','medium'=>'متوسط','high'=>'مرتفع'];
$ACT = ['low'=>'منخفض','moderate'=>'متوسط','high'=>'مرتفع'];
$lbl = fn($map, $k) => $k !== null && isset($map[$k]) ? $map[$k] : '—';
$v   = fn($x) => ($x === null || $x === '') ? '—' : h($x);
$FMS = ['deep_squat'=>'قرفصاء عميقة','hurdle_step'=>'خطوة الحاجز','inline_lunge'=>'طعنة على خط',
        'shoulder_mobility'=>'حركية الكتف','active_slr'=>'رفع الساق النشط','trunk_stability'=>'دفع ثبات الجذع',
        'rotary_stability'=>'ثبات دوراني','single_leg_balance'=>'توازن ساق واحدة','overhead_reach'=>'مدّ علوي',
        'hip_hinge'=>'مفصلة الورك','thoracic_rotation'=>'دوران صدري'];
$POST = ['forward_head'=>'رأس أمامي','rounded_shoulders'=>'كتف مدوّر','kyphosis'=>'تحدّب صدري','lordosis'=>'تقعّر قطني',
         'anterior_pelvic_tilt'=>'ميل حوضي أمامي','posterior_pelvic_tilt'=>'ميل حوضي خلفي','knee_valgus'=>'ركبة للداخل',
         'knee_varus'=>'ركبة للخارج','scapular_winging'=>'جناحية اللوح','leg_length'=>'فرق طول الساقين','foot_arch'=>'قوس القدم'];
$REG = ['chest'=>'الصدر','upper_back'=>'الظهر العلوي','lower_back'=>'أسفل الظهر','shoulders'=>'الأكتاف','biceps'=>'البايسبس',
        'triceps'=>'الترايسبس','glutes'=>'الأرداف','hamstrings'=>'أوتار الركبة','quads'=>'الأمامية','calves'=>'السمانة'];
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>استمارة تقييم — <?=h($A['full_name'])?></title>
<style>
  :root { --gold:#c9a227; --gold2:#fbbf24; --ink:#111827; }
  * { box-sizing:border-box; }
  body { margin:0; background:#334155; font-family:'Segoe UI',Tahoma,Arial,sans-serif; color:#111827; }
  .toolbar { text-align:center; padding:12px; }
  .toolbar button { background:var(--ink); color:var(--gold2); border:1px solid var(--gold); padding:9px 22px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; }
  .sheet { width:210mm; min-height:297mm; margin:0 auto 16px; background:#fff; padding:10mm; }
  .hd { background:var(--ink); color:#fff; border-radius:10px; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; gap:14px; }
  .hd .brand { font-size:26px; font-weight:900; letter-spacing:1px; }
  .hd .brand b { color:var(--gold2); }
  .hd .title { text-align:center; flex:1; }
  .hd .title .en { color:var(--gold2); font-size:19px; font-weight:800; letter-spacing:1px; }
  .hd .title .ar { font-size:15px; opacity:.9; }
  .hd .idbox { background:#fff; color:#111; border-radius:8px; padding:6px 10px; text-align:center; min-width:120px; }
  .hd .idbox .k { font-size:9px; color:#64748b; }
  .hd .idbox .qr { width:74px; height:74px; margin:2px auto 0; background:#fff; }
  .hd .idbox .qr svg { width:100%; height:100%; display:block; }
  .row { display:flex; gap:8px; margin-top:8px; }
  .box { border:1.5px solid var(--ink); border-radius:9px; overflow:hidden; flex:1; }
  .box > h3 { margin:0; background:var(--ink); color:#fff; font-size:12px; padding:5px 10px; display:flex; align-items:center; gap:6px; }
  .box > h3 .num { background:var(--gold); color:#111; width:16px; height:16px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; }
  .box .bd { padding:8px 10px; }
  .f { font-size:11.5px; margin:3px 0; }
  .f b { color:#111; }
  .val { border-bottom:1px dotted #94a3b8; padding:0 4px; font-weight:700; color:#1e3a8a; }
  table.g { width:100%; border-collapse:collapse; font-size:10.5px; }
  table.g th, table.g td { border:1px solid #cbd5e1; padding:3px 5px; text-align:center; }
  table.g th { background:#f1f5f9; font-weight:700; }
  .chips { display:flex; flex-wrap:wrap; gap:4px; }
  .chip { font-size:10.5px; border:1px solid #cbd5e1; border-radius:5px; padding:2px 7px; }
  .chip.on { background:#111827; color:#fbbf24; border-color:#111827; font-weight:700; }
  .yn { font-weight:800; }
  .yn.y { color:#dc2626; } .yn.n { color:#16a34a; }
  .muted { color:#64748b; }
  .empty-note { font-size:9.5px; color:#94a3b8; }
  .ft { margin-top:10px; background:var(--ink); color:#fff; border-radius:8px; padding:8px 14px; display:flex; justify-content:space-between; font-size:11px; }
  .ft .g { color:var(--gold2); font-weight:800; letter-spacing:1px; }
  .sig { display:flex; gap:14px; margin-top:8px; }
  .sig div { flex:1; border-top:1px solid #111; padding-top:3px; font-size:10.5px; text-align:center; }
  @media print {
    body { background:#fff; }
    .toolbar { display:none; }
    .sheet { margin:0; box-shadow:none; width:auto; min-height:auto; padding:6mm; }
    @page { size:A4; margin:6mm; }
  }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">🖨️ اطبع / احفظ PDF</button></div>
<div class="sheet">
  <div class="hd">
    <div class="brand">X<b>CAMP</b><div style="font-size:9px;letter-spacing:2px;color:var(--gold2)">TRAIN · LIVE · BECOME</div></div>
    <div class="title"><div class="en">ELITE MEMBER ASSESSMENT</div><div class="ar">استمارة التقييم الشامل للعضو</div></div>
    <div class="idbox"><div class="k">MEMBER · <?=$memId?></div><div class="qr"><?=$qr?></div></div>
  </div>

  <div class="row">
    <div class="box"><h3><span class="num">1</span> البيانات الشخصية</h3><div class="bd">
      <div class="f"><b>الاسم:</b> <span class="val"><?=$v($A['full_name'])?></span></div>
      <div class="f"><b>الهاتف:</b> <span class="val"><?=$v($A['phone'])?></span></div>
      <div class="f"><b>البريد:</b> <span class="val"><?=$v($A['email'])?></span></div>
      <div class="f"><b>الحالة:</b> <span class="val"><?=$v($A['mstatus'])?></span></div>
    </div></div>
    <div class="box"><h3><span class="num">2</span> الاشتراك والتدريب</h3><div class="bd">
      <div class="f"><b>الخطة:</b> <span class="val"><?= $ms ? $v($ms['plan_name']) : '—' ?></span></div>
      <div class="f"><b>تبدأ:</b> <span class="val"><?= $ms ? $v($ms['start_date']) : '—' ?></span> · <b>تنتهي:</b> <span class="val"><?= $ms ? $v($ms['end_date']) : '—' ?></span></div>
      <div class="f"><b>الكابتن:</b> <span class="val"><?=$v($A['coach_name'])?></span></div>
      <div class="f"><b>تاريخ التقييم:</b> <span class="val"><?=$v($A['assessment_date'])?></span></div>
    </div></div>
    <div class="box"><h3><span class="num">5</span> الهدف الأساسي</h3><div class="bd">
      <div class="f" style="font-size:15px;text-align:center;margin-top:6px"><span class="val" style="font-size:15px"><?=$lbl($GOALS,$A['goal_primary'])?></span></div>
      <div class="f"><b>الهدف التالي:</b> <span class="val"><?=$v($A['next_goal'])?></span></div>
      <div class="f"><b>الدرجة الكلية:</b> <span class="val"><?= $A['overall_score']!==null ? (int)$A['overall_score'].' / 100' : '—' ?></span></div>
    </div></div>
  </div>

  <div class="row">
    <div class="box"><h3><span class="num">3</span> التاريخ الصحي (PAR-Q)</h3><div class="bd">
      <table class="g"><tr><th>البند</th><th>الإجابة</th><th>البند</th><th>الإجابة</th></tr>
        <?php $keys = array_keys($PARQ); for ($i=0;$i<count($keys);$i+=2): ?>
          <tr>
            <?php for ($j=$i;$j<$i+2 && $j<count($keys);$j++): $k=$keys[$j]; $on=$parq[$k]??0; ?>
              <td style="text-align:right"><?=h($PARQ[$k])?></td>
              <td class="yn <?=$on?'y':'n'?>"><?=$on?'نعم':'لا'?></td>
            <?php endfor; ?>
          </tr>
        <?php endfor; ?>
      </table>
      <div class="f" style="margin-top:5px"><b>موافقة الطبيب:</b> <span class="val"><?= ['na'=>'—','yes'=>'نعم','no'=>'لا'][$A['doctor_approval']] ?></span> · <b>ملاحظات:</b> <span class="val"><?=$v($A['health_notes'])?></span></div>
    </div></div>
    <div class="box"><h3><span class="num">4</span> نمط الحياة</h3><div class="bd">
      <div class="f"><b>نوم:</b> <span class="val"><?=$v($A['sleep_hours'])?></span> س (<?=$lbl($SLEEPQ,$A['sleep_quality'])?>) · <b>ماء:</b> <span class="val"><?=$v($A['water_liters'])?></span> ل</div>
      <div class="f"><b>خطوات:</b> <span class="val"><?=$v($A['daily_steps'])?></span> · <b>جلوس:</b> <span class="val"><?=$v($A['sitting_hours'])?></span> س</div>
      <div class="f"><b>عمل:</b> <span class="val"><?=$lbl($JOBS,$A['job_type'])?></span> · <b>توتّر:</b> <span class="val"><?=$lbl($LMH,$A['stress_level'])?></span> · <b>نشاط:</b> <span class="val"><?=$lbl($ACT,$A['activity_level'])?></span></div>
      <div class="chips" style="margin-top:5px">
        <span class="chip <?= $A['smoking']?'on':'' ?>">تدخين</span>
        <span class="chip <?= $A['alcohol']?'on':'' ?>">كحول</span>
      </div>
    </div></div>
  </div>

  <div class="row">
    <div class="box" style="flex:1.1"><h3><span class="num">8</span> تركيب الجسم</h3><div class="bd">
      <table class="g">
        <tr><th>الوزن</th><th>دهون %</th><th>عضل kg</th><th>صافٍ kg</th><th>ماء %</th></tr>
        <tr><td><?=$v($A['weight_kg'])?></td><td><?=$v($A['body_fat_pct'])?></td><td><?=$v($A['muscle_mass_kg'])?></td><td><?=$v($A['lean_mass_kg'])?></td><td><?=$v($A['water_pct'])?></td></tr>
        <tr><th>BMI</th><th>BMR</th><th>TDEE</th><th>عمر أيضي</th><th></th></tr>
        <tr><td><?=$v($A['bmi'])?></td><td><?=$v($A['bmr_kcal'])?></td><td><?=$v($A['tdee_kcal'])?></td><td><?=$v($A['metabolic_age'])?></td><td></td></tr>
      </table>
    </div></div>
    <div class="box"><h3><span class="num">9</span> المحيطات (سم)</h3><div class="bd">
      <table class="g"><tr><th>الموضع</th><th>يمين</th><th>يسار</th><th>الموضع</th><th>يمين</th><th>يسار</th></tr>
        <?php $sk = array_keys($SITES); for ($i=0;$i<count($sk);$i+=2): ?>
          <tr>
            <?php for ($j=$i;$j<$i+2 && $j<count($sk);$j++): $k=$sk[$j]; $m=$meas[$k]??null; ?>
              <td style="text-align:right"><?=h($SITES[$k])?></td>
              <td><?= $m && $m['right_cm']!==null ? h($m['right_cm']) : '' ?></td>
              <td><?= $m && $m['left_cm']!==null ? h($m['left_cm']) : '' ?></td>
            <?php endfor; ?>
            <?php if ($i+1 >= count($sk)): ?><td></td><td></td><td></td><?php endif; ?>
          </tr>
        <?php endfor; ?>
      </table>
    </div></div>
  </div>

  <div class="row">
    <div class="box"><h3><span class="num">7</span> فحص الحركة الوظيفية (FMS)</h3><div class="bd">
      <table class="g"><tr><th style="width:34%">الحركة</th><th>٠</th><th>١</th><th>٢</th><th>٣</th><th style="width:34%">الحركة</th><th>٠</th><th>١</th><th>٢</th><th>٣</th></tr>
        <?php $fk = array_keys($FMS); for ($i=0;$i<count($fk);$i+=2): ?>
          <tr>
            <?php for ($j=$i;$j<$i+2;$j++): ?>
              <?php if (isset($fk[$j])): $mv=$fk[$j]; $sc=$fmsMap[$mv]??null; ?>
                <td style="text-align:right"><?=h($FMS[$mv])?></td>
                <?php for ($s=0;$s<=3;$s++): ?><td<?= $sc===$s ? ' style="background:#111827;color:#fbbf24;font-weight:800"' : '' ?>><?= $sc===$s ? '●' : '☐' ?></td><?php endfor; ?>
              <?php else: ?><td></td><td></td><td></td><td></td><td></td><?php endif; ?>
            <?php endfor; ?>
          </tr>
        <?php endfor; ?>
      </table>
      <div class="empty-note">المجموع: <strong><?= $reco ? $reco['fms_total'].' / 33' : '____' ?></strong> · ٠ ألم · ١ عاجز · ٢ تعويض · ٣ طبيعي.</div>
    </div></div>
  </div>

  <div class="row">
    <div class="box"><h3><span class="num">6</span> القوام</h3><div class="bd">
      <div class="chips">
        <?php $anyP=false; foreach ($POST as $k=>$lb){ if(!empty($postMap[$k])){ $anyP=true; echo '<span class="chip on">'.h($lb).'</span>'; } }
              if(!$anyP) echo '<span class="muted" style="font-size:10.5px">لا انحرافات مُعلَّمة</span>'; ?>
      </div>
    </div></div>
    <div class="box"><h3><span class="num">11</span> الاختلالات العضلية</h3><div class="bd">
      <div class="chips">
        <?php $anyI=false; foreach ($REG as $k=>$lb){ $f=$imbMap[$k]??null; if($f && ($f['weak']||$f['tight'])){ $anyI=true;
                echo '<span class="chip on">'.h($lb).' ('.($f['weak']?'ضعيف':'').($f['weak']&&$f['tight']?'/':'').($f['tight']?'مشدود':'').')</span>'; } }
              if(!$anyI) echo '<span class="muted" style="font-size:10.5px">لا اختلالات مُعلَّمة</span>'; ?>
      </div>
    </div></div>
  </div>

  <?php if ($reco): ?>
  <div class="box" style="margin-top:8px;border-color:#7c3aed"><h3 style="background:#4c1d95"><span class="num" style="background:#fbbf24">★</span> التوصية الآلية · أولوية <?=h($reco['priority'])?> (خطر <?=$reco['risk']?>/100)</h3><div class="bd">
    <div class="f"><b>المرحلة المقترحة:</b> <span class="val"><?=h($reco['phase_ar'])?></span> — <?=h($reco['prescription']['label'])?> · <b>الوصفة:</b> <span class="val"><?=$reco['prescription']['sets']?>×<?=h($reco['prescription']['reps'])?> · RPE <?=$reco['prescription']['rpe']?> · راحة <?=$reco['prescription']['rest']?>ث</span></div>
    <?php if ($reco['avoid']): ?><div class="f" style="color:#991b1b"><b>⛔ تجنّب مؤقتًا:</b> <?=h(implode('، ', $reco['avoid']))?></div><?php endif; ?>
    <?php if ($reco['corrective']): ?>
      <div class="f"><b>تركيز تصحيحي:</b></div>
      <ul style="margin:2px 0 0;padding-inline-start:18px;font-size:10.5px">
        <?php foreach ($reco['corrective'] as $c): ?><li><?=h($c)?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <div class="f" style="margin-top:8px"><b>ملاحظات عامة:</b> <span class="val" style="display:inline-block;min-width:60%"><?=$v($A['notes'])?></span></div>
  <div class="sig">
    <div>توقيع العضو<br><span class="muted">التاريخ: __ / __ / ____</span></div>
    <div>توقيع الكابتن<br><span class="muted">التاريخ: __ / __ / ____</span></div>
    <div>توقيع المدير<br><span class="muted">التاريخ: __ / __ / ____</span></div>
  </div>
  <div class="ft"><span class="g">DISCIPLINE TODAY · RESULTS TOMORROW</span><span>XCAMP — TRAIN. LIVE. BECOME.</span></div>
</div>
</body>
</html>

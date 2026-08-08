<?php
// =============================================================================
// Xcamp Gym — ذكاء الأحمال: دوال نقية للحسابات التدريبية (بدون قاعدة/جلسة)
//   - تقدير 1RM (Epley)
//   - المنطقة التدريبية حسب التكرارات
//   - اقتراح الحمل التالي (Progressive Overload) من آخر أداء + RPE
//   - كشف الثبات (Plateau) من سلسلة الأحمال
// =============================================================================

/** يستخرج أول عدد صحيح من نص التكرارات ("8-10" -> 8، "AMRAP" -> null) */
function reps_to_int($reps): ?int {
    if ($reps === null || $reps === '') return null;
    return preg_match('/\d+/', (string)$reps, $m) ? (int)$m[0] : null;
}

/** تقدير أقصى قوة (1RM) بمعادلة Epley: load × (1 + reps/30) */
function epley_1rm(?float $load, ?int $reps): ?float {
    if (!$load || $load <= 0 || !$reps || $reps < 1) return null;
    if ($reps === 1) return $load;
    return round($load * (1 + $reps / 30), 1);
}

/** تقريب لأقرب 2.5 كجم (أصغر زيادة أطباق شائعة) */
function round25(float $x): float { return round($x / 2.5) * 2.5; }

/** تقدير 1RM بمعادلة Brzycki: load × 36 / (37 − reps) — أدقّ في نطاق 2..10 تكرار */
function brzycki_1rm(?float $load, ?int $reps): ?float {
    if (!$load || $load <= 0 || !$reps || $reps < 1) return null;
    if ($reps === 1) return $load;
    if ($reps >= 37) return null;              // المعادلة تنهار عند 37 تكرار
    return round($load * 36 / (37 - $reps), 1);
}

/**
 * المحرّك التكيّفي: من أداء مجموعة اختبار (وزن/تكرار/RPE/RIR) يحسب 1RM المقدَّر
 * ويقترح **وزن العمل** للأسبوع القادم — والإصلاح المحترف هنا: وزن العمل يُشتقّ من
 * الوزن المُؤدَّى (performed) لا من الـ1RM؛ فالـ1RM حِمل تكرار واحد لا وزن عمل.
 *
 * القرار من مقياس الجهد (RPE/RIR الأقلّ إجهادًا يعطي مساحة للزيادة):
 *   RPE ≤ 8 و RIR ≥ 2 → up   : زد ~3% (بحد أدنى +2.5 كجم فعلية)
 *   RPE = 9  أو RIR = 1        → hold : ثبّت الوزن (قريب من الفشل)
 *   RPE = 10 أو RIR = 0        → warn : تخفيف ~5% + تنبيه (وصل للفشل)
 * حماية الانحدار: إن هبط 1RM المقدَّر تحت 95% من السابق نُنبّه (تعب/سوء أداء).
 *
 * @return array{est_1rm:?float, next_weight:?float, action:string, increase_pct:float,
 *               note:string, alert:?string, formula:string}
 */
function adaptive_load_decision(float $performed, int $reps, int $rpe, int $rir,
                               string $formula = 'epley', ?float $prev1rm = null): array {
    $formula = ($formula === 'brzycki') ? 'brzycki' : 'epley';
    $est = ($formula === 'brzycki') ? brzycki_1rm($performed, $reps) : epley_1rm($performed, $reps);

    // قرار الحمل القادم مبنيّ على الوزن المُؤدَّى، لا على 1RM
    if ($rpe >= 10 || $rir <= 0) {
        $action = 'warn';  $incPct = -5.0;
        $note   = 'وصلت للفشل (RPE 10 / RIR 0): خفّف الحمل وراجع الأداء والتعافي.';
        $alert  = 'مجهود أقصى — تخفيف مؤقّت لتفادي الإفراط والإصابة.';
    } elseif ($rpe >= 9 || $rir <= 1) {
        $action = 'hold';  $incPct = 0.0;
        $note   = 'قريب من الفشل (RPE 9 / RIR 1): ثبّت الوزن حتى يهبط الجهد.';
        $alert  = null;
    } else {
        $action = 'up';    $incPct = 3.0;
        $note   = 'هامش جيّد (RPE ≤ 8 و RIR ≥ 2): زِد الحمل تدريجيًا.';
        $alert  = null;
    }

    $raw  = $performed * (1 + $incPct / 100.0);
    $next = round25($raw);
    if ($action === 'up' && $next <= $performed) $next = round25($performed + 2.5); // ضمان زيادة فعلية

    // حماية الانحدار: مقارنة 1RM المقدَّر بالسابق
    if ($est !== null && $prev1rm !== null && $prev1rm > 0 && $est < $prev1rm * 0.95) {
        $alert = ($alert ? $alert . ' ' : '')
               . 'انحدار في القوة (1RM أقلّ من السابق بأكثر من 5%): افحص النوم والتغذية والحِمل.';
    }

    return [
        'est_1rm'      => $est,
        'next_weight'  => $next,
        'action'       => $action,
        'increase_pct' => $incPct,
        'note'         => $note,
        'alert'        => $alert,
        'formula'      => $formula,
    ];
}

/** المنطقة التدريبية حسب التكرارات */
function load_zone(?int $reps): string {
    if ($reps === null) return '';
    if ($reps <= 5)  return 'قوة';
    if ($reps <= 12) return 'تضخيم';
    return 'تحمّل';
}

/**
 * اقتراح الحمل التالي بناءً على آخر حمل + مجهود (RPE):
 *   RPE ≤ 6  → زد ~5%   | RPE 7 → +2.5% | RPE 8 → ثبّت | RPE ≥ 9 → خفّف ~5%
 * يرجّع ['load'=>?float, 'reason'=>string, 'color'=>string]
 */
function suggest_next_load(?float $lastLoad, ?int $lastRpe): array {
    if ($lastLoad === null || $lastLoad <= 0)
        return ['load' => null, 'reason' => 'سجّل حملًا أولًا', 'color' => '#94a3b8'];
    if ($lastRpe === null)
        return ['load' => $lastLoad, 'reason' => 'أضف RPE لاقتراح أدق', 'color' => '#6b7280'];
    if ($lastRpe <= 6)
        return ['load' => round25($lastLoad * 1.05), 'reason' => 'جهد منخفض — زد ~5%', 'color' => '#16a34a'];
    if ($lastRpe == 7)
        return ['load' => round25($lastLoad * 1.025), 'reason' => 'تقدّم تدريجي +2.5%', 'color' => '#16a34a'];
    if ($lastRpe == 8)
        return ['load' => $lastLoad, 'reason' => 'الحمل مثالي — ثبّت', 'color' => '#2563eb'];
    return ['load' => round25($lastLoad * 0.95), 'reason' => 'جهد مرتفع — خفّف ~5%', 'color' => '#f59e0b'];
}

/**
 * كشف الثبات: تُمرَّر أحمال آخر الجلسات (الأحدث أولًا).
 * ثبات = 3 قيم متتالية أو أكثر بلا زيادة (الأحدث ≤ اللي قبله).
 */
function is_plateau(array $loadsDesc): bool {
    $loadsDesc = array_values(array_filter($loadsDesc, fn($v) => $v !== null && $v !== ''));
    if (count($loadsDesc) < 3) return false;
    for ($i = 0; $i < 3 - 1; $i++) {
        if ((float)$loadsDesc[$i] > (float)$loadsDesc[$i + 1]) return false; // فيه تحسّن
    }
    return true;
}

/** الاتجاه العام: مقارنة آخر حمل بأفضل حمل */
function load_trend(?float $last, ?float $best): array {
    if ($last === null || $best === null) return ['▬', '#94a3b8', ''];
    if ($last >= $best) return ['▲', '#16a34a', 'في أفضل مستوى'];
    if ($last >= $best * 0.95) return ['▬', '#f59e0b', 'قريب من الأفضل'];
    return ['▼', '#dc2626', 'تحت الأفضل'];
}

// =============================================================================
// ذكاء تدريبي أعمق: حدود الحجم + خطة التدرّج/التخفيف + مؤشر الجاهزية
// =============================================================================

/**
 * حالة الحجم الأسبوعي لعضلة مقابل الحدود العلمية (بالمجموعات/أسبوع):
 *   MEV≈10 (أدنى فعّال) · MAV≈20 (أعلى تكيّف) · MRV≈22 (أقصى تحمّل).
 * يرجّع ['label','color','zone'] حيث zone ∈ low|optimal|high|over.
 */
function volume_status(int $sets): array {
    if ($sets < 10) return ['أقل من المطلوب', '#f59e0b', 'low'];
    if ($sets <= 20) return ['مثالي', '#16a34a', 'optimal'];
    if ($sets <= 22) return ['قرب الحد الأقصى', '#2563eb', 'high'];
    return ['إفراط محتمل', '#dc2626', 'over'];
}

/**
 * خطة الأسبوع القادم حسب رقم الأسبوع في الدورة (1-based) + متوسط RPE + وجود ثبات.
 * كل أسبوع رابع = تخفيف. يرجّع ['action','detail','color'].
 */
function progression_plan(int $weekIndex, ?float $avgRpe, bool $anyPlateau): array {
    if ($weekIndex > 0 && $weekIndex % 4 === 0)
        return ['تخفيف (Deload)', 'أسبوع تخفيف مجدول — قلّل الأحمال/الحجم ~40–50% للتعافي.', '#f59e0b'];
    if ($avgRpe !== null && $avgRpe >= 9)
        return ['تخفيف مبكر', 'الإجهاد مرتفع (RPE مرتفع) — أدرِج تخفيفًا قبل موعده.', '#dc2626'];
    if ($anyPlateau)
        return ['تغيير المتغيّرات', 'يوجد ثبات — غيّر التمرين أو نطاق التكرارات أو أسلوب التنفيذ.', '#2563eb'];
    if ($avgRpe !== null && $avgRpe <= 7)
        return ['زيادة الحمل/الحجم', 'الجهد منخفض — قدّم بزيادة الحمل أو مجموعة إضافية.', '#16a34a'];
    return ['تقدّم تدريجي', 'حافظ على التدرّج الأسبوعي مع زيادة بسيطة في الحمل.', '#16a34a'];
}

/**
 * مؤشر جاهزية العضو للتعافي (0–100) من متوسط RPE + عدد عضلات الإفراط + عدد الثبات.
 * يرجّع ['score','label','color'].
 */
function readiness_score(?float $avgRpe, int $overloadedGroups, int $plateauCount): array {
    $s = 100;
    if ($avgRpe !== null) {
        if ($avgRpe >= 9) $s -= 30;
        elseif ($avgRpe >= 8) $s -= 12;
        elseif ($avgRpe <= 6) $s += 0;
    }
    $s -= $overloadedGroups * 15;
    $s -= $plateauCount * 8;
    $s = max(0, min(100, $s));
    if ($s >= 75) return [$s, 'جاهز للتقدّم', '#16a34a'];
    if ($s >= 50) return [$s, 'تعافٍ متوسط', '#f59e0b'];
    return [$s, 'يحتاج راحة/تخفيف', '#dc2626'];
}

// =============================================================================
// المولّد التلقائي للبرامج (بقواعد): الهدف + التقييم + الإصابات → برنامج كامل
// =============================================================================

/** يحدّد المرحلة التدريبية من الهدف + درجة الخطر من آخر تقييم */
function phase_for_goal(string $goal, ?float $riskScore): string {
    if ($riskScore !== null && $riskScore >= 80) return 'corrective';
    if ($riskScore !== null && $riskScore >= 60) return 'stabilization';
    return [
        'muscle_gain' => 'hypertrophy', 'strength' => 'strength',
        'performance' => 'power',       'fat_loss' => 'hypertrophy',
        'rehab' => 'corrective',        'general_fitness' => 'maintenance',
    ][$goal] ?? 'maintenance';
}

/** وصفة المجموعات/التكرارات/الجهد/الراحة لكل مرحلة */
function phase_prescription(string $phase): array {
    $map = [
        'corrective'    => [2, '15',    6, 60,  'إصلاحي — حمل خفيف وتكرار عالٍ'],
        'stabilization' => [3, '12-15', 7, 75,  'تثبيت — تحكّم وثبات'],
        'hypertrophy'   => [3, '8-12',  8, 90,  'تضخيم — حجم متوسط'],
        'strength'      => [4, '4-6',   8, 150, 'قوة — حمل مرتفع'],
        'power'         => [4, '3-5',   7, 180, 'قدرة — سرعة وقوة'],
        'maintenance'   => [3, '10-12', 7, 90,  'محافظة — توازن عام'],
    ];
    [$sets, $reps, $rpe, $rest, $label] = $map[$phase] ?? $map['maintenance'];
    return ['sets' => $sets, 'reps' => $reps, 'rpe' => $rpe, 'rest' => $rest, 'label' => $label];
}

/**
 * المجموعات العضلية الواجب تجنّبها بسبب إصابات نشطة/متعافية.
 * $injuries: صفوف بها body_area و current_status.
 */
function injury_avoided_groups(array $injuries): array {
    $map = [
        'knee' => ['legs'], 'ركبة' => ['legs'], 'ankle' => ['legs'], 'كاحل' => ['legs'],
        'back' => ['back','legs'], 'ظهر' => ['back','legs'], 'lumbar' => ['back','legs'],
        'spine' => ['back','legs'], 'قطني' => ['back','legs'],
        'shoulder' => ['shoulders','chest'], 'كتف' => ['shoulders','chest'],
        'elbow' => ['arms'], 'كوع' => ['arms'], 'wrist' => ['arms'], 'رسغ' => ['arms'],
        'hip' => ['legs','glutes'], 'ورك' => ['legs','glutes'],
        'neck' => ['shoulders'], 'رقبة' => ['shoulders'],
    ];
    $avoid = [];
    foreach ($injuries as $inj) {
        $st = $inj['current_status'] ?? '';
        if (!in_array($st, ['active','recovering'], true)) continue;
        $area = mb_strtolower((string)($inj['body_area'] ?? ''));
        foreach ($map as $kw => $groups) {
            if (mb_strpos_compat($area, $kw)) $avoid = array_merge($avoid, $groups);
        }
    }
    return array_values(array_unique($avoid));
}

/** بحث نصّي بسيط يعمل بدون mbstring (يرجّع true لو وُجدت السلسلة) */
function mb_strpos_compat(string $haystack, string $needle): bool {
    return $needle !== '' && strpos($haystack, $needle) !== false;
}

/**
 * محرّك التوصيات الإكلينيكية للتقييم: من FMS + القوام + الاختلالات + PAR-Q + الهدف
 * يحسب درجة خطر (0..100) ويشتقّ مرحلة البرنامج (عبر phase_for_goal) ووصفتها
 * (phase_prescription) والمجموعات الواجب تجنّبها (injury_avoided_groups) وقائمة
 * تركيز تصحيحي مقروءة. دالة صرفة: تأخذ مصفوفات وتُرجع مصفوفة.
 *
 * @param array $fms        movement => score(0..3)
 * @param array $posture    finding => bool present
 * @param array $imbalances region => ['weak'=>bool,'tight'=>bool]
 * @param bool  $jointPain  إشارة ألم مفاصل/ظهر من PAR-Q
 * @param ?string $goal     goal_primary من التقييم
 */
function assessment_clinical_reco(array $fms, array $posture, array $imbalances, bool $jointPain, ?string $goal): array {
    // ---- درجة الخطر ----
    $risk = 0;
    $fmsTotal = 0; $fmsFlags = []; $painMoves = [];
    $fmsAr = ['deep_squat'=>'قرفصاء عميقة','hurdle_step'=>'خطوة الحاجز','inline_lunge'=>'طعنة على خط',
        'shoulder_mobility'=>'حركية الكتف','active_slr'=>'رفع الساق النشط','trunk_stability'=>'ثبات الجذع (دفع)',
        'rotary_stability'=>'ثبات دوراني','single_leg_balance'=>'توازن ساق واحدة','overhead_reach'=>'مدّ علوي',
        'hip_hinge'=>'مفصلة الورك','thoracic_rotation'=>'دوران صدري'];
    foreach ($fms as $mv => $sc) {
        $sc = (int)$sc; $fmsTotal += $sc;
        if ($sc === 0) { $risk += 20; $fmsFlags[$mv] = 0; $painMoves[] = $mv; }
        elseif ($sc === 1) { $risk += 10; $fmsFlags[$mv] = 1; }
        elseif ($sc === 2) { $risk += 3; }
    }
    $postureOn = array_keys(array_filter($posture));
    $risk += count($postureOn) * 6;
    $weak = []; $tight = [];
    foreach ($imbalances as $rg => $f) {
        if (!empty($f['weak']))  { $weak[]  = $rg; $risk += 4; }
        if (!empty($f['tight'])) { $tight[] = $rg; $risk += 4; }
    }
    if ($jointPain) $risk += 15;
    $risk = max(0, min(100, $risk));

    // ---- المرحلة والوصفة ----
    $goalMap = ['muscle_gain'=>'muscle_gain','fat_loss'=>'fat_loss','recomposition'=>'fat_loss',
        'strength'=>'strength','athletic'=>'performance','health_fitness'=>'general_fitness',
        'rehab'=>'rehab','posture'=>'rehab','flexibility'=>'general_fitness','other'=>'general_fitness'];
    $phase = phase_for_goal($goalMap[$goal] ?? 'general_fitness', (float)$risk);
    $phaseAr = ['corrective'=>'إصلاحي','stabilization'=>'تثبيت','hypertrophy'=>'تضخيم',
        'strength'=>'قوة','power'=>'قدرة','maintenance'=>'محافظة'][$phase] ?? $phase;
    $rx = phase_prescription($phase);

    // ---- المجموعات الواجب تجنّبها (من ألم PAR-Q + حركات FMS المؤلمة) ----
    $areaMap = ['deep_squat'=>'knee','hip_hinge'=>'back','single_leg_balance'=>'knee','inline_lunge'=>'knee',
        'active_slr'=>'hip','hurdle_step'=>'hip','shoulder_mobility'=>'shoulder','overhead_reach'=>'shoulder',
        'trunk_stability'=>'back','rotary_stability'=>'back','thoracic_rotation'=>'back'];
    $injuries = [];
    if ($jointPain) $injuries[] = ['body_area' => 'back', 'current_status' => 'active'];
    foreach ($painMoves as $mv) if (isset($areaMap[$mv])) $injuries[] = ['body_area' => $areaMap[$mv], 'current_status' => 'active'];
    $avoid = injury_avoided_groups($injuries);
    $avoidAr = ['legs'=>'الأرجل','back'=>'الظهر','shoulders'=>'الأكتاف','chest'=>'الصدر','arms'=>'الذراعين','glutes'=>'الأرداف'];

    // ---- تركيز تصحيحي مقروء ----
    $corr = [];
    $postCue = [
        'forward_head'=>'رأس أمامي: إطالة خلف الرقبة + تقوية الثنيات العنقية العميقة',
        'rounded_shoulders'=>'كتف مدوّر: شدّ الصدر + تقوية الظهر العلوي والمثبّتات الكتفية',
        'kyphosis'=>'تحدّب صدري: إطالة صدرية + تقوية باسطات الصدر الظهرية',
        'lordosis'=>'تقعّر قطني: إطالة ثنيات الورك + تقوية الـcore والأرداف',
        'anterior_pelvic_tilt'=>'ميل حوضي أمامي: إطالة ثنيات الورك + تفعيل الأرداف/البطن',
        'posterior_pelvic_tilt'=>'ميل حوضي خلفي: تقوية باسطات الورك + إطالة أوتار الركبة',
        'knee_valgus'=>'ركبة للداخل: تقوية مبعّدات الورك والألوية المتوسطة',
        'knee_varus'=>'ركبة للخارج: تقوية المقرّبات وتوازن الحمل',
        'scapular_winging'=>'جناحية اللوح: تقوية المنشارية الأمامية',
        'leg_length'=>'فرق طول الساقين: تقييم بيوميكانيكي وعمل توازن',
        'foot_arch'=>'قوس القدم: دعم القوس وتقوية القدم/الكاحل',
    ];
    foreach ($postureOn as $f) if (isset($postCue[$f])) $corr[] = $postCue[$f];
    $regAr = ['chest'=>'الصدر','upper_back'=>'الظهر العلوي','lower_back'=>'أسفل الظهر','shoulders'=>'الأكتاف',
        'biceps'=>'البايسبس','triceps'=>'الترايسبس','glutes'=>'الأرداف','hamstrings'=>'أوتار الركبة',
        'quads'=>'الأمامية','calves'=>'السمانة'];
    foreach ($weak  as $rg) $corr[] = 'ضعف ' . ($regAr[$rg] ?? $rg) . ': أضِف تمارين تقوية موجّهة';
    foreach ($tight as $rg) $corr[] = 'شدّ ' . ($regAr[$rg] ?? $rg) . ': أضِف إطالة/تحرير عضلي';
    if (isset($fmsFlags['trunk_stability']) || isset($fmsFlags['rotary_stability'])) $corr[] = 'ثبات جذع منخفض: ابدأ بتمارين تفعيل الـcore قبل الأحمال المركّبة';
    if (isset($fmsFlags['shoulder_mobility']) || isset($fmsFlags['overhead_reach'])) $corr[] = 'حركية كتف محدودة: عمل حركية صدرية/كتفية قبل الضغط العلوي';
    if (isset($fmsFlags['deep_squat'])) $corr[] = 'قرفصاء محدودة: حركية كاحل/ورك قبل تحميل السكوات';

    $priority = $risk >= 60 ? ['عالية','#dc2626'] : ($risk >= 35 ? ['متوسطة','#f59e0b'] : ['منخفضة','#16a34a']);

    return [
        'risk'         => $risk,
        'priority'     => $priority[0], 'priority_color' => $priority[1],
        'phase'        => $phase, 'phase_ar' => $phaseAr,
        'goal_workout' => $goalMap[$goal] ?? 'general_fitness',
        'prescription' => $rx,
        'fms_total'    => $fmsTotal, 'fms_flags' => $fmsFlags, 'fms_ar' => $fmsAr,
        'avoid'        => array_map(fn($g) => $avoidAr[$g] ?? $g, $avoid),
        'avoid_raw'    => $avoid,
        'corrective'   => $corr,
    ];
}

/**
 * مخطط البرنامج حسب الهدف: قائمة جلسات، كل جلسة بها عنوان + مجموعات التركيز + يوم الإزاحة.
 * أهداف القوة/التضخيم/الأداء → دفع/سحب/أرجل؛ الباقي → كامل الجسم ×3.
 */
function program_blueprint(string $goal): array {
    if (in_array($goal, ['muscle_gain','strength','performance'], true)) {
        return [
            ['title' => 'دفع (صدر/كتف/تراي)', 'muscle_group' => 'push',     'day_offset' => 0, 'focus' => ['chest','shoulders','arms']],
            ['title' => 'سحب (ظهر/باي)',      'muscle_group' => 'pull',     'day_offset' => 2, 'focus' => ['back','arms']],
            ['title' => 'أرجل (أرجل/جلوت/كور)','muscle_group' => 'legs',     'day_offset' => 4, 'focus' => ['legs','glutes','core']],
        ];
    }
    return [
        ['title' => 'كامل الجسم A', 'muscle_group' => 'full body', 'day_offset' => 0, 'focus' => ['legs','chest','back','core']],
        ['title' => 'كامل الجسم B', 'muscle_group' => 'full body', 'day_offset' => 2, 'focus' => ['legs','back','shoulders','core']],
        ['title' => 'كامل الجسم C', 'muscle_group' => 'full body', 'day_offset' => 4, 'focus' => ['legs','chest','arms','core']],
    ];
}

/** تصنيف مؤشر كتلة الجسم → [الفئة، اللون، الأيقونة]. */
function bmi_category(float $bmi): array {
    if ($bmi <= 0)    return ['—', '#94a3b8', '❔'];
    if ($bmi < 18.5)  return ['نقص وزن', '#eab308', '⚡'];
    if ($bmi < 25)    return ['طبيعي', '#22c55e', '✅'];
    if ($bmi < 30)    return ['زيادة وزن', '#f59e0b', '⚠️'];
    if ($bmi < 35)    return ['سمنة درجة 1', '#f97316', '⚠️'];
    if ($bmi < 40)    return ['سمنة درجة 2', '#ef4444', '🚨'];
    return ['سمنة مفرطة', '#dc2626', '🚨'];
}

/**
 * لوحة الرياضي اليومية: تجمع مقاييس العضو الحقيقية في عرض موحّد مع بطاقات
 * وتنبيهات وتوصيات ذكية. دالة عرض صرفة: تأخذ القيم المُجمّعة وتُرجع بنية اللوحة.
 * مصدر واحد للجاهزية: readinessScore = 100 − fatigue (يُصحّح ازدواج المنطق الأصلي).
 *
 * @param array $x name,program,bmi,weight,body_fat,calories,protein,risk_num(0..100),
 *                 fatigue(0..100),sleep_hours(?),attendance30
 */
function athlete_dashboard(array $x): array {
    $bmi     = (float)($x['bmi'] ?? 0);
    $weight  = (float)($x['weight'] ?? 0);
    $cal     = (int)($x['calories'] ?? 0);
    $prot    = (float)($x['protein'] ?? 0);
    $riskNum = (int)($x['risk_num'] ?? 0);
    $fatigue = max(0, min(100, (int)($x['fatigue'] ?? 0)));
    $sleep   = isset($x['sleep_hours']) && $x['sleep_hours'] !== null ? (float)$x['sleep_hours'] : null;
    $program = (string)($x['program'] ?? 'لياقة عامة');
    $readiness = 100 - $fatigue;

    // نطاق الخطر
    if ($riskNum >= 60)      [$riskLabel, $riskBand, $riskColor] = ['مرتفع', 'high', '#ef4444'];
    elseif ($riskNum >= 35)  [$riskLabel, $riskBand, $riskColor] = ['متوسط', 'moderate', '#eab308'];
    else                     [$riskLabel, $riskBand, $riskColor] = ['منخفض', 'low', '#22c55e'];
    [$bmiCat, $bmiColor, $bmiIcon] = bmi_category($bmi);

    // ── التنبيهات ──
    $alerts = [];
    if ($riskBand === 'high')
        $alerts[] = ['type'=>'danger','icon'=>'🚨','message'=>"مستوى خطر مرتفع ($riskNum/100) — راجع البيانات فورًا",'action'=>'مراجعة PAR-Q والتاريخ الطبي'];
    if ($fatigue >= 65)
        $alerts[] = ['type'=>'danger','icon'=>'😴','message'=>"إرهاق مرتفع ($fatigue/100) — يُنصح بيوم راحة",'action'=>'تخفيف الحمل التدريبي 40–50%'];
    elseif ($fatigue >= 45)
        $alerts[] = ['type'=>'warning','icon'=>'⚠️','message'=>"إرهاق متوسط ($fatigue/100) — راقب التعافي",'action'=>'تقليل الحجم أو الشدة 20%'];
    if ($bmi > 30)
        $alerts[] = ['type'=>'warning','icon'=>'⚖️','message'=>"مؤشر كتلة الجسم $bmi — $bmiCat",'action'=>'برنامج تنشيف + متابعة'];
    elseif ($bmi > 0 && $bmi < 18.5)
        $alerts[] = ['type'=>'info','icon'=>'⚖️','message'=>"مؤشر كتلة الجسم $bmi — نقص وزن",'action'=>'زيادة السعرات + برنامج تضخيم'];
    if ($cal > 0 && $cal < 1200)
        $alerts[] = ['type'=>'warning','icon'=>'🍽️','message'=>"سعرات منخفضة جدًا ($cal) — خطر على الصحة",'action'=>'رفع السعرات إلى 1500 على الأقل'];
    if ($weight > 0 && $prot > 0 && $prot < 1.2 * $weight)
        $alerts[] = ['type'=>'info','icon'=>'🥩','message'=>'البروتين أقل من الموصى به','action'=>'استهدف 1.6–2.2 جم/كجم'];

    // ── التوصيات ──
    $recs = [];
    if ($readiness >= 80)     $recs[] = ['category'=>'training','priority'=>'low','text'=>'جاهزية عالية — يمكن رفع الشدة أو الحجم هذا الأسبوع'];
    elseif ($readiness >= 50) $recs[] = ['category'=>'training','priority'=>'medium','text'=>'تدريب معتدل — حافظ على الشدة الحالية'];
    else                      $recs[] = ['category'=>'training','priority'=>'high','text'=>'الأولوية للتعافي — تمارين خفيفة أو راحة نشطة'];
    if ($cal > 0) {
        $pPct = (int)round($prot * 4 / $cal * 100);
        if ($pPct < 25) $recs[] = ['category'=>'nutrition','priority'=>'medium','text'=>"نسبة البروتين منخفضة ($pPct%) — استهدف 25–35%"];
    }
    if ($sleep !== null && $sleep < 6)
        $recs[] = ['category'=>'recovery','priority'=>'high','text'=>'النوم غير كافٍ ('.$sleep.'س) — استهدف 7–9 ساعات'];

    // ── البطاقات ──
    $cards = [
        ['label'=>'BMI','value'=>$bmi>0?number_format($bmi,1):'—','unit'=>'kg/m²','color'=>$bmiColor,'icon'=>$bmiIcon,'sub'=>$bmiCat],
        ['label'=>'السعرات','value'=>$cal?:'—','unit'=>'kcal','color'=>$cal>=1800?'#22c55e':($cal?'#eab308':'#94a3b8'),'icon'=>'🔥','sub'=>''],
        ['label'=>'بروتين','value'=>$prot?rtrim(rtrim(number_format($prot,1),'0'),'.'):'—','unit'=>'g','color'=>$prot>=100?'#22c55e':($prot?'#eab308':'#94a3b8'),'icon'=>'💪','sub'=>''],
        ['label'=>'الخطر','value'=>$riskLabel,'unit'=>'','color'=>$riskColor,'icon'=>'⚠️','sub'=>$riskNum.'/100'],
        ['label'=>'الإرهاق','value'=>$fatigue,'unit'=>'/100','color'=>$fatigue<=35?'#22c55e':($fatigue<=60?'#eab308':'#ef4444'),'icon'=>'😴','sub'=>''],
        ['label'=>'الجاهزية','value'=>$readiness,'unit'=>'/100','color'=>$readiness>=70?'#22c55e':($readiness>=40?'#eab308':'#ef4444'),'icon'=>$readiness>=70?'🟢':($readiness>=40?'🟡':'🔴'),'sub'=>''],
    ];

    $trainReco = $readiness >= 70 ? 'جاهز للتدريب الكامل' : ($readiness >= 40 ? 'تدريب معدّل (خفيف–متوسط)' : 'راحة أو تعافٍ نشط');
    $summary = ($readiness >= 70 ? '✅ جاهز للتدريب' : '⚠️ يحتاج تعافي') . " · $program · " . ($cal?:'—') . " kcal · " . ($prot?rtrim(rtrim(number_format($prot,1),'0'),'.'):'—') . "g بروتين";

    return [
        'readiness_score' => $readiness,
        'fatigue'         => $fatigue,
        'risk_label'      => $riskLabel, 'risk_band' => $riskBand, 'risk_num' => $riskNum,
        'bmi_category'    => $bmiCat,
        'cards'           => $cards,
        'alerts'          => $alerts,
        'recommendations' => $recs,
        'training_reco'   => $trainReco,
        'ready_to_train'  => $readiness >= 60,
        'needs_rest'      => $readiness < 40,
        'summary'         => $summary,
    ];
}

/**
 * حاسبة ماكروز الحصة من وصفة. الإصلاح المحترف: القسمة على **الوزن المطبوخ**
 * (الحصيلة) لا مجموع الخام — الطبخ يغيّر الوزن والماكروز محفوظة، فتُوزَّع على
 * وزن الطبق المطبوخ. عند غياب الوزن المطبوخ نرجع لمجموع الخام كتقريب موثّق.
 * دالة صرفة.
 * @param ?float $cookedWeight الوزن المطبوخ للوصفة (المقام الصحيح) أو null
 * @param array  $items        [['name','amount_grams','cal100','p100','c100','f100'], ...]
 * @param float  $portion      وزن الحصة المطلوبة بالجرام
 */
function recipe_portion_macros(?float $cookedWeight, array $items, float $portion): array {
    $rawTotal = 0.0;
    foreach ($items as $it) $rawTotal += (float)$it['amount_grams'];
    $denom = ($cookedWeight !== null && $cookedWeight > 0) ? $cookedWeight : $rawTotal;
    $denomSource = ($cookedWeight !== null && $cookedWeight > 0) ? 'cooked' : 'raw';
    if ($denom <= 0 || $portion <= 0)
        return ['ok' => false, 'msg' => 'وزن غير صالح للحساب.'];

    // ماكروز الوصفة بالكامل (من الكميات الخام × القيم/100غ)
    $tot = ['cal' => 0.0, 'p' => 0.0, 'c' => 0.0, 'f' => 0.0];
    $rows = [];
    foreach ($items as $it) {
        $w = (float)$it['amount_grams']; $k = $w / 100.0;
        $cal = (float)$it['cal100'] * $k; $p = (float)$it['p100'] * $k;
        $c = (float)$it['c100'] * $k;   $f = (float)$it['f100'] * $k;
        $tot['cal'] += $cal; $tot['p'] += $p; $tot['c'] += $c; $tot['f'] += $f;
        $rows[] = ['name' => $it['name'], 'raw' => $w, 'cal' => $cal, 'p' => $p, 'c' => $c, 'f' => $f];
    }
    $factor = $portion / $denom;                       // نسبة الحصة من الطبق كاملًا

    $breakdown = array_map(fn($r) => [
        'name'   => $r['name'],
        'amount' => round($r['raw'] * $factor, 1),      // مساهمة المكوّن (خام) في هذه الحصة
        'cal'    => round($r['cal'] * $factor, 1),
        'p'      => round($r['p']   * $factor, 1),
        'c'      => round($r['c']   * $factor, 1),
        'f'      => round($r['f']   * $factor, 1),
    ], $rows);

    return [
        'ok'           => true,
        'portion'      => $portion,
        'denom'        => round($denom, 1),
        'denom_source' => $denomSource,               // cooked = دقيق، raw = تقريب
        'raw_total'    => round($rawTotal, 1),
        'calories'     => round($tot['cal'] * $factor, 1),
        'protein'      => round($tot['p']   * $factor, 1),
        'carbs'        => round($tot['c']   * $factor, 1),
        'fats'         => round($tot['f']   * $factor, 1),
        'kcal_per_100' => round($tot['cal'] / $denom * 100, 1),   // كثافة الطبق (kcal/100غ)
        'breakdown'    => $breakdown,
    ];
}

/**
 * اختيار تمارين لجلسة: يغطّي مجموعات التركيز بالترتيب، يتجنّب المجموعات المُصابة،
 * بحد أقصى $cap تمرينًا. $exByGroup: خريطة muscle_group => [صفوف التمارين].
 */
function select_session_exercises(array $exByGroup, array $focus, array $avoid, int $cap = 5): array {
    $picked = [];
    foreach ($focus as $g) {
        if (in_array($g, $avoid, true)) continue;
        foreach ($exByGroup[$g] ?? [] as $ex) {
            if (count($picked) >= $cap) break 2;
            $picked[] = $ex;
            break; // تمرين واحد لكل مجموعة تركيز في التمريرة الأولى
        }
    }
    // تمريرة ثانية لملء الباقي من نفس مجموعات التركيز
    foreach ($focus as $g) {
        if (in_array($g, $avoid, true)) continue;
        foreach ($exByGroup[$g] ?? [] as $ex) {
            if (count($picked) >= $cap) break 2;
            if (!in_array($ex, $picked, true)) $picked[] = $ex;
        }
    }
    return $picked;
}

// =============================================================================
// تحليل التغذية الذكي (بقواعد): الوزن + الهدف → سعرات وماكروز مقترحة
// =============================================================================

/**
 * أهداف تغذية مقترحة من وزن الجسم (كجم) + الهدف.
 * صيانة ≈ 31 سعرة/كجم؛ تنشيف −20%؛ تضخيم +12%.
 * بروتين 2.2 جم/كجم (تنشيف/تضخيم/قوة) وإلا 1.8؛ دهون 0.9 جم/كجم؛ الباقي كارب.
 */
function nutrition_targets(float $weightKg, string $goal): array {
    if ($weightKg <= 0) return ['calories' => null, 'protein_g' => null, 'fat_g' => null, 'carbs_g' => null, 'hydration_l' => null, 'note' => 'أدخل وزنًا حديثًا للعضو أولًا'];
    $maint = $weightKg * 31;
    $adj   = ['fat_loss' => -0.20, 'muscle_gain' => 0.12][$goal] ?? 0.0;
    $cal   = (int) (round($maint * (1 + $adj) / 10) * 10);
    $proteinPerKg = in_array($goal, ['fat_loss','muscle_gain','strength'], true) ? 2.2 : 1.8;
    $protein = (int) round($weightKg * $proteinPerKg);
    $fat     = (int) round($weightKg * 0.9);
    $carbs   = (int) max(0, round(($cal - ($protein * 4 + $fat * 9)) / 4));
    $hyd     = round($weightKg * 0.035, 1);
    $note = $adj < 0 ? 'عجز حراري ~20% للتنشيف مع بروتين مرتفع للحفاظ على العضل'
          : ($adj > 0 ? 'فائض حراري ~12% للتضخيم النظيف'
          : 'سعرات صيانة متوازنة');
    return ['calories' => $cal, 'protein_g' => $protein, 'fat_g' => $fat, 'carbs_g' => $carbs, 'hydration_l' => $hyd, 'note' => $note];
}

// =============================================================================
// تحليل التغذية المتقدّم: توزيع الوجبات + refeed/diet-break + تتبّع الالتزام
// =============================================================================

/**
 * توزيع الماكروز على الوجبات بأسلوب احترافي:
 *   - البروتين موزّع بالتساوي (عتبة اللوسين لتحفيز بناء العضل كل ~3–4 ساعات).
 *   - الكارب مركّز حول التمرين (وجبتا ما قبل/بعد التمرين تأخذان حصة أكبر).
 *   - الدهون أقل في وجبة التمرين (هضم أسرع) وأعلى في الوجبات البعيدة.
 * $trainMeal = فهرس وجبة التمرين (1-based، 0 = لا يوجد توقيت تمرين محدّد).
 * يرجّع مصفوفة وجبات، كل وجبة ['label','is_train','protein','carbs','fat','calories'].
 */
function meal_distribution(int $protein, int $carbs, int $fat, int $meals, int $trainMeal = 0): array {
    $meals = max(1, min(8, $meals));
    // أوزان الكارب: وجبتا ما قبل/بعد التمرين ×2، الباقي ×1
    $carbW = array_fill(1, $meals, 1.0);
    $fatW  = array_fill(1, $meals, 1.0);
    if ($trainMeal >= 1 && $trainMeal <= $meals) {
        $carbW[$trainMeal] = 2.0;                          // وجبة ما بعد التمرين
        if ($trainMeal - 1 >= 1) $carbW[$trainMeal - 1] = 1.6; // ما قبل التمرين
        $fatW[$trainMeal] = 0.4;                           // دهون أقل حول التمرين
    }
    $carbTot = array_sum($carbW);
    $fatTot  = array_sum($fatW);
    $pEach   = (int) round($protein / $meals);
    $out = [];
    for ($i = 1; $i <= $meals; $i++) {
        $c = (int) round($carbs * $carbW[$i] / $carbTot);
        $f = (int) round($fat   * $fatW[$i]  / $fatTot);
        $out[] = [
            'label'    => 'الوجبة ' . $i . ($i === $trainMeal ? ' (بعد التمرين)' : ($i === $trainMeal - 1 ? ' (قبل التمرين)' : '')),
            'is_train' => $i === $trainMeal,
            'protein'  => $pEach,
            'carbs'    => $c,
            'fat'      => $f,
            'calories' => $pEach * 4 + $c * 4 + $f * 9,
        ];
    }
    return $out;
}

/**
 * بروتوكول أيام الكارب العالي (Refeed) — مطلوب أساسًا أثناء التنشيف.
 * يرفع الكارب إلى مستوى الصيانة يومًا–يومين أسبوعيًا لاستعادة الليبتين والجليكوجين.
 * يرجّع ['applicable','frequency','carb_target','note'] أو applicable=false لغير التنشيف.
 */
function refeed_plan(string $goal, float $weightKg, int $baseProtein, int $baseFat): array {
    if ($goal !== 'fat_loss' || $weightKg <= 0)
        return ['applicable' => false, 'note' => 'أيام الكارب العالي غير ضرورية لهذا الهدف — تُستخدم أساسًا أثناء التنشيف.'];
    // كارب الصيانة = السعرات عند الصيانة ناقص البروتين/الدهون ÷ 4
    $maintCal    = $weightKg * 31;
    $refeedCarbs = (int) max(0, round(($maintCal - ($baseProtein * 4 + $baseFat * 9)) / 4));
    return [
        'applicable'  => true,
        'frequency'   => 'يوم–يومان أسبوعيًا (يفضَّل أيام التمرين الثقيل)',
        'carb_target' => $refeedCarbs,
        'note'        => 'ارفع الكارب إلى ~' . $refeedCarbs . ' جم مع خفض الدهون، لاستعادة الليبتين وامتلاء الجليكوجين وتحسين الأداء.',
    ];
}

/** بروتوكول فترة راحة الدايت (Diet Break) — أثناء التنشيف الطويل فقط. */
function diet_break_plan(string $goal): array {
    if ($goal !== 'fat_loss')
        return ['applicable' => false, 'note' => 'غير مطلوب لهذا الهدف.'];
    return [
        'applicable' => true,
        'note'       => 'كل 6–8 أسابيع تنشيف متواصل: خذ 1–2 أسبوع على سعرات الصيانة لاستعادة الهرمونات (الغدة الدرقية/الليبتين)، تقليل الإجهاد، والحفاظ على العضل قبل استئناف العجز الحراري.',
    ];
}

/**
 * حساب نسبة الالتزام الغذائي من سجلّات يومية:
 *   on_plan=1 · partial=0.5 · off_plan=0 → متوسط موزون %.
 * يرجّع ['pct','label','color','count'].
 */
function nutrition_compliance(array $logs): array {
    $w = ['on_plan' => 1.0, 'partial' => 0.5, 'off_plan' => 0.0];
    $n = count($logs);
    if ($n === 0) return ['pct' => null, 'label' => 'لا سجل بعد', 'color' => '#94a3b8', 'count' => 0];
    $sum = 0.0;
    foreach ($logs as $lg) $sum += $w[$lg['adherence']] ?? 0.0;
    $pct = (int) round($sum / $n * 100);
    if ($pct >= 85) return ['pct' => $pct, 'label' => 'التزام ممتاز', 'color' => '#16a34a', 'count' => $n];
    if ($pct >= 60) return ['pct' => $pct, 'label' => 'التزام جيد', 'color' => '#f59e0b', 'count' => $n];
    return ['pct' => $pct, 'label' => 'التزام ضعيف', 'color' => '#dc2626', 'count' => $n];
}

// =============================================================================
// محرّك الاحتفاظ ومكافحة التسرّب (بقواعد): تسميات الإنذارات + التدخّل + قوالب الرسائل
// =============================================================================

/** بيانات عرض نوع الإنذار: ['label','color','priority'] (الأولوية أعلى = أخطر) */
function flag_meta(string $type): array {
    $map = [
        'injury'         => ['إصابة',            '#dc2626', 9],
        'payment_failed' => ['فشل الدفع',        '#dc2626', 8],
        'high_risk'      => ['خطر مرتفع',        '#dc2626', 7],
        'no_progress'    => ['لا تقدّم',         '#f59e0b', 5],
        'low_attendance' => ['غياب متكرر',       '#f59e0b', 4],
        'no_show'        => ['عدم حضور',         '#f59e0b', 4],
        'low_motivation' => ['دافعية منخفضة',    '#f59e0b', 3],
        'low_response'   => ['ضعف تفاعل',        '#6b7280', 2],
    ];
    [$label, $color, $prio] = $map[$type] ?? [$type, '#6b7280', 1];
    return ['label' => $label, 'color' => $color, 'priority' => $prio];
}

/**
 * التدخّل الموصى به: يُختار حسب أخطر إنذار مفتوح.
 * يرجّع ['action','scenario','color'] حيث scenario يربط بقالب الرسالة.
 */
function recommended_intervention(array $flagTypes): array {
    $playbook = [
        'injury'         => ['متابعة طبية عاجلة + تحويل لبرنامج تأهيلي مؤقّت', 'reassess',   '#dc2626'],
        'payment_failed' => ['تذكير دفع ودّي + عرض خيار تقسيط/تمديد',          'payment',    '#dc2626'],
        'high_risk'      => ['تدخّل المدير + خطة احتفاظ مخصّصة ومكالمة شخصية',  'checkin',    '#dc2626'],
        'no_progress'    => ['إعادة تقييم + تعديل البرنامج وتحديد هدف قصير',    'reassess',   '#f59e0b'],
        'low_attendance' => ['تواصل تحفيزي + جدولة جلسة محدّدة هذا الأسبوع',    'motivation', '#f59e0b'],
        'no_show'        => ['اتصال لإعادة الجدولة + تذكير بقيمة الاشتراك',     'motivation', '#f59e0b'],
        'low_motivation' => ['مكالمة تحفيز + تحدٍّ قصير قابل للتحقيق',          'motivation', '#f59e0b'],
        'low_response'   => ['تغيير قناة التواصل + رسالة قصيرة مباشرة',        'checkin',    '#6b7280'],
    ];
    $best = null; $bestPrio = -1;
    foreach ($flagTypes as $t) {
        $p = flag_meta($t)['priority'];
        if ($p > $bestPrio) { $bestPrio = $p; $best = $t; }
    }
    if ($best === null || !isset($playbook[$best]))
        return ['action' => 'تواصل تحقّق دوري للحفاظ على العلاقة', 'scenario' => 'checkin', 'color' => '#6b7280'];
    [$action, $scenario, $color] = $playbook[$best];
    return ['action' => $action, 'scenario' => $scenario, 'color' => $color];
}

/** قالب رسالة جاهز حسب السيناريو (يُدرج اسم العضو). */
function retention_message(string $scenario, string $name): array {
    $n = trim($name) !== '' ? $name : 'بطلنا';
    $templates = [
        'winback'    => ['winback', "أهلًا {$n} 👋 وحشتنا في الجيم! رجوعك يهمّنا — جهّزنالك عرض خاص لاستئناف اشتراكك وبرنامج جديد يناسب هدفك. تحب نحجزلك جلسة رجوع؟"],
        'payment'    => ['renewal', "أهلًا {$n} 🙏 لاحظنا إن فيه مشكلة في دفع الاشتراك. حابين نسهّلها عليك — عندنا خيار تقسيط أو تمديد. تحب نرتّبها مع بعض؟"],
        'motivation' => ['followup', "{$n} 💪 افتقدناك في الجلسات! خطوة صغيرة النهارده بتفرق. جهّزت لك تمرين خفيف نبدأ بيه — إيه أنسب وقت أشوفك فيه هذا الأسبوع؟"],
        'reassess'   => ['progress', "أهلًا {$n} 📊 مرّ وقت من آخر تقييم. تعال نراجع تقدّمك ونعدّل البرنامج عشان توصل لهدفك أسرع — أحجزلك موعد إعادة تقييم؟"],
        'renewal'    => ['renewal', "أهلًا {$n} ⏰ اشتراكك قرب يخلص. جدّد بدري وكمّل تقدّمك بدون انقطاع — تحب أجهّزلك التجديد؟"],
        'checkin'    => ['followup', "أهلًا {$n} 😊 بنطمّن عليك — إزاي ماشي مع البرنامج؟ لو محتاج أي مساعدة أو تعديل إحنا موجودين."],
    ];
    [$msgType, $text] = $templates[$scenario] ?? $templates['checkin'];
    return ['message_type' => $msgType, 'text' => $text];
}

/** نطاق خطر التسرّب من درجة الخطر: ['label','color'] */
function churn_band(?float $riskScore): array {
    if ($riskScore === null) return ['غير مقيَّم', '#94a3b8'];
    if ($riskScore >= 80) return ['حرج', '#dc2626'];
    if ($riskScore >= 60) return ['مرتفع', '#f59e0b'];
    if ($riskScore >= 40) return ['متوسط', '#eab308'];
    return ['منخفض', '#16a34a'];
}

// =============================================================================
// التجديدات والإيرادات المتوقّعة (بقواعد): MRR + احتمال التجديد + المتحصّلات
// =============================================================================

/** تطبيع سعر الخطة إلى قيمة شهرية (MRR) حسب مدّتها بالأيام */
function monthlyize(float $price, int $durationDays): float {
    if ($durationDays <= 0) return round($price, 0);
    return round($price / $durationDays * 30, 0);
}

/**
 * احتمال التجديد (0–1) لتقدير الإيراد المتوقّع، من حالة التجديد + الدفع +
 * التجديد التلقائي + الأيام المتبقّية. مُجدَّد=1، ملغى=0، والباقي بقواعد.
 */
function renewal_likelihood(string $renewalStatus, string $paymentStatus, bool $autoRenew, int $daysLeft): float {
    if ($renewalStatus === 'renewed')   return 1.0;
    if ($renewalStatus === 'cancelled') return 0.0;
    $base   = $autoRenew ? 0.85 : 0.50;
    $payAdj = ['paid' => 0.10, 'partial' => 0.0, 'unpaid' => -0.15, 'failed' => -0.35, 'refunded' => -0.40][$paymentStatus] ?? 0.0;
    if ($renewalStatus === 'expired' || $daysLeft < 0) $base -= 0.25;   // المنتهي أقل احتمالًا
    return max(0.0, min(1.0, $base + $payAdj));
}

/** المبلغ المتبقّي (غير المُحصَّل) من قيمة اشتراك حسب حالة الدفع */
function payment_outstanding(float $price, string $paymentStatus): float {
    if ($paymentStatus === 'unpaid' || $paymentStatus === 'failed') return round($price, 0);
    if ($paymentStatus === 'partial') return round($price * 0.5, 0);
    return 0.0;   // paid / refunded
}

/** تنسيق مبلغ بالجنيه المصري بفواصل آلاف */
function money(float $v): string {
    return number_format($v, 0) . ' ج.م';
}

// =============================================================================
// المحاسبة ونقطة البيع (بقواعد)
// =============================================================================

/** إجمالي سلّة البيع من بنودها [['qty'=>int,'unit_price'=>float], ...] */
function pos_cart_total(array $lines): float {
    $t = 0.0;
    foreach ($lines as $l) $t += max(0, (int)($l['qty'] ?? 0)) * (float)($l['unit_price'] ?? 0);
    return round($t, 2);
}

/** صافي الربح = الدخل − المصروفات */
function net_profit(float $income, float $expenses): float {
    return round($income - $expenses, 2);
}

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

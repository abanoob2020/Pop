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

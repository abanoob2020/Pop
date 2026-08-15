<?php
// =============================================================================
// tests/test_pure.php — اختبارات دوال نقية (بلا قاعدة/framework). شغّل: php tests/test_pure.php
// تغطّي منطق الأحمال/الوصفات الحرِج في training.php (لا يمسّ أي بيانات).
// =============================================================================
require __DIR__ . '/../dashboard/training.php';

$fail = 0; $pass = 0;
function check(bool $cond, string $msg): void {
    global $fail, $pass;
    if ($cond) { $pass++; echo "  ✓ $msg\n"; }
    else       { $fail++; echo "  ✗ FAIL: $msg\n"; }
}
$approx = fn($a, $b, $e = 0.6) => $a !== null && abs((float)$a - (float)$b) <= $e;

echo "== pure function tests ==\n";

// المحرّك التكيّفي: وزن العمل القادم ≠ الـ1RM (إصلاح احترافي أساسي).
$d = adaptive_load_decision(60, 8, 8, 2, 'epley', null);
check($approx($d['est_1rm'], 76.0), "60×8 epley ⇒ 1RM ≈ 76 (got {$d['est_1rm']})");
check($d['next_weight'] < $d['est_1rm'], "next working weight < 1RM (وزن العمل دون الـ1RM)");
check($d['action'] === 'up', "RPE8/RIR2 ⇒ up");
$d2 = adaptive_load_decision(60, 8, 9, 1, 'epley', 76.0);
check($d2['action'] === 'hold' && (float)$d2['next_weight'] === 60.0, "RPE9/RIR1 ⇒ hold @ performed");
$d3 = adaptive_load_decision(60, 8, 10, 0, 'epley', 76.0);
check($d3['action'] === 'warn' && (float)$d3['next_weight'] < 60.0 && $d3['alert'] !== null, "RPE10/RIR0 ⇒ warn+deload+alert");

// دوال 1RM والتقريب
check($approx(epley_1rm(100, 1), 100.0, 0.01), "epley 1 rep = load");
check((float)round25(61.0) === 60.0, "round25(61) = 60.0");
check((float)round25(64.0) === 65.0, "round25(64) = 65.0");

// حاسبة الوصفات: القسمة على الوزن المطبوخ (المقام الصحيح)
$items = [['amount_grams' => 400, 'name' => 'أرز', 'cal100' => 350, 'p100' => 7, 'c100' => 78, 'f100' => 1]];
$r = recipe_portion_macros(1400.0, $items, 450.0);
check($r['ok'] === true && $r['denom_source'] === 'cooked', "recipe: يستخدم الوزن المطبوخ كمقام");
$rRaw = recipe_portion_macros(null, $items, 450.0);
check($rRaw['ok'] === true && $rRaw['denom_source'] === 'raw', "recipe: يسقط للوزن الخام عند غياب المطبوخ");

echo "\nRESULT: pass=$pass fail=$fail\n";
exit($fail ? 1 : 0);

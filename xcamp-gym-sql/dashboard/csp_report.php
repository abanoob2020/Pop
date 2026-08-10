<?php
// =============================================================================
// Xcamp Gym — نقطة تجميع تقارير مخالفات CSP (وضع القياس CSP_REPORT_ONLY=1).
// يستقبل تقرير المتصفّح، يسجّل مختصرًا عبر app_log('WARNING','csp_violation'، …)،
// ويردّ 204 بلا أي إخراج. لا يتطلّب تسجيل دخول (المتصفّح يرسله تلقائيًا).
// آمن: يحدّ حجم الجسم، يفكّ JSON، ويستخرج حقولًا محدّدة فقط (لا انعكاس/إخراج).
// =============================================================================
require __DIR__ . '/db.php';   // يمنح app_log()

http_response_code(204);       // No Content

// حدّ 16KB للجسم لتفادي إساءة الاستخدام.
$raw = file_get_contents('php://input', false, null, 0, 16384);
if ($raw === '' || $raw === false) return;

$j = json_decode($raw, true);
if (!is_array($j)) return;
$r = $j['csp-report'] ?? $j;   // صيغة report-uri الكلاسيكية أو report-to
if (!is_array($r)) return;

app_log('WARNING', 'csp_violation', [
    'directive' => (string)($r['effective-directive'] ?? $r['violated-directive'] ?? ''),
    'blocked'   => (string)($r['blocked-uri'] ?? ''),
    'document'  => (string)($r['document-uri'] ?? ''),
    'source'    => (string)($r['source-file'] ?? ''),
    'line'      => (int)($r['line-number'] ?? 0),
]);

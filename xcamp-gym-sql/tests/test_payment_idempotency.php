<?php
// =============================================================================
// tests/test_payment_idempotency.php — اختبارات انحدار لحماية تكرار الدفع (P0-B).
// تغطّي: تحقّق مفتاح idempotency (بما فيه ما يؤول إلى فراغ بعد التطبيع) + دورة
// الازدواج الكاملة عبر HTTP حقيقي.
//
// يتطلّب خادمًا يعمل + بيانات دخول موظّف. المتغيّرات (كلها اختيارية بقيم افتراضية):
//   BASE_URL (http://127.0.0.1:8097) ADMIN_EMAIL ADMIN_PASS
//   DB_HOST DB_PORT DB_NAME DB_USER DB_PASS   (لعدّ صفوف payments)
// شغّل:  BASE_URL=... DB_USER=... DB_PASS=... php tests/test_payment_idempotency.php
// يتخطّى بأمان (exit 0) إن تعذّر الوصول للخادم، حتى لا يكسر تشغيلًا بلا بيئة ويب.
// =============================================================================

$base  = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8097', '/');
$email = getenv('ADMIN_EMAIL') ?: 'admin@xcamp.com';
$pass  = getenv('ADMIN_PASS')  ?: 'admin123';

$fail = 0; $pass_c = 0;
function check(bool $cond, string $msg): void {
    global $fail, $pass_c;
    if ($cond) { $pass_c++; echo "  ✓ $msg\n"; }
    else       { $fail++;   echo "  ✗ FAIL: $msg\n"; }
}

// ── عميل HTTP بسيط يحتفظ بالكوكيز ──
$jar = tempnam(sys_get_temp_dir(), 'xcj');
register_shutdown_function(fn() => @unlink($jar));
function http(string $url, ?array $post = null): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 10,
    ]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}
$csrfOf = fn(string $html) => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
$idemOf = fn(string $html) => preg_match('/name="idempotency_key" value="([^"]+)"/', $html, $m) ? $m[1] : '';

echo "== payment idempotency regression (HTTP) ==\n";

$login = http("$base/login.php");
if ($login['code'] !== 200) { echo "  ⚠ SKIP: الخادم غير متاح على $base\n\nRESULT: skipped\n"; exit(0); }

http("$base/login.php", ['csrf' => $csrfOf($login['body']), 'email' => $email, 'password' => $pass]);
$fin = http("$base/finance.php");
if (strpos($fin['body'], 'record_membership_payment') === false) {
    echo "  ⚠ SKIP: تعذّر الدخول أو لا اشتراكات مستحقّة\n\nRESULT: skipped\n"; exit(0);
}
$csrf = $csrfOf($fin['body']);

// عدّاد صفوف payments (اختياري — يُتخطّى إن تعذّر الاتصال بالقاعدة)
$pdo = null;
try {
    $h = getenv('DB_HOST') ?: '127.0.0.1'; $p = getenv('DB_PORT') ?: '3306';
    $n = getenv('DB_NAME') ?: 'xcamp_gym'; $u = getenv('DB_USER') ?: 'root';
    $w = getenv('DB_PASS'); $w = ($w === false) ? '' : $w;
    $pdo = new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", $u, $w, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { $pdo = null; }
$countPayments = fn() => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn() : -1;

$msid = 0;
if ($pdo) $msid = (int)$pdo->query("SELECT membership_id FROM memberships ORDER BY membership_id LIMIT 1")->fetchColumn();
if ($msid <= 0 && preg_match('/name="membership_id"[\s\S]*?option value="(\d+)"/', $fin['body'], $m)) $msid = (int)$m[1];

$post = fn(string $key, $amount) => http("$base/finance.php", [
    'csrf' => $GLOBALS['csrf'], 'action' => 'record_membership_payment',
    'idempotency_key' => $key, 'membership_id' => $GLOBALS['msid'],
    'amount' => $amount, 'method' => 'cash',
]);

// ── (أ) تحقّق المفتاح: كل ما يؤول إلى فراغ يجب أن يُرفض 400 قبل أي إدراج ──
$before = $countPayments();

$r = http("$base/finance.php", ['csrf' => $csrf, 'action' => 'record_membership_payment',
          'membership_id' => $msid, 'amount' => 111, 'method' => 'cash']);   // بلا الحقل أصلًا
check($r['code'] === 400 && strpos($r['body'], 'missing_idempotency_key') !== false, "missing key ⇒ 400");

foreach ([
    ''            => 'empty key ⇒ 400',
    '   '         => 'whitespace-only ⇒ 400',
    '!!!'         => 'punctuation-only (يؤول إلى فراغ بعد التطبيع) ⇒ 400',
    '---'         => 'dashes-only (يؤول إلى فراغ) ⇒ 400',
    '@#$%^&*()'   => 'symbols-only (يؤول إلى فراغ) ⇒ 400',
] as $key => $label) {
    $r = $post((string)$key, 222);
    check($r['code'] === 400 && strpos($r['body'], 'missing_idempotency_key') !== false, $label);
}

check($countPayments() === $before, "لا صفّ دفع أُنشئ من أي مفتاح مرفوض");

// ── (ب) دورة الازدواج الكاملة ──
$valid = bin2hex(random_bytes(16));
$n0 = $countPayments();

$r1 = $post($valid, 500);
check($r1['code'] === 302, "(1) دفعة أصلية بمفتاح صالح ⇒ مقبولة (302)");
check($countPayments() === $n0 + 1, "(1) الصفوف +1");

$r2 = $post($valid, 500);
check($r2['code'] === 302, "(2) إعادة إرسال مطابقة ⇒ نجاح idempotent");
check($countPayments() === $n0 + 1, "(2) الصفوف +0 (لا ازدواج)");

$r3 = $post($valid, 800);
check($r3['code'] === 409 && strpos($r3['body'], 'duplicate_idempotency_key_mismatch') !== false,
      "(3) نفس المفتاح بحمولة مختلفة ⇒ 409");
check($countPayments() === $n0 + 1, "(3) الصفوف +0 (لا كتابة عند التعارض)");

$r4 = $post('!!!', 900);
check($r4['code'] === 400, "(4) مفتاح يؤول إلى فراغ ⇒ 400");
check($countPayments() === $n0 + 1, "(4) الصفوف +0");

echo "\nRESULT: pass=$pass_c fail=$fail\n";
exit($fail ? 1 : 0);

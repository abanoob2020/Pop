<?php
// =============================================================================
// tests/test_db_invariants.php — اختبارات ثوابت القاعدة الحرِجة (P0).
// آمنة: كل كتابة داخل معاملة تُلغى بـ ROLLBACK (reversible، لا تغيّر بيانات).
// يقرأ الاتصال من متغيّرات البيئة (DB_HOST/PORT/NAME/USER/PASS).
// شغّل: DB_USER=... DB_PASS=... php tests/test_db_invariants.php
// =============================================================================
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_NAME') ?: 'xcamp_gym';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS'); if ($pass === false) $pass = '';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$fail = 0; $pass_c = 0;
function check(bool $cond, string $msg): void {
    global $fail, $pass_c;
    if ($cond) { $pass_c++; echo "  ✓ $msg\n"; }
    else       { $fail++; echo "  ✗ FAIL: $msg\n"; }
}
$idxExists = function (PDO $pdo, string $name, string $table, string $index): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.statistics
                        WHERE table_schema=? AND table_name=? AND index_name=? LIMIT 1");
    $q->execute([$name, $table, $index]);
    return (bool)$q->fetchColumn();
};

echo "== DB invariant tests (reversible) ==\n";

// (1) القيود/الفهارس موجودة
check($idxExists($pdo, $name, 'payments', 'uq_payments_idem'), "UNIQUE uq_payments_idem على payments");
check($idxExists($pdo, $name, 'daily_attendance', 'uq_attendance_member_day'), "UNIQUE uq_attendance_member_day على daily_attendance");
foreach ([
    'daily_attendance' => 'idx_att_date', 'memberships' => 'idx_ms_end_date',
    'memberships2' => 'idx_ms_pay_status', 'memberships3' => 'idx_ms_renew_status',
    'members' => 'idx_members_status', 'followups' => 'idx_fu_next_date',
] as $t => $ix) {
    $tbl = rtrim($t, '23');
    check($idxExists($pdo, $name, $tbl, $ix), "فهرس $ix على $tbl");
}

// (2) دلالة الإيراد: «المحصّل» = SUM(payments حيث status='paid') — قابل للحساب
$sum = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'")->fetchColumn();
check(is_numeric($sum), "المحصّل = SUM(payments paid) = $sum");

// عيّنة مفاتيح لعمليات الكتابة (تُلغى)
$mid  = $pdo->query("SELECT member_id FROM members ORDER BY member_id LIMIT 1")->fetchColumn();
$msid = $pdo->query("SELECT membership_id FROM memberships ORDER BY membership_id LIMIT 1")->fetchColumn();

// (3) idempotency الدفع: نفس المفتاح مرّتين ⇒ الثانية تُرفض (UNIQUE)
$dupBlocked = false;
if ($mid) {
    $key = 'test_' . bin2hex(random_bytes(8));
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO payments (member_id, membership_id, payment_date, amount, method, status, idempotency_key)
                          VALUES (?,?,NOW(),10.00,'cash','paid',?)");
    $ins->execute([$mid, $msid ?: null, $key]);
    try { $ins->execute([$mid, $msid ?: null, $key]); }
    catch (PDOException $e) { $dupBlocked = ($e->getCode() === '23000'); }
    $pdo->rollBack();
}
check($dupBlocked, "دفعة بمفتاح idempotency مكرّر ⇒ محجوبة بالقيد الفريد (rolled back)");

// (4) UNIQUE الحضور: نفس (member,date) مرّتين (INSERT خام) ⇒ الثانية تُرفض
$attBlocked = false;
if ($mid) {
    $pdo->beginTransaction();
    $insA = $pdo->prepare("INSERT INTO daily_attendance (member_id, attendance_date, attended) VALUES (?, '2099-01-01', 1)");
    $insA->execute([$mid]);
    try { $insA->execute([$mid]); }
    catch (PDOException $e) { $attBlocked = ($e->getCode() === '23000'); }
    $pdo->rollBack();
}
check($attBlocked, "حضور مكرّر (member,date) ⇒ محجوب بالقيد الفريد (rolled back)");

// (5) ON DUPLICATE KEY UPDATE ⇒ idempotent بلا خطأ (كما في مسارات الحضور)
$onDupOk = false;
if ($mid) {
    $pdo->beginTransaction();
    $insD = $pdo->prepare("INSERT INTO daily_attendance (member_id, attendance_date, attended)
                           VALUES (?, '2099-01-02', 1) ON DUPLICATE KEY UPDATE member_id = member_id");
    $insD->execute([$mid]);
    $insD->execute([$mid]);   // لا يرمي — no-op
    $cnt = $pdo->query("SELECT COUNT(*) FROM daily_attendance WHERE member_id=$mid AND attendance_date='2099-01-02'")->fetchColumn();
    $onDupOk = ((int)$cnt === 1);
    $pdo->rollBack();
}
check($onDupOk, "ON DUPLICATE KEY UPDATE ⇒ صفّ واحد فقط، بلا خطأ (rolled back)");

echo "\nRESULT: pass=$pass_c fail=$fail\n";
exit($fail ? 1 : 0);

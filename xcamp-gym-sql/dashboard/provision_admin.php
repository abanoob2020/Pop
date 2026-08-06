<?php
// =============================================================================
// Xcamp Gym — تجهيز أول تشغيل (CLI فقط): إنشاء/تعيين مستخدم طاقم بكلمة مرور
// مُعمّاة (bcrypt). ضروري بعد النشر النظيف (DB_SEED=0) حيث لا يوجد أي مستخدم.
//
// الاستخدام:
//   php provision_admin.php --email=admin@yourgym.com --pass='S3cret!' --name='المدير' [--role=admin]
// أو عبر البيئة:
//   ADMIN_EMAIL=... ADMIN_PASS=... ADMIN_NAME=... ADMIN_ROLE=admin php provision_admin.php
// إعدادات الاتصال تُقرأ من نفس متغيّرات البيئة: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
// =============================================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("هذا السكربت يعمل من سطر الأوامر فقط.\n"); }

$opts  = getopt('', ['email:', 'pass:', 'name:', 'role:']);
$email = $opts['email'] ?? getenv('ADMIN_EMAIL') ?: '';
$pass  = $opts['pass']  ?? getenv('ADMIN_PASS')  ?: '';
$name  = $opts['name']  ?? getenv('ADMIN_NAME')  ?: 'Administrator';
$role  = strtolower($opts['role'] ?? getenv('ADMIN_ROLE') ?: 'admin');

$ROLES = ['admin', 'manager', 'reception', 'coach'];
$fail = function (string $m) { fwrite(STDERR, "خطأ: $m\n"); exit(1); };

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('بريد إلكتروني غير صالح (--email).');
if (strlen($pass) < 8) $fail('كلمة المرور يجب أن تكون 8 أحرف على الأقل (--pass).');
if (!in_array($role, $ROLES, true)) $fail('الدور يجب أن يكون: ' . implode(' / ', $ROLES) . ' (--role).');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbnm = getenv('DB_NAME') ?: 'xcamp_gym';
$user = getenv('DB_USER') ?: 'xcamp_admin';
$dpas = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbnm;charset=utf8mb4", $user, $dpas, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    $fail('تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage());
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

// email فريد في جدول users → إنشاء أو تحديث بأمان
$stmt = $pdo->prepare(
    "INSERT INTO users (full_name, email, password_hash, role, is_active)
     VALUES (:name, :email, :hash, :role, 1)
     ON DUPLICATE KEY UPDATE
        full_name = VALUES(full_name),
        password_hash = VALUES(password_hash),
        role = VALUES(role),
        is_active = 1"
);
$stmt->execute([':name' => $name, ':email' => $email, ':hash' => $hash, ':role' => $role]);

$created = $stmt->rowCount() === 1;   // 1 = INSERT، 2 = UPDATE في MySQL
echo ($created ? "✓ أُنشئ" : "✓ حُدِّث") . " مستخدم الطاقم: $email (الدور: $role)\n";
echo "يمكنك الآن تسجيل الدخول عبر login.php بهذا البريد وكلمة المرور.\n";

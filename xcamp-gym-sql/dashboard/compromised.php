<?php
// =============================================================================
// Xcamp Gym — قفل كلمات المرور المسرّبة (Compromised-credential lockout)
// -----------------------------------------------------------------------------
// كلمات المرور التجريبية منشورة في المستودع والوثائق وتسرّبت في محادثات سابقة،
// فهي مُخترَقة. هذا الملف يرفضها في كل مسار:
//   • الدخول (موظّفين + أعضاء): يُرفض الدخول حتى لو طابقت الهاش في القاعدة.
//   • تعيين/تغيير كلمة مرور (account / captains / provision_admin): ممنوع اختيارها.
// الاستثناء الوحيد: بيئة تطوير محلية صريحة APP_DEBUG=1 (لتجربة بذرة DB_SEED=1).
// يمكن إضافة كلمات مسرّبة أخرى عبر COMPROMISED_PASSWORDS (مفصولة بفواصل) أو ملف
// COMPROMISED_PASSWORDS_FILE (سطر لكل كلمة) — لا تضع القيم الحقيقية في Git.
// =============================================================================

/** قائمة كلمات المرور المسرّبة (مقارنة غير حسّاسة لحالة الأحرف بعد trim). */
function compromised_passwords(): array {
    static $list = null;
    if ($list !== null) return $list;
    $list = [
        // بيانات الدخول التجريبية المنشورة (README / setup_logins.sql / login.php)
        'admin123', 'manager123', 'coach123', 'member123',
        // أمثلة/افتراضات قديمة ظهرت في الشيفرة أو الوثائق
        'changethispass123', 'mypass123', 's3cret!', 'strong-db-pass', 'your_password',
        'replace_me', 'password', '12345678', 'xcamp123',
    ];
    $extra = getenv('COMPROMISED_PASSWORDS');
    if ($extra) foreach (explode(',', $extra) as $p) $list[] = $p;
    $file = getenv('COMPROMISED_PASSWORDS_FILE');
    if ($file && is_readable($file)) {
        foreach ((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $p) $list[] = $p;
    }
    $list = array_values(array_unique(array_filter(array_map(
        fn($p) => mb_strtolower(trim((string)$p)), $list), 'strlen')));
    return $list;
}

function is_compromised_password(string $pass): bool {
    return in_array(mb_strtolower(trim($pass)), compromised_passwords(), true);
}

/** هل يُسمح بالدخول بكلمة مسرّبة؟ فقط في تطوير محلي صريح (APP_DEBUG=1). */
function compromised_login_allowed(): bool {
    return getenv('APP_DEBUG') === '1';
}

/** يُستدعى عند تعيين كلمة مرور جديدة — يرمي استثناءً إن كانت مسرّبة (بلا استثناء بيئي). */
function assert_password_not_compromised(string $pass): void {
    if (is_compromised_password($pass)) {
        throw new RuntimeException('كلمة المرور هذه مُسرّبة/معروفة علنًا ولا يمكن استخدامها. اختر كلمة مرور أخرى.');
    }
}

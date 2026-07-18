<?php
// =============================================================================
// Xcamp Gym — تسجيل الدخول (يصادق على جدول users عبر bcrypt)
// =============================================================================
require __DIR__ . '/db.php';

if (current_user()) { header('Location: ' . (is_manager() ? 'index.php' : 'captains.php')); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $st = db()->prepare("SELECT user_id, full_name, role, password_hash FROM users WHERE email = ? AND is_active = 1");
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            throw new RuntimeException('بيانات الدخول غير صحيحة.');
        }
        $coachId = null;
        if ($u['role'] === 'coach') {
            $cs = db()->prepare("SELECT coach_id FROM coaches WHERE user_id = ?");
            $cs->execute([$u['user_id']]);
            $coachId = ($cs->fetch()['coach_id'] ?? null);
            $coachId = $coachId !== null ? (int)$coachId : null;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'uid'      => (int)$u['user_id'],
            'name'     => $u['full_name'],
            'role'     => $u['role'],
            'coach_id' => $coachId,
        ];
        header('Location: ' . (is_manager() ? 'index.php' : 'captains.php'));
        exit;
    } catch (PDOException $e) {
        $error = 'تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

page_head('تسجيل الدخول');
?>
<div style="max-width:380px;margin:6vh auto">
  <section>
    <h2 style="text-align:center">🔐 تسجيل دخول الموظفين</h2>
    <?php if ($error): ?><div class="err" style="margin:0 0 14px"><?=h($error)?></div><?php endif; ?>
    <form method="post" style="display:grid;gap:12px">
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">البريد الإلكتروني</label>
        <input name="email" type="email" required autofocus style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">كلمة المرور</label>
        <input name="password" type="password" required style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:11px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">دخول</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:14px;line-height:1.7">
      حسابات افتراضية (بعد تشغيل <code>setup_logins.sql</code>):<br>
      مدير: <code>admin@xcamp.com / admin123</code><br>
      كابتن: <code>coach1@xcamp.com / coach123</code>
    </p>
  </section>
</div>
<?php page_foot();

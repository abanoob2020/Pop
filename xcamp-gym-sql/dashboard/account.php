<?php
// =============================================================================
// Xcamp Gym — حسابي: تغيير كلمة المرور (يتحقق من الحالية، bcrypt للجديدة)
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $cur  = (string)($_POST['current_password'] ?? '');
        $new  = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['confirm_password'] ?? '');
        if (strlen($new) < 8) throw new RuntimeException('كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف.');
        if ($new !== $new2) throw new RuntimeException('تأكيد كلمة المرور غير مطابق.');

        $st = db()->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $st->execute([$me['uid']]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($cur, $hash)) throw new RuntimeException('كلمة المرور الحالية غير صحيحة.');

        db()->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), $me['uid']]);
        session_regenerate_id(true);
        $flash = 'تم تغيير كلمة المرور بنجاح.';
    } catch (PDOException $e) {
        $error = 'تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

page_head('حسابي');
?>
<div style="max-width:420px;margin:4vh auto">
  <section>
    <h2>⚙️ حسابي — <?=h($me['name'])?> <span class="muted" style="font-size:13px">(<?=h($me['role'])?>)</span></h2>
    <?php if ($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
    <?php if ($error): ?><div class="err" style="margin:0 0 14px"><?=h($error)?></div><?php endif; ?>
    <h3>تغيير كلمة المرور</h3>
    <form method="post" style="display:grid;gap:12px">
      <?=csrf_field()?>
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">كلمة المرور الحالية</label>
        <input name="current_password" type="password" required style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">كلمة المرور الجديدة (8+ أحرف)</label>
        <input name="new_password" type="password" required minlength="8" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">تأكيد كلمة المرور الجديدة</label>
        <input name="confirm_password" type="password" required minlength="8" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:11px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">تغيير كلمة المرور</button>
    </form>
  </section>
</div>
<?php page_foot();

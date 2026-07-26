<?php
// =============================================================================
// Xcamp Gym — دخول العضو لبوابته (bcrypt) مع CSRF وتقييد المحاولات الفاشلة.
//   يدخل بالهاتف أو البريد + كلمة المرور. منفصل تمامًا عن دخول الموظّفين.
// =============================================================================
require __DIR__ . '/db.php';

if (current_member()) { header('Location: portal.php'); exit; }

const MRL_MAX = 5;
const MRL_WINDOW = 900;
function mrl_file(): string { return sys_get_temp_dir() . '/xcamp_member_attempts.json'; }
function mrl_load(): array {
    $d = @json_decode(@file_get_contents(mrl_file()), true) ?: [];
    $now = time();
    foreach ($d as $k => $t) { $d[$k] = array_values(array_filter($t, fn($x) => $now - $x < MRL_WINDOW)); if (!$d[$k]) unset($d[$k]); }
    return $d;
}
function mrl_save(array $d): void { @file_put_contents(mrl_file(), json_encode($d), LOCK_EX); }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $id   = trim($_POST['identifier'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $key  = ($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . mb_strtolower($id);
        $rl = mrl_load();
        if (count($rl[$key] ?? []) >= MRL_MAX) {
            $wait = (int)ceil((MRL_WINDOW - (time() - min($rl[$key]))) / 60);
            throw new RuntimeException("تم تجاوز عدد المحاولات. حاول بعد نحو {$wait} دقيقة.");
        }
        $st = db()->prepare("SELECT m.member_id, m.full_name, ma.password_hash, ma.portal_enabled
                             FROM members m JOIN member_auth ma ON ma.member_id = m.member_id
                             WHERE m.phone = ? OR m.email = ? LIMIT 1");
        $st->execute([$id, $id]);
        $mem = $st->fetch();
        if (!$mem || !password_verify($pass, $mem['password_hash'])) {
            $rl[$key][] = time(); mrl_save($rl);
            $left = MRL_MAX - count($rl[$key]);
            throw new RuntimeException('بيانات الدخول غير صحيحة.' . ($left > 0 ? " (متبقٍ {$left})" : ''));
        }
        if (!$mem['portal_enabled']) throw new RuntimeException('بوابتك غير مُفعّلة — راجع الاستقبال.');
        unset($rl[$key]); mrl_save($rl);
        db()->prepare("UPDATE member_auth SET last_login_at = NOW() WHERE member_id = ?")->execute([(int)$mem['member_id']]);
        session_regenerate_id(true);
        $_SESSION['member'] = ['member_id' => (int)$mem['member_id'], 'name' => $mem['full_name']];
        header('Location: portal.php');
        exit;
    } catch (PDOException $e) {
        $error = 'تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

page_head('بوابة العضو');
?>
<div style="max-width:380px;margin:6vh auto">
  <section>
    <h2 style="text-align:center">🏋️ بوابة العضو</h2>
    <?php if ($error): ?><div class="err" style="margin:0 0 14px"><?=h($error)?></div><?php endif; ?>
    <form method="post" data-no-ajax style="display:grid;gap:12px">
      <?=csrf_field()?>
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">الهاتف أو البريد</label>
        <input name="identifier" required autofocus style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <div><label style="font-size:13px;color:#475569;display:block;margin-bottom:4px">كلمة المرور</label>
        <input name="password" type="password" required style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px"></div>
      <button type="submit" style="background:#2563eb;color:#fff;border:0;padding:11px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">دخول</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:14px;line-height:1.7">
      دخول الموظّفين من <a class="link" href="login.php">هنا</a>.<br>
      لتفعيل بوابتك اطلب من الكابتن/الاستقبال تعيين كلمة مرور.
    </p>
  </section>
</div>
<?php page_foot();

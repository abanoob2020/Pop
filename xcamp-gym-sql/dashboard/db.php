<?php
// =============================================================================
// Xcamp Gym dashboard — الاتصال + الجلسة + المصادقة + عناصر الواجهة المشتركة
// إعدادات الاتصال عبر متغيّرات البيئة: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
// =============================================================================

if (session_status() === PHP_SESSION_NONE) session_start();

// بدائل آمنة لدوال mbstring في حال عدم تثبيت الامتداد (utf8mb4-aware)
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) {
        return count(preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $marker = '', $enc = null) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = implode('', array_slice($chars, $start, $width));
        return (count($chars) - $start > $width) ? $out . $marker : $out;
    }
}

require_once __DIR__ . '/training.php';   // ذكاء الأحمال: دوال الحسابات التدريبية

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'xcamp_gym';
    $user = getenv('DB_USER') ?: 'xcamp_admin';
    $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'ChangeThisPass123';
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// ---- المصادقة / الصلاحيات ----
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}
function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}
/** يتطلب دورًا معيّنًا؛ الكابتن يُحوَّل لصفحته المسموح بها */
function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) { header('Location: captains.php'); exit; }
    return $u;
}
function is_manager(): bool {
    $u = current_user();
    return $u && in_array($u['role'], ['admin','manager','reception'], true);
}

/** رمز دخول العضو للـ QR — يُنشأ عند أول طلب ويُخزَّن (16 حرفًا، يتجنّب حدود سعة QR) */
function member_qr_token(PDO $pdo, int $memberId): string {
    $q = $pdo->prepare("SELECT token FROM member_qr WHERE member_id = ?");
    $q->execute([$memberId]);
    $tok = $q->fetchColumn();
    if ($tok !== false) return $tok;
    $tok = 'XCG-' . bin2hex(random_bytes(6));   // 4 + 12 = 16 حرفًا
    $pdo->prepare("INSERT INTO member_qr (member_id, token) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE token = token")->execute([$memberId, $tok]);
    // في حال تسابق نادر أعِد القراءة
    $q->execute([$memberId]);
    return $q->fetchColumn() ?: $tok;
}

// ---- مصادقة العضو (بوابة العضو — منفصلة عن الموظّفين) ----
function current_member(): ?array { return $_SESSION['member'] ?? null; }
function require_member(): array {
    $m = current_member();
    if (!$m) { header('Location: member_login.php'); exit; }
    return $m;
}

// ---- جلسات التدريب الشخصي (PT): سعر افتراضي + حساب المواعيد المتاحة من الورديات ----
if (!defined('PT_PRICE'))    define('PT_PRICE', 150);   // سعر الجلسة الافتراضي (ج.م)
if (!defined('PT_SLOT_MIN')) define('PT_SLOT_MIN', 60); // مدّة الموعد بالدقائق

/** يعرض مدى وقتي (بداية–نهاية) معزولًا LTR ليقرأ صحيحًا داخل صفحة RTL. */
function trange($start, $end, bool $boldStart = false): string {
    $a = substr((string)$start, 0, 5); $b = substr((string)$end, 0, 5);
    $left = $boldStart ? "<strong>$a</strong>" : $a;
    return '<span dir="ltr" style="unicode-bidi:isolate;display:inline-block">' . $left . '–' . $b . '</span>';
}

/** يحوّل التاريخ إلى ترميز يوم الأسبوع المستخدم في coach_shifts (0=السبت..6=الجمعة). */
function pt_weekday_for(string $date): int {
    return ((int)date('w', strtotime($date)) + 1) % 7;  // PHP: 0=الأحد..6=السبت
}

/**
 * يبني مواعيد جلسات كابتن في تاريخ معيّن من ورديات ذلك اليوم، ويعلّم المحجوز.
 * @return array<int,array{start:string,end:string,free:bool}>
 */
function pt_slots_for(PDO $pdo, int $coachId, string $date, int $slotMin = PT_SLOT_MIN): array {
    $wd = pt_weekday_for($date);
    $sh = $pdo->prepare("SELECT start_time, end_time FROM coach_shifts WHERE coach_id = ? AND weekday = ? ORDER BY start_time");
    $sh->execute([$coachId, $wd]);
    $shifts = $sh->fetchAll();
    $bk = $pdo->prepare("SELECT start_time, end_time FROM pt_sessions
                         WHERE coach_id = ? AND session_date = ? AND status IN ('booked','completed')");
    $bk->execute([$coachId, $date]);
    $busy = $bk->fetchAll();
    $slots = [];
    foreach ($shifts as $s) {
        $t = strtotime($s['start_time']); $end = strtotime($s['end_time']);
        while ($t + $slotMin * 60 <= $end) {
            $st = date('H:i:s', $t); $en = date('H:i:s', $t + $slotMin * 60);
            $free = true;
            foreach ($busy as $b) if ($st < $b['end_time'] && $en > $b['start_time']) { $free = false; break; }
            $slots[] = ['start' => $st, 'end' => $en, 'free' => $free];
            $t += $slotMin * 60;
        }
    }
    return $slots;
}

// ---- أكواد الخصم والإحالة ----
/**
 * يتحقّق من كود خصم ويحسب أثره على مبلغ في سياق معيّن دون تعديل قاعدة البيانات.
 * @return array{ok:bool,msg:?string,row:?array,discount:float,final:float}
 */
function discount_evaluate(PDO $pdo, string $code, float $base, string $context): array {
    $code = strtoupper(trim($code));
    $out = ['ok' => false, 'msg' => null, 'row' => null, 'discount' => 0.0, 'final' => $base];
    if ($code === '') { $out['msg'] = 'أدخل كودًا.'; return $out; }
    $q = $pdo->prepare("SELECT * FROM discount_codes WHERE code = ?");
    $q->execute([$code]); $row = $q->fetch();
    if (!$row)                                   { $out['msg'] = 'كود غير موجود.'; return $out; }
    if (!$row['active'])                         { $out['msg'] = 'الكود موقوف.'; return $out; }
    if ($row['expires_on'] && $row['expires_on'] < date('Y-m-d')) { $out['msg'] = 'انتهت صلاحية الكود.'; return $out; }
    if ((int)$row['max_uses'] > 0 && (int)$row['used_count'] >= (int)$row['max_uses']) { $out['msg'] = 'استُنفد الكود.'; return $out; }
    if ($row['scope'] !== 'both' && $row['scope'] !== $context) { $out['msg'] = 'الكود لا ينطبق هنا.'; return $out; }
    $disc = $row['kind'] === 'percent' ? round($base * (float)$row['value'] / 100, 2) : (float)$row['value'];
    $disc = max(0, min($disc, $base));           // لا يتجاوز الخصمُ المبلغَ
    $out['ok'] = true; $out['row'] = $row; $out['discount'] = $disc; $out['final'] = round($base - $disc, 2);
    return $out;
}

/** يسجّل استخدام كود خصم داخل معاملة قائمة: يزيد العدّاد ويكتب سجلّ التدقيق. */
function discount_redeem(PDO $pdo, array $row, string $context, float $base, float $discount, ?int $memberId): void {
    $pdo->prepare("UPDATE discount_codes SET used_count = used_count + 1 WHERE code_id = ?")->execute([(int)$row['code_id']]);
    $pdo->prepare("INSERT INTO discount_redemptions (code_id, code, member_id, context, base_amount, discount_amount, final_amount)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([(int)$row['code_id'], $row['code'], $memberId, $context, $base, $discount, round($base - $discount, 2)]);
}

// ---- حماية CSRF ----
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
/** يُستدعى في بداية معالجة أي POST؛ يرمي استثناءً عند فشل التحقق */
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('فشل التحقق الأمني (CSRF) — أعد تحميل الصفحة وحاول مجددًا.');
    }
}

function page_head(string $title, string $active = ''): void {
    $db = getenv('DB_NAME') ?: 'xcamp_gym';
    $u  = current_user();
    ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($title)?> — Xcamp Gym</title>
<?php page_styles(); ?>
</head>
<body>
<header>
  <span class="brand">🏋️ Xcamp Gym</span>
  <?php if ($u): ?>
  <button class="nav-toggle" type="button" aria-label="القائمة">☰</button>
  <nav>
    <?php if (is_manager()): ?><a href="index.php" class="<?= $active==='dash' ? 'active' : '' ?>">لوحة الإدارة</a><?php endif; ?>
    <a href="captains.php" class="<?= $active==='captains' ? 'active' : '' ?>">واجهة الكباتن</a>
    <a href="pt.php" class="<?= $active==='pt' ? 'active' : '' ?>">جلسات PT</a>
    <a href="checkin.php" class="<?= $active==='checkin' ? 'active' : '' ?>">الحضور (QR)</a>
    <a href="crm.php" class="<?= $active==='crm' ? 'active' : '' ?>">CRM</a>
    <a href="retention.php" class="<?= $active==='retention' ? 'active' : '' ?>">الاحتفاظ</a>
    <a href="revenue.php" class="<?= $active==='revenue' ? 'active' : '' ?>">الإيرادات</a>
    <?php if (is_manager()): ?><a href="finance.php" class="<?= $active==='finance' ? 'active' : '' ?>">المحاسبة</a><?php endif; ?>
    <?php if (is_manager()): ?><a href="analytics.php" class="<?= $active==='analytics' ? 'active' : '' ?>">التحليلات</a><?php endif; ?>
    <?php if (is_manager()): ?><a href="hr.php" class="<?= $active==='hr' ? 'active' : '' ?>">شؤون الكباتن</a><?php endif; ?>
    <?php if (is_manager()): ?><a href="promos.php" class="<?= $active==='promos' ? 'active' : '' ?>">الإحالات والأكواد</a><?php endif; ?>
    <a href="calendar.php" class="<?= $active==='calendar' ? 'active' : '' ?>">التقويم</a>
    <a href="templates.php" class="<?= $active==='templates' ? 'active' : '' ?>">التمارين والقوالب</a>
  </nav>
  <span class="sub">
    👤 <?=h($u['name'])?> (<?=h($u['role'])?>)
    · <a href="account.php" style="color:#93c5fd">حسابي</a>
    · <a href="logout.php">خروج</a>
    · <span style="color:#475569">قاعدة: <?=h($db)?></span>
  </span>
  <?php endif; ?>
</header>
<main>
<?php
}

/** كتلة الأنماط المشتركة (تُستخدم في رأس الموظّفين ورأس العضو) */
function page_styles(): void {
    ?>
<style>
  * { box-sizing: border-box; }
  html, body { overflow-x: hidden; }
  body { margin:0; font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background:#f1f5f9; color:#0f172a; max-width:100%; }
  header { background:#0f172a; color:#fff; padding:14px 24px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
  header .brand { font-size:18px; font-weight:800; }
  header nav a { color:#cbd5e1; text-decoration:none; padding:6px 12px; border-radius:8px; font-size:14px; }
  header nav a.active, header nav a:hover { background:#1e293b; color:#fff; }
  header .sub { color:#94a3b8; font-size:12px; margin-inline-start:auto; display:flex; align-items:center; gap:12px; }
  header .sub a { color:#f87171; text-decoration:none; }
  main { padding:24px; max-width:1200px; margin:0 auto; }
  .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:26px; }
  .card { background:#fff; border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.08); border-top:4px solid var(--c,#2563eb); }
  .card .n { font-size:30px; font-weight:800; line-height:1; }
  .card .l { color:#64748b; font-size:13px; margin-top:8px; }
  section { background:#fff; border-radius:12px; padding:18px 20px; margin-bottom:22px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
  section h2 { margin:0 0 14px; font-size:16px; }
  section h3 { margin:18px 0 8px; font-size:14px; color:#334155; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { text-align:right; padding:9px 10px; border-bottom:1px solid #eef2f7; vertical-align:top; }
  th { color:#64748b; font-weight:600; font-size:12px; }
  tr:hover td { background:#f8fafc; }
  a.link { color:#2563eb; text-decoration:none; font-weight:600; }
  a.link:hover { text-decoration:underline; }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; color:#fff; font-size:12px; font-weight:600; background:#6b7280; }
  .muted { color:#94a3b8; }
  .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:22px; }
  @media (max-width:820px){ .grid2 { grid-template-columns:1fr; } }
  form.frm { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; align-items:end; background:#f8fafc; padding:14px; border-radius:10px; margin-top:8px; }
  form.frm label { font-size:12px; color:#475569; display:block; margin-bottom:4px; }
  form.frm input, form.frm select, form.frm textarea { width:100%; padding:7px 9px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; }
  form.frm button { background:#2563eb; color:#fff; border:0; padding:9px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }
  form.frm button:hover { background:#1d4ed8; }
  .flash { background:#dcfce7; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:18px; }
  .err { background:#fee2e2; color:#991b1b; padding:16px; border-radius:10px; margin-bottom:18px; }
  .err code { display:block; margin-top:8px; font-size:13px; opacity:.85; word-break:break-all; }
  .empty { color:#94a3b8; padding:10px 0; }
  .crumb { margin-bottom:16px; font-size:14px; }
  /* ---- المرحلة 4: التبويبات ---- */
  .tabbar { display:none; gap:6px; overflow-x:auto; background:#fff; border-radius:12px; padding:8px; margin-bottom:20px;
            box-shadow:0 1px 3px rgba(0,0,0,.06); position:sticky; top:0; z-index:20; -webkit-overflow-scrolling:touch; }
  .tabbar.ready { display:flex; }
  .tabbar button { flex:0 0 auto; border:0; background:transparent; padding:9px 15px; border-radius:8px; font-size:14px;
                   font-weight:600; color:#475569; cursor:pointer; white-space:nowrap; font-family:inherit; transition:background .12s,color .12s; }
  .tabbar button:hover { background:#f1f5f9; }
  .tabbar button.active { background:#2563eb; color:#fff; }
  /* ---- المرحلة 4: زر قائمة الموبايل ---- */
  .nav-toggle { display:none; background:#1e293b; color:#fff; border:0; border-radius:8px; padding:6px 12px; font-size:18px; cursor:pointer; line-height:1; }
  /* ---- المرحلة 4: توست AJAX ---- */
  .toast { position:fixed; inset-block-end:20px; inset-inline-start:50%; transform:translateX(50%) translateY(24px);
           background:#0f172a; color:#fff; padding:12px 20px; border-radius:10px; font-size:14px; font-weight:600;
           box-shadow:0 8px 24px rgba(0,0,0,.25); opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:60; }
  .toast.show { opacity:1; transform:translateX(50%) translateY(0); }
  .toast.err { background:#991b1b; }
  .ajax-busy { opacity:.6; pointer-events:none; }
  /* ---- المرحلة 4: تحسينات الموبايل ---- */
  @media (max-width:720px){
    header { padding:12px 16px; gap:10px; }
    header .brand { font-size:16px; }
    header nav { display:none; flex-basis:100%; flex-direction:column; gap:4px; }
    header.nav-open nav { display:flex; }
    header .sub { flex-basis:100%; margin-inline-start:0; flex-wrap:wrap; gap:6px; font-size:11px; word-break:break-word; }
    .nav-toggle { display:inline-block; margin-inline-start:auto; }
    main { padding:14px; }
    section { padding:14px; overflow-wrap:anywhere; }
    section table { display:block; overflow-x:auto; white-space:nowrap; -webkit-overflow-scrolling:touch; }
    form.frm { grid-template-columns:1fr 1fr; }
  }
</style>
<?php
}

/** رأس بوابة العضو: نفس التنسيق لكن ترويسة موجّهة للعضو (لا نظهر أدوات الموظّفين) */
function portal_head(string $title): void {
    $m = current_member();
    ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($title)?> — Xcamp Gym</title>
<?php page_styles(); ?>
</head>
<body>
<header>
  <span class="brand">🏋️ Xcamp Gym</span>
  <span class="sub" style="margin-inline-start:auto">
    👤 <?=h($m['name'] ?? '')?> · <a href="member_logout.php">خروج</a>
  </span>
</header>
<main>
<?php
}

function page_foot(): void {
    echo "</main>";
    page_script();
    echo "</body></html>";
}

/** المرحلة 4: سكربت مشترك — قائمة الموبايل + التبويبات + حفظ AJAX (تحسين تدريجي) */
function page_script(): void {
    ?>
<div class="toast" id="toast"></div>
<script>
(function(){
  "use strict";
  var header = document.querySelector('header');
  var toggle = document.querySelector('.nav-toggle');
  if (toggle && header) toggle.addEventListener('click', function(){ header.classList.toggle('nav-open'); });

  function tabKey(){ return 'xtab:' + location.pathname + location.search; }
  function bar(){ return document.querySelector('.tabbar'); }
  function panels(){ return [].slice.call(document.querySelectorAll('main [data-tab]')); }
  function tabBtns(){ var b = bar(); return b ? [].slice.call(b.querySelectorAll('[data-tabtarget]')) : []; }

  function activate(name, save){
    var btns = tabBtns(); if (!btns.length) return;
    var valid = btns.some(function(t){ return t.getAttribute('data-tabtarget') === name; });
    if (!valid) name = btns[0].getAttribute('data-tabtarget');
    panels().forEach(function(s){ s.style.display = (s.getAttribute('data-tab') === name) ? '' : 'none'; });
    btns.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-tabtarget') === name); });
    if (save !== false){ try { sessionStorage.setItem(tabKey(), name); } catch(e){} }
  }
  function initTabs(){
    var b = bar(); if (!b) return;
    b.classList.add('ready');
    tabBtns().forEach(function(t){
      t.addEventListener('click', function(){ activate(t.getAttribute('data-tabtarget')); });
    });
    var stored = null; try { stored = sessionStorage.getItem(tabKey()); } catch(e){}
    var want = (location.hash || '').replace('#','') || stored;
    activate(want, false);
  }
  initTabs();

  var toastEl = document.getElementById('toast'), toastT;
  function toast(msg, isErr){
    if (!toastEl) return;
    toastEl.textContent = msg; toastEl.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(toastT); toastT = setTimeout(function(){ toastEl.className = 'toast' + (isErr ? ' err' : ''); }, 2600);
  }

  // حفظ AJAX: يعترض نماذج POST داخل main (عدا رفع الملفات ومن يطلب التخطّي)
  document.addEventListener('submit', function(e){
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || e.defaultPrevented) return;              // احترم confirm() الذي ألغى الإرسال
    if ((f.method || '').toLowerCase() !== 'post') return;                     // نماذج GET (بحث) تعمل عاديًا
    if ((f.enctype || '').indexOf('multipart') !== -1) return;                 // رفع الملفات: إرسال عادي
    if (f.hasAttribute('data-no-ajax') || !f.closest('main')) return;
    e.preventDefault();
    var main = document.querySelector('main');
    var btn = f.querySelector('button[type=submit], button:not([type])');
    if (btn) btn.disabled = true;
    main.classList.add('ajax-busy');
    fetch(f.action || location.href, { method:'POST', body:new FormData(f), redirect:'follow', headers:{'X-Requested-With':'fetch'} })
      .then(function(r){ return r.text(); })
      .then(function(html){
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nm = doc.querySelector('main');
        if (!nm) throw new Error('no main');
        var errBox = nm.querySelector('.err');
        main.innerHTML = nm.innerHTML;
        main.classList.remove('ajax-busy');
        initTabs();
        toast(errBox ? errBox.textContent.trim().slice(0,80) : 'تم الحفظ ✓', !!errBox);
      })
      .catch(function(){ main.classList.remove('ajax-busy'); if (btn) btn.disabled = false; f.submit(); });
  });
})();
</script>
    <?php
}

function db_error_box(string $msg): void {
    ?>
    <div class="err">
      <strong>تعذّر الاتصال بقاعدة البيانات أو تنفيذ استعلام.</strong>
      <code><?=h($msg)?></code>
      <div style="margin-top:10px;font-size:13px">
        أنشئ المستخدم وصلاحياته (مرة واحدة):<br>
        <code>sudo mysql -e "CREATE USER IF NOT EXISTS 'xcamp_admin'@'localhost' IDENTIFIED BY 'MyPass123'; CREATE USER IF NOT EXISTS 'xcamp_admin'@'127.0.0.1' IDENTIFIED BY 'MyPass123'; CREATE USER IF NOT EXISTS 'xcamp_admin'@'%' IDENTIFIED BY 'MyPass123'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'localhost'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'127.0.0.1'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'%'; FLUSH PRIVILEGES;"</code>
        وفعّل الدخول: <code>sudo mysql xcamp_gym &lt; setup_logins.sql</code>
      </div>
    </div>
    <?php
}

/** رسم بياني خطّي بسيط (SVG، بدون مكتبات خارجية) لسلسلة أو أكثر.
 *  $series = [ ['label'=>..., 'color'=>..., 'points'=>[[x_label, value], ...]], ... ] */
function line_chart(array $series, int $w = 560, int $hgt = 220): string {
    $pad = 34; $iw = $w - $pad * 2; $ih = $hgt - $pad * 2;
    $all = [];
    $labels = [];
    foreach ($series as $s) foreach ($s['points'] as $p) { if ($p[1] !== null) $all[] = (float)$p[1]; $labels[$p[0]] = true; }
    if (!$all) return '<div class="empty">لا توجد بيانات كافية للرسم.</div>';
    $min = min($all); $max = max($all); if ($max == $min) { $max += 1; $min -= 1; }
    $labels = array_keys($labels); sort($labels);
    $n = count($labels); $xi = $n > 1 ? $iw / ($n - 1) : 0;
    $xpos = []; foreach ($labels as $i => $l) $xpos[$l] = $pad + $i * $xi;
    $ymap = fn($v) => $pad + $ih - (($v - $min) / ($max - $min)) * $ih;

    $svg  = "<svg viewBox=\"0 0 $w $hgt\" width=\"100%\" style=\"max-width:{$w}px\" font-family=\"sans-serif\">";
    // شبكة أفقية + قيم المحور
    for ($g = 0; $g <= 4; $g++) {
        $y = $pad + $ih * $g / 4; $val = $max - ($max - $min) * $g / 4;
        $svg .= "<line x1=\"$pad\" y1=\"$y\" x2=\"" . ($pad + $iw) . "\" y2=\"$y\" stroke=\"#eef2f7\"/>";
        $svg .= "<text x=\"" . ($pad - 6) . "\" y=\"" . ($y + 3) . "\" text-anchor=\"end\" font-size=\"9\" fill=\"#94a3b8\">" . round($val, 1) . "</text>";
    }
    // المسارات + النقاط
    foreach ($series as $s) {
        $pts = [];
        foreach ($s['points'] as $p) if ($p[1] !== null) $pts[] = round($xpos[$p[0]], 1) . ',' . round($ymap((float)$p[1]), 1);
        if ($pts) {
            $svg .= "<polyline fill=\"none\" stroke=\"{$s['color']}\" stroke-width=\"2\" points=\"" . implode(' ', $pts) . "\"/>";
            foreach ($pts as $pt) { [$cx,$cy] = explode(',', $pt); $svg .= "<circle cx=\"$cx\" cy=\"$cy\" r=\"3\" fill=\"{$s['color']}\"/>"; }
        }
    }
    // تسميات المحور السيني
    foreach ($labels as $i => $l) {
        if ($n > 8 && $i % (int)ceil($n / 8) !== 0 && $i !== $n - 1) continue;
        $svg .= "<text x=\"" . round($xpos[$l], 1) . "\" y=\"" . ($hgt - 8) . "\" text-anchor=\"middle\" font-size=\"9\" fill=\"#94a3b8\">" . h(substr($l, 5)) . "</text>";
    }
    $svg .= "</svg>";
    // مفتاح الألوان
    $leg = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;font-size:12px">';
    foreach ($series as $s) $leg .= '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' . $s['color'] . ';margin-inline-end:5px"></span>' . h($s['label']) . '</span>';
    return $svg . $leg . '</div>';
}

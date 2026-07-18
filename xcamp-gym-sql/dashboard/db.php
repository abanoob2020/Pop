<?php
// =============================================================================
// Xcamp Gym dashboard — الاتصال المشترك + عناصر الواجهة (مضمّن في كل الصفحات)
// إعدادات الاتصال عبر متغيّرات البيئة: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
// =============================================================================

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

function page_head(string $title, string $active = ''): void {
    $db = getenv('DB_NAME') ?: 'xcamp_gym';
    ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($title)?> — Xcamp Gym</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background:#f1f5f9; color:#0f172a; }
  header { background:#0f172a; color:#fff; padding:14px 24px; display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
  header .brand { font-size:18px; font-weight:800; }
  header nav a { color:#cbd5e1; text-decoration:none; padding:6px 12px; border-radius:8px; font-size:14px; }
  header nav a.active, header nav a:hover { background:#1e293b; color:#fff; }
  header .sub { color:#64748b; font-size:12px; margin-inline-start:auto; }
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
  form.frm { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; align-items:end; background:#f8fafc; padding:14px; border-radius:10px; margin-top:8px; }
  form.frm label { font-size:12px; color:#475569; display:block; margin-bottom:4px; }
  form.frm input, form.frm select, form.frm textarea { width:100%; padding:7px 9px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; }
  form.frm button { background:#2563eb; color:#fff; border:0; padding:9px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }
  form.frm button:hover { background:#1d4ed8; }
  .flash { background:#dcfce7; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:18px; }
  .err { background:#fee2e2; color:#991b1b; padding:16px; border-radius:10px; margin-bottom:18px; }
  .err code { display:block; margin-top:8px; font-size:13px; opacity:.85; word-break:break-all; }
  .empty { color:#94a3b8; padding:10px 0; }
  .crumb { margin-bottom:16px; font-size:14px; }
</style>
</head>
<body>
<header>
  <span class="brand">🏋️ Xcamp Gym</span>
  <nav>
    <a href="index.php" class="<?= $active==='dash' ? 'active' : '' ?>">لوحة الإدارة</a>
    <a href="captains.php" class="<?= $active==='captains' ? 'active' : '' ?>">واجهة الكباتن</a>
  </nav>
  <span class="sub">قاعدة: <?=h($db)?> · <?=date('Y-m-d H:i')?></span>
</header>
<main>
<?php
}

function page_foot(): void { echo "</main></body></html>"; }

function db_error_box(string $msg): void {
    ?>
    <div class="err">
      <strong>تعذّر الاتصال بقاعدة البيانات أو تنفيذ استعلام.</strong>
      <code><?=h($msg)?></code>
      <div style="margin-top:10px;font-size:13px">
        أنشئ المستخدم وصلاحياته (مرة واحدة) — لثلاث حالات الاتصال:<br>
        <code>sudo mysql -e "CREATE USER IF NOT EXISTS 'xcamp_admin'@'localhost' IDENTIFIED BY 'MyPass123'; CREATE USER IF NOT EXISTS 'xcamp_admin'@'127.0.0.1' IDENTIFIED BY 'MyPass123'; CREATE USER IF NOT EXISTS 'xcamp_admin'@'%' IDENTIFIED BY 'MyPass123'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'localhost'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'127.0.0.1'; GRANT ALL PRIVILEGES ON xcamp_gym.* TO 'xcamp_admin'@'%'; FLUSH PRIVILEGES;"</code>
        ثم شغّل: <code>DB_USER=xcamp_admin DB_PASS='MyPass123' php -S 0.0.0.0:8000</code>
      </div>
    </div>
    <?php
}

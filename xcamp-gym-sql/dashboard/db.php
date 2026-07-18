<?php
// =============================================================================
// Xcamp Gym dashboard — الاتصال + الجلسة + المصادقة + عناصر الواجهة المشتركة
// إعدادات الاتصال عبر متغيّرات البيئة: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
// =============================================================================

if (session_status() === PHP_SESSION_NONE) session_start();

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

function page_head(string $title, string $active = ''): void {
    $db = getenv('DB_NAME') ?: 'xcamp_gym';
    $u  = current_user();
    ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($title)?> — Xcamp Gym</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, "Segoe UI", Tahoma, sans-serif; background:#f1f5f9; color:#0f172a; }
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
</style>
</head>
<body>
<header>
  <span class="brand">🏋️ Xcamp Gym</span>
  <?php if ($u): ?>
  <nav>
    <?php if (is_manager()): ?><a href="index.php" class="<?= $active==='dash' ? 'active' : '' ?>">لوحة الإدارة</a><?php endif; ?>
    <a href="captains.php" class="<?= $active==='captains' ? 'active' : '' ?>">واجهة الكباتن</a>
  </nav>
  <span class="sub">
    👤 <?=h($u['name'])?> (<?=h($u['role'])?>)
    · <a href="logout.php">خروج</a>
    · <span style="color:#475569">قاعدة: <?=h($db)?></span>
  </span>
  <?php endif; ?>
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

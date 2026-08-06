<?php
// =============================================================================
// Xcamp Gym — الإحالات وأكواد الخصم: إنشاء/إدارة أكواد الخصم، ولوحة الإحالات
// (المُحيلون الأعلى + مكافآتهم) مع سجلّ الاستخدام. للإدارة فقط.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_role(['admin','manager','reception']);

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$KINDS  = ['percent','fixed'];
$SCOPES = ['membership','pos','both'];

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'add_code') {
            $code = strtoupper(trim($_POST['code'] ?? ''));
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) throw new RuntimeException('الكود: 3–40 حرفًا/رقمًا (A-Z 0-9 - _).');
            $kind  = in_array($_POST['kind'] ?? '', $KINDS, true) ? $_POST['kind'] : 'percent';
            $scope = in_array($_POST['scope'] ?? '', $SCOPES, true) ? $_POST['scope'] : 'both';
            $value = max(0, (float)($_POST['value'] ?? 0));
            if ($kind === 'percent' && $value > 100) throw new RuntimeException('النسبة لا تتجاوز 100%.');
            $maxUses = max(0, (int)($_POST['max_uses'] ?? 0));
            $expires = ($_POST['expires_on'] ?? '') !== '' ? $_POST['expires_on'] : null;
            $pdo->prepare("INSERT INTO discount_codes (code, kind, value, scope, max_uses, expires_on) VALUES (?,?,?,?,?,?)")
                ->execute([$code, $kind, $value, $scope, $maxUses, $expires]);
        } elseif ($act === 'toggle_code') {
            $pdo->prepare("UPDATE discount_codes SET active = 1 - active WHERE code_id = ?")->execute([(int)$_POST['code_id']]);
        }
        header('Location: promos.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'uq_code') !== false || strpos($msg, 'Duplicate') !== false) $msg = 'هذا الكود موجود بالفعل.';
        $error = 'تعذّر الحفظ: ' . $msg;
    }
}

page_head('الإحالات والأكواد', 'promos');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح ✓</div>';

// ---- KPIs ----
$activeCodes = (int)$pdo->query("SELECT COUNT(*) FROM discount_codes WHERE active=1")->fetchColumn();
$redMonth    = (int)$pdo->query("SELECT COUNT(*) FROM discount_redemptions WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
$discMonth   = (float)$pdo->query("SELECT COALESCE(SUM(discount_amount),0) FROM discount_redemptions WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
$topRef      = $pdo->query("SELECT m.full_name, dc.used_count, dc.used_count*dc.reward_value reward
                            FROM discount_codes dc JOIN members m ON m.member_id=dc.owner_member_id
                            WHERE dc.owner_member_id IS NOT NULL ORDER BY dc.used_count DESC, reward DESC LIMIT 1")->fetch();

// ---- الأكواد ----
$codes = $pdo->query("SELECT dc.*, m.full_name owner_name
                      FROM discount_codes dc LEFT JOIN members m ON m.member_id=dc.owner_member_id
                      ORDER BY dc.owner_member_id IS NOT NULL, dc.active DESC, dc.used_count DESC, dc.code")->fetchAll();

// ---- لوحة الإحالات (أكواد مملوكة لأعضاء) ----
$refs = $pdo->query("SELECT m.full_name, dc.code, dc.used_count joined, dc.reward_value,
                       dc.used_count*dc.reward_value reward
                     FROM discount_codes dc JOIN members m ON m.member_id=dc.owner_member_id
                     WHERE dc.owner_member_id IS NOT NULL
                     ORDER BY joined DESC, reward DESC, m.full_name")->fetchAll();
$refTotalJoined = 0; $refTotalReward = 0.0;
foreach ($refs as $r) { $refTotalJoined += (int)$r['joined']; $refTotalReward += (float)$r['reward']; }

// ---- أحدث الاستخدامات ----
$recent = $pdo->query("SELECT dr.*, m.full_name FROM discount_redemptions dr
                       LEFT JOIN members m ON m.member_id=dr.member_id
                       ORDER BY dr.redemption_id DESC LIMIT 15")->fetchAll();

$kindLabel  = ['percent'=>'نسبة %','fixed'=>'مبلغ ثابت'];
$scopeLabel = ['membership'=>'اشتراكات','pos'=>'نقطة بيع','both'=>'الكل'];
$ctxLabel   = ['membership'=>'اشتراك','pos'=>'مبيعات'];
$fmtVal = fn($r) => $r['kind']==='percent' ? rtrim(rtrim(number_format($r['value'],2),'0'),'.').'%' : money((float)$r['value']);
?>
<div style="margin-bottom:6px"><h2 style="margin:0">🎟️ الإحالات وأكواد الخصم</h2></div>
<div class="kpis">
  <div class="card" style="--c:#2563eb"><div class="n"><?=$activeCodes?></div><div class="l">أكواد فعّالة</div></div>
  <div class="card" style="--c:#16a34a"><div class="n"><?=$redMonth?></div><div class="l">استخدامات هذا الشهر</div></div>
  <div class="card" style="--c:#dc2626"><div class="n" style="font-size:22px"><?=money($discMonth)?></div><div class="l">خصومات مُمنوحة (الشهر)</div></div>
  <div class="card" style="--c:#7c3aed"><div class="n" style="font-size:16px"><?= $topRef ? h($topRef['full_name']).' ('.$topRef['used_count'].')' : '—' ?></div><div class="l">أعلى مُحيل</div></div>
</div>

<nav class="tabbar" aria-label="أقسام الإحالات">
  <button type="button" data-tabtarget="codes">🎟️ الأكواد</button>
  <button type="button" data-tabtarget="refs">🤝 الإحالات</button>
  <button type="button" data-tabtarget="log">🧾 السجل</button>
</nav>

<!-- ===== الأكواد ===== -->
<section data-tab="codes">
  <h2>🎟️ أكواد الخصم</h2>
  <h3>➕ إنشاء كود تسويقي</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_code">
    <div><label>الكود *</label><input name="code" placeholder="مثال: BACK2GYM" required style="text-transform:uppercase"></div>
    <div><label>النوع</label><select name="kind"><?php foreach ($KINDS as $k): ?><option value="<?=$k?>"><?=h($kindLabel[$k])?></option><?php endforeach; ?></select></div>
    <div><label>القيمة</label><input type="number" step="0.01" min="0" name="value" value="10" required></div>
    <div><label>النطاق</label><select name="scope"><?php foreach ($SCOPES as $s): ?><option value="<?=$s?>" <?=$s==='both'?'selected':''?>><?=h($scopeLabel[$s])?></option><?php endforeach; ?></select></div>
    <div><label>حدّ الاستخدام (0=∞)</label><input type="number" min="0" name="max_uses" value="0"></div>
    <div><label>ينتهي في (اختياري)</label><input type="date" name="expires_on"></div>
    <div><button type="submit">إنشاء</button></div>
  </form>
  <table>
    <tr><th>الكود</th><th>النوع/القيمة</th><th>النطاق</th><th>المالك</th><th>الاستخدام</th><th>الحالة</th><th></th></tr>
    <?php foreach ($codes as $c): $limited = (int)$c['max_uses'] > 0; ?>
      <tr style="<?= $c['active']?'':'opacity:.5' ?>">
        <td><strong style="font-family:monospace"><?=h($c['code'])?></strong><?php if ($c['expires_on']): ?><div class="muted" style="font-size:11px">ينتهي <?=h($c['expires_on'])?></div><?php endif; ?></td>
        <td><?=h($kindLabel[$c['kind']])?> · <strong><?=$fmtVal($c)?></strong></td>
        <td class="muted"><?=h($scopeLabel[$c['scope']])?></td>
        <td><?= $c['owner_name'] ? '<span class="badge" style="background:#7c3aed">إحالة</span> '.h($c['owner_name']) : '<span class="muted">تسويقي</span>' ?></td>
        <td><?=(int)$c['used_count']?><?= $limited ? ' / '.(int)$c['max_uses'] : '' ?></td>
        <td><span class="badge" style="background:<?= $c['active']?'#16a34a':'#6b7280' ?>"><?= $c['active']?'فعّال':'موقوف' ?></span></td>
        <td><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="action" value="toggle_code"><input type="hidden" name="code_id" value="<?=(int)$c['code_id']?>"><button type="submit" style="background:<?= $c['active']?'#f59e0b':'#16a34a' ?>;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><?= $c['active']?'إيقاف':'تفعيل' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:8px 0 0">أكواد الإحالة (REF-…) تُنشأ تلقائيًا لكل عضو ويملكها العضو — استخدامها يكافئ صاحبها.</p>
</section>

<!-- ===== الإحالات ===== -->
<section data-tab="refs">
  <h2>🤝 لوحة الإحالات</h2>
  <div class="kpis" style="margin-bottom:10px">
    <div class="card" style="--c:#16a34a"><div class="n"><?=$refTotalJoined?></div><div class="l">إجمالي الإحالات المستخدَمة</div></div>
    <div class="card" style="--c:#7c3aed"><div class="n" style="font-size:22px"><?=money($refTotalReward)?></div><div class="l">إجمالي مكافآت المُحيلين</div></div>
  </div>
  <table>
    <tr><th>#</th><th>العضو</th><th>كود الإحالة</th><th>إحالات</th><th>مكافأة/إحالة</th><th>إجمالي المكافأة</th></tr>
    <?php foreach ($refs as $i => $r): ?>
      <tr>
        <td><?= $i<3 ? ['🥇','🥈','🥉'][$i] : ($i+1) ?></td>
        <td><strong><?=h($r['full_name'])?></strong></td>
        <td style="font-family:monospace"><?=h($r['code'])?></td>
        <td><strong style="color:<?= (int)$r['joined']>0?'#16a34a':'#94a3b8' ?>"><?=(int)$r['joined']?></strong></td>
        <td class="muted"><?=money((float)$r['reward_value'])?></td>
        <td style="font-weight:700;color:#7c3aed"><?=money((float)$r['reward'])?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$refs): ?><tr><td colspan="6" class="muted">لا أعضاء بعد.</td></tr><?php endif; ?>
  </table>
</section>

<!-- ===== السجل ===== -->
<section data-tab="log">
  <h2>🧾 أحدث الاستخدامات</h2>
  <?php if (!$recent): ?><div class="empty">لا استخدامات بعد.</div><?php else: ?>
  <table>
    <tr><th>التاريخ</th><th>الكود</th><th>المستفيد</th><th>السياق</th><th>الأساس</th><th>الخصم</th><th>الصافي</th></tr>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?=h(substr((string)$r['created_at'],0,16))?></td>
        <td style="font-family:monospace"><?=h($r['code'])?></td>
        <td class="muted"><?=h($r['full_name'] ?? '— زائر')?></td>
        <td><span class="badge" style="background:<?= $r['context']==='membership'?'#2563eb':'#16a34a' ?>"><?=h($ctxLabel[$r['context']] ?? $r['context'])?></span></td>
        <td><?=money((float)$r['base_amount'])?></td>
        <td style="color:#dc2626">−<?=money((float)$r['discount_amount'])?></td>
        <td style="font-weight:700"><?=money((float)$r['final_amount'])?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>
<?php page_foot();

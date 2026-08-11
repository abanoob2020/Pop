<?php
// =============================================================================
// Xcamp Gym — المحاسبة ونقطة البيع: P&L + نقطة بيع بمخزون + مدفوعات + مصروفات
// للإدارة/الاستقبال فقط (تعامل مالي). يستفيد من نظام التبويبات (المرحلة 4).
// =============================================================================
require __DIR__ . '/db.php';
$me = require_role(['admin','manager','reception']);
$uid = (int)($me['uid'] ?? 0) ?: null;

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$METHODS = ['cash','card','bank_transfer','wallet','other'];
$PCATS   = ['supplement','drink','apparel','accessory','service','other'];
$ECATS   = ['rent','salaries','equipment','utilities','marketing','maintenance','supplies','other'];

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'pos_checkout') {
            $method = in_array($_POST['method'] ?? '', $METHODS, true) ? $_POST['method'] : 'cash';
            $memberId = (int)($_POST['member_id'] ?? 0) ?: null;
            $qty = $_POST['qty'] ?? [];
            // اجمع البنود المطلوبة وتحقّق من المخزون
            $lines = [];
            foreach ($qty as $pid => $q) {
                $q = (int)$q; if ($q <= 0) continue;
                $p = $pdo->prepare("SELECT product_id, name, price, stock_qty, active FROM products WHERE product_id = ?");
                $p->execute([(int)$pid]); $p = $p->fetch();
                if (!$p || !$p['active']) throw new RuntimeException('منتج غير متاح.');
                if ($q > (int)$p['stock_qty']) throw new RuntimeException('الكمية المطلوبة من «' . $p['name'] . '» تتجاوز المخزون (' . (int)$p['stock_qty'] . ').');
                $lines[] = ['product_id' => (int)$p['product_id'], 'name' => $p['name'], 'qty' => $q, 'unit_price' => (float)$p['price']];
            }
            if (!$lines) throw new RuntimeException('اختر منتجًا واحدًا على الأقل.');
            $gross = pos_cart_total($lines);
            // كود خصم اختياري (سياق: نقطة بيع)
            $promo = strtoupper(trim($_POST['promo'] ?? ''));
            $promoRow = null; $discount = 0.0; $total = $gross;
            if ($promo !== '') {
                $ev = discount_evaluate($pdo, $promo, $gross, 'pos');
                if (!$ev['ok']) throw new RuntimeException('كود الخصم: ' . $ev['msg']);
                $promoRow = $ev['row']; $discount = $ev['discount']; $total = $ev['final'];
            }
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO pos_sales (member_id, cashier_user_id, total, method) VALUES (?,?,?,?)")
                ->execute([$memberId, $uid, $total, $method]);
            $saleId = (int)$pdo->lastInsertId();
            if ($promoRow) discount_redeem($pdo, $promoRow, 'pos', $gross, $discount, $memberId);
            $insItem = $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, qty, unit_price) VALUES (?,?,?,?,?)");
            $decr    = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE product_id = ?");
            foreach ($lines as $l) {
                $insItem->execute([$saleId, $l['product_id'], $l['name'], $l['qty'], $l['unit_price']]);
                $decr->execute([$l['qty'], $l['product_id']]);
            }
            $pdo->commit();   // pos_sales هو سجلّ البيع (الدخل يُحتسب منه في اللوحة)
        } elseif ($act === 'add_product') {
            $pdo->prepare("INSERT INTO products (name, category, price, stock_qty) VALUES (?,?,?,?)")
                ->execute([trim($_POST['name']), in_array($_POST['category'] ?? '', $PCATS, true) ? $_POST['category'] : 'other',
                           max(0, (float)$_POST['price']), max(0, (int)$_POST['stock_qty'])]);
        } elseif ($act === 'update_product') {
            $pdo->prepare("UPDATE products SET price = ?, stock_qty = ? WHERE product_id = ?")
                ->execute([max(0, (float)$_POST['price']), max(0, (int)$_POST['stock_qty']), (int)$_POST['product_id']]);
        } elseif ($act === 'toggle_product') {
            $pdo->prepare("UPDATE products SET active = 1 - active WHERE product_id = ?")->execute([(int)$_POST['product_id']]);
        } elseif ($act === 'add_expense') {
            $pdo->prepare("INSERT INTO expenses (category, amount, description, spent_on, recorded_by) VALUES (?,?,?,?,?)")
                ->execute([in_array($_POST['category'] ?? '', $ECATS, true) ? $_POST['category'] : 'other',
                           max(0, (float)$_POST['amount']), trim($_POST['description'] ?? '') ?: null,
                           $_POST['spent_on'] ?: date('Y-m-d'), $uid]);
        } elseif ($act === 'record_membership_payment') {
            $msid = (int)$_POST['membership_id'];
            // مفتاح idempotency من النموذج (hex) — يمنع ازدواج الدفعة عند نقر مزدوج/تحديث/
            // إعادة محاولة/طلبات متزامنة (يُنفَّذ القيد على مستوى القاعدة uq_payments_idem).
            $idem = preg_replace('/[^a-f0-9]/', '', (string)($_POST['idem'] ?? ''));
            $idem = ($idem !== '') ? substr($idem, 0, 64) : null;
            $q = $pdo->prepare("SELECT member_id FROM memberships WHERE membership_id = ?");
            $q->execute([$msid]); $mid = $q->fetchColumn();
            if ($mid === false) throw new RuntimeException('اشتراك غير موجود.');
            $method = in_array($_POST['method'] ?? '', $METHODS, true) ? $_POST['method'] : 'cash';
            $amount = max(0, (float)$_POST['amount']);
            if ($amount <= 0) throw new RuntimeException('أدخل مبلغًا صحيحًا.');
            // كود خصم اختياري (سياق: اشتراكات)
            $promo = strtoupper(trim($_POST['promo'] ?? ''));
            $promoRow = null; $discount = 0.0; $payAmount = $amount; $note = 'دفعة اشتراك عبر المحاسبة';
            if ($promo !== '') {
                $ev = discount_evaluate($pdo, $promo, $amount, 'membership');
                if (!$ev['ok']) throw new RuntimeException('كود الخصم: ' . $ev['msg']);
                $promoRow = $ev['row']; $discount = $ev['discount']; $payAmount = $ev['final'];
                $note .= ' (كود ' . $promoRow['code'] . ' −' . money($discount) . ')';
            }
            try {
                $pdo->beginTransaction();
                // يُسجَّل في جدول payments الأصلي بالمخطط (مع مفتاح idempotency)
                $pdo->prepare("INSERT INTO payments (member_id, membership_id, payment_date, amount, method, status, notes, idempotency_key)
                               VALUES (?,?, NOW(), ?, ?, 'paid', ?, ?)")
                    ->execute([(int)$mid, $msid, $payAmount, $method, $note, $idem]);
                $pdo->prepare("UPDATE memberships SET payment_status = 'paid' WHERE membership_id = ?")->execute([$msid]);
                if ($promoRow) discount_redeem($pdo, $promoRow, 'membership', $amount, $discount, (int)$mid);
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // ازدواج مفتاح idempotency = الدفعة سُجّلت سابقًا → نجاح idempotent بلا صفّ مكرّر.
                if ($e->getCode() === '23000' && $idem !== null && strpos($e->getMessage(), 'uq_payments_idem') !== false) {
                    header('Location: finance.php?ok=1&dup=1');
                    exit;
                }
                throw $e;
            }
        }
        header('Location: finance.php?ok=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('المحاسبة', 'finance');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح ✓</div>';

// ---- بيانات اللوحة (الشهر الحالي) ----
$MFILTER_PAY = "YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE())";
$MFILTER_POS = "YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())";
$membInc = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid' AND $MFILTER_PAY")->fetchColumn();
$posInc  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM pos_sales WHERE $MFILTER_POS")->fetchColumn();
$incMonth = $membInc + $posInc;
$expMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(spent_on)=YEAR(CURDATE()) AND MONTH(spent_on)=MONTH(CURDATE())")->fetchColumn();
$net = net_profit($incMonth, $expMonth);
$byCat = array_values(array_filter([
    ['category' => 'membership', 's' => $membInc],
    ['category' => 'pos',        's' => $posInc],
], fn($r) => $r['s'] > 0));
// الدخل حسب طريقة الدفع (دمج المدفوعات + مبيعات نقطة البيع)
$methodTot = [];
foreach ($pdo->query("SELECT method, SUM(amount) s FROM payments WHERE status='paid' AND $MFILTER_PAY GROUP BY method") as $r) $methodTot[$r['method']] = ($methodTot[$r['method']] ?? 0) + (float)$r['s'];
foreach ($pdo->query("SELECT method, SUM(total) s FROM pos_sales WHERE $MFILTER_POS GROUP BY method") as $r) $methodTot[$r['method']] = ($methodTot[$r['method']] ?? 0) + (float)$r['s'];
arsort($methodTot);
$byMethod = [];
foreach ($methodTot as $mth => $s) $byMethod[] = ['method' => $mth, 's' => $s];
// أحدث الحركات: دمج مدفوعات الاشتراكات + مبيعات نقطة البيع
$recentPay = [];
foreach ($pdo->query("SELECT p.payment_date dt, p.amount, p.method, p.status, m.full_name, 'membership' cat
                      FROM payments p LEFT JOIN members m ON m.member_id=p.member_id ORDER BY p.payment_id DESC LIMIT 10") as $r) $recentPay[] = $r;
foreach ($pdo->query("SELECT s.created_at dt, s.total amount, s.method, 'paid' status, m.full_name, 'pos' cat
                      FROM pos_sales s LEFT JOIN members m ON m.member_id=s.member_id ORDER BY s.sale_id DESC LIMIT 10") as $r) $recentPay[] = $r;
usort($recentPay, fn($a, $b) => strcmp($b['dt'], $a['dt']));
$recentPay = array_slice($recentPay, 0, 12);
$recentExp = $pdo->query("SELECT * FROM expenses ORDER BY expense_id DESC LIMIT 8")->fetchAll();
$products  = $pdo->query("SELECT * FROM products ORDER BY category, name")->fetchAll();
$lowStock  = array_filter($products, fn($p) => $p['active'] && (int)$p['stock_qty'] <= 5);
$members   = $pdo->query("SELECT member_id, full_name FROM members WHERE status <> 'expired' ORDER BY full_name")->fetchAll();
$dueMs = $pdo->query("SELECT ms.membership_id, m.full_name, pl.plan_name, pl.price, ms.payment_status
                      FROM memberships ms JOIN members m ON m.member_id=ms.member_id JOIN plans pl ON pl.plan_id=ms.plan_id
                      WHERE ms.payment_status IN ('unpaid','partial','failed') ORDER BY m.full_name")->fetchAll();

$catLabel = ['membership'=>'اشتراكات','pos'=>'مبيعات','other'=>'أخرى'];
$mLabel   = ['cash'=>'نقدي','card'=>'بطاقة','bank_transfer'=>'تحويل بنكي','wallet'=>'محفظة','other'=>'أخرى'];
$pcatLabel= ['supplement'=>'مكمّلات','drink'=>'مشروبات','apparel'=>'ملابس','accessory'=>'إكسسوار','service'=>'خدمة','other'=>'أخرى'];
$ecatLabel= ['rent'=>'إيجار','salaries'=>'رواتب','equipment'=>'معدّات','utilities'=>'مرافق','marketing'=>'تسويق','maintenance'=>'صيانة','supplies'=>'مستلزمات','other'=>'أخرى'];
?>
<div style="margin-bottom:6px"><h2 style="margin:0">💵 المحاسبة ونقطة البيع</h2></div>
<nav class="tabbar" aria-label="أقسام المحاسبة">
  <button type="button" data-tabtarget="dash">📊 اللوحة المالية</button>
  <button type="button" data-tabtarget="pos">🛒 نقطة البيع</button>
  <button type="button" data-tabtarget="prod">📦 المنتجات<?= $lowStock ? ' ⚠️' : '' ?></button>
  <button type="button" data-tabtarget="exp">💸 المصروفات</button>
</nav>

<!-- ===== اللوحة المالية ===== -->
<section data-tab="dash">
  <h2>📊 أداء الشهر الحالي</h2>
  <div class="kpis">
    <div class="card" style="--c:#16a34a"><div class="n" style="color:#16a34a;font-size:23px"><?=money($incMonth)?></div><div class="l">💰 الدخل</div></div>
    <div class="card" style="--c:#dc2626"><div class="n" style="color:#dc2626;font-size:23px"><?=money($expMonth)?></div><div class="l">💸 المصروفات</div></div>
    <div class="card" style="--c:<?= $net>=0?'#2563eb':'#dc2626' ?>"><div class="n" style="color:<?= $net>=0?'#2563eb':'#dc2626' ?>;font-size:23px"><?=money($net)?></div><div class="l">📈 صافي الربح</div></div>
  </div>
  <div class="grid2" style="margin-top:8px">
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px">
      <strong style="font-size:13px;color:#334155">الدخل حسب المصدر</strong>
      <table style="margin-top:8px"><?php if (!$byCat): ?><tr><td class="muted">لا دخل هذا الشهر.</td></tr><?php endif; ?>
        <?php foreach ($byCat as $c): $pct = $incMonth ? round((float)$c['s']/$incMonth*100) : 0; ?>
          <tr><td style="width:90px"><?=h($catLabel[$c['category']] ?? $c['category'])?></td><td style="width:90px"><strong><?=money((float)$c['s'])?></strong></td>
            <td><div style="height:8px;background:#eef2f7;border-radius:6px"><div style="height:100%;width:<?=$pct?>%;background:#16a34a;border-radius:6px"></div></div></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px">
      <strong style="font-size:13px;color:#334155">الدخل حسب طريقة الدفع</strong>
      <table style="margin-top:8px"><?php if (!$byMethod): ?><tr><td class="muted">—</td></tr><?php endif; ?>
        <?php foreach ($byMethod as $c): ?>
          <tr><td><?=h($mLabel[$c['method']] ?? $c['method'])?></td><td style="text-align:left"><strong><?=money((float)$c['s'])?></strong></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <h3>💳 تسجيل دفعة اشتراك</h3>
  <?php if (!$dueMs): ?><div class="empty">لا توجد اشتراكات غير محصّلة 🎉</div><?php else: ?>
    <form class="frm" method="post"><?=csrf_field()?>
      <input type="hidden" name="action" value="record_membership_payment">
      <input type="hidden" name="idem" value="<?=bin2hex(random_bytes(16))?>">
      <div style="grid-column:span 2"><label>العضو / الاشتراك</label><select name="membership_id" onchange="var o=this.options[this.selectedIndex];document.getElementById('mpay').value=o.dataset.price;">
        <?php foreach ($dueMs as $d): ?><option value="<?=(int)$d['membership_id']?>" data-price="<?=h($d['price'])?>"><?=h($d['full_name'])?> — <?=h($d['plan_name'])?> (<?=h($d['payment_status'])?>)</option><?php endforeach; ?>
      </select></div>
      <div><label>المبلغ</label><input id="mpay" type="number" step="0.01" name="amount" value="<?=h($dueMs[0]['price'])?>" required></div>
      <div><label>الطريقة</label><select name="method"><?php foreach ($METHODS as $m): ?><option value="<?=$m?>"><?=h($mLabel[$m])?></option><?php endforeach; ?></select></div>
      <div><label>كود خصم (اختياري)</label><input name="promo" placeholder="REF-… أو WELCOME10" style="text-transform:uppercase"></div>
      <div><button type="submit">تسجيل الدفعة</button></div>
    </form>
  <?php endif; ?>
  <h3>🧾 أحدث الحركات</h3>
  <table>
    <tr><th>التاريخ</th><th>البند</th><th>العضو</th><th>الطريقة</th><th>الحالة</th><th>المبلغ</th></tr>
    <?php foreach ($recentPay as $p): $paid = $p['status'] === 'paid'; ?>
      <tr><td><?=h(substr((string)$p['dt'],0,10))?></td>
        <td><span class="badge" style="background:<?= $p['cat']==='membership'?'#2563eb':'#16a34a' ?>"><?=h($catLabel[$p['cat']] ?? $p['cat'])?></span></td>
        <td class="muted"><?=h($p['full_name'] ?? '— زائر')?></td><td><?=h($mLabel[$p['method']] ?? $p['method'])?></td>
        <td><span class="badge" style="background:<?= $paid?'#16a34a':($p['status']==='partial'?'#f59e0b':'#dc2626') ?>"><?=h($p['status'])?></span></td>
        <td style="font-weight:700;color:<?= $paid?'#16a34a':'#94a3b8' ?>"><?= $paid?'+':'' ?><?=money((float)$p['amount'])?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recentPay): ?><tr><td colspan="6" class="muted">لا حركات بعد.</td></tr><?php endif; ?>
  </table>
</section>

<!-- ===== نقطة البيع ===== -->
<section data-tab="pos">
  <h2>🛒 نقطة البيع</h2>
  <form method="post" data-no-ajax><?=csrf_field()?>
    <input type="hidden" name="action" value="pos_checkout">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:12px">
      <?php foreach ($products as $p): if (!$p['active']) continue; $out = (int)$p['stock_qty'] <= 0; ?>
        <div style="border:1px solid #eef2f7;border-radius:10px;padding:10px;<?= $out?'opacity:.5':'' ?>">
          <div style="font-weight:700;font-size:13px"><?=h($p['name'])?></div>
          <div class="muted" style="font-size:11px"><?=h($pcatLabel[$p['category']] ?? $p['category'])?> · مخزون <?=(int)$p['stock_qty']?></div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:6px">
            <strong style="color:#16a34a"><?=money((float)$p['price'])?></strong>
            <input type="number" name="qty[<?=(int)$p['product_id']?>]" value="0" min="0" max="<?=(int)$p['stock_qty']?>" <?= $out?'disabled':'' ?> style="width:64px;padding:6px;border:1px solid #cbd5e1;border-radius:8px;text-align:center">
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="frm" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
      <div><label>العضو (اختياري)</label><select name="member_id"><option value="">— زائر —</option>
        <?php foreach ($members as $m): ?><option value="<?=(int)$m['member_id']?>"><?=h($m['full_name'])?></option><?php endforeach; ?></select></div>
      <div><label>طريقة الدفع</label><select name="method"><?php foreach ($METHODS as $m): ?><option value="<?=$m?>"><?=h($mLabel[$m])?></option><?php endforeach; ?></select></div>
      <div><label>كود خصم (اختياري)</label><input name="promo" placeholder="REF-… أو WELCOME10" style="text-transform:uppercase"></div>
      <div style="display:flex;align-items:end"><button type="submit" style="background:#16a34a;color:#fff;border:0;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;width:100%">💳 إتمام البيع</button></div>
    </div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">حدّد الكميات ثم أتمّ البيع — يُسجَّل الدخل تلقائيًا ويُخصم المخزون.</p>
  </form>
</section>

<!-- ===== المنتجات ===== -->
<section data-tab="prod">
  <h2>📦 المنتجات والمخزون</h2>
  <?php if ($lowStock): ?><div style="background:#fef3c7;color:#92400e;padding:10px 14px;border-radius:8px;margin-bottom:12px">⚠️ مخزون منخفض: <?=h(implode('، ', array_map(fn($p)=>$p['name'].' ('.(int)$p['stock_qty'].')', $lowStock)))?></div><?php endif; ?>
  <table>
    <tr><th>المنتج</th><th>الفئة</th><th>السعر</th><th>المخزون</th><th>الحالة</th><th>تعديل</th></tr>
    <?php foreach ($products as $p): ?>
      <tr style="<?= $p['active']?'':'opacity:.5' ?>">
        <td><strong><?=h($p['name'])?></strong></td><td class="muted"><?=h($pcatLabel[$p['category']] ?? $p['category'])?></td>
        <td><?=money((float)$p['price'])?></td>
        <td style="<?= (int)$p['stock_qty']<=5?'color:#dc2626;font-weight:700':'' ?>"><?=(int)$p['stock_qty']?></td>
        <td><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="product_id" value="<?=(int)$p['product_id']?>"><button type="submit" style="background:<?= $p['active']?'#f59e0b':'#16a34a' ?>;color:#fff;border:0;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer"><?= $p['active']?'إيقاف':'تفعيل' ?></button></form></td>
        <td><details><summary class="link" style="cursor:pointer;font-size:12px">تعديل</summary>
          <form class="frm" method="post" style="margin-top:6px"><?=csrf_field()?>
            <input type="hidden" name="action" value="update_product"><input type="hidden" name="product_id" value="<?=(int)$p['product_id']?>">
            <div><label>السعر</label><input type="number" step="0.01" name="price" value="<?=h($p['price'])?>"></div>
            <div><label>المخزون</label><input type="number" name="stock_qty" value="<?=(int)$p['stock_qty']?>"></div>
            <div><button type="submit">حفظ</button></div>
          </form></details></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <h3>➕ إضافة منتج</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_product">
    <div><label>الاسم *</label><input name="name" required></div>
    <div><label>الفئة</label><select name="category"><?php foreach ($PCATS as $c): ?><option value="<?=$c?>"><?=h($pcatLabel[$c])?></option><?php endforeach; ?></select></div>
    <div><label>السعر</label><input type="number" step="0.01" name="price" value="0"></div>
    <div><label>المخزون</label><input type="number" name="stock_qty" value="0"></div>
    <div><button type="submit">إضافة</button></div>
  </form>
</section>

<!-- ===== المصروفات ===== -->
<section data-tab="exp">
  <h2>💸 المصروفات</h2>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_expense">
    <div><label>الفئة</label><select name="category"><?php foreach ($ECATS as $c): ?><option value="<?=$c?>"><?=h($ecatLabel[$c])?></option><?php endforeach; ?></select></div>
    <div><label>المبلغ</label><input type="number" step="0.01" name="amount" required></div>
    <div><label>التاريخ</label><input type="date" name="spent_on" value="<?=date('Y-m-d')?>" required></div>
    <div style="grid-column:1/-1"><label>الوصف</label><input name="description"></div>
    <div><button type="submit">تسجيل المصروف</button></div>
  </form>
  <?php if ($recentExp): ?>
    <h3>أحدث المصروفات</h3>
    <table><tr><th>التاريخ</th><th>الفئة</th><th>الوصف</th><th>المبلغ</th></tr>
      <?php foreach ($recentExp as $e): ?>
        <tr><td><?=h($e['spent_on'])?></td><td><span class="badge" style="background:#6b7280"><?=h($ecatLabel[$e['category']] ?? $e['category'])?></span></td>
          <td class="muted"><?=h($e['description'])?></td><td style="font-weight:700;color:#dc2626">−<?=money((float)$e['amount'])?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</section>
<?php page_foot();

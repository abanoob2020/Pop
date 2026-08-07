<?php
// =============================================================================
// Xcamp Gym — مكتبة الوصفات وحاسبة الحصص: مكوّنات + وصفات + حساب دقيق لأي وزن
// حصة (بالوزن المطبوخ). للطاقم. يعتمد recipe_portion_macros() في training.php.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'add_ingredient') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('أدخل اسم المكوّن.');
            $pdo->prepare("INSERT INTO ingredients (name, calories_per_100g, protein_per_100g, carbs_per_100g, fats_per_100g, is_arabic)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$name, max(0,(float)$_POST['cal']), max(0,(float)$_POST['p']), max(0,(float)$_POST['c']),
                           max(0,(float)$_POST['f']), isset($_POST['is_arabic']) ? 1 : 0]);
        } elseif ($act === 'add_recipe') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('أدخل اسم الوصفة.');
            $cooked = ($_POST['cooked'] ?? '') !== '' ? max(0,(float)$_POST['cooked']) : null;
            $pdo->prepare("INSERT INTO recipes (name, cooked_weight_grams, default_servings, description) VALUES (?,?,?,?)")
                ->execute([$name, $cooked, max(1,(int)($_POST['servings'] ?? 1)), trim($_POST['description'] ?? '') ?: null]);
        } elseif ($act === 'update_recipe') {
            $cooked = ($_POST['cooked'] ?? '') !== '' ? max(0,(float)$_POST['cooked']) : null;
            $pdo->prepare("UPDATE recipes SET cooked_weight_grams = ?, default_servings = ? WHERE recipe_id = ?")
                ->execute([$cooked, max(1,(int)($_POST['servings'] ?? 1)), (int)$_POST['recipe_id']]);
        } elseif ($act === 'add_recipe_ingredient') {
            $rid = (int)$_POST['recipe_id']; $iid = (int)$_POST['ingredient_id']; $amt = max(0,(float)$_POST['amount_grams']);
            if ($amt <= 0) throw new RuntimeException('أدخل كمية صحيحة.');
            $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, amount_grams) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE amount_grams = VALUES(amount_grams)")
                ->execute([$rid, $iid, $amt]);
        } elseif ($act === 'del_recipe_ingredient') {
            $pdo->prepare("DELETE FROM recipe_ingredients WHERE recipe_ingredient_id = ?")->execute([(int)$_POST['ri_id']]);
        }
        header('Location: recipes.php?ok=1' . (isset($_POST['open']) ? '&open=' . (int)$_POST['open'] : ''));
        exit;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'uq_') !== false || strpos($msg, 'Duplicate') !== false) $msg = 'الاسم موجود بالفعل.';
        $error = 'تعذّر الحفظ: ' . $msg;
    }
}

page_head('الوصفات', 'recipes');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح ✓</div>';

$ingredients = $pdo->query("SELECT * FROM ingredients WHERE active=1 ORDER BY name")->fetchAll();
$recipes = $pdo->query("SELECT r.*,
      (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.recipe_id=r.recipe_id) ing_cnt,
      (SELECT COALESCE(SUM(ri.amount_grams),0) FROM recipe_ingredients ri WHERE ri.recipe_id=r.recipe_id) raw_total
    FROM recipes r WHERE r.active=1 ORDER BY r.name")->fetchAll();

// ── الحاسبة ──
$selRid = (int)($_GET['recipe'] ?? 0);
$portion = ($_GET['grams'] ?? '') !== '' ? max(0,(float)$_GET['grams']) : 0;
$selMember = (int)($_GET['member'] ?? 0);
$calc = null; $calcRecipe = null;
if ($selRid && $portion > 0) {
    $rq = $pdo->prepare("SELECT * FROM recipes WHERE recipe_id = ?"); $rq->execute([$selRid]); $calcRecipe = $rq->fetch();
    if ($calcRecipe) {
        $iq = $pdo->prepare("SELECT ri.amount_grams, i.name, i.calories_per_100g cal100, i.protein_per_100g p100, i.carbs_per_100g c100, i.fats_per_100g f100
                             FROM recipe_ingredients ri JOIN ingredients i ON i.ingredient_id=ri.ingredient_id WHERE ri.recipe_id = ?");
        $iq->execute([$selRid]);
        $items = $iq->fetchAll();
        if ($items) {
            $cooked = $calcRecipe['cooked_weight_grams'] !== null ? (float)$calcRecipe['cooked_weight_grams'] : null;
            $calc = recipe_portion_macros($cooked, $items, $portion);
        } else $calc = ['ok'=>false,'msg'=>'الوصفة بلا مكوّنات.'];
    }
}
// هدف العضو (اختياري) للمقارنة
$memberTarget = null; $membersList = [];
$membersList = $pdo->query("SELECT member_id, full_name FROM members WHERE status <> 'expired' ORDER BY full_name")->fetchAll();
if ($selMember) {
    $tq = $pdo->prepare("SELECT calories, protein_g FROM nutrition_plans WHERE member_id = ? AND status='active' ORDER BY nutrition_plan_id DESC LIMIT 1");
    $tq->execute([$selMember]); $memberTarget = $tq->fetch();
}
// مكوّنات وصفة مفتوحة للتحرير
$openRid = (int)($_GET['open'] ?? 0);
$openRecipe = null; $openItems = [];
if ($openRid) {
    $r = $pdo->prepare("SELECT * FROM recipes WHERE recipe_id = ?"); $r->execute([$openRid]); $openRecipe = $r->fetch();
    if ($openRecipe) {
        $ri = $pdo->prepare("SELECT ri.*, i.name FROM recipe_ingredients ri JOIN ingredients i ON i.ingredient_id=ri.ingredient_id WHERE ri.recipe_id = ? ORDER BY ri.recipe_ingredient_id");
        $ri->execute([$openRid]); $openItems = $ri->fetchAll();
    }
}
$pct = fn($v, $t) => $t > 0 ? round($v / $t * 100) : null;
?>
<div style="margin-bottom:6px"><h2 style="margin:0">🍽️ مكتبة الوصفات وحاسبة الحصص</h2></div>
<nav class="tabbar" aria-label="أقسام الوصفات">
  <button type="button" data-tabtarget="calc">🧮 الحاسبة</button>
  <button type="button" data-tabtarget="recs">📖 الوصفات</button>
  <button type="button" data-tabtarget="ings">🥕 المكوّنات</button>
</nav>

<!-- ===== الحاسبة ===== -->
<section data-tab="calc">
  <h2>🧮 حاسبة الحصص</h2>
  <form method="get" data-no-ajax class="frm" style="align-items:end">
    <div><label>الوصفة</label><select name="recipe" required>
      <option value="">— اختر —</option>
      <?php foreach ($recipes as $r): ?><option value="<?=(int)$r['recipe_id']?>" <?=$selRid===(int)$r['recipe_id']?'selected':''?>><?=h($r['name'])?></option><?php endforeach; ?>
    </select></div>
    <div><label>وزن الحصة (غ)</label><input type="number" step="1" min="1" name="grams" value="<?=$portion?:''?>" placeholder="مثال: 450" required></div>
    <div><label>مقارنة بهدف عضو (اختياري)</label><select name="member"><option value="">—</option>
      <?php foreach ($membersList as $mm): ?><option value="<?=(int)$mm['member_id']?>" <?=$selMember===(int)$mm['member_id']?'selected':''?>><?=h($mm['full_name'])?></option><?php endforeach; ?>
    </select></div>
    <div><button type="submit">احسب</button></div>
  </form>

  <?php if ($calc && !$calc['ok']): ?>
    <div class="err" style="margin-top:12px"><?=h($calc['msg'])?></div>
  <?php elseif ($calc): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px">
      <h3 style="margin:0"><?=h($calcRecipe['name'])?> — حصة <?=rtrim(rtrim(number_format($calc['portion'],1),'0'),'.')?> غ</h3>
      <span class="badge" style="background:<?= $calc['denom_source']==='cooked'?'#16a34a':'#f59e0b' ?>">
        <?= $calc['denom_source']==='cooked' ? 'دقيق (وزن مطبوخ '.rtrim(rtrim(number_format($calc['denom'],1),'0'),'.').'غ)' : 'تقريبي (وزن خام '.rtrim(rtrim(number_format($calc['denom'],1),'0'),'.').'غ)' ?>
      </span>
    </div>
    <div class="kpis" style="margin:8px 0">
      <div class="card" style="--c:#0891b2"><div class="n" style="font-size:22px"><?=$calc['calories']?></div><div class="l">سعرات (kcal)</div></div>
      <div class="card" style="--c:#16a34a"><div class="n" style="font-size:22px"><?=$calc['protein']?></div><div class="l">بروتين (غ)</div></div>
      <div class="card" style="--c:#f59e0b"><div class="n" style="font-size:22px"><?=$calc['carbs']?></div><div class="l">كارب (غ)</div></div>
      <div class="card" style="--c:#dc2626"><div class="n" style="font-size:22px"><?=$calc['fats']?></div><div class="l">دهون (غ)</div></div>
      <div class="card" style="--c:#6b7280"><div class="n" style="font-size:22px"><?=$calc['kcal_per_100']?></div><div class="l">كثافة (kcal/100غ)</div></div>
    </div>
    <?php if ($memberTarget): $cp=$pct($calc['calories'],$memberTarget['calories']); $pp=$pct($calc['protein'],$memberTarget['protein_g']); ?>
      <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;padding:10px 14px;margin-bottom:8px;font-size:13px">
        📊 هذه الحصة تغطّي <strong><?= $cp!==null?$cp.'%':'—' ?></strong> من سعرات الهدف اليومي و<strong><?= $pp!==null?$pp.'%':'—' ?></strong> من البروتين المستهدف.
      </div>
    <?php endif; ?>
    <table>
      <tr><th>المكوّن</th><th>كمية (خام)</th><th>سعرات</th><th>بروتين</th><th>كارب</th><th>دهون</th></tr>
      <?php foreach ($calc['breakdown'] as $b): ?>
        <tr><td><?=h($b['name'])?></td><td class="muted"><?=$b['amount']?> غ</td><td><?=$b['cal']?></td><td><?=$b['p']?></td><td><?=$b['c']?></td><td><?=$b['f']?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:12px;margin:8px 0 0">الحساب يقسم ماكروز الطبق على وزنه <strong>المطبوخ</strong> (الحصيلة) ليعكس الكثافة الحقيقية للحصة؛ إن غاب يُستخدم الوزن الخام كتقريب.</p>
  <?php else: ?>
    <p class="muted" style="font-size:13px;margin-top:10px">اختر وصفة وأدخل وزن الحصة لعرض السعرات والماكروز الدقيقة.</p>
  <?php endif; ?>
</section>

<!-- ===== الوصفات ===== -->
<section data-tab="recs">
  <h2>📖 الوصفات</h2>
  <?php if ($openRecipe): ?>
    <div class="crumb"><a class="link" href="recipes.php">→ كل الوصفات</a> / <strong><?=h($openRecipe['name'])?></strong></div>
    <form class="frm" method="post" style="margin-bottom:10px"><?=csrf_field()?>
      <input type="hidden" name="action" value="update_recipe"><input type="hidden" name="recipe_id" value="<?=$openRid?>"><input type="hidden" name="open" value="<?=$openRid?>">
      <div><label>الوزن المطبوخ (غ)</label><input type="number" step="1" min="0" name="cooked" value="<?=h($openRecipe['cooked_weight_grams'])?>" placeholder="الحصيلة المطبوخة"></div>
      <div><label>حصص افتراضية</label><input type="number" min="1" name="servings" value="<?=(int)$openRecipe['default_servings']?>"></div>
      <div><button type="submit">حفظ</button></div>
    </form>
    <table><tr><th>المكوّن</th><th>كمية (غ)</th><th></th></tr>
      <?php foreach ($openItems as $it): ?>
        <tr><td><?=h($it['name'])?></td><td><?=rtrim(rtrim(number_format($it['amount_grams'],1),'0'),'.')?></td>
          <td><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="action" value="del_recipe_ingredient"><input type="hidden" name="ri_id" value="<?=(int)$it['recipe_ingredient_id']?>"><input type="hidden" name="open" value="<?=$openRid?>"><button type="submit" style="background:none;border:1px solid #fecaca;color:#dc2626;border-radius:6px;padding:2px 9px;font-size:11px;cursor:pointer">حذف</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$openItems): ?><tr><td colspan="3" class="muted">لا مكوّنات بعد.</td></tr><?php endif; ?>
    </table>
    <h3>➕ أضِف مكوّنًا للوصفة</h3>
    <form class="frm" method="post"><?=csrf_field()?>
      <input type="hidden" name="action" value="add_recipe_ingredient"><input type="hidden" name="recipe_id" value="<?=$openRid?>"><input type="hidden" name="open" value="<?=$openRid?>">
      <div style="grid-column:span 2"><label>المكوّن</label><select name="ingredient_id" required><?php foreach ($ingredients as $i): ?><option value="<?=(int)$i['ingredient_id']?>"><?=h($i['name'])?></option><?php endforeach; ?></select></div>
      <div><label>الكمية (غ)</label><input type="number" step="1" min="1" name="amount_grams" required></div>
      <div><button type="submit">إضافة</button></div>
    </form>
  <?php else: ?>
    <table>
      <tr><th>الوصفة</th><th>مكوّنات</th><th>وزن خام</th><th>وزن مطبوخ</th><th></th></tr>
      <?php foreach ($recipes as $r): ?>
        <tr><td><strong><?=h($r['name'])?></strong><div class="muted" style="font-size:11px"><?=h($r['cuisine_type'])?></div></td>
          <td><?=(int)$r['ing_cnt']?></td>
          <td class="muted"><?=rtrim(rtrim(number_format($r['raw_total'],1),'0'),'.')?> غ</td>
          <td><?= $r['cooked_weight_grams']!==null ? rtrim(rtrim(number_format($r['cooked_weight_grams'],1),'0'),'.').' غ' : '<span class="badge" style="background:#f59e0b">غير محدَّد</span>' ?></td>
          <td style="white-space:nowrap"><a class="link" href="recipes.php?open=<?=(int)$r['recipe_id']?>#recs">تحرير</a> · <a class="link" href="recipes.php?recipe=<?=(int)$r['recipe_id']?>&grams=<?= (int)($r['cooked_weight_grams'] ?: $r['raw_total']) ?>#calc">احسب</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$recipes): ?><tr><td colspan="5" class="muted">لا وصفات بعد.</td></tr><?php endif; ?>
    </table>
    <h3>➕ أضِف وصفة</h3>
    <form class="frm" method="post"><?=csrf_field()?>
      <input type="hidden" name="action" value="add_recipe">
      <div><label>الاسم *</label><input name="name" required></div>
      <div><label>الوزن المطبوخ (غ)</label><input type="number" step="1" min="0" name="cooked" placeholder="الحصيلة (للدقّة)"></div>
      <div><label>حصص</label><input type="number" min="1" name="servings" value="1"></div>
      <div style="grid-column:1/-1"><label>وصف</label><input name="description"></div>
      <div><button type="submit">إضافة</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin:6px 0 0">💡 حدّد «الوزن المطبوخ» لكل وصفة — هو ما يجعل حساب الحصة دقيقًا (المكوّنات تُقاس خامًا والطبخ يغيّر الوزن).</p>
  <?php endif; ?>
</section>

<!-- ===== المكوّنات ===== -->
<section data-tab="ings">
  <h2>🥕 المكوّنات (لكل 100غ نيّئ)</h2>
  <table>
    <tr><th>المكوّن</th><th>سعرات</th><th>بروتين</th><th>كارب</th><th>دهون</th><th></th></tr>
    <?php foreach ($ingredients as $i): ?>
      <tr><td><strong><?=h($i['name'])?></strong> <?= $i['is_arabic'] ? '<span class="badge" style="background:#0891b2">عربي</span>' : '' ?></td>
        <td><?=rtrim(rtrim(number_format($i['calories_per_100g'],1),'0'),'.')?></td>
        <td><?=rtrim(rtrim(number_format($i['protein_per_100g'],1),'0'),'.')?></td>
        <td><?=rtrim(rtrim(number_format($i['carbs_per_100g'],1),'0'),'.')?></td>
        <td><?=rtrim(rtrim(number_format($i['fats_per_100g'],1),'0'),'.')?></td>
        <td class="muted" style="font-size:11px"><?=h($i['unit'])?></td></tr>
    <?php endforeach; ?>
  </table>
  <h3>➕ أضِف مكوّنًا</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_ingredient">
    <div><label>الاسم *</label><input name="name" required></div>
    <div><label>سعرات/100غ</label><input type="number" step="0.1" min="0" name="cal" value="0"></div>
    <div><label>بروتين/100غ</label><input type="number" step="0.1" min="0" name="p" value="0"></div>
    <div><label>كارب/100غ</label><input type="number" step="0.1" min="0" name="c" value="0"></div>
    <div><label>دهون/100غ</label><input type="number" step="0.1" min="0" name="f" value="0"></div>
    <div style="display:flex;align-items:center;gap:6px;padding-top:20px"><label style="display:flex;gap:6px;align-items:center;cursor:pointer"><input type="checkbox" name="is_arabic" value="1" checked>مطبخ عربي</label></div>
    <div><button type="submit">إضافة</button></div>
  </form>
</section>
<?php page_foot();

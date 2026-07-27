<?php
// =============================================================================
// Xcamp Gym — شؤون الكباتن (HR): ورديات أسبوعية + رواتب/عمولات + سعة الأعضاء.
// للإدارة فقط. العمولة = نسبة الكابتن × مدفوعات أعضائه المحصّلة في الشهر.
// تسجيل الراتب يُنشئ سجلًّا في coach_payroll ومصروفًا (salaries) في expenses.
// =============================================================================
require __DIR__ . '/db.php';
$me  = require_role(['admin','manager','reception']);
$uid = (int)($me['uid'] ?? 0) ?: null;

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

// الفترة (شهر) قيد العرض/الصرف
$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$WD = [0=>'السبت',1=>'الأحد',2=>'الاثنين',3=>'الثلاثاء',4=>'الأربعاء',5=>'الخميس',6=>'الجمعة'];

/** يحسب أساس العمولة (مدفوعات أعضاء الكابتن المحصّلة) لكل كابتن في الشهر */
function hr_commission_base(PDO $pdo, string $ym): array {
    $out = [];
    $q = $pdo->prepare("SELECT m.coach_id, COALESCE(SUM(p.amount),0) s
                        FROM payments p JOIN members m ON m.member_id = p.member_id
                        WHERE p.status='paid' AND DATE_FORMAT(p.payment_date,'%Y-%m') = ?
                          AND m.coach_id IS NOT NULL
                        GROUP BY m.coach_id");
    $q->execute([$ym]);
    foreach ($q as $r) $out[(int)$r['coach_id']] = (float)$r['s'];
    return $out;
}

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'save_hr') {
            $cid = (int)$_POST['coach_id'];
            $pdo->prepare("INSERT INTO coach_hr (coach_id, base_salary, commission_rate, capacity_members)
                           VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary),
                             commission_rate=VALUES(commission_rate), capacity_members=VALUES(capacity_members)")
                ->execute([$cid, max(0,(float)$_POST['base_salary']),
                           max(0,min(100,(float)$_POST['commission_rate'])), max(0,(int)$_POST['capacity_members'])]);
        } elseif ($act === 'add_shift') {
            $cid = (int)$_POST['coach_id'];
            $wd  = (int)$_POST['weekday'];
            $st  = $_POST['start_time'] ?? ''; $en = $_POST['end_time'] ?? '';
            if ($wd < 0 || $wd > 6) throw new RuntimeException('يوم غير صحيح.');
            if (!$st || !$en) throw new RuntimeException('أدخل وقت البداية والنهاية.');
            if ($en <= $st) throw new RuntimeException('وقت النهاية يجب أن يكون بعد البداية.');
            $pdo->prepare("INSERT INTO coach_shifts (coach_id, weekday, start_time, end_time) VALUES (?,?,?,?)")
                ->execute([$cid, $wd, $st, $en]);
        } elseif ($act === 'del_shift') {
            $pdo->prepare("DELETE FROM coach_shifts WHERE shift_id = ?")->execute([(int)$_POST['shift_id']]);
        } elseif ($act === 'pay_salary') {
            $cid    = (int)$_POST['coach_id'];
            $period = preg_match('/^\d{4}-\d{2}$/', $_POST['period_ym'] ?? '') ? $_POST['period_ym'] : $ym;
            // إعدادات الكابتن
            $h = $pdo->prepare("SELECT base_salary, commission_rate FROM coach_hr WHERE coach_id = ?");
            $h->execute([$cid]); $h = $h->fetch();
            if (!$h) throw new RuntimeException('لا توجد إعدادات راتب لهذا الكابتن.');
            $cn = $pdo->prepare("SELECT full_name FROM coaches WHERE coach_id = ?");
            $cn->execute([$cid]); $coachName = (string)$cn->fetchColumn();
            $base = (float)$h['base_salary'];
            $cbase = hr_commission_base($pdo, $period)[$cid] ?? 0.0;
            $comm = round($cbase * (float)$h['commission_rate'] / 100, 2);
            $total = $base + $comm;
            $pdo->beginTransaction();
            // سجلّ الراتب (فريد لكل كابتن/شهر — يمنع الصرف المزدوج)
            $ins = $pdo->prepare("INSERT INTO coach_payroll (coach_id, period_ym, base, commission, total, status, paid_at)
                                  VALUES (?,?,?,?,?, 'paid', NOW())");
            $ins->execute([$cid, $period, $base, $comm, $total]);
            // مصروف مقابل (رواتب) في المحاسبة
            $pdo->prepare("INSERT INTO expenses (category, amount, description, spent_on, recorded_by)
                           VALUES ('salaries', ?, ?, ?, ?)")
                ->execute([$total, "راتب $coachName — $period", date('Y-m-d'), $uid]);
            $pdo->commit();
        }
        header('Location: hr.php?ym=' . urlencode($ym) . '&ok=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        if (strpos($msg, 'uq_coach_period') !== false || strpos($msg, 'Duplicate') !== false)
            $msg = 'راتب هذا الكابتن لهذا الشهر مسجَّل بالفعل.';
        $error = 'تعذّر الحفظ: ' . $msg;
    }
}

page_head('شؤون الكباتن', 'hr');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح ✓</div>';

// ---- البيانات ----
$coaches = $pdo->query("SELECT c.coach_id, c.full_name, c.active,
      COALESCE(h.base_salary,0) base_salary, COALESCE(h.commission_rate,0) commission_rate,
      COALESCE(h.capacity_members,0) capacity_members
    FROM coaches c LEFT JOIN coach_hr h ON h.coach_id = c.coach_id
    WHERE c.active = 1 ORDER BY c.full_name")->fetchAll();

// عدد الأعضاء المُسنَدين (حِمل نشط) لكل كابتن
$loadMap = [];
foreach ($pdo->query("SELECT coach_id, COUNT(*) c FROM members WHERE coach_id IS NOT NULL AND status <> 'expired' GROUP BY coach_id") as $r)
    $loadMap[(int)$r['coach_id']] = (int)$r['c'];

// الورديات
$shiftMap = [];
foreach ($pdo->query("SELECT * FROM coach_shifts ORDER BY coach_id, weekday, start_time") as $s)
    $shiftMap[(int)$s['coach_id']][(int)$s['weekday']][] = $s;

// أساس العمولة للشهر المعروض + سجلّات الرواتب المدفوعة له
$cbaseMap = hr_commission_base($pdo, $ym);
$paidMap = [];
$pp = $pdo->prepare("SELECT * FROM coach_payroll WHERE period_ym = ?");
$pp->execute([$ym]);
foreach ($pp as $r) $paidMap[(int)$r['coach_id']] = $r;

// خيارات الأشهر (آخر ٦)
$monthOpts = [];
for ($i = 0; $i < 6; $i++) $monthOpts[] = date('Y-m', strtotime("first day of -$i month"));

// ملخّص الرواتب المصروفة للشهر
$paidTotal = 0.0; foreach ($paidMap as $r) $paidTotal += (float)$r['total'];
?>
<div style="margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <h2 style="margin:0">🧑‍🏫 شؤون الكباتن</h2>
  <form method="get" data-no-ajax style="margin:0;display:flex;align-items:center;gap:6px">
    <label class="muted" style="font-size:13px">الشهر</label>
    <select name="ym" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px">
      <?php foreach ($monthOpts as $mo): ?><option value="<?=$mo?>" <?=$mo===$ym?'selected':''?>><?=$mo?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<nav class="tabbar" aria-label="أقسام شؤون الكباتن">
  <button type="button" data-tabtarget="shifts">📅 الورديات</button>
  <button type="button" data-tabtarget="pay">💰 الرواتب والعمولات</button>
  <button type="button" data-tabtarget="cap">📊 السعة والحِمل</button>
</nav>

<!-- ===== الورديات ===== -->
<section data-tab="shifts">
  <h2>📅 جدول الورديات الأسبوعي</h2>
  <div style="overflow-x:auto">
    <table style="min-width:720px">
      <tr><th style="width:120px">الكابتن</th><?php foreach ($WD as $wd => $nm): ?><th style="text-align:center"><?=$nm?></th><?php endforeach; ?></tr>
      <?php foreach ($coaches as $c): $cid = (int)$c['coach_id']; ?>
        <tr>
          <td><strong><?=h($c['full_name'])?></strong></td>
          <?php foreach ($WD as $wd => $nm): $cells = $shiftMap[$cid][$wd] ?? []; ?>
            <td style="vertical-align:top;text-align:center">
              <?php foreach ($cells as $s): ?>
                <div style="display:inline-flex;align-items:center;gap:4px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:6px;padding:2px 6px;margin:2px 0;font-size:11px;white-space:nowrap">
                  <?=h(substr($s['start_time'],0,5))?>–<?=h(substr($s['end_time'],0,5))?>
                  <form method="post" style="margin:0;display:inline"><?=csrf_field()?><input type="hidden" name="action" value="del_shift"><input type="hidden" name="shift_id" value="<?=(int)$s['shift_id']?>"><button type="submit" title="حذف" style="background:none;border:0;color:#dc2626;cursor:pointer;padding:0;font-size:12px;line-height:1">×</button></form>
                </div>
              <?php endforeach; ?>
              <?php if (!$cells): ?><span class="muted" style="font-size:11px">—</span><?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <h3>➕ إضافة وردية</h3>
  <form class="frm" method="post"><?=csrf_field()?>
    <input type="hidden" name="action" value="add_shift">
    <div><label>الكابتن</label><select name="coach_id"><?php foreach ($coaches as $c): ?><option value="<?=(int)$c['coach_id']?>"><?=h($c['full_name'])?></option><?php endforeach; ?></select></div>
    <div><label>اليوم</label><select name="weekday"><?php foreach ($WD as $wd => $nm): ?><option value="<?=$wd?>"><?=$nm?></option><?php endforeach; ?></select></div>
    <div><label>من</label><input type="time" name="start_time" value="16:00" required></div>
    <div><label>إلى</label><input type="time" name="end_time" value="22:00" required></div>
    <div><button type="submit">إضافة الوردية</button></div>
  </form>
</section>

<!-- ===== الرواتب ===== -->
<section data-tab="pay">
  <h2>💰 الرواتب والعمولات — <?=h($ym)?></h2>
  <div class="kpis" style="margin-bottom:10px">
    <div class="card" style="--c:#7c3aed"><div class="n" style="font-size:22px"><?=money($paidTotal)?></div><div class="l">رواتب مصروفة هذا الشهر</div></div>
    <div class="card" style="--c:#0891b2"><div class="n"><?=count($paidMap)?>/<?=count($coaches)?></div><div class="l">كباتن تم صرفهم</div></div>
  </div>
  <table>
    <tr><th>الكابتن</th><th>الأساسي</th><th>مبيعات أعضائه</th><th>العمولة</th><th>الإجمالي</th><th>الحالة / الإجراء</th></tr>
    <?php foreach ($coaches as $c): $cid = (int)$c['coach_id'];
      $base = (float)$c['base_salary']; $rate = (float)$c['commission_rate'];
      $cbase = $cbaseMap[$cid] ?? 0.0; $comm = round($cbase * $rate / 100, 2); $total = $base + $comm;
      $paid = $paidMap[$cid] ?? null; ?>
      <tr>
        <td><strong><?=h($c['full_name'])?></strong><div class="muted" style="font-size:11px">عمولة <?=rtrim(rtrim(number_format($rate,2),'0'),'.')?>%</div></td>
        <td><?=money($base)?></td>
        <td class="muted"><?=money($cbase)?></td>
        <td style="color:#16a34a;font-weight:700"><?=money($paid ? (float)$paid['commission'] : $comm)?></td>
        <td style="font-weight:800"><?=money($paid ? (float)$paid['total'] : $total)?></td>
        <td>
          <?php if ($paid): ?>
            <span class="badge" style="background:#16a34a">مدفوع</span>
            <span class="muted" style="font-size:11px">· <?=h(substr((string)$paid['paid_at'],0,10))?></span>
          <?php else: ?>
            <form method="post" style="margin:0" onsubmit="return confirm('صرف راتب <?=h($c['full_name'])?> لشهر <?=h($ym)?> بمبلغ <?=money($total)?>؟')"><?=csrf_field()?>
              <input type="hidden" name="action" value="pay_salary">
              <input type="hidden" name="coach_id" value="<?=$cid?>">
              <input type="hidden" name="period_ym" value="<?=h($ym)?>">
              <button type="submit" style="background:#7c3aed;color:#fff;border:0;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer">💸 صرف الراتب</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:8px 0 0">العمولة = نسبة الكابتن × إجمالي مدفوعات أعضائه المحصّلة («paid») خلال الشهر المحدَّد. الصرف يُنشئ مصروفًا في المحاسبة تلقائيًا.</p>

  <h3>⚙️ إعدادات الرواتب</h3>
  <table>
    <tr><th>الكابتن</th><th>الراتب الأساسي</th><th>نسبة العمولة %</th><th>سعة الأعضاء</th><th></th></tr>
    <?php foreach ($coaches as $c): ?>
      <tr>
        <form method="post" style="margin:0;display:contents"><?=csrf_field()?>
        <input type="hidden" name="action" value="save_hr"><input type="hidden" name="coach_id" value="<?=(int)$c['coach_id']?>">
        <td><strong><?=h($c['full_name'])?></strong></td>
        <td><input type="number" step="0.01" min="0" name="base_salary" value="<?=h($c['base_salary'])?>" style="width:110px;padding:6px;border:1px solid #cbd5e1;border-radius:8px"></td>
        <td><input type="number" step="0.01" min="0" max="100" name="commission_rate" value="<?=h($c['commission_rate'])?>" style="width:90px;padding:6px;border:1px solid #cbd5e1;border-radius:8px"></td>
        <td><input type="number" min="0" name="capacity_members" value="<?=(int)$c['capacity_members']?>" style="width:90px;padding:6px;border:1px solid #cbd5e1;border-radius:8px"></td>
        <td><button type="submit" style="padding:6px 12px;border-radius:8px">حفظ</button></td>
        </form>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:8px 0 0">سعة الأعضاء = الحدّ الأقصى للأعضاء المُسنَدين لكل كابتن (0 = بلا حدّ).</p>
</section>

<!-- ===== السعة ===== -->
<section data-tab="cap">
  <h2>📊 السعة والحِمل الحالي</h2>
  <table>
    <tr><th style="width:130px">الكابتن</th><th style="width:110px">الحِمل / السعة</th><th>نسبة الإشغال</th></tr>
    <?php foreach ($coaches as $c): $cid = (int)$c['coach_id'];
      $load = $loadMap[$cid] ?? 0; $cap = (int)$c['capacity_members'];
      $pct = $cap > 0 ? round($load / $cap * 100) : 0;
      $barPct = min(100, $pct);
      $col = $cap === 0 ? '#94a3b8' : ($pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#f59e0b' : '#16a34a')); ?>
      <tr>
        <td><strong><?=h($c['full_name'])?></strong></td>
        <td><strong><?=$load?></strong> / <?= $cap > 0 ? $cap : '∞' ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;height:12px;background:#eef2f7;border-radius:6px;overflow:hidden"><div style="height:100%;width:<?=$barPct?>%;background:<?=$col?>;border-radius:6px"></div></div>
            <span style="font-weight:700;color:<?=$col?>;min-width:52px;text-align:left"><?= $cap > 0 ? $pct.'%' : '—' ?></span>
          </div>
          <?php if ($cap > 0 && $pct >= 100): ?><div style="font-size:11px;color:#dc2626;margin-top:3px">⚠️ تجاوز السعة — وزّع أعضاءً على كابتن آخر.</div>
          <?php elseif ($cap > 0 && $pct >= 80): ?><div style="font-size:11px;color:#92400e;margin-top:3px">اقترب من السعة.</div><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:8px 0 0">الحِمل = عدد الأعضاء المُسنَدين غير المنتهين. عدّل السعة من تبويب «الرواتب ← الإعدادات».</p>
</section>
<?php page_foot();

<?php
// =============================================================================
// Xcamp Gym — التحليلات التنفيذية (BI): اتجاهات زمنية لكل البيانات → قرارات.
// النمو · الإيرادات · النشاط · أداء الكباتن. للإدارة فقط. يستخدم رسوم line_chart.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_role(['admin','manager','reception']);

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

page_head('التحليلات', 'analytics');
if ($error) { db_error_box($error); page_foot(); exit; }

// آخر 6 أشهر (تسميات YYYY-MM)
$months = [];
for ($i = 5; $i >= 0; $i--) $months[] = date('Y-m', strtotime("first day of -$i month"));
$mLabel = fn($ym) => $ym;                       // نُبقي YYYY-MM (line_chart يقصّ لـ MM)
$seriesFrom = function (array $map) use ($months) {
    $pts = [];
    foreach ($months as $ym) $pts[] = [$ym . '-01', (float)($map[$ym] ?? 0)];
    return $pts;
};
$fetchMonthly = function (string $sql) use ($pdo) {
    $out = [];
    foreach ($pdo->query($sql) as $r) $out[$r['ym']] = $r['v'];
    return $out;
};

// ---- النمو ----
$newByMonth = $fetchMonthly("SELECT DATE_FORMAT(join_date,'%Y-%m') ym, COUNT(*) v FROM members GROUP BY ym");
$statusDist = $pdo->query("SELECT status, COUNT(*) c FROM members GROUP BY status ORDER BY c DESC")->fetchAll();
$totalMembers = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status IN ('active','upgraded','reactivated')")->fetchColumn();

// ---- الإيرادات ----
$payByMonth = $fetchMonthly("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) v FROM payments WHERE status='paid' GROUP BY ym");
$posByMonth = $fetchMonthly("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, SUM(total) v FROM pos_sales GROUP BY ym");
$incByMonth = [];
foreach ($months as $ym) $incByMonth[$ym] = (float)($payByMonth[$ym] ?? 0) + (float)($posByMonth[$ym] ?? 0);
$thisYm = date('Y-m');
$incThis = $incByMonth[$thisYm] ?? 0;
// MRR (عقود سارية)
$mrrRows = $pdo->query("SELECT p.price, p.duration_days, DATEDIFF(ms.end_date, CURDATE()) dl, ms.renewal_status
                        FROM memberships ms JOIN plans p ON p.plan_id = ms.plan_id")->fetchAll();
$mrr = 0.0;
foreach ($mrrRows as $r) if ((int)$r['dl'] >= 0 && $r['renewal_status'] !== 'cancelled') $mrr += monthlyize((float)$r['price'], (int)$r['duration_days']);
$arpm = $totalMembers ? $incThis / $totalMembers : 0;

// ---- النشاط ----
$attByMonth = $fetchMonthly("SELECT DATE_FORMAT(attendance_date,'%Y-%m') ym, COUNT(*) v FROM daily_attendance WHERE attended=1 GROUP BY ym");
$sessDone = $fetchMonthly("SELECT DATE_FORMAT(session_date,'%Y-%m') ym, SUM(completion_status='completed') v FROM workout_sessions GROUP BY ym");
$att30 = (int)$pdo->query("SELECT COUNT(*) FROM daily_attendance WHERE attended=1 AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
// الحضور حسب يوم الأسبوع (آخر 90 يومًا) — ساعات/أيام الذروة
$dow = $pdo->query("SELECT DAYOFWEEK(attendance_date) d, COUNT(*) c FROM daily_attendance
                    WHERE attended=1 AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY d")->fetchAll();
$dowMap = []; foreach ($dow as $r) $dowMap[(int)$r['d']] = (int)$r['c'];
$dowNames = [1=>'الأحد',2=>'الاثنين',3=>'الثلاثاء',4=>'الأربعاء',5=>'الخميس',6=>'الجمعة',7=>'السبت'];

// ---- أداء الكباتن ----
$coachPerf = $pdo->query("SELECT c.coach_id, c.full_name,
      (SELECT COUNT(*) FROM members m WHERE m.coach_id=c.coach_id) members,
      (SELECT COUNT(*) FROM members m WHERE m.coach_id=c.coach_id AND m.status IN ('at_risk','corrective')) at_risk,
      (SELECT COUNT(*) FROM daily_attendance da JOIN members m ON m.member_id=da.member_id
         WHERE m.coach_id=c.coach_id AND da.attended=1 AND da.attendance_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)) att30,
      (SELECT ROUND(100*SUM(ws.completion_status='completed')/NULLIF(COUNT(*),0))
          FROM workout_sessions ws JOIN workout_plans wp ON wp.workout_plan_id=ws.workout_plan_id
          JOIN members m2 ON m2.member_id=wp.member_id
          WHERE m2.coach_id=c.coach_id AND ws.session_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)) compliance
    FROM coaches c WHERE c.active=1 ORDER BY members DESC")->fetchAll();

$stColor = ['new'=>'#0891b2','onboarding'=>'#0891b2','active'=>'#16a34a','upgraded'=>'#16a34a','reactivated'=>'#16a34a',
            'at_risk'=>'#f59e0b','corrective'=>'#f59e0b','paused'=>'#6b7280','expired'=>'#dc2626'];
?>
<div style="margin-bottom:6px"><h2 style="margin:0">📊 التحليلات التنفيذية</h2></div>
<div class="kpis">
  <div class="card" style="--c:#2563eb"><div class="n"><?=$totalMembers?></div><div class="l">إجمالي الأعضاء</div></div>
  <div class="card" style="--c:#16a34a"><div class="n"><?=$activeMembers?></div><div class="l">نشط</div></div>
  <div class="card" style="--c:#0891b2"><div class="n" style="font-size:22px"><?=money($incThis)?></div><div class="l">إيراد هذا الشهر</div></div>
  <div class="card" style="--c:#7c3aed"><div class="n" style="font-size:22px"><?=money($mrr)?></div><div class="l">MRR</div></div>
  <div class="card" style="--c:#f59e0b"><div class="n"><?=$att30?></div><div class="l">حضور (٣٠ يومًا)</div></div>
</div>

<nav class="tabbar" aria-label="أقسام التحليلات">
  <button type="button" data-tabtarget="growth">📈 النمو</button>
  <button type="button" data-tabtarget="rev">💰 الإيرادات</button>
  <button type="button" data-tabtarget="act">🏋️ النشاط</button>
  <button type="button" data-tabtarget="coach">👨‍🏫 الكباتن</button>
</nav>

<!-- ===== النمو ===== -->
<section data-tab="growth">
  <h2>📈 نمو الأعضاء</h2>
  <h3>أعضاء جدد شهريًا (آخر ٦ أشهر)</h3>
  <?= line_chart([['label'=>'أعضاء جدد','color'=>'#2563eb','points'=>$seriesFrom($newByMonth)]]) ?>
  <h3>توزيع الحالات الحالي</h3>
  <table><tr><th>الحالة</th><th>العدد</th><th>النسبة</th></tr>
    <?php foreach ($statusDist as $s): $pct = $totalMembers ? round((int)$s['c']/$totalMembers*100) : 0; ?>
      <tr><td><span class="badge" style="background:<?=$stColor[$s['status']] ?? '#6b7280'?>"><?=h($s['status'])?></span></td>
        <td><strong><?=h($s['c'])?></strong></td>
        <td style="min-width:160px"><div style="height:8px;background:#eef2f7;border-radius:6px"><div style="height:100%;width:<?=$pct?>%;background:<?=$stColor[$s['status']] ?? '#6b7280'?>;border-radius:6px"></div></div></td></tr>
    <?php endforeach; ?>
  </table>
</section>

<!-- ===== الإيرادات ===== -->
<section data-tab="rev">
  <h2>💰 اتجاه الإيرادات</h2>
  <h3>الإيراد الشهري: اشتراكات + مبيعات (آخر ٦ أشهر)</h3>
  <?= line_chart([
      ['label'=>'اشتراكات','color'=>'#16a34a','points'=>$seriesFrom($payByMonth)],
      ['label'=>'مبيعات POS','color'=>'#2563eb','points'=>$seriesFrom($posByMonth)],
      ['label'=>'الإجمالي','color'=>'#7c3aed','points'=>$seriesFrom($incByMonth)],
  ]) ?>
  <div class="kpis" style="margin-top:14px">
    <div class="card" style="--c:#0891b2"><div class="n" style="font-size:22px"><?=money($arpm)?></div><div class="l">متوسط الإيراد لكل عضو (هذا الشهر)</div></div>
    <div class="card" style="--c:#16a34a"><div class="n" style="font-size:22px"><?=money(array_sum($incByMonth))?></div><div class="l">إجمالي ٦ أشهر</div></div>
  </div>
</section>

<!-- ===== النشاط ===== -->
<section data-tab="act">
  <h2>🏋️ اتجاه النشاط</h2>
  <h3>الحضور والجلسات المكتملة شهريًا</h3>
  <?= line_chart([
      ['label'=>'الحضور','color'=>'#2563eb','points'=>$seriesFrom($attByMonth)],
      ['label'=>'جلسات مكتملة','color'=>'#16a34a','points'=>$seriesFrom($sessDone)],
  ]) ?>
  <h3>الحضور حسب اليوم (آخر ٩٠ يومًا) — أيام الذروة</h3>
  <?php $maxDow = max(1, max($dowMap ?: [1])); ?>
  <table>
    <?php for ($d = 1; $d <= 7; $d++): $c = $dowMap[$d] ?? 0; $pct = round($c/$maxDow*100); ?>
      <tr><td style="width:90px"><?=$dowNames[$d]?></td><td style="width:40px"><strong><?=$c?></strong></td>
        <td><div style="height:10px;background:#eef2f7;border-radius:6px"><div style="height:100%;width:<?=$pct?>%;background:#2563eb;border-radius:6px"></div></div></td></tr>
    <?php endfor; ?>
  </table>
</section>

<!-- ===== الكباتن ===== -->
<section data-tab="coach">
  <h2>👨‍🏫 أداء الكباتن</h2>
  <table>
    <tr><th>الكابتن</th><th>الأعضاء</th><th>معرّض للخطر</th><th>حضور (٣٠ي)</th><th>الالتزام</th></tr>
    <?php foreach ($coachPerf as $c):
      $comp = $c['compliance'] !== null ? (int)$c['compliance'] : null;
      $cc = $comp === null ? '#94a3b8' : ($comp >= 75 ? '#16a34a' : ($comp >= 50 ? '#f59e0b' : '#dc2626')); ?>
      <tr>
        <td><strong><?=h($c['full_name'])?></strong></td>
        <td><?=h($c['members'])?></td>
        <td style="color:<?= (int)$c['at_risk']>0?'#dc2626':'#334155' ?>;font-weight:<?= (int)$c['at_risk']>0?'700':'400' ?>"><?=h($c['at_risk'])?></td>
        <td><?=h($c['att30'])?></td>
        <td><span style="font-weight:700;color:<?=$cc?>"><?= $comp===null ? '—' : $comp.'%' ?></span></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:10px 0 0">الالتزام = متوسط (جلسات مكتملة ÷ مستحقّة) لأعضاء الكابتن خلال ٣٠ يومًا.</p>
</section>
<?php page_foot();

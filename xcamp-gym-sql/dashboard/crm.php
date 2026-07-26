<?php
// =============================================================================
// Xcamp Gym — CRM: لوحة موحّدة لدورة حياة العضو (قمع + خط أنابيب + متابعات + تجديدات)
// المدير يرى الجميع؛ الكابتن يرى أعضاءه فقط. يستفيد من نظام التبويبات (المرحلة 4).
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$isCoach = ($me['role'] === 'coach');
$myCoach = (int)($me['coach_id'] ?? 0);

// إجراء سريع: تسجيل جهة اتصال (متابعة) مباشرة من الـ CRM
$FREASONS  = ['no_show','low_attendance','payment_issue','injury','motivation','progress_review','other'];
$FCHANNELS = ['call','whatsapp','sms','email','in_person'];
$FRESP     = ['no_response','replied','booked','converted','escalated'];

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        if (($_POST['action'] ?? '') === 'crm_log_contact') {
            $mid = (int)$_POST['member_id'];
            // الكابتن لا يسجّل إلا لأعضائه
            $own = $pdo->prepare("SELECT coach_id FROM members WHERE member_id = ?");
            $own->execute([$mid]);
            $mc = $own->fetchColumn();
            if ($mc === false) throw new RuntimeException('العضو غير موجود.');
            if ($isCoach && (int)$mc !== $myCoach) throw new RuntimeException('غير مسموح — العضو ليس ضمن أعضائك.');
            $reason  = in_array($_POST['reason'] ?? '', $FREASONS, true) ? $_POST['reason'] : 'other';
            $channel = in_array($_POST['contact_channel'] ?? '', $FCHANNELS, true) ? $_POST['contact_channel'] : 'whatsapp';
            $resp    = in_array($_POST['response_status'] ?? '', $FRESP, true) ? $_POST['response_status'] : 'no_response';
            $pdo->prepare("INSERT INTO followups (member_id, coach_id, followup_date, reason, contact_channel, response_status, action_taken, next_followup_date)
                           VALUES (?,?,CURDATE(),?,?,?,?,?)")->execute([
                $mid, (int)$mc ?: null, $reason, $channel, $resp,
                trim($_POST['action_taken'] ?? '') ?: null,
                $_POST['next_followup_date'] ?: null,
            ]);
        }
        header('Location: crm.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('CRM', 'crm');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح.</div>';

// نطاق البيانات: الكابتن يرى أعضاءه فقط
$scopeSql = $isCoach ? ' WHERE coach_id = ' . $myCoach : '';
$rows = $pdo->query("SELECT * FROM vw_member_operational_status$scopeSql ORDER BY member_name")->fetchAll();

// تجميع دورة الحياة في مراحل خط الأنابيب
$stages = [
    'leads'  => ['label' => '🌱 جدد / محتملون', 'color' => '#0891b2', 'statuses' => ['new','onboarding'],            'members' => []],
    'active' => ['label' => '✅ نشط',           'color' => '#16a34a', 'statuses' => ['active','upgraded','reactivated'], 'members' => []],
    'risk'   => ['label' => '⚠️ معرّض للخطر',   'color' => '#f59e0b', 'statuses' => ['at_risk','corrective'],          'members' => []],
    'paused' => ['label' => '⏸️ متوقّف',        'color' => '#6b7280', 'statuses' => ['paused'],                        'members' => []],
    'churn'  => ['label' => '❌ منتهي / متسرّب', 'color' => '#dc2626', 'statuses' => ['expired'],                       'members' => []],
];
$statusToStage = [];
foreach ($stages as $k => $st) foreach ($st['statuses'] as $s) $statusToStage[$s] = $k;

$total = count($rows);
$renewSoon = [];      // اشتراكات تنتهي خلال 30 يومًا أو منتهية بانتظار التجديد
$today = new DateTimeImmutable('today');
foreach ($rows as $r) {
    $stage = $statusToStage[$r['member_status']] ?? 'active';
    $stages[$stage]['members'][] = $r;
    if (!empty($r['membership_end_date'])) {
        $end = date_create_immutable($r['membership_end_date']);
        if ($end) {
            $daysLeft = (int)$today->diff($end)->format('%r%a');
            if ($daysLeft <= 30 && ($r['renewal_status'] ?? '') !== 'renewed' && ($r['renewal_status'] ?? '') !== 'cancelled') {
                $r['days_left'] = $daysLeft;
                $renewSoon[] = $r;
            }
        }
    }
}
usort($renewSoon, fn($a, $b) => $a['days_left'] <=> $b['days_left']);

$cnt = fn($k) => count($stages[$k]['members']);
$activeN = $cnt('active'); $leadsN = $cnt('leads'); $riskN = $cnt('risk'); $churnN = $cnt('churn'); $pausedN = $cnt('paused');
$conversion = $total ? round($activeN / $total * 100) : 0;                 // نسبة النشطين من الإجمالي
$churnRate  = $total ? round($churnN / $total * 100) : 0;                  // نسبة المتسرّبين
$retention  = ($activeN + $churnN + $pausedN) ? round($activeN / ($activeN + $churnN + $pausedN) * 100) : 0;

// طابور المتابعات المستحقّة (غير المحسومة)
$fupScope = $isCoach ? ' WHERE coach_id = ' . $myCoach : '';
$fups = $pdo->query("SELECT * FROM vw_due_followups$fupScope ORDER BY followup_date ASC")->fetchAll();

// قمع الاستجابة (تحويل المتابعات) — كل الوقت ضمن النطاق
$funScope = $isCoach ? ' WHERE f.coach_id = ' . $myCoach : '';
$funnel = $pdo->query("SELECT response_status, COUNT(*) c FROM followups f$funScope GROUP BY response_status")->fetchAll();
$funMap = [];
foreach ($funnel as $f) $funMap[$f['response_status']] = (int)$f['c'];
$funTotal = array_sum($funMap);
$convFunnel = $funTotal ? round((($funMap['converted'] ?? 0) + ($funMap['booked'] ?? 0)) / $funTotal * 100) : 0;

$stColor = ['new'=>'#0891b2','onboarding'=>'#0891b2','active'=>'#16a34a','upgraded'=>'#16a34a','reactivated'=>'#16a34a',
            'at_risk'=>'#f59e0b','corrective'=>'#f59e0b','paused'=>'#6b7280','expired'=>'#dc2626'];
$payColor = ['paid'=>'#16a34a','partial'=>'#f59e0b','unpaid'=>'#dc2626','failed'=>'#dc2626','refunded'=>'#6b7280'];
function member_link($r) {
    $cid = (int)($r['coach_id'] ?? 0);
    return 'captains.php?coach=' . $cid . '&member=' . (int)$r['member_id'];
}
?>
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px">
  <h2 style="margin:0">🧲 إدارة علاقات الأعضاء (CRM)</h2>
  <span class="muted" style="font-size:13px"><?= $isCoach ? 'أعضاؤك فقط' : 'كل الأعضاء' ?> · <?=$total?> عضو</span>
</div>

<nav class="tabbar" aria-label="أقسام CRM">
  <button type="button" data-tabtarget="dash">📊 اللوحة</button>
  <button type="button" data-tabtarget="pipe">🔀 خط الأنابيب</button>
  <button type="button" data-tabtarget="fups">☎️ المتابعات<?= $fups ? ' (' . count($fups) . ')' : '' ?></button>
  <button type="button" data-tabtarget="renew">🔄 التجديدات<?= $renewSoon ? ' (' . count($renewSoon) . ')' : '' ?></button>
</nav>

<!-- ===== اللوحة: القمع والمؤشرات ===== -->
<section data-tab="dash">
  <h2>📊 مؤشرات دورة الحياة</h2>
  <div class="kpis">
    <div class="card" style="--c:#0891b2"><div class="n" style="color:#0891b2"><?=$leadsN?></div><div class="l">🌱 جدد / محتملون</div></div>
    <div class="card" style="--c:#16a34a"><div class="n" style="color:#16a34a"><?=$activeN?></div><div class="l">✅ نشط</div></div>
    <div class="card" style="--c:#f59e0b"><div class="n" style="color:#f59e0b"><?=$riskN?></div><div class="l">⚠️ معرّض للخطر</div></div>
    <div class="card" style="--c:#6b7280"><div class="n" style="color:#6b7280"><?=$pausedN?></div><div class="l">⏸️ متوقّف</div></div>
    <div class="card" style="--c:#dc2626"><div class="n" style="color:#dc2626"><?=$churnN?></div><div class="l">❌ منتهي / متسرّب</div></div>
  </div>
  <div class="grid2" style="margin-top:8px">
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px">
      <strong style="font-size:13px;color:#334155">معدّلات الأداء</strong>
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px">
        <div><div style="font-size:26px;font-weight:800;color:#16a34a"><?=$conversion?>%</div><div class="muted" style="font-size:12px">نسبة النشطين</div></div>
        <div><div style="font-size:26px;font-weight:800;color:#2563eb"><?=$retention?>%</div><div class="muted" style="font-size:12px">الاحتفاظ</div></div>
        <div><div style="font-size:26px;font-weight:800;color:#dc2626"><?=$churnRate?>%</div><div class="muted" style="font-size:12px">التسرّب</div></div>
      </div>
      <p class="muted" style="font-size:11px;margin:10px 0 0">الاحتفاظ = النشطون ÷ (النشطون + المتوقّفون + المنتهون).</p>
    </div>
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px">
      <strong style="font-size:13px;color:#334155">قمع تحويل المتابعات</strong>
      <?php if (!$funTotal): ?>
        <p class="muted" style="font-size:12px;margin:8px 0 0">لا توجد متابعات مسجّلة بعد.</p>
      <?php else: ?>
        <table style="margin-top:8px">
          <?php foreach ([['no_response','بلا رد','#94a3b8'],['replied','ردّ','#0891b2'],['booked','حجز','#2563eb'],['converted','تحوّل','#16a34a'],['escalated','تصعيد','#dc2626']] as [$k,$lbl,$c]):
            $v = $funMap[$k] ?? 0; $pct = $funTotal ? round($v / $funTotal * 100) : 0; ?>
            <tr>
              <td style="width:70px"><span class="badge" style="background:<?=$c?>"><?=$lbl?></span></td>
              <td style="width:44px"><strong><?=$v?></strong></td>
              <td><div style="height:8px;background:#eef2f7;border-radius:6px"><div style="height:100%;width:<?=$pct?>%;background:<?=$c?>;border-radius:6px"></div></div></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="muted" style="font-size:11px;margin:8px 0 0">معدّل التحويل (حجز/تحوّل) = <strong style="color:#16a34a"><?=$convFunnel?>%</strong> من إجمالي المتابعات.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ===== خط الأنابيب (Kanban) ===== -->
<section data-tab="pipe">
  <h2>🔀 خط أنابيب دورة الحياة</h2>
  <?php if (!$total): ?><div class="empty">لا يوجد أعضاء في النطاق.</div><?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px">
    <?php foreach ($stages as $k => $st): ?>
      <div style="background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:10px;min-height:80px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <strong style="font-size:13px;color:<?=$st['color']?>"><?=$st['label']?></strong>
          <span class="badge" style="background:<?=$st['color']?>"><?=count($st['members'])?></span>
        </div>
        <?php foreach ($st['members'] as $m): ?>
          <a href="<?=h(member_link($m))?>" style="display:block;text-decoration:none;background:#fff;border-right:3px solid <?=$st['color']?>;
             border-radius:8px;padding:8px 10px;margin-bottom:6px;box-shadow:0 1px 2px rgba(0,0,0,.05)">
            <div style="font-size:13px;font-weight:700;color:#0f172a"><?=h($m['member_name'])?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px">
              <?php if ($m['risk_score'] !== null): ?>خطر <?=h((int)$m['risk_score'])?><?php endif; ?>
              <?php if (!empty($m['payment_status'])): ?> · <span style="color:<?=$payColor[$m['payment_status']] ?? '#64748b'?>"><?=h($m['payment_status'])?></span><?php endif; ?>
              <?php if (!empty($m['membership_end_date'])): ?> · ينتهي <?=h(substr($m['membership_end_date'],5))?><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
        <?php if (!$st['members']): ?><div class="muted" style="font-size:12px">—</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:12px 0 0">اضغط أي عضو لفتح ملفه الكامل. المراحل تُدار تلقائيًا عبر أتمتة الحضور/الدفع/التقييم.</p>
  <?php endif; ?>
</section>

<!-- ===== طابور المتابعات ===== -->
<section data-tab="fups">
  <h2>☎️ المتابعات المستحقّة</h2>
  <?php if (!$fups): ?><div class="empty">لا توجد متابعات مستحقّة حاليًا. 🎉</div><?php else: ?>
  <table>
    <tr><th>التاريخ</th><th>العضو</th><?php if (!$isCoach): ?><th>الكابتن</th><?php endif; ?><th>السبب</th><th>القناة</th><th>الرد</th><th></th></tr>
    <?php foreach ($fups as $f):
      $overdue = $f['followup_date'] < date('Y-m-d'); ?>
      <tr>
        <td style="<?= $overdue ? 'color:#dc2626;font-weight:700' : '' ?>"><?=h($f['followup_date'])?><?= $overdue ? ' ⏰' : '' ?></td>
        <td><a class="link" href="captains.php?coach=<?=(int)$f['coach_id']?>&member=<?=(int)$f['member_id']?>"><?=h($f['member_name'])?></a></td>
        <?php if (!$isCoach): ?><td class="muted"><?=h($f['coach_name'])?></td><?php endif; ?>
        <td><?=h($f['reason'])?></td><td><?=h($f['contact_channel'])?></td>
        <td><span class="badge" style="background:<?= $f['response_status']==='no_response' ? '#94a3b8' : '#2563eb' ?>"><?=h($f['response_status'])?></span></td>
        <td>
          <details><summary class="link" style="cursor:pointer;font-size:12px">＋ تسجيل تواصل</summary>
            <form class="frm" method="post" style="margin-top:6px"><?=csrf_field()?>
              <input type="hidden" name="action" value="crm_log_contact">
              <input type="hidden" name="member_id" value="<?=(int)$f['member_id']?>">
              <div><label>القناة</label><select name="contact_channel"><?php foreach ($FCHANNELS as $c): ?><option <?=$c===$f['contact_channel']?'selected':''?>><?=h($c)?></option><?php endforeach; ?></select></div>
              <div><label>الرد</label><select name="response_status"><?php foreach ($FRESP as $r): ?><option><?=h($r)?></option><?php endforeach; ?></select></div>
              <div><label>السبب</label><select name="reason"><?php foreach ($FREASONS as $r): ?><option <?=$r===$f['reason']?'selected':''?>><?=h($r)?></option><?php endforeach; ?></select></div>
              <div><label>الإجراء</label><input name="action_taken"></div>
              <div><label>متابعة قادمة</label><input type="date" name="next_followup_date"></div>
              <div><button type="submit">حفظ</button></div>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:10px 0 0">⏰ = متأخّرة. تسجيل «تحوّل/حجز» يحلّ الإنذارات المفتوحة تلقائيًا عبر الأتمتة.</p>
  <?php endif; ?>
</section>

<!-- ===== رادار التجديدات ===== -->
<section data-tab="renew">
  <h2>🔄 رادار التجديدات (٣٠ يومًا)</h2>
  <?php if (!$renewSoon): ?><div class="empty">لا توجد اشتراكات تقترب من الانتهاء. 🎉</div><?php else: ?>
  <table>
    <tr><th>العضو</th><?php if (!$isCoach): ?><th>الكابتن</th><?php endif; ?><th>ينتهي</th><th>المتبقّي</th><th>حالة التجديد</th><th>الدفع</th><th></th></tr>
    <?php foreach ($renewSoon as $r):
      $d = (int)$r['days_left'];
      $dc = $d < 0 ? '#dc2626' : ($d <= 7 ? '#f59e0b' : '#334155'); ?>
      <tr>
        <td><strong><?=h($r['member_name'])?></strong></td>
        <?php if (!$isCoach): ?><td class="muted"><?=h($r['coach_name'])?></td><?php endif; ?>
        <td><?=h($r['membership_end_date'])?></td>
        <td style="color:<?=$dc?>;font-weight:700"><?= $d < 0 ? 'منتهٍ منذ ' . abs($d) . ' يوم' : $d . ' يوم' ?></td>
        <td><span class="badge" style="background:<?= ($r['renewal_status']??'')==='pending' ? '#f59e0b' : '#6b7280' ?>"><?=h($r['renewal_status'] ?? '—')?></span></td>
        <td><span style="color:<?=$payColor[$r['payment_status']] ?? '#64748b'?>;font-weight:600"><?=h($r['payment_status'] ?? '—')?></span></td>
        <td><a class="link" href="<?=h(member_link($r))?>">فتح الملف ←</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="font-size:12px;margin:10px 0 0">يشمل المنتهية بانتظار التجديد + ما ينتهي خلال ٣٠ يومًا (عدا المُجدَّدة/الملغاة).</p>
  <?php endif; ?>
</section>
<?php page_foot();

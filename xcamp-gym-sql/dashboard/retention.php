<?php
// =============================================================================
// Xcamp Gym — الاحتفاظ ومكافحة التسرّب: تدخّلات الخطر + الاسترجاع + تحليل التسرّب
// المدير يرى الجميع؛ الكابتن يرى أعضاءه فقط. يستفيد من نظام التبويبات (المرحلة 4).
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$isCoach = ($me['role'] === 'coach');
$myCoach = (int)($me['coach_id'] ?? 0);
$CHANNELS = ['whatsapp','sms','email','call','in_person','other'];
$MSGTYPES = ['welcome','followup','reminder','winback','renewal','progress','warning','other'];

// التحقّق من أن العضو ضمن نطاق المستخدم
function member_scope_ok(PDO $pdo, bool $isCoach, int $myCoach, int $mid): bool {
    $q = $pdo->prepare("SELECT coach_id FROM members WHERE member_id = ?");
    $q->execute([$mid]);
    $mc = $q->fetchColumn();
    if ($mc === false) return false;
    return !$isCoach || (int)$mc === $myCoach;
}

if ($pdo && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $act = $_POST['action'] ?? '';
        if ($act === 'log_retention_message') {
            $mid = (int)$_POST['member_id'];
            if (!member_scope_ok($pdo, $isCoach, $myCoach, $mid)) throw new RuntimeException('غير مسموح — العضو ليس ضمن نطاقك.');
            $mc = (int)$pdo->query("SELECT coach_id FROM members WHERE member_id = $mid")->fetchColumn();
            $ch  = in_array($_POST['channel'] ?? '', $CHANNELS, true) ? $_POST['channel'] : 'whatsapp';
            $mt  = in_array($_POST['message_type'] ?? '', $MSGTYPES, true) ? $_POST['message_type'] : 'followup';
            $txt = trim($_POST['content'] ?? '');
            if ($txt === '') throw new RuntimeException('نص الرسالة فارغ.');
            $pdo->prepare("INSERT INTO messages_log (member_id, coach_id, channel, message_type, content, sent_at, status)
                           VALUES (?,?,?,?,?,NOW(),'sent')")->execute([$mid, $mc ?: null, $ch, $mt, $txt]);
        } elseif ($act === 'resolve_flag') {
            $fid = (int)$_POST['flag_id'];
            $row = $pdo->prepare("SELECT member_id FROM retention_flags WHERE flag_id = ?");
            $row->execute([$fid]);
            $mid = (int)$row->fetchColumn();
            if (!$mid || !member_scope_ok($pdo, $isCoach, $myCoach, $mid)) throw new RuntimeException('غير مسموح.');
            $newStatus = ($_POST['to'] ?? '') === 'dismissed' ? 'dismissed' : 'resolved';
            $pdo->prepare("UPDATE retention_flags SET status = ?, resolved_at = NOW() WHERE flag_id = ?")->execute([$newStatus, $fid]);
        }
        header('Location: retention.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $error = 'تعذّر الحفظ: ' . $e->getMessage();
    }
}

page_head('الاحتفاظ', 'retention');
if ($error && !$pdo) { db_error_box($error); page_foot(); exit; }
if ($error) echo '<div class="err">' . h($error) . '</div>';
if (isset($_GET['ok'])) echo '<div class="flash">تم الحفظ بنجاح.</div>';

$scope = $isCoach ? ' AND m.coach_id = ' . $myCoach : '';

// ---- تدخّلات: الإنذارات المفتوحة مجمّعة حسب العضو ----
$fq = $pdo->query("SELECT rf.flag_id, rf.member_id, rf.flag_type, rf.severity, rf.status, rf.created_at,
                          m.full_name, m.coach_id, m.status AS member_status
                   FROM retention_flags rf JOIN members m ON m.member_id = rf.member_id
                   WHERE rf.status IN ('open','in_progress')$scope
                   ORDER BY FIELD(rf.severity,'critical','high','medium','low'), rf.created_at");
$byMember = [];
foreach ($fq as $r) {
    $mid = (int)$r['member_id'];
    if (!isset($byMember[$mid])) $byMember[$mid] = ['name' => $r['full_name'], 'coach_id' => (int)$r['coach_id'], 'flags' => []];
    $byMember[$mid]['flags'][] = $r;
}
// خريطة درجات الخطر
$riskMap = [];
foreach ($pdo->query("SELECT member_id, risk_score FROM vw_member_operational_status") as $r)
    $riskMap[(int)$r['member_id']] = $r['risk_score'] !== null ? (float)$r['risk_score'] : null;

// ---- الاسترجاع: الأعضاء المنتهون ----
$wq = $pdo->prepare("SELECT m.member_id, m.full_name, m.phone, m.coach_id, m.join_date, c.full_name AS coach_name,
                            (SELECT MAX(sent_at) FROM messages_log ml WHERE ml.member_id = m.member_id AND ml.message_type = 'winback') AS last_winback
                     FROM members m LEFT JOIN coaches c ON c.coach_id = m.coach_id
                     WHERE m.status = 'expired'" . ($isCoach ? ' AND m.coach_id = ?' : '') . " ORDER BY m.join_date");
$isCoach ? $wq->execute([$myCoach]) : $wq->execute();
$winback = $wq->fetchAll();

// ---- تحليل التسرّب: توزيع الإنذارات المفتوحة حسب النوع + محلولة/مفتوحة ----
$cq = $pdo->query("SELECT rf.flag_type, COUNT(*) c FROM retention_flags rf JOIN members m ON m.member_id = rf.member_id
                   WHERE rf.status IN ('open','in_progress')$scope GROUP BY rf.flag_type ORDER BY c DESC");
$churnByType = $cq->fetchAll();
$churnTotal = array_sum(array_map(fn($r) => (int)$r['c'], $churnByType));
$rq = $pdo->query("SELECT SUM(rf.status IN ('resolved','dismissed')) closed, COUNT(*) total
                   FROM retention_flags rf JOIN members m ON m.member_id = rf.member_id WHERE 1=1$scope");
$resStat = $rq->fetch();
$resolveRate = ($resStat && (int)$resStat['total']) ? round((int)$resStat['closed'] / (int)$resStat['total'] * 100) : 0;
$sevColor = ['critical'=>'#dc2626','high'=>'#f59e0b','medium'=>'#eab308','low'=>'#6b7280'];
?>
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px">
  <h2 style="margin:0">🛡️ الاحتفاظ ومكافحة التسرّب</h2>
  <span class="muted" style="font-size:13px"><?= $isCoach ? 'أعضاؤك فقط' : 'كل الأعضاء' ?></span>
</div>

<nav class="tabbar" aria-label="أقسام الاحتفاظ">
  <button type="button" data-tabtarget="interv">🎯 التدخّلات<?= $byMember ? ' (' . count($byMember) . ')' : '' ?></button>
  <button type="button" data-tabtarget="winback">♻️ الاسترجاع<?= $winback ? ' (' . count($winback) . ')' : '' ?></button>
  <button type="button" data-tabtarget="churn">📉 تحليل التسرّب</button>
</nav>

<!-- ===== التدخّلات ===== -->
<section data-tab="interv">
  <h2>🎯 قائمة التدخّل (أعضاء بإنذارات مفتوحة)</h2>
  <?php if (!$byMember): ?><div class="empty">لا توجد إنذارات مفتوحة. الأعضاء في وضع مستقر 🎉</div><?php else: ?>
    <?php foreach ($byMember as $mid => $mb):
      $types = array_map(fn($f) => $f['flag_type'], $mb['flags']);
      $iv = recommended_intervention($types);
      [$bandLabel, $bandColor] = churn_band($riskMap[$mid] ?? null);
      $tpl = retention_message($iv['scenario'], $mb['name']);
    ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
          <a class="link" style="font-size:15px" href="captains.php?coach=<?=$mb['coach_id']?>&member=<?=$mid?>"><?=h($mb['name'])?></a>
          <span class="badge" style="background:<?=$bandColor?>">خطر <?=$bandLabel?><?= isset($riskMap[$mid]) && $riskMap[$mid]!==null ? ' ('.(int)$riskMap[$mid].')' : '' ?></span>
          <?php foreach ($mb['flags'] as $f): $fm = flag_meta($f['flag_type']); ?>
            <span class="badge" style="background:<?=$sevColor[$f['severity']] ?? '#6b7280'?>" title="<?=h($f['severity'])?>"><?=h($fm['label'])?></span>
          <?php endforeach; ?>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px;margin-bottom:8px">
          <strong style="font-size:13px;color:<?=$iv['color']?>">💡 التدخّل الموصى به:</strong>
          <span style="font-size:13px;color:#334155"><?=h($iv['action'])?></span>
        </div>
        <details>
          <summary class="link" style="cursor:pointer;font-size:13px">✉️ إرسال رسالة (قالب جاهز) / حلّ الإنذار</summary>
          <form class="frm" method="post" style="margin-top:8px"><?=csrf_field()?>
            <input type="hidden" name="action" value="log_retention_message">
            <input type="hidden" name="member_id" value="<?=$mid?>">
            <div><label>القناة</label><select name="channel"><?php foreach ($CHANNELS as $c): ?><option><?=h($c)?></option><?php endforeach; ?></select></div>
            <div><label>النوع</label><select name="message_type"><?php foreach ($MSGTYPES as $t): ?><option <?=$t===$tpl['message_type']?'selected':''?>><?=h($t)?></option><?php endforeach; ?></select></div>
            <div style="grid-column:1/-1"><label>نص الرسالة (قالب مقترح — عدّله كما تشاء)</label><textarea name="content" rows="2"><?=h($tpl['text'])?></textarea></div>
            <div><button type="submit">📨 تسجيل الإرسال</button></div>
          </form>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            <?php foreach ($mb['flags'] as $f): $fm = flag_meta($f['flag_type']); ?>
              <form method="post" style="margin:0" onsubmit="return confirm('حلّ إنذار «<?=h($fm['label'])?>»؟')"><?=csrf_field()?>
                <input type="hidden" name="action" value="resolve_flag">
                <input type="hidden" name="flag_id" value="<?=$f['flag_id']?>">
                <button type="submit" style="background:#16a34a;color:#fff;border:0;padding:6px 12px;border-radius:8px;font-size:12px;cursor:pointer">✓ حلّ «<?=h($fm['label'])?>»</button>
              </form>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<!-- ===== الاسترجاع (Win-back) ===== -->
<section data-tab="winback">
  <h2>♻️ استرجاع الأعضاء المنتهين</h2>
  <?php if (!$winback): ?><div class="empty">لا يوجد أعضاء منتهون حاليًا 🎉</div><?php else: ?>
    <?php foreach ($winback as $w):
      $tpl = retention_message('winback', $w['full_name']);
    ?>
      <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
          <a class="link" style="font-size:15px" href="captains.php?coach=<?=(int)$w['coach_id']?>&member=<?=(int)$w['member_id']?>"><?=h($w['full_name'])?></a>
          <span class="muted" style="font-size:12px">📞 <?=h($w['phone'] ?? '—')?><?= !$isCoach ? ' · ' . h($w['coach_name']) : '' ?></span>
          <span class="badge" style="background:<?= $w['last_winback'] ? '#6b7280' : '#dc2626' ?>"><?= $w['last_winback'] ? 'آخر استرجاع: ' . h(substr($w['last_winback'],0,10)) : 'لم يُتواصل بعد' ?></span>
        </div>
        <form class="frm" method="post" style="margin:0"><?=csrf_field()?>
          <input type="hidden" name="action" value="log_retention_message">
          <input type="hidden" name="member_id" value="<?=(int)$w['member_id']?>">
          <input type="hidden" name="message_type" value="winback">
          <div><label>القناة</label><select name="channel"><?php foreach ($CHANNELS as $c): ?><option><?=h($c)?></option><?php endforeach; ?></select></div>
          <div style="grid-column:1/-1"><label>رسالة الاسترجاع (قالب — عدّله)</label><textarea name="content" rows="2"><?=h($tpl['text'])?></textarea></div>
          <div><button type="submit">📨 تسجيل رسالة الاسترجاع</button></div>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<!-- ===== تحليل التسرّب ===== -->
<section data-tab="churn">
  <h2>📉 تحليل أسباب التسرّب</h2>
  <div class="grid2" style="margin-bottom:12px">
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px">
      <strong style="font-size:13px;color:#334155">الإنذارات المفتوحة حسب السبب</strong>
      <?php if (!$churnByType): ?>
        <p class="muted" style="font-size:12px;margin:8px 0 0">لا توجد إنذارات مفتوحة.</p>
      <?php else: ?>
        <table style="margin-top:8px">
          <?php foreach ($churnByType as $ct): $fm = flag_meta($ct['flag_type']); $pct = $churnTotal ? round((int)$ct['c'] / $churnTotal * 100) : 0; ?>
            <tr>
              <td style="width:120px"><span class="badge" style="background:<?=$fm['color']?>"><?=h($fm['label'])?></span></td>
              <td style="width:36px"><strong><?=h($ct['c'])?></strong></td>
              <td><div style="height:8px;background:#eef2f7;border-radius:6px"><div style="height:100%;width:<?=$pct?>%;background:<?=$fm['color']?>;border-radius:6px"></div></div></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    <div style="border:1px solid #eef2f7;border-radius:10px;padding:14px">
      <strong style="font-size:13px;color:#334155">فعالية المعالجة</strong>
      <div style="display:flex;align-items:center;gap:12px;margin-top:10px">
        <div style="font-size:32px;font-weight:800;color:#16a34a"><?=$resolveRate?><span style="font-size:15px;font-weight:600">%</span></div>
        <span class="muted" style="font-size:12px">من الإنذارات تمّت معالجتها<br>(محلولة/مصروفة ÷ الإجمالي)</span>
      </div>
      <div style="height:8px;background:#eef2f7;border-radius:6px;margin-top:10px;overflow:hidden">
        <div style="height:100%;width:<?=$resolveRate?>%;background:#16a34a"></div>
      </div>
      <p class="muted" style="font-size:12px;margin:12px 0 0">إنذارات مفتوحة الآن: <strong style="color:#dc2626"><?=$churnTotal?></strong> · أعضاء منتهون: <strong style="color:#dc2626"><?=count($winback)?></strong></p>
    </div>
  </div>
</section>
<?php page_foot();

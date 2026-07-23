<?php
// =============================================================================
// Xcamp Gym — تقويم الكابتن الأسبوعي: كل جلسات أعضائه في شبكة أيام (سبت→جمعة)
// الكابتن يرى تقويمه فقط؛ المدير يختار الكابتن.
// =============================================================================
require __DIR__ . '/db.php';
$me = require_login();

$error = null;
try { $pdo = db(); } catch (Throwable $e) { $error = $e->getMessage(); }

$isCoach = ($me['role'] === 'coach');
$coachId = $isCoach ? (int)($me['coach_id'] ?? 0) : (int)($_GET['coach'] ?? 0);

page_head('التقويم', 'calendar');
if ($error) { db_error_box($error); page_foot(); exit; }

// المدير بدون كابتن محدد: اختيار
if (!$coachId) {
    $coaches = $pdo->query("SELECT coach_id, full_name FROM coaches WHERE active = 1 ORDER BY coach_id")->fetchAll();
    echo '<section><h2>📅 اختر الكابتن لعرض تقويمه</h2><table><tr><th>#</th><th>الاسم</th><th></th></tr>';
    foreach ($coaches as $c) {
        echo '<tr><td>' . h($c['coach_id']) . '</td><td>' . h($c['full_name']) . '</td>' .
             '<td><a class="link" href="calendar.php?coach=' . (int)$c['coach_id'] . '">عرض التقويم ←</a></td></tr>';
    }
    echo '</table></section>';
    page_foot(); exit;
}

$coach = $pdo->prepare("SELECT full_name FROM coaches WHERE coach_id = ?");
$coach->execute([$coachId]);
$coachName = $coach->fetchColumn();
if ($coachName === false) { echo '<div class="err">الكابتن غير موجود.</div>'; page_foot(); exit; }

// بداية الأسبوع = السبت
$ref = $_GET['week'] ?? date('Y-m-d');
$ts  = strtotime($ref) ?: time();
$dow = (int)date('w', $ts);                 // 0=أحد .. 6=سبت
$satOffset = ($dow + 1) % 7;                // أيام منذ السبت
$start = date('Y-m-d', $ts - $satOffset * 86400);
$end   = date('Y-m-d', strtotime($start) + 6 * 86400);
$prev  = date('Y-m-d', strtotime($start) - 7 * 86400);
$next  = date('Y-m-d', strtotime($start) + 7 * 86400);
$dayNames = ['السبت','الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة'];

$rows = $pdo->prepare("SELECT ws.session_id, ws.session_date, ws.muscle_group, ws.completion_status, ws.notes,
                              m.member_id, m.full_name,
                              (SELECT COUNT(*) FROM session_exercises se WHERE se.session_id = ws.session_id) AS ex_cnt
                       FROM workout_sessions ws
                       JOIN workout_plans wp ON wp.workout_plan_id = ws.workout_plan_id
                       JOIN members m ON m.member_id = wp.member_id
                       WHERE m.coach_id = ? AND ws.session_date BETWEEN ? AND ?
                       ORDER BY ws.session_date, m.full_name");
$rows->execute([$coachId, $start, $end]);
$byDay = [];
foreach ($rows as $r) $byDay[$r['session_date']][] = $r;

$stColor = ['planned' => '#2563eb', 'completed' => '#16a34a', 'partial' => '#f59e0b', 'missed' => '#dc2626'];
$coachQ = $isCoach ? '' : '&coach=' . $coachId;
$total = 0; $done = 0;
foreach ($byDay as $list) foreach ($list as $r) { $total++; if ($r['completion_status'] === 'completed') $done++; }
?>
<section>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <h2 style="margin:0">📅 تقويم <?=h($coachName)?></h2>
    <span class="muted" style="font-size:13px"><?=h($start)?> → <?=h($end)?> · <?=$total?> جلسة (<?=$done?> مكتملة)</span>
    <span style="margin-inline-start:auto;display:flex;gap:8px">
      <a class="link" href="calendar.php?week=<?=$prev?><?=$coachQ?>">→ الأسبوع السابق</a>
      <a class="link" href="calendar.php<?= $coachQ ? '?' . ltrim($coachQ, '&') : '' ?>">هذا الأسبوع</a>
      <a class="link" href="calendar.php?week=<?=$next?><?=$coachQ?>">الأسبوع التالي ←</a>
    </span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px">
    <?php for ($i = 0; $i < 7; $i++):
      $d = date('Y-m-d', strtotime($start) + $i * 86400);
      $isToday = $d === date('Y-m-d');
    ?>
      <div style="background:<?= $isToday ? '#eff6ff' : '#f8fafc' ?>;border:1px solid <?= $isToday ? '#93c5fd' : '#eef2f7' ?>;border-radius:10px;padding:8px;min-height:110px">
        <div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:6px"><?=$dayNames[$i]?><br><span class="muted" style="font-weight:400"><?=h(substr($d, 5))?></span></div>
        <?php foreach ($byDay[$d] ?? [] as $s): ?>
          <a href="session.php?id=<?=$s['session_id']?>" style="display:block;text-decoration:none;background:#fff;border-right:4px solid <?=$stColor[$s['completion_status']] ?? '#6b7280'?>;border-radius:7px;padding:5px 8px;margin-bottom:5px;box-shadow:0 1px 2px rgba(0,0,0,.06)">
            <div style="font-size:12px;font-weight:700;color:#0f172a"><?=h($s['full_name'])?></div>
            <div style="font-size:11px;color:#64748b"><?=h($s['muscle_group'] ?? $s['notes'] ?? 'جلسة')?> · <?=h($s['ex_cnt'])?> تمارين</div>
            <span class="badge" style="background:<?=$stColor[$s['completion_status']] ?? '#6b7280'?>;font-size:10px;padding:1px 8px"><?=h($s['completion_status'])?></span>
          </a>
        <?php endforeach; ?>
        <?php if (empty($byDay[$d])): ?><div class="muted" style="font-size:11px">—</div><?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:12px 0 0">اضغط أي جلسة لفتح <strong>وضع بدء الجلسة</strong> وتسجيل الأداء الفعلي مباشرة.</p>
</section>
<?php page_foot();

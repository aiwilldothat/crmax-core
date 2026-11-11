<?php
/** CRMax Core - Reports: a quick read on contacts, pipeline, lead sources and email. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

$contacts = (int) db()->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$monthStart = mktime(0, 0, 0, (int) date('n'), 1);
$newMonth = (int) db()->query("SELECT COUNT(*) FROM contacts WHERE created_at>=$monthStart")->fetchColumn();
$open = db()->query("SELECT COUNT(*) n, COALESCE(SUM(value_cents),0) v FROM deals WHERE status='open'")->fetch();
$won = db()->query("SELECT COUNT(*) n, COALESCE(SUM(value_cents),0) v FROM deals WHERE status='won'")->fetch();
$wonMonth = db()->query("SELECT COALESCE(SUM(value_cents),0) v FROM deals WHERE status='won' AND closed_at>=$monthStart")->fetch();
$lost = db()->query("SELECT COUNT(*) n FROM deals WHERE status='lost'")->fetch();
$closedN = (int) $won['n'] + (int) $lost['n'];
$winRate = $closedN > 0 ? (int) round((int) $won['n'] / $closedN * 100) : null;
$avgWon = (int) $won['n'] > 0 ? (int) round((int) $won['v'] / (int) $won['n']) : 0;
$avgDays = db()->query("SELECT AVG((closed_at - created_at) / 86400.0) FROM deals WHERE status='won' AND closed_at>0 AND closed_at>=created_at")->fetchColumn();
$reasons = db()->query("SELECT lost_reason r, COUNT(*) n FROM deals WHERE status='lost' AND lost_reason!='' GROUP BY lost_reason COLLATE NOCASE ORDER BY n DESC, r LIMIT 5")->fetchAll();

// Forecast: every open deal weighted by its win probability, grouped by the month it is expected to close.
$probsBy = [];
foreach (db()->query("SELECT * FROM pipelines")->fetchAll() as $p) $probsBy[(int) $p['id']] = stage_probs($p);
$blank = ['n' => 0, 'raw' => 0, 'w' => 0];
$fc = ['overdue' => ['label' => 'Overdue (earlier months)'] + $blank];
for ($i = 0; $i < 6; $i++) { $ts = mktime(0, 0, 0, (int) date('n') + $i, 1); $fc[date('Y-m', $ts)] = ['label' => date('M Y', $ts)] + $blank; }
$fc['later'] = ['label' => 'Later'] + $blank;
$fc['none'] = ['label' => 'No close date'] + $blank;
$thisMonth = date('Y-m'); $fcWeighted = 0; $fcRaw = 0;
foreach (db()->query("SELECT * FROM deals WHERE status='open'")->fetchAll() as $d) {
    $w = deal_weighted($d, $probsBy[(int) $d['pipeline_id']] ?? []);
    $ym = substr((string) $d['close_date'], 0, 7);
    $k = $d['close_date'] === '' ? 'none' : ($ym < $thisMonth ? 'overdue' : (isset($fc[$ym]) ? $ym : 'later'));
    $fc[$k]['n']++; $fc[$k]['raw'] += (int) $d['value_cents']; $fc[$k]['w'] += $w;
    $fcWeighted += $w; $fcRaw += (int) $d['value_cents'];
}
foreach (['overdue', 'later', 'none'] as $k) if ($fc[$k]['n'] === 0) unset($fc[$k]);   // these three only show when they hold something
$fcMax = max(1, max(array_map(fn($b) => $b['w'], $fc)));
$campSent = (int) db()->query("SELECT COALESCE(SUM(sent_count),0) FROM campaigns")->fetchColumn();
$campOpen = (int) db()->query("SELECT COALESCE(SUM(open_count),0) FROM campaigns")->fetchColumn();
$openRate = $campSent > 0 ? round($campOpen / $campSent * 100) : 0;

// contacts added per month, last 6 months
$byMonth = [];
for ($i = 5; $i >= 0; $i--) { $ts = mktime(0, 0, 0, (int) date('n') - $i, 1); $next = mktime(0, 0, 0, (int) date('n') - $i + 1, 1);
    $n = (int) db()->query("SELECT COUNT(*) FROM contacts WHERE created_at>=$ts AND created_at<$next")->fetchColumn();
    $byMonth[date('M', $ts)] = $n; }
$maxMonth = max(1, max($byMonth));

// lead sources
$sources = [];
foreach (db()->query("SELECT COALESCE(NULLIF(source,''),'direct') s, COUNT(*) n FROM contacts GROUP BY s ORDER BY n DESC LIMIT 8") as $r) $sources[$r['s']] = (int) $r['n'];
$maxSrc = max(1, $sources ? max($sources) : 1);

// deals by stage for the first pipeline
$pipe = db()->query("SELECT * FROM pipelines ORDER BY id LIMIT 1")->fetch();
$stageData = [];
if ($pipe) { $stages = jdec($pipe['stages']);
    foreach ($stages as $i => $s) { $r = db()->query("SELECT COUNT(*) n, COALESCE(SUM(value_cents),0) v FROM deals WHERE pipeline_id=" . (int) $pipe['id'] . " AND status='open' AND stage=$i")->fetch(); $stageData[$s] = ['n' => (int) $r['n'], 'v' => (int) $r['v']]; } }
$maxStage = max(1, $stageData ? max(array_map(fn($x) => $x['n'], $stageData)) : 1);

function bar(string $label, int $val, int $max, string $sub = ''): string {
    $pct = $max > 0 ? round($val / $max * 100) : 0;
    return '<div style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:3px"><span>' . h($label) . '</span><span class="muted">' . h($sub !== '' ? $sub : (string) $val) . '</span></div>'
        . '<div style="background:var(--surface2);border-radius:6px;height:10px;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(110deg,var(--brand),var(--brand2))"></div></div></div>';
}

head('Reports', 'reports', $u);
?>
<div class="pagehead"><h1>Reports</h1></div>
<div class="grid4" style="margin-bottom:16px">
  <div class="kpi"><div class="n"><?= number_format($contacts) ?></div><div class="l">Contacts</div></div>
  <div class="kpi"><div class="n"><?= number_format($newMonth) ?></div><div class="l">New this month</div></div>
  <div class="kpi"><div class="n"><?= money((int) $open['v']) ?></div><div class="l">Open pipeline</div></div>
  <div class="kpi"><div class="n"><?= money((int) $wonMonth['v']) ?></div><div class="l">Won this month</div></div>
</div>
<div class="grid2">
  <div class="card"><h2>Forecast &middot; weighted pipeline by expected close</h2>
    <?php if ($fcRaw === 0 && !array_sum(array_map(fn($b) => $b['n'], $fc))): ?><p class="muted small">No open deals yet. Add deals with an expected close date to see a forecast.</p>
    <?php else: foreach ($fc as $b) echo bar($b['label'], $b['w'], $fcMax, $b['n'] . ' deal' . ($b['n'] === 1 ? '' : 's') . ', ' . money($b['raw']) . ', weighted ' . money($b['w'])); ?>
      <p class="small muted" style="margin-top:10px">Weighted total <b style="color:var(--text)"><?= money($fcWeighted) ?></b> of <?= money($fcRaw) ?> open. Each deal counts for its value times its win probability (its own, or its stage's default).</p>
    <?php endif; ?>
  </div>
  <div class="card"><h2>Sales performance</h2>
    <table class="tbl">
      <tr><td class="muted">Win rate</td><td><b><?= $winRate === null ? '-' : $winRate . '%' ?></b><?php if ($closedN): ?> <span class="muted small">of <?= number_format($closedN) ?> closed</span><?php endif; ?></td></tr>
      <tr><td class="muted">Deals won</td><td><b><?= number_format((int) $won['n']) ?></b> <span class="muted small"><?= money((int) $won['v']) ?></span></td></tr>
      <tr><td class="muted">Deals lost</td><td><b><?= number_format((int) $lost['n']) ?></b></td></tr>
      <tr><td class="muted">Average won deal</td><td><b><?= (int) $won['n'] ? money($avgWon) : '-' ?></b></td></tr>
      <tr><td class="muted">Average days to close</td><td><b><?= $avgDays === null || $avgDays === false ? '-' : number_format((float) $avgDays, 1) ?></b></td></tr>
    </table>
    <?php if ($reasons): ?><div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--line)"><div class="small muted" style="margin-bottom:6px">Most common reasons deals were lost</div>
      <?php foreach ($reasons as $r): ?><div style="display:flex;justify-content:space-between;font-size:.86rem;padding:3px 0"><span><?= h($r['r']) ?></span><span class="muted"><?= (int) $r['n'] ?></span></div><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card"><h2>New contacts (6 months)</h2>
    <?php foreach ($byMonth as $m => $n) echo bar($m, $n, $maxMonth); ?>
  </div>
  <div class="card"><h2>Lead sources</h2>
    <?php if (!$sources): ?><p class="muted small">No contacts yet.</p><?php else: foreach ($sources as $s => $n) echo bar($s, $n, $maxSrc); endif; ?>
  </div>
  <div class="card"><h2>Pipeline by stage<?= $pipe ? ' &middot; ' . h($pipe['name']) : '' ?></h2>
    <?php if (!$stageData): ?><p class="muted small">No pipeline yet.</p><?php else: foreach ($stageData as $s => $d) echo bar((string) $s, $d['n'], $maxStage, $d['n'] . ' deal' . ($d['n'] === 1 ? '' : 's') . ', ' . money($d['v'])); endif; ?>
  </div>
  <div class="card"><h2>Email</h2>
    <table class="tbl">
      <tr><td class="muted">Campaigns sent</td><td><b><?= number_format($campSent) ?></b></td></tr>
      <tr><td class="muted">Opens tracked</td><td><b><?= number_format($campOpen) ?></b></td></tr>
      <tr><td class="muted">Open rate</td><td><b><?= $openRate ?>%</b></td></tr>
    </table>
  </div>
</div>
<?php foot();

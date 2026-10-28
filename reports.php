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
$wonMonth = db()->query("SELECT COALESCE(SUM(value_cents),0) v FROM deals WHERE status='won' AND updated_at>=$monthStart")->fetch();
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
  <div class="card"><h2>New contacts (6 months)</h2>
    <?php foreach ($byMonth as $m => $n) echo bar($m, $n, $maxMonth); ?>
  </div>
  <div class="card"><h2>Lead sources</h2>
    <?php if (!$sources): ?><p class="muted small">No contacts yet.</p><?php else: foreach ($sources as $s => $n) echo bar($s, $n, $maxSrc); endif; ?>
  </div>
  <div class="card"><h2>Pipeline by stage<?= $pipe ? ' &middot; ' . h($pipe['name']) : '' ?></h2>
    <?php if (!$stageData): ?><p class="muted small">No pipeline yet.</p><?php else: foreach ($stageData as $s => $d) echo bar($s, $d['n'], $maxStage, $d['n'] . ' &middot; ' . money($d['v'])); endif; ?>
  </div>
  <div class="card"><h2>Email</h2>
    <table class="tbl">
      <tr><td class="muted">Campaigns sent</td><td><b><?= number_format($campSent) ?></b></td></tr>
      <tr><td class="muted">Opens tracked</td><td><b><?= number_format($campOpen) ?></b></td></tr>
      <tr><td class="muted">Open rate</td><td><b><?= $openRate ?>%</b></td></tr>
      <tr><td class="muted">Deals won (all time)</td><td><b><?= number_format((int) $won['n']) ?> &middot; <?= money((int) $won['v']) ?></b></td></tr>
    </table>
  </div>
</div>
<?php foot();

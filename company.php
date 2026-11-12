<?php
/** CRMax Core - a single company: details, its people, its deals and their combined activity. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

$id = (int) ($_GET['id'] ?? 0);
$co = go_get('companies', $id);
if (!$co) redirect('companies.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        $name = company_clean((string) ($_POST['name'] ?? ''));
        if ($name === '') { flash_set('A company needs a name.', 'err'); redirect('company.php?id=' . $id . '&edit=1'); }
        $st = db()->prepare("SELECT id FROM companies WHERE name=? COLLATE NOCASE AND id!=? LIMIT 1"); $st->execute([$name, $id]);
        if ($st->fetchColumn()) { flash_set('Another company already has that name. Use Merge to combine them.', 'err'); redirect('company.php?id=' . $id . '&edit=1'); }
        if ($name !== $co['name']) company_rename($id, $name);
        $owner = (int) ($_POST['owner_id'] ?? 0); if ($owner && !user_by_id($owner)) $owner = 0;
        go_update('companies', $id, company_input($_POST) + ['owner_id' => $owner, 'updated_at' => time()]);
        if ($owner !== (int) $co['owner_id']) {
            audit('owner_changed', 'Owner of ' . audit_record('companies', $co) . ': ' . audit_who((int) $co['owner_id']) . ' -> ' . audit_who($owner), 'companies', $id);
            notify_assigned('company', $owner, $u, go_get('companies', $id) ?? $co);   // tell the new owner
        }
        flash_set('Company updated.');
        redirect('company.php?id=' . $id);
    }
    if ($do === 'merge') {
        $into = (int) ($_POST['into'] ?? 0);
        if (company_merge($id, $into)) { flash_set('Merged. People and deals now sit under this company.'); redirect('company.php?id=' . $into); }
        flash_set('Choose a different company to merge into.', 'err');
        redirect('company.php?id=' . $id);
    }
    if ($do === 'delete') {
        company_delete($id);
        flash_set('Company deleted. Its people and deals were kept.');
        redirect('companies.php');
    }
}

$people = db()->prepare("SELECT * FROM contacts WHERE company_id=? ORDER BY first_name COLLATE NOCASE, last_name COLLATE NOCASE"); $people->execute([$id]); $people = $people->fetchAll();
$deals = db()->prepare("SELECT d.*, p.name pname, p.stages, p.stage_probs FROM deals d LEFT JOIN pipelines p ON p.id=d.pipeline_id WHERE d.company_id=? ORDER BY (d.status!='open'), d.updated_at DESC"); $deals->execute([$id]); $deals = $deals->fetchAll();
$acts = db()->prepare("SELECT a.*, c.first_name, c.last_name, c.email FROM activities a JOIN contacts c ON c.id=a.contact_id WHERE c.company_id=? ORDER BY a.id DESC LIMIT 40"); $acts->execute([$id]); $acts = $acts->fetchAll();
$others = db()->prepare("SELECT id, name FROM companies WHERE id!=? ORDER BY name COLLATE NOCASE LIMIT 1000"); $others->execute([$id]); $others = $others->fetchAll();
$users = users_all();
$owner = (int) $co['owner_id'] ? user_by_id((int) $co['owner_id']) : null;

$openVal = 0; $wonVal = 0; $openN = 0;
foreach ($deals as $d) { if ($d['status'] === 'open') { $openVal += (int) $d['value_cents']; $openN++; } elseif ($d['status'] === 'won') $wonVal += (int) $d['value_cents']; }
$actLabel = ['contact_created' => 'Created', 'form_submitted' => 'Submitted a form', 'workflow_email' => 'Workflow email', 'email_sent' => 'Email sent', 'note_added' => 'Note added', 'deal_created' => 'Deal created', 'deal_won' => 'Deal won', 'deal_lost' => 'Deal lost'];
$href = website_href((string) $co['website']);
$editing = isset($_GET['edit']);

head($co['name'], 'companies', $u);
?>
<div class="pagehead"><a href="<?= h(url('companies.php')) ?>" class="muted">&larr; Companies</a>
  <div class="sp"><a class="btn ghost sm" href="<?= h(url('company.php?id=' . $id . ($editing ? '' : '&edit=1'))) ?>"><?= $editing ? 'Close editor' : 'Edit' ?></a>
    <a class="btn sm" href="<?= h(url('contacts.php?new=1&company=' . rawurlencode($co['name']))) ?>">+ Add person</a></div></div>

<h1 style="font-size:1.5rem;margin-bottom:2px"><?= h($co['name']) ?></h1>
<p class="muted" style="margin-bottom:16px"><?php if ($co['industry'] !== ''): ?><span class="tag"><?= h($co['industry']) ?></span> <?php endif; ?><?php if ($href !== ''): ?><a href="<?= h($href) ?>" target="_blank" rel="noopener noreferrer"><?= h($co['domain'] !== '' ? $co['domain'] : $co['website']) ?></a><?php endif; ?></p>

<div class="grid3" style="margin-bottom:16px">
  <div class="kpi"><div class="n"><?= count($people) ?></div><div class="l">People</div></div>
  <div class="kpi"><div class="n"><?= money($openVal) ?></div><div class="l">Open deals (<?= $openN ?>)</div></div>
  <div class="kpi"><div class="n"><?= money($wonVal) ?></div><div class="l">Won</div></div>
</div>

<?php if ($editing): ?>
<div class="card" style="max-width:720px"><h2>Edit company</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save">
    <label>Company name</label><input name="name" value="<?= h($co['name']) ?>" required maxlength="160">
    <div class="row"><div><label>Website</label><input name="website" value="<?= h($co['website']) ?>" placeholder="example.com"></div><div><label>Phone</label><input name="phone" value="<?= h($co['phone']) ?>"></div></div>
    <div class="row"><div><label>Industry</label><input name="industry" value="<?= h($co['industry']) ?>"></div><div><label>Owner</label>
      <select name="owner_id"><option value="0">- unassigned -</option><?php foreach ($users as $us): ?><option value="<?= (int) $us['id'] ?>" <?= (int) $us['id'] === (int) $co['owner_id'] ? 'selected' : '' ?>><?= h($us['name'] ?: $us['email']) ?></option><?php endforeach; ?></select></div></div>
    <label>Address</label><input name="address" value="<?= h($co['address']) ?>">
    <label>Notes</label><textarea name="notes" style="min-height:90px"><?= h($co['notes']) ?></textarea>
    <div style="margin-top:14px"><button class="btn sm">Save changes</button></div>
  </form>
  <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line);display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
    <?php if ($others): ?>
    <form method="post" onsubmit="return confirm('Move this company\'s people and deals into the selected company, then remove this one?')" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="do" value="merge">
      <div><label>Merge into another company</label><select name="into" required style="min-width:220px"><option value="">- choose -</option><?php foreach ($others as $o): ?><option value="<?= (int) $o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn ghost sm">Merge</button></form>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Delete this company? Its people and deals are kept.')" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><button class="btn danger sm">Delete company</button></form>
  </div>
</div>
<?php endif; ?>

<div class="grid2 stack">
 <div>
  <div class="card"><h2>Details</h2>
    <table class="tbl">
      <?php if ($co['phone'] !== ''): ?><tr><td class="muted">Phone</td><td><?= h($co['phone']) ?></td></tr><?php endif; ?>
      <?php if ($co['address'] !== ''): ?><tr><td class="muted">Address</td><td><?= h($co['address']) ?></td></tr><?php endif; ?>
      <?php if ($owner): ?><tr><td class="muted">Owner</td><td><?= h($owner['name'] ?: $owner['email']) ?></td></tr><?php endif; ?>
      <tr><td class="muted">Added</td><td><?= h(date('j M Y', (int) $co['created_at'])) ?></td></tr>
    </table>
    <?php if ($co['notes'] !== ''): ?><div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line)"><?= nl2br(h($co['notes'])) ?></div><?php endif; ?>
  </div>
  <div class="card"><h2>People (<?= count($people) ?>)</h2>
    <?php if (!$people): ?><p class="muted small">Nobody linked yet. <a href="<?= h(url('contacts.php?new=1&company=' . rawurlencode($co['name']))) ?>">Add a person</a>, or set this company on an existing contact.</p>
    <?php else: ?><table class="tbl"><?php foreach ($people as $c): ?>
      <tr><td><a href="<?= h(url('contact.php?id=' . (int) $c['id'])) ?>"><b><?= h(contact_name($c)) ?></b></a></td><td class="muted"><?= h($c['email']) ?></td><td class="muted"><?= h($c['phone']) ?></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>
 </div>
 <div>
  <div class="card"><h2>Deals (<?= count($deals) ?>)</h2>
    <?php if (!$deals): ?><p class="muted small">No deals yet. <a href="<?= h(url('pipelines.php')) ?>">Open the pipeline</a> to add one.</p>
    <?php else: ?><table class="tbl"><?php foreach ($deals as $d): $stages = jdec($d['stages']); ?>
      <tr><td><a href="<?= h(url('pipelines.php?id=' . (int) $d['pipeline_id'] . '&deal=' . (int) $d['id'])) ?>"><b><?= h($d['name'] ?: 'Deal') ?></b></a>
            <div class="muted small"><?= h($d['pname'] ?? '') ?><?php if ($d['status'] === 'open' && isset($stages[(int) $d['stage']])): ?> &middot; <?= h($stages[(int) $d['stage']]) ?><?php endif; ?></div></td>
          <td><?php if ($d['status'] === 'won'): ?><span class="tag ok">won</span><?php elseif ($d['status'] === 'lost'): ?><span class="tag bad">lost</span><?php elseif ($d['close_date'] !== ''): ?><span class="tag <?= $d['close_date'] < date('Y-m-d') ? 'bad' : '' ?>" title="Expected close"><?= h(date('j M Y', strtotime($d['close_date']))) ?></span><?php endif; ?></td>
          <td style="text-align:right"><b><?= money((int) $d['value_cents']) ?></b></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>
  <div class="card"><h2>Activity</h2>
    <?php if (!$acts): ?><p class="muted small">No activity yet.</p>
    <?php else: foreach ($acts as $a): ?><div style="display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid var(--line)"><span><?= h($actLabel[$a['type']] ?? $a['type']) ?> <span class="muted small">&middot; <a href="<?= h(url('contact.php?id=' . (int) $a['contact_id'])) ?>"><?= h(contact_name($a)) ?></a></span></span><span class="muted small" style="white-space:nowrap"><?= h(ago((int) $a['created_at'])) ?></span></div><?php endforeach; endif; ?>
  </div>
 </div>
</div>
<?php foot();

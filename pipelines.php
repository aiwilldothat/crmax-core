<?php
/** CRMax Core - Pipeline: deal kanban with drag-to-move, won/lost, per-contact linking. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

// Ensure at least one pipeline exists.
if ((int) db()->query("SELECT COUNT(*) FROM pipelines")->fetchColumn() === 0) {
    go_insert('pipelines', ['name' => 'Sales', 'stages' => json_encode(['New', 'Contacted', 'Proposal', 'Negotiation'], JSON_UNESCAPED_SLASHES), 'created_at' => time()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'move') {   // AJAX drag-drop
        header('Content-Type: application/json');
        $d = go_get('deals', (int) ($_POST['id'] ?? 0));
        if ($d) go_update('deals', (int) $d['id'], ['stage' => max(0, (int) ($_POST['stage'] ?? 0)), 'updated_at' => time()]);
        echo json_encode(['ok' => (bool) $d]); exit;
    }
    if ($do === 'new_deal') {
        $pid = (int) ($_POST['pipeline_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $cid = (int) ($_POST['contact_id'] ?? 0);
        $val = (int) round((float) ($_POST['value'] ?? 0) * 100);
        if ($name !== '' && go_get('pipelines', $pid)) { $id = go_insert('deals', ['pipeline_id' => $pid, 'contact_id' => $cid, 'name' => $name, 'stage' => (int) ($_POST['stage'] ?? 0), 'value_cents' => $val, 'status' => 'open', 'created_at' => time(), 'updated_at' => time()]); if ($cid) act_log($cid, 'deal_created', ['name' => $name]); flash_set('Deal added.'); }
        redirect('pipelines.php?id=' . $pid);
    }
    if ($do === 'deal_status') {
        $d = go_get('deals', (int) ($_POST['id'] ?? 0)); $s = (string) ($_POST['status'] ?? 'open');
        if ($d && in_array($s, ['open', 'won', 'lost'], true)) { go_update('deals', (int) $d['id'], ['status' => $s, 'updated_at' => time()]); if ((int) $d['contact_id'] && $s !== 'open') act_log((int) $d['contact_id'], 'deal_' . $s, ['name' => $d['name']]); }
        redirect('pipelines.php?id=' . (int) $d['pipeline_id']);
    }
    if ($do === 'del_deal') { $d = go_get('deals', (int) ($_POST['id'] ?? 0)); if ($d) { go_del('deals', (int) $d['id']); } redirect('pipelines.php?id=' . (int) ($d['pipeline_id'] ?? 0)); }
    if ($do === 'save_pipeline') {
        $pid = (int) ($_POST['pipeline_id'] ?? 0);
        $stages = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['stages'] ?? '')))));
        if (!$stages) $stages = ['New'];
        $data = ['name' => trim((string) ($_POST['name'] ?? 'Pipeline')) ?: 'Pipeline', 'stages' => json_encode($stages, JSON_UNESCAPED_SLASHES)];
        if ($pid && go_get('pipelines', $pid)) go_update('pipelines', $pid, $data);
        else { $data['created_at'] = time(); $pid = go_insert('pipelines', $data); }
        flash_set('Pipeline saved.'); redirect('pipelines.php?id=' . $pid);
    }
}

$pipes = db()->query("SELECT * FROM pipelines ORDER BY id")->fetchAll();
$pid = (int) ($_GET['id'] ?? ($pipes[0]['id'] ?? 0));
$pipe = go_get('pipelines', $pid) ?: $pipes[0];
$pid = (int) $pipe['id'];
$stages = jdec($pipe['stages']);
$deals = db()->prepare("SELECT d.*, c.first_name, c.last_name, c.email FROM deals d LEFT JOIN contacts c ON c.id=d.contact_id WHERE d.pipeline_id=? AND d.status='open' ORDER BY d.updated_at DESC"); $deals->execute([$pid]); $deals = $deals->fetchAll();
$byStage = array_fill(0, count($stages), []);
foreach ($deals as $d) { $s = min((int) $d['stage'], count($stages) - 1); $byStage[$s][] = $d; }
$contacts = db()->query("SELECT id, first_name, last_name, email FROM contacts ORDER BY updated_at DESC LIMIT 1000")->fetchAll();

head('Pipeline', 'pipelines', $u);
?>
<div class="pagehead"><h1>Pipeline</h1>
  <div class="sp">
    <?php if (count($pipes) > 1): ?><select onchange="location=this.value" style="width:auto"><?php foreach ($pipes as $p): ?><option value="<?= h(url('pipelines.php?id=' . (int) $p['id'])) ?>" <?= (int) $p['id'] === $pid ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn ghost sm" onclick="document.getElementById('pedit').classList.toggle('open2')">Edit stages</button>
    <button class="btn" onclick="document.getElementById('nd').classList.toggle('open2')">+ New deal</button>
  </div></div>

<div id="nd" class="card" style="display:none;max-width:560px">
  <h2>New deal</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="new_deal"><input type="hidden" name="pipeline_id" value="<?= $pid ?>">
    <label>Deal name</label><input name="name" required>
    <div class="row"><div><label>Value</label><input name="value" type="number" step="0.01" min="0" placeholder="0.00"></div>
      <div><label>Stage</label><select name="stage"><?php foreach ($stages as $i => $s): ?><option value="<?= $i ?>"><?= h($s) ?></option><?php endforeach; ?></select></div></div>
    <label>Contact (optional)</label>
    <select name="contact_id"><option value="0">- none -</option><?php foreach ($contacts as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h(contact_name($c)) ?></option><?php endforeach; ?></select>
    <button class="btn sm" style="margin-top:14px">Add deal</button>
  </form>
</div>
<div id="pedit" class="card" style="display:none;max-width:560px">
  <h2>Pipeline stages</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_pipeline"><input type="hidden" name="pipeline_id" value="<?= $pid ?>">
    <label>Name</label><input name="name" value="<?= h($pipe['name']) ?>">
    <label>Stages (comma separated)</label><input name="stages" value="<?= h(implode(', ', $stages)) ?>">
    <button class="btn sm" style="margin-top:12px">Save</button>
    <a class="btn ghost sm" href="<?= h(url('pipelines.php?new=1')) ?>" onclick="this.closest('form').querySelector('[name=pipeline_id]').value=0;this.closest('form').querySelector('[name=name]').value='';this.closest('form').querySelector('[name=stages]').value='New, Contacted, Proposal, Negotiation';return false">New pipeline</a>
  </form>
</div>

<div class="scrollx"><div class="kanban" style="display:flex;gap:12px;align-items:flex-start;min-width:min-content">
  <?php foreach ($stages as $i => $s): $sum = array_sum(array_map(fn($d) => (int) $d['value_cents'], $byStage[$i])); ?>
  <div class="kcol" data-stage="<?= $i ?>" style="flex:0 0 250px;background:var(--surface2);border:1px solid var(--line);border-radius:14px;padding:10px;min-height:120px"
       ondragover="event.preventDefault();this.style.outline='2px dashed var(--brand)'" ondragleave="this.style.outline='none'" ondrop="drop(event,<?= $i ?>)">
    <div style="display:flex;justify-content:space-between;font-weight:700;font-size:.85rem;margin-bottom:8px"><span><?= h($s) ?> <span class="muted">(<?= count($byStage[$i]) ?>)</span></span><span class="muted small"><?= money($sum) ?></span></div>
    <?php foreach ($byStage[$i] as $d): ?>
    <div class="kcard" draggable="true" ondragstart="event.dataTransfer.setData('id',<?= (int) $d['id'] ?>)" style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:8px;cursor:grab;box-shadow:var(--shadow)">
      <div style="font-weight:600;font-size:.9rem"><?= h($d['name']) ?></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:5px">
        <span class="muted small"><?php if ($d['contact_id'] && ($d['first_name'] || $d['email'])): ?><a href="<?= h(url('contact.php?id=' . (int) $d['contact_id'])) ?>"><?= h(contact_name($d)) ?></a><?php else: ?>-<?php endif; ?></span>
        <b class="small"><?= money((int) $d['value_cents']) ?></b>
      </div>
      <div style="display:flex;gap:4px;margin-top:8px">
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="deal_status"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="won"><button class="btn ghost sm" style="padding:2px 8px;font-size:.72rem" title="Won">Won</button></form>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="deal_status"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="lost"><button class="btn ghost sm" style="padding:2px 8px;font-size:.72rem" title="Lost">Lost</button></form>
        <form method="post" style="display:inline;margin-left:auto" onsubmit="return confirm('Delete deal?')"><?= csrf_field() ?><input type="hidden" name="do" value="del_deal"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn danger sm" style="padding:2px 7px;font-size:.72rem">&times;</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div></div>

<script>
function drop(e,stage){e.preventDefault();e.currentTarget.style.outline='none';var id=e.dataTransfer.getData('id');if(!id)return;
 var fd=new FormData();fd.append('do','move');fd.append('id',id);fd.append('stage',stage);fd.append('csrf',<?= jscript(csrf()) ?>);
 fetch(<?= jscript(url('pipelines.php')) ?>,{method:'POST',body:fd}).then(r=>r.json()).then(()=>location.reload());}
</script>
<style>.open2{display:block !important}</style>
<?php foot();

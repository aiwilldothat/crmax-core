<?php
/**
 * CRMax Core - Pipeline: a deal board with drag-to-move, a deal editor (expected close date,
 * win probability, company, contact, owner, notes), won/lost with a reason, and recently closed deals.
 */
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
        $ok = false;
        $d = go_get('deals', (int) ($_POST['id'] ?? 0));
        if ($d) {
            $p = go_get('pipelines', (int) $d['pipeline_id']);
            $stage = (int) ($_POST['stage'] ?? -1);
            if ($p && $stage >= 0 && $stage < count(jdec($p['stages']))) { go_update('deals', (int) $d['id'], ['stage' => $stage, 'updated_at' => time()]); $ok = true; }
        }
        echo json_encode(['ok' => $ok]); exit;
    }
    if ($do === 'save_deal') {   // create (id=0) or edit
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id ? go_get('deals', $id) : null;
        $pid = $existing ? (int) $existing['pipeline_id'] : (int) ($_POST['pipeline_id'] ?? 0);
        $pipe = go_get('pipelines', $pid);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
        if (!$pipe || $name === '' || ($id && !$existing)) { flash_set('A deal needs a name.', 'err'); redirect('pipelines.php' . ($pipe ? '?id=' . $pid : '')); }
        $cid = (int) ($_POST['contact_id'] ?? 0);
        $contact = $cid ? go_get('contacts', $cid) : null;
        if (!$contact) $cid = 0;
        $coId = company_upsert((string) ($_POST['company'] ?? ''));
        if (!$coId && $contact && !$existing) $coId = (int) $contact['company_id'];   // a new deal takes its contact's company unless one was typed
        $prob = trim((string) ($_POST['probability'] ?? ''));
        $owner = (int) ($_POST['owner_id'] ?? 0);
        if ($owner && !user_by_id($owner)) $owner = 0;
        $data = [
            'name' => $name,
            'value_cents' => max(0, (int) round((float) ($_POST['value'] ?? 0) * 100)),
            'stage' => max(0, min(max(0, count(jdec($pipe['stages'])) - 1), (int) ($_POST['stage'] ?? 0))),
            'contact_id' => $cid, 'company_id' => $coId,
            'close_date' => valid_date((string) ($_POST['close_date'] ?? '')),
            'probability' => $prob === '' ? -1 : max(0, min(100, (int) $prob)),
            'owner_id' => $owner,
            'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 5000),
            'updated_at' => time(),
        ];
        if ($existing) { go_update('deals', $id, $data); flash_set('Deal updated.'); }
        else {
            go_insert('deals', $data + ['pipeline_id' => $pid, 'status' => 'open', 'created_at' => time()]);
            if ($cid) act_log($cid, 'deal_created', ['name' => $name]);
            flash_set('Deal added.');
        }
        redirect('pipelines.php?id=' . $pid);
    }
    if ($do === 'deal_status') {
        $d = go_get('deals', (int) ($_POST['id'] ?? 0)); $s = (string) ($_POST['status'] ?? 'open');
        if ($d && in_array($s, ['open', 'won', 'lost'], true)) {
            go_update('deals', (int) $d['id'], [
                'status' => $s, 'updated_at' => time(),
                'closed_at' => $s === 'open' ? 0 : time(),
                'lost_reason' => $s === 'lost' ? mb_substr(trim((string) ($_POST['lost_reason'] ?? '')), 0, 300) : '',
            ]);
            if ((int) $d['contact_id'] && $s !== 'open' && $s !== $d['status']) act_log((int) $d['contact_id'], 'deal_' . $s, ['name' => $d['name']]);
        }
        redirect('pipelines.php?id=' . (int) ($d['pipeline_id'] ?? 0));
    }
    if ($do === 'del_deal') { $d = go_get('deals', (int) ($_POST['id'] ?? 0)); if ($d) { go_del('deals', (int) $d['id']); flash_set('Deal deleted.'); } redirect('pipelines.php?id=' . (int) ($d['pipeline_id'] ?? 0)); }
    if ($do === 'save_pipeline') {
        $pid = (int) ($_POST['pipeline_id'] ?? 0);
        $stages = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['stages'] ?? '')))));
        if (!$stages) $stages = ['New'];
        // Win probability per stage: blank = an even spread; otherwise one whole number per stage, missing ones filled in.
        $raw = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['probs'] ?? ''))), fn($v) => $v !== ''));
        $probs = $raw ? stage_probs(['stages' => json_encode($stages), 'stage_probs' => json_encode(array_map(fn($v) => is_numeric($v) ? (int) $v : null, $raw))]) : [];
        $data = ['name' => trim((string) ($_POST['name'] ?? 'Pipeline')) ?: 'Pipeline', 'stages' => json_encode($stages, JSON_UNESCAPED_SLASHES), 'stage_probs' => json_encode($probs)];
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
$probs = stage_probs($pipe);
$savedProbs = jdec($pipe['stage_probs']) ? $probs : [];

$sel = "SELECT d.*, c.first_name, c.last_name, c.email, co.name company_name, us.name owner_name, us.email owner_email
        FROM deals d LEFT JOIN contacts c ON c.id=d.contact_id LEFT JOIN companies co ON co.id=d.company_id LEFT JOIN users us ON us.id=d.owner_id";
$st = db()->prepare("$sel WHERE d.pipeline_id=? AND d.status='open' ORDER BY d.updated_at DESC"); $st->execute([$pid]); $deals = $st->fetchAll();
$st = db()->prepare("$sel WHERE d.pipeline_id=? AND d.status!='open' ORDER BY d.closed_at DESC, d.id DESC LIMIT 20"); $st->execute([$pid]); $closed = $st->fetchAll();
$focus = (int) ($_GET['deal'] ?? 0);   // deep link from a contact or company page: open this deal's editor
$known = array_column(array_merge($deals, $closed), 'id');
$extra = [];
if ($focus && !in_array($focus, array_map('intval', $known), true)) { $st = db()->prepare("$sel WHERE d.id=? AND d.pipeline_id=?"); $st->execute([$focus, $pid]); $extra = $st->fetchAll(); if (!$extra) $focus = 0; }

$byStage = array_fill(0, max(1, count($stages)), []);
foreach ($deals as $d) { $byStage[min((int) $d['stage'], max(0, count($stages) - 1))][] = $d; }
$totalOpen = 0; $totalWeighted = 0;
foreach ($deals as $d) { $totalOpen += (int) $d['value_cents']; $totalWeighted += deal_weighted($d, $probs); }

$contacts = db()->query("SELECT id, first_name, last_name, email FROM contacts ORDER BY updated_at DESC LIMIT 1000")->fetchAll();
$coNames = db()->query("SELECT name FROM companies ORDER BY name COLLATE NOCASE LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
$users = users_all();

// Everything the editor needs to fill itself in, keyed by deal id.
$js = [];
foreach (array_merge($deals, $closed, $extra) as $d) {
    $js[(int) $d['id']] = [
        'name' => $d['name'], 'value' => number_format((int) $d['value_cents'] / 100, 2, '.', ''), 'stage' => (int) $d['stage'],
        'close_date' => $d['close_date'], 'probability' => (int) $d['probability'] >= 0 ? (int) $d['probability'] : '',
        'company' => (string) ($d['company_name'] ?? ''), 'contact_id' => (int) $d['contact_id'],
        'contact_name' => ((int) $d['contact_id'] && ($d['first_name'] || $d['last_name'] || $d['email'])) ? contact_name($d) : '',
        'owner_id' => (int) $d['owner_id'], 'notes' => $d['notes'], 'status' => $d['status'],
    ];
}
$today = date('Y-m-d');

head('Pipeline', 'pipelines', $u);
?>
<div class="pagehead"><h1>Pipeline</h1>
  <div class="sp">
    <?php if (count($pipes) > 1): ?><select onchange="location=this.value" style="width:auto" aria-label="Pipeline"><?php foreach ($pipes as $p): ?><option value="<?= h(url('pipelines.php?id=' . (int) $p['id'])) ?>" <?= (int) $p['id'] === $pid ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn ghost sm" onclick="document.getElementById('pedit').classList.toggle('open2')">Edit stages</button>
    <button class="btn" onclick="openDeal(0)">+ New deal</button>
  </div></div>
<p class="muted small" style="margin:-8px 0 14px"><?= count($deals) ?> open deal<?= count($deals) === 1 ? '' : 's' ?> &middot; <?= money($totalOpen) ?> &middot; weighted <b><?= money($totalWeighted) ?></b></p>

<div id="pedit" class="card" style="display:none;max-width:640px">
  <h2>Pipeline stages</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_pipeline"><input type="hidden" name="pipeline_id" value="<?= $pid ?>">
    <label>Name</label><input name="name" value="<?= h($pipe['name']) ?>">
    <label>Stages (comma separated)</label><input name="stages" value="<?= h(implode(', ', $stages)) ?>">
    <label>Win probability per stage, % (same order, comma separated - leave blank for an even spread)</label>
    <input name="probs" value="<?= h(implode(', ', $savedProbs)) ?>" placeholder="<?= h(implode(', ', $probs)) ?>">
    <button class="btn sm" style="margin-top:12px">Save</button>
    <a class="btn ghost sm" href="<?= h(url('pipelines.php?new=1')) ?>" onclick="var f=this.closest('form');f.querySelector('[name=pipeline_id]').value=0;f.querySelector('[name=name]').value='';f.querySelector('[name=stages]').value='New, Contacted, Proposal, Negotiation';f.querySelector('[name=probs]').value='';return false">New pipeline</a>
  </form>
</div>

<div class="scrollx"><div class="kanban" style="display:flex;gap:12px;align-items:flex-start;min-width:min-content">
  <?php foreach ($stages as $i => $s):
      $sum = array_sum(array_map(fn($d) => (int) $d['value_cents'], $byStage[$i]));
      $wsum = array_sum(array_map(fn($d) => deal_weighted($d, $probs), $byStage[$i])); ?>
  <div class="kcol" data-stage="<?= $i ?>" style="flex:0 0 260px;background:var(--surface2);border:1px solid var(--line);border-radius:14px;padding:10px;min-height:120px"
       ondragover="event.preventDefault();this.style.outline='2px dashed var(--brand)'" ondragleave="this.style.outline='none'" ondrop="drop(event,<?= $i ?>)">
    <div style="display:flex;justify-content:space-between;font-weight:700;font-size:.85rem"><span><?= h($s) ?> <span class="muted">(<?= count($byStage[$i]) ?>)</span></span><span class="muted small" title="Default win probability for this stage"><?= (int) $probs[$i] ?>%</span></div>
    <div class="muted small" style="margin-bottom:8px"><?= money($sum) ?> &middot; weighted <?= money($wsum) ?></div>
    <?php foreach ($byStage[$i] as $d):
        $overdue = $d['close_date'] !== '' && $d['close_date'] < $today;
        $who = trim(($d['company_name'] ?? '') !== '' ? (string) $d['company_name'] : '');
        $person = ((int) $d['contact_id'] && ($d['first_name'] || $d['last_name'] || $d['email'])) ? contact_name($d) : '';
        $ownerLabel = (string) (($d['owner_name'] ?? '') !== '' ? $d['owner_name'] : ($d['owner_email'] ?? '')); ?>
    <div class="kcard" draggable="true" ondragstart="event.dataTransfer.setData('id',<?= (int) $d['id'] ?>)" style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:8px;cursor:grab;box-shadow:var(--shadow)">
      <a href="<?= h(url('pipelines.php?id=' . $pid . '&deal=' . (int) $d['id'])) ?>" onclick="openDeal(<?= (int) $d['id'] ?>);return false" style="font-weight:600;font-size:.9rem;color:var(--text);display:block"><?= h($d['name']) ?></a>
      <div class="muted small" style="margin-top:2px">
        <?php if ($who !== ''): ?><a href="<?= h(url('company.php?id=' . (int) $d['company_id'])) ?>"><?= h($who) ?></a><?php endif; ?>
        <?php if ($who !== '' && $person !== ''): ?> &middot; <?php endif; ?>
        <?php if ($person !== ''): ?><a href="<?= h(url('contact.php?id=' . (int) $d['contact_id'])) ?>"><?= h($person) ?></a><?php endif; ?>
        <?php if ($who === '' && $person === ''): ?>-<?php endif; ?>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:6px">
        <span><?php if ($d['close_date'] !== ''): ?><span class="tag <?= $overdue ? 'bad' : '' ?>" title="<?= $overdue ? 'Expected close date has passed' : 'Expected close' ?>"><?= h(date('j M', strtotime($d['close_date']))) ?></span><?php else: ?><span class="muted small">no close date</span><?php endif; ?></span>
        <b class="small"><?= money((int) $d['value_cents']) ?></b>
      </div>
      <div style="display:flex;gap:4px;margin-top:8px;align-items:center">
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="deal_status"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="won"><button class="btn ghost sm" style="padding:2px 8px;font-size:.72rem">Won</button></form>
        <form method="post" style="display:inline" onsubmit="return askLost(this)"><?= csrf_field() ?><input type="hidden" name="do" value="deal_status"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="lost"><input type="hidden" name="lost_reason" value=""><button class="btn ghost sm" style="padding:2px 8px;font-size:.72rem">Lost</button></form>
        <span class="muted small" style="margin-left:auto" title="Win probability"><?= deal_prob($d, $probs) ?>%</span>
        <?php if ($ownerLabel !== ''): ?><span title="Owner: <?= h($ownerLabel) ?>" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:var(--surface2);border:1px solid var(--line);font-size:.62rem;font-weight:700;color:var(--muted)"><?= h(initials($ownerLabel)) ?></span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div></div>

<?php if ($closed): ?>
<div class="card" style="margin-top:16px"><h2>Recently closed</h2>
  <div class="scrollx"><table class="tbl"><thead><tr><th>Deal</th><th>Company / contact</th><th>Outcome</th><th>Closed</th><th style="text-align:right">Value</th><th></th></tr></thead><tbody>
  <?php foreach ($closed as $d): $person = ((int) $d['contact_id'] && ($d['first_name'] || $d['last_name'] || $d['email'])) ? contact_name($d) : ''; ?>
    <tr><td><a href="<?= h(url('pipelines.php?id=' . $pid . '&deal=' . (int) $d['id'])) ?>" onclick="openDeal(<?= (int) $d['id'] ?>);return false"><b><?= h($d['name']) ?></b></a></td>
        <td class="muted"><?= h(trim(($d['company_name'] ?? '') . (($d['company_name'] ?? '') !== '' && $person !== '' ? ' / ' : '') . $person)) ?: '-' ?></td>
        <td><?php if ($d['status'] === 'won'): ?><span class="tag ok">won</span><?php else: ?><span class="tag bad">lost</span><?php if ($d['lost_reason'] !== ''): ?> <span class="muted small"><?= h($d['lost_reason']) ?></span><?php endif; ?><?php endif; ?></td>
        <td class="muted small"><?= (int) $d['closed_at'] ? h(date('j M Y', (int) $d['closed_at'])) : '-' ?></td>
        <td style="text-align:right"><b><?= money((int) $d['value_cents']) ?></b></td>
        <td style="text-align:right"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="deal_status"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="open"><button class="btn ghost sm" style="padding:2px 9px;font-size:.72rem">Reopen</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="modalbg" id="dealbg" onclick="if(event.target===this)closeDeal()">
 <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dealtitle">
  <h2 id="dealtitle" style="font-size:1.05rem;margin-bottom:4px">New deal</h2>
  <form method="post" id="dealform"><?= csrf_field() ?><input type="hidden" name="do" value="save_deal"><input type="hidden" name="id" value="0"><input type="hidden" name="pipeline_id" value="<?= $pid ?>">
    <label>Deal name</label><input name="name" required maxlength="160">
    <div class="row"><div><label>Value</label><input name="value" type="number" step="0.01" min="0" placeholder="0.00"></div>
      <div><label>Stage</label><select name="stage" onchange="probHint()"><?php foreach ($stages as $i => $s): ?><option value="<?= $i ?>"><?= h($s) ?></option><?php endforeach; ?></select></div></div>
    <div class="row"><div><label>Expected close</label><input type="date" name="close_date"></div>
      <div><label>Win probability %</label><input name="probability" type="number" min="0" max="100" step="1"></div></div>
    <label>Company</label><input name="company" list="colist" autocomplete="off" placeholder="Pick an existing company or type a new one">
    <datalist id="colist"><?php foreach ($coNames as $cn): ?><option value="<?= h($cn) ?>"><?php endforeach; ?></datalist>
    <div class="row"><div><label>Contact</label><select name="contact_id"><option value="0">- none -</option><?php foreach ($contacts as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h(contact_name($c)) ?></option><?php endforeach; ?></select></div>
      <div><label>Owner</label><select name="owner_id"><option value="0">- unassigned -</option><?php foreach ($users as $us): ?><option value="<?= (int) $us['id'] ?>"><?= h($us['name'] ?: $us['email']) ?></option><?php endforeach; ?></select></div></div>
    <label>Notes</label><textarea name="notes" style="min-height:70px"></textarea>
    <div style="display:flex;gap:8px;margin-top:14px;align-items:center"><button class="btn sm">Save deal</button><button type="button" class="btn ghost sm" onclick="closeDeal()">Cancel</button></div>
  </form>
  <form method="post" id="delform" onsubmit="return confirm('Delete this deal?')" style="display:none;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);text-align:right">
    <?= csrf_field() ?><input type="hidden" name="do" value="del_deal"><input type="hidden" name="id" value="0"><button class="btn danger sm">Delete deal</button></form>
 </div>
</div>

<script>
var DEALS=<?= jscript($js) ?>,PROBS=<?= jscript($probs) ?>,ME=<?= (int) $u['id'] ?>,FOCUS=<?= $focus ?>;
// Form controls are read through .elements[...] because a control named "id" or "name" would otherwise
// be confused with the form's own id and name properties.
function fld(n){return document.getElementById('dealform').elements[n];}
function probHint(){fld('probability').placeholder='stage default '+(PROBS[fld('stage').value]||0)+'%';}
function openDeal(id){
 var d=id?DEALS[id]:null,del=document.getElementById('delform');
 if(id&&!d)return;
 fld('id').value=id||0;fld('name').value=d?d.name:'';fld('value').value=d&&d.value!=='0.00'?d.value:'';fld('stage').value=d?d.stage:0;
 fld('close_date').value=d?d.close_date:'';fld('probability').value=d?d.probability:'';fld('company').value=d?d.company:'';fld('notes').value=d?d.notes:'';
 var cs=fld('contact_id'),cid=d?d.contact_id:0;
 if(cid&&!cs.querySelector('option[value="'+cid+'"]')){var o=document.createElement('option');o.value=cid;o.textContent=d.contact_name||('Contact #'+cid);cs.appendChild(o);}
 cs.value=cid;fld('owner_id').value=d?d.owner_id:ME;
 document.getElementById('dealtitle').textContent=d?'Edit deal':'New deal';
 del.style.display=d?'block':'none';del.elements['id'].value=id||0;
 probHint();document.getElementById('dealbg').classList.add('open');fld('name').focus();
}
function closeDeal(){document.getElementById('dealbg').classList.remove('open');}
function askLost(form){var r=prompt('Why was this deal lost? (optional)','');if(r===null)return false;form.elements['lost_reason'].value=r;return true;}
function drop(e,stage){e.preventDefault();e.currentTarget.style.outline='none';var id=e.dataTransfer.getData('id');if(!id)return;
 var fd=new FormData();fd.append('do','move');fd.append('id',id);fd.append('stage',stage);fd.append('csrf',<?= jscript(csrf()) ?>);
 fetch(<?= jscript(url('pipelines.php')) ?>,{method:'POST',body:fd}).then(r=>r.json()).then(()=>location.reload());}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeDeal();});
if(FOCUS)openDeal(FOCUS);
</script>
<style>.open2{display:block !important}</style>
<?php foot();

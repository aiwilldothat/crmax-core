<?php
/** CRMax Core - Workflows: when something happens, run a sequence of steps (wait, email, tag, task). */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

$TRIGGERS = ['contact_created' => 'A contact is created', 'form_submitted' => 'A form is submitted', 'tag_added' => 'A tag is added'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $trigger = array_key_exists((string) ($_POST['trigger_on'] ?? ''), $TRIGGERS) ? (string) $_POST['trigger_on'] : 'contact_created';
        $cfg = [];
        if ($trigger === 'form_submitted' && !empty($_POST['cfg_form'])) $cfg['form_id'] = (int) $_POST['cfg_form'];
        if ($trigger === 'tag_added' && ($_POST['cfg_tag'] ?? '') !== '') $cfg['tag'] = trim((string) $_POST['cfg_tag']);
        $steps = [];
        foreach ((array) ($_POST['s_type'] ?? []) as $i => $type) {
            if ($type === 'wait') $steps[] = ['type' => 'wait', 'minutes' => max(1, (int) ($_POST['s_days'][$i] ?? 1)) * 1440];
            elseif ($type === 'email') { $sub = trim((string) ($_POST['s_subject'][$i] ?? '')); if ($sub !== '' || trim((string) ($_POST['s_body'][$i] ?? '')) !== '') $steps[] = ['type' => 'email', 'subject' => $sub, 'body' => (string) ($_POST['s_body'][$i] ?? '')]; }
            elseif ($type === 'tag') { $tg = trim((string) ($_POST['s_tag'][$i] ?? '')); if ($tg !== '') $steps[] = ['type' => 'tag', 'tag' => $tg]; }
            elseif ($type === 'task') { $tt = trim((string) ($_POST['s_title'][$i] ?? '')); if ($tt !== '') $steps[] = ['type' => 'task', 'title' => $tt, 'due_days' => max(0, (int) ($_POST['s_due'][$i] ?? 1))]; }
        }
        $data = ['name' => trim((string) ($_POST['name'] ?? 'Workflow')) ?: 'Workflow', 'trigger_on' => $trigger, 'config' => json_encode($cfg, JSON_UNESCAPED_SLASHES), 'steps' => json_encode($steps, JSON_UNESCAPED_SLASHES)];
        if ($id && go_get('workflows', $id)) go_update('workflows', $id, $data);
        else { $data['status'] = 'paused'; $data['created_at'] = time(); $id = go_insert('workflows', $data); }
        flash_set('Workflow saved. Activate it when you are ready.'); redirect('workflows.php?edit=' . $id);
    }
    if ($do === 'toggle') { $w = go_get('workflows', (int) ($_POST['id'] ?? 0)); if ($w) go_update('workflows', (int) $w['id'], ['status' => $w['status'] === 'active' ? 'paused' : 'active']); redirect('workflows.php'); }
    if ($do === 'delete') { $id = (int) ($_POST['id'] ?? 0); db()->prepare("DELETE FROM wf_enrollments WHERE workflow_id=?")->execute([$id]); go_del('workflows', $id); flash_set('Workflow deleted.'); redirect('workflows.php'); }
}

$edit = null;
if (isset($_GET['edit'])) { $edit = go_get('workflows', (int) $_GET['edit']); if (!$edit) redirect('workflows.php'); }
if (isset($_GET['new']) || $edit) {
    $w = $edit ?: ['name' => '', 'trigger_on' => 'contact_created', 'config' => '{}', 'steps' => '[]'];
    $cfg = jdec($w['config']); $steps = jdec($w['steps']);
    $forms = db()->query("SELECT id,name FROM forms ORDER BY id DESC")->fetchAll();
    head($edit ? $w['name'] : 'New workflow', 'workflows', $u);
    ?>
    <div class="pagehead"><a href="<?= h(url('workflows.php')) ?>" class="muted">&larr; Workflows</a></div>
    <div class="card" style="max-width:720px"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <label>Name</label><input name="name" value="<?= h($w['name']) ?>" required>
      <label>When this happens (trigger)</label>
      <select name="trigger_on" id="trig" onchange="trigUI()"><?php foreach ($TRIGGERS as $k => $lbl): ?><option value="<?= $k ?>" <?= $w['trigger_on'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
      <div id="c_form" style="<?= $w['trigger_on'] === 'form_submitted' ? '' : 'display:none' ?>"><label>Which form (optional - blank = any)</label><select name="cfg_form"><option value="0">Any form</option><?php foreach ($forms as $f): ?><option value="<?= (int) $f['id'] ?>" <?= (int) ($cfg['form_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= h($f['name']) ?></option><?php endforeach; ?></select></div>
      <div id="c_tag" style="<?= $w['trigger_on'] === 'tag_added' ? '' : 'display:none' ?>"><label>Which tag</label><input name="cfg_tag" value="<?= h($cfg['tag'] ?? '') ?>"></div>

      <h2 style="margin:18px 0 8px">Then do this</h2>
      <div id="steps"><?php foreach ($steps as $i => $s): wf_step_row($i, $s); endforeach; ?></div>
      <button type="button" class="btn ghost sm" onclick="addStep()">+ Add step</button>
      <div style="margin-top:18px"><button class="btn">Save workflow</button></div>
    </form></div>
    <script>
    function trigUI(){var t=document.getElementById('trig').value;document.getElementById('c_form').style.display=t==='form_submitted'?'block':'none';document.getElementById('c_tag').style.display=t==='tag_added'?'block':'none';}
    function stepUI(sel){var row=sel.closest('.wfstep');['wait','email','tag','task'].forEach(function(p){var el=row.querySelector('.p-'+p);if(el)el.style.display=sel.value===p?'block':'none';});}
    var stepN=<?= count($steps) ?>;
    function addStep(){var i=stepN++;var d=document.getElementById('steps');var r=document.createElement('div');r.innerHTML=stepHtml(i);d.appendChild(r.firstElementChild);}
    function stepHtml(i){return '<div class="wfstep card" style="padding:12px;background:var(--surface2)">'+
      '<div style="display:flex;gap:8px;align-items:center"><select name="s_type['+i+']" onchange="stepUI(this)" style="width:auto"><option value="wait">Wait</option><option value="email">Send email</option><option value="tag">Add tag</option><option value="task">Create task</option></select>'+
      '<button type="button" class="btn danger sm" style="margin-left:auto;padding:4px 9px" onclick="this.closest(\'.wfstep\').remove()">&times;</button></div>'+
      '<div class="p-wait">Wait <input name="s_days['+i+']" type="number" min="1" value="1" style="width:90px;display:inline-block"> day(s)</div>'+
      '<div class="p-email" style="display:none"><input name="s_subject['+i+']" placeholder="Email subject"><textarea name="s_body['+i+']" placeholder="Email body - merge tags like {{first_name}} work" style="min-height:90px"></textarea></div>'+
      '<div class="p-tag" style="display:none">Add tag <input name="s_tag['+i+']" placeholder="tag"></div>'+
      '<div class="p-task" style="display:none">Task <input name="s_title['+i+']" placeholder="Follow up"> due in <input name="s_due['+i+']" type="number" min="0" value="1" style="width:80px;display:inline-block"> day(s)</div></div>';}
    </script>
    <?php foot(); exit;
}

/* list */
$wfs = db()->query("SELECT * FROM workflows ORDER BY id DESC")->fetchAll();
head('Workflows', 'workflows', $u);
?>
<div class="pagehead"><h1>Workflows</h1><div class="sp"><a class="btn" href="<?= h(url('workflows.php?new=1')) ?>">+ New workflow</a></div></div>
<div class="card">
<?php if (!$wfs): ?><div class="empty"><div class="big">&#9881;&#65039;</div>No workflows yet. Automate follow-ups when a contact is created or a form is submitted.</div>
<?php else: ?><table class="tbl"><thead><tr><th>Name</th><th>Trigger</th><th>Steps</th><th>Enrolled</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($wfs as $w): ?><tr><td><a href="<?= h(url('workflows.php?edit=' . (int) $w['id'])) ?>"><b><?= h($w['name']) ?></b></a></td>
    <td class="muted small"><?= h($TRIGGERS[$w['trigger_on']] ?? $w['trigger_on']) ?></td><td><?= count(jdec($w['steps'])) ?></td><td><?= (int) $w['enrolled_count'] ?></td>
    <td><span class="tag <?= $w['status'] === 'active' ? 'ok' : '' ?>"><?= h($w['status']) ?></span></td>
    <td style="text-align:right"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><button class="btn ghost sm"><?= $w['status'] === 'active' ? 'Pause' : 'Activate' ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php foot();

/* server-side renderer for an existing step row (mirrors the JS stepHtml). */
function wf_step_row(int $i, array $s): void {
    $type = (string) ($s['type'] ?? 'wait');
    $sel = fn($t) => $type === $t ? 'selected' : '';
    $show = fn($t) => $type === $t ? '' : 'display:none';
    ?>
    <div class="wfstep card" style="padding:12px;background:var(--surface2)">
      <div style="display:flex;gap:8px;align-items:center">
        <select name="s_type[<?= $i ?>]" onchange="stepUI(this)" style="width:auto">
          <option value="wait" <?= $sel('wait') ?>>Wait</option><option value="email" <?= $sel('email') ?>>Send email</option><option value="tag" <?= $sel('tag') ?>>Add tag</option><option value="task" <?= $sel('task') ?>>Create task</option></select>
        <button type="button" class="btn danger sm" style="margin-left:auto;padding:4px 9px" onclick="this.closest('.wfstep').remove()">&times;</button></div>
      <div class="p-wait" style="<?= $show('wait') ?>">Wait <input name="s_days[<?= $i ?>]" type="number" min="1" value="<?= (int) max(1, (int) round(((int) ($s['minutes'] ?? 1440)) / 1440)) ?>" style="width:90px;display:inline-block"> day(s)</div>
      <div class="p-email" style="<?= $show('email') ?>"><input name="s_subject[<?= $i ?>]" placeholder="Email subject" value="<?= h($s['subject'] ?? '') ?>"><textarea name="s_body[<?= $i ?>]" placeholder="Email body - merge tags like {{first_name}} work" style="min-height:90px"><?= h($s['body'] ?? '') ?></textarea></div>
      <div class="p-tag" style="<?= $show('tag') ?>">Add tag <input name="s_tag[<?= $i ?>]" placeholder="tag" value="<?= h($s['tag'] ?? '') ?>"></div>
      <div class="p-task" style="<?= $show('task') ?>">Task <input name="s_title[<?= $i ?>]" placeholder="Follow up" value="<?= h($s['title'] ?? '') ?>"> due in <input name="s_due[<?= $i ?>]" type="number" min="0" value="<?= (int) ($s['due_days'] ?? 1) ?>" style="width:80px;display:inline-block"> day(s)</div>
    </div>
    <?php
}

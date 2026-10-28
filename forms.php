<?php
/** CRMax Core - Forms: build a lead-capture form, get a shareable link + embed, read submissions. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $fields = [];
        foreach ((array) ($_POST['fld_label'] ?? []) as $i => $lbl) {
            $lbl = trim((string) $lbl); if ($lbl === '') continue;
            $name = trim((string) (($_POST['fld_name'][$i] ?? ''))); if ($name === '') $name = slugify($lbl);
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
            $fields[] = ['label' => $lbl, 'name' => $name, 'type' => in_array(($_POST['fld_type'][$i] ?? 'text'), ['text', 'email', 'tel', 'textarea'], true) ? $_POST['fld_type'][$i] : 'text', 'required' => !empty($_POST['fld_req'][$i])];
        }
        if (!$fields) { flash_set('Add at least one field.', 'err'); redirect('forms.php' . ($id ? '?edit=' . $id : '?new=1')); }
        $slug = $id && ($ex = go_get('forms', $id)) ? $ex['slug'] : '';
        if ($slug === '') { $slug = slugify((string) ($_POST['name'] ?? 'form')); while (db()->query("SELECT 1 FROM forms WHERE slug=" . db()->quote($slug))->fetchColumn()) $slug .= bin2hex(random_bytes(1)); }
        $data = ['name' => trim((string) ($_POST['name'] ?? 'Form')) ?: 'Form', 'slug' => $slug, 'fields' => json_encode($fields, JSON_UNESCAPED_SLASHES),
            'thankyou' => trim((string) ($_POST['thankyou'] ?? '')), 'notify' => isset($_POST['notify']) ? 1 : 0, 'redirect' => trim((string) ($_POST['redirect'] ?? '')), 'status' => 'active'];
        if ($id && go_get('forms', $id)) go_update('forms', $id, $data);
        else { $data['created_at'] = time(); $id = go_insert('forms', $data); }
        flash_set('Form saved.'); redirect('forms.php?edit=' . $id);
    }
    if ($do === 'delete') { $id = (int) ($_POST['id'] ?? 0); db()->prepare("DELETE FROM form_submissions WHERE form_id=?")->execute([$id]); go_del('forms', $id); flash_set('Form deleted.'); redirect('forms.php'); }
}

/* view submissions */
if (isset($_GET['subs'])) {
    $form = go_get('forms', (int) $_GET['subs']); if (!$form) redirect('forms.php');
    $subs = db()->prepare("SELECT s.*, c.first_name, c.last_name, c.email FROM form_submissions s LEFT JOIN contacts c ON c.id=s.contact_id WHERE s.form_id=? ORDER BY s.id DESC LIMIT 500"); $subs->execute([(int) $form['id']]); $subs = $subs->fetchAll();
    head('Submissions', 'forms', $u);
    echo '<div class="pagehead"><a href="' . h(url('forms.php?edit=' . (int) $form['id'])) . '" class="muted">&larr; ' . h($form['name']) . '</a></div><div class="card"><h2>Submissions (' . count($subs) . ')</h2>';
    if (!$subs) echo '<div class="empty">No submissions yet.</div>';
    else { echo '<div class="scrollx"><table class="tbl"><thead><tr><th>When</th><th>Contact</th><th>Data</th></tr></thead><tbody>';
        foreach ($subs as $s) { $d = jdec($s['data']); $parts = []; foreach ($d as $k => $v) $parts[] = h((string) $k) . ': <b>' . h((string) $v) . '</b>';
            echo '<tr><td class="muted small">' . h(date('j M, H:i', (int) $s['created_at'])) . '</td><td>' . ($s['contact_id'] ? '<a href="' . h(url('contact.php?id=' . (int) $s['contact_id'])) . '">' . h(contact_name($s)) . '</a>' : '<span class="muted">-</span>') . '</td><td class="small">' . implode(' &middot; ', $parts) . '</td></tr>'; }
        echo '</tbody></table></div>'; }
    echo '</div>'; foot(); exit;
}

/* new / edit */
$edit = null;
if (isset($_GET['edit'])) { $edit = go_get('forms', (int) $_GET['edit']); if (!$edit) redirect('forms.php'); }
if (isset($_GET['new']) || $edit) {
    $f = $edit ?: ['name' => '', 'fields' => '[]', 'thankyou' => 'Thanks - we will be in touch shortly.', 'notify' => 1, 'redirect' => '', 'slug' => ''];
    $fields = jdec($f['fields']) ?: [['label' => 'Name', 'name' => 'first_name', 'type' => 'text', 'required' => true], ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'required' => true]];
    $pub = base_url() . '/f.php?s=' . rawurlencode((string) $f['slug']);
    head($edit ? 'Edit form' : 'New form', 'forms', $u);
    ?>
    <div class="pagehead"><h1><?= $edit ? 'Edit form' : 'New form' ?></h1><div class="sp"><a class="btn ghost sm" href="<?= h(url('forms.php')) ?>">Back</a></div></div>
    <div class="grid2"><div>
      <div class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <label>Form name</label><input name="name" value="<?= h($f['name']) ?>" required>
        <h2 style="margin:16px 0 4px">Fields</h2>
        <div id="flds"><?php foreach ($fields as $i => $fld): ?>
          <div class="fldrow" style="display:flex;gap:6px;margin-bottom:6px;align-items:center">
            <input name="fld_label[<?= $i ?>]" value="<?= h($fld['label']) ?>" placeholder="Label" style="flex:2">
            <input name="fld_name[<?= $i ?>]" value="<?= h($fld['name']) ?>" placeholder="key" style="flex:1" title="Use first_name, last_name, email, phone or company to map to a contact">
            <select name="fld_type[<?= $i ?>]" style="width:auto"><?php foreach (['text', 'email', 'tel', 'textarea'] as $t): ?><option <?= ($fld['type'] ?? 'text') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
            <label style="margin:0;display:flex;align-items:center;gap:3px;font-size:.75rem"><input type="checkbox" name="fld_req[<?= $i ?>]" value="1" style="width:auto" <?= !empty($fld['required']) ? 'checked' : '' ?>>req</label>
            <button type="button" class="btn danger sm" style="padding:4px 9px" onclick="this.closest('.fldrow').remove()">&times;</button>
          </div>
        <?php endforeach; ?></div>
        <button type="button" class="btn ghost sm" onclick="addFld()">+ Add field</button>
        <label style="margin-top:16px">Thank-you message</label><input name="thankyou" value="<?= h($f['thankyou']) ?>">
        <label>Redirect URL after submit (optional)</label><input name="redirect" value="<?= h($f['redirect']) ?>" placeholder="https://...">
        <label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="notify" style="width:auto" <?= (int) $f['notify'] === 1 ? 'checked' : '' ?>> Email me each submission</label>
        <button class="btn" style="margin-top:16px">Save form</button>
      </form></div>
    </div><div>
      <?php if ($edit): ?>
      <div class="card"><h2>Share</h2>
        <label>Public link</label><input class="mono" readonly value="<?= h($pub) ?>" onclick="this.select()">
        <p class="small muted" style="margin-top:6px"><a href="<?= h($pub) ?>" target="_blank">Open form &rarr;</a> &middot; <a href="<?= h(url('forms.php?subs=' . (int) $edit['id'])) ?>">View submissions (<?= (int) $edit['submits'] ?>)</a></p>
        <label style="margin-top:12px">Embed (iframe)</label>
        <textarea class="mono" readonly onclick="this.select()" style="min-height:70px">&lt;iframe src="<?= h($pub) ?>" style="width:100%;max-width:520px;height:560px;border:0"&gt;&lt;/iframe&gt;</textarea>
      </div>
      <div class="card"><h2>Delete</h2><form method="post" onsubmit="return confirm('Delete this form and its submissions?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><button class="btn danger sm">Delete form</button></form></div>
      <?php endif; ?>
    </div></div>
    <script>
    var fldN = <?= count($fields) ?>;
    function addFld(){var i=fldN++;var d=document.getElementById('flds');var r=document.createElement('div');r.className='fldrow';r.style.cssText='display:flex;gap:6px;margin-bottom:6px;align-items:center';
      r.innerHTML='<input name="fld_label['+i+']" placeholder="Label" style="flex:2"><input name="fld_name['+i+']" placeholder="key" style="flex:1"><select name="fld_type['+i+']" style="width:auto"><option>text</option><option>email</option><option>tel</option><option>textarea</option></select><label style="margin:0;display:flex;align-items:center;gap:3px;font-size:.75rem"><input type="checkbox" name="fld_req['+i+']" value="1" style="width:auto">req</label><button type="button" class="btn danger sm" style="padding:4px 9px" onclick="this.closest(\'.fldrow\').remove()">&times;</button>';
      d.appendChild(r);}
    </script>
    <?php foot(); exit;
}

/* list */
$forms = db()->query("SELECT * FROM forms ORDER BY id DESC")->fetchAll();
head('Forms', 'forms', $u);
?>
<div class="pagehead"><h1>Forms</h1><div class="sp"><a class="btn" href="<?= h(url('forms.php?new=1')) ?>">+ New form</a></div></div>
<div class="card">
<?php if (!$forms): ?><div class="empty"><div class="big">&#128221;</div>No forms yet. Build one to capture leads on your website.</div>
<?php else: ?><table class="tbl"><thead><tr><th>Name</th><th>Link</th><th>Submissions</th><th></th></tr></thead><tbody>
  <?php foreach ($forms as $f): ?><tr><td><a href="<?= h(url('forms.php?edit=' . (int) $f['id'])) ?>"><b><?= h($f['name']) ?></b></a></td>
    <td class="mono small"><a href="<?= h(base_url() . '/f.php?s=' . rawurlencode((string) $f['slug'])) ?>" target="_blank">/f.php?s=<?= h($f['slug']) ?></a></td>
    <td><a href="<?= h(url('forms.php?subs=' . (int) $f['id'])) ?>"><?= (int) $f['submits'] ?></a></td>
    <td style="text-align:right"><a class="btn ghost sm" href="<?= h(url('forms.php?edit=' . (int) $f['id'])) ?>">Edit</a></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php foot();

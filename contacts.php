<?php
/** CRMax Core - Contacts: list, search, filter by owner, add/edit, bulk actions, CSV import/export. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts-' . date('Ymd') . '.csv"');
    $team = team_map();
    $out = fopen('php://output', 'w');
    fputcsv($out, ['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'tags', 'source', 'created', 'owner']);
    foreach (db()->query("SELECT * FROM contacts ORDER BY id") as $c) {
        fputcsv($out, [csv_safe($c['first_name']), csv_safe($c['last_name']), csv_safe($c['email']), csv_safe($c['phone']), csv_safe($c['company']), csv_safe($c['address']), csv_safe(implode(', ', jdec($c['tags']))), csv_safe($c['source']), date('Y-m-d', (int) $c['created_at']), csv_safe($team[(int) $c['owner_id']] ?? '')]);
    }
    fclose($out); exit;
}

/* ---------- writes ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')), 'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))), 'phone' => trim((string) ($_POST['phone'] ?? '')),
            'company' => trim((string) ($_POST['company'] ?? '')), 'address' => trim((string) ($_POST['address'] ?? '')),
            'source' => trim((string) ($_POST['source'] ?? '')), 'dnd_email' => isset($_POST['dnd_email']) ? 1 : 0,
            'tags' => json_encode(array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? ''))))), JSON_UNESCAPED_SLASHES),
            'updated_at' => time(),
        ];
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { flash_set('Enter a valid email address.', 'err'); redirect('contacts.php' . ($id ? '?edit=' . $id : '?new=1')); }
        $existing = $id ? go_get('contacts', $id) : null;
        // The owner is whoever the picker says. With no choice sent, a new contact belongs to the person adding it and an existing one keeps its owner.
        $owner = posted_user('owner_id', $existing ? (int) $existing['owner_id'] : (int) $u['id']);
        if ($existing) {
            go_update('contacts', $id, $data + ['owner_id' => $owner]); contact_set_company($id, $data['company']);
            wf_fire_tags_added($id, jdec($existing['tags']), jdec($data['tags']));
            if ($owner !== (int) $existing['owner_id']) notify_assigned('contact', $owner, $u, go_get('contacts', $id) ?? $existing);
            flash_set('Contact updated.');
        } else {
            $data['created_at'] = time(); $data['owner_id'] = $owner;
            $id = go_insert('contacts', $data); contact_set_company($id, $data['company']);
            act_log($id, 'contact_created', ['source' => 'manual']); wf_fire('contact_created', $id); wf_fire_tags_added($id, [], jdec($data['tags']));
            notify_assigned('contact', $owner, $u, go_get('contacts', $id) ?? []);
            flash_set('Contact added.');
        }
        redirect('contact.php?id=' . $id);
    }
    if ($do === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        contact_delete($id);
        flash_set('Contact deleted.'); redirect('contacts.php');
    }
    if ($do === 'bulk') {   // assign, add a tag to, or delete every ticked contact
        $keep = array_filter(['q' => trim((string) ($_POST['q'] ?? '')), 'tag' => trim((string) ($_POST['tag'] ?? ''))], fn($v) => $v !== '');
        $back = 'contacts.php' . ($keep ? '?' . http_build_query($keep) : '');   // back to the search the list was showing
        $ids = [];
        foreach ((array) ($_POST['ids'] ?? []) as $v) if (is_scalar($v) && (int) $v > 0) $ids[(int) $v] = true;
        $ids = array_slice(array_keys($ids), 0, 1000);
        $what = (string) ($_POST['bulk'] ?? '');
        $count = fn(int $n): string => $n . ' contact' . ($n === 1 ? '' : 's');
        if (!$ids) { flash_set('Tick the contacts you want to change first.', 'err'); redirect($back); }
        if ($what === 'assign') {
            $raw = trim((string) ($_POST['owner_id'] ?? ''));
            $owner = $raw === '0' ? 0 : valid_user_id((int) $raw);
            if ($raw === '' || ($raw !== '0' && !$owner)) { flash_set('Choose who to assign the contacts to.', 'err'); redirect($back); }
            $n = contacts_assign($ids, $owner, $u);
            $name = $owner ? user_label(user_by_id($owner)) : '';
            if ($n === 0) flash_set($owner ? 'Those contacts already belong to ' . $name . '.' : 'Those contacts were already unassigned.');
            else flash_set($owner ? $count($n) . ' assigned to ' . $name . '.' : $count($n) . ' now unassigned.');
            redirect($back);
        }
        if ($what === 'tag') {
            $tags = array_slice(array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim($t), 0, 60), explode(',', (string) ($_POST['newtag'] ?? '')))))), 0, 10);
            if (!$tags) { flash_set('Type the tag to add.', 'err'); redirect($back); }
            $n = (int) db_tx(function () use ($ids, $tags) { $n = 0; foreach ($ids as $id) if (contact_add_tags($id, $tags) > 0) $n++; return $n; });
            flash_set($n === 0 ? 'Those contacts already have that tag.' : 'Tag added to ' . $count($n) . '.');
            redirect($back);
        }
        if ($what === 'delete') {
            // The list asks "are you sure?" and only then sets this flag, so a delete cannot go through unconfirmed.
            if (($_POST['confirm'] ?? '') !== 'yes') { flash_set('Nothing was deleted. Deleting contacts has to be confirmed first.', 'err'); redirect($back); }
            $n = (int) db_tx(function () use ($ids) { $n = 0; foreach ($ids as $id) if (contact_delete($id)) $n++; return $n; });
            flash_set($count($n) . ' deleted.');
            redirect($back);
        }
        flash_set('Choose what to do with the ticked contacts.', 'err'); redirect($back);
    }
    if ($do === 'import' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r'); $head = null; $n = 0;
        // An optional "owner" column names a team member by email address or by name. Rows without one follow the lead routing rule.
        $people = [];
        foreach (users_all() as $us) {
            $people[mb_strtolower((string) $us['email'])] = (int) $us['id'];
            if ((string) $us['name'] !== '') $people[mb_strtolower((string) $us['name'])] ??= (int) $us['id'];
        }
        $given = [];   // user id => how many contacts this import gave them: one summary email each, not one per contact
        while (($row = fgetcsv($fh)) !== false) {
            if ($head === null) { $head = array_map(fn($h) => strtolower(trim((string) $h)), $row); continue; }
            $r = @array_combine($head, array_pad($row, count($head), '')); if (!$r) continue;
            $in = [
                'first_name' => $r['first_name'] ?? ($r['first name'] ?? ''), 'last_name' => $r['last_name'] ?? ($r['last name'] ?? ''),
                'email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '', 'company' => $r['company'] ?? '',
                'address' => $r['address'] ?? ($r['street'] ?? ''),
            ];
            // Only a file with a tags column sets tags; without one, existing contacts keep theirs.
            if (isset($r['tags'])) $in['tags'] = array_map('trim', explode(',', (string) $r['tags']));
            $owner = mb_strtolower(trim((string) ($r['owner'] ?? '')));
            if ($owner !== '' && isset($people[$owner])) $in['owner_id'] = $people[$owner];
            $saved = contact_upsert_info($in, 'import', ['notify' => false]);
            if ($saved['assigned']) $given[$saved['owner_id']] = ($given[$saved['owner_id']] ?? 0) + 1;
            $n++;
        }
        fclose($fh);
        foreach ($given as $uid => $howMany) notify_assigned('contacts', (int) $uid, $u, [], ['count' => $howMany, 'why' => 'import']);
        flash_set("Imported $n row(s)."); redirect('contacts.php');
    }
}

/* ---------- add / edit form ---------- */
$edit = null;
if (isset($_GET['edit'])) { $edit = go_get('contacts', (int) $_GET['edit']); if (!$edit) redirect('contacts.php'); }
if (isset($_GET['new']) || $edit) {
    // A new contact can arrive with the company filled in (the "Add person" button on a company page). It starts out owned by whoever adds it.
    $c = $edit ?: ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'company' => company_clean((string) ($_GET['company'] ?? '')), 'address' => '', 'source' => '', 'dnd_email' => 0, 'tags' => '[]', 'owner_id' => (int) $u['id']];
    $coNames = db()->query("SELECT name FROM companies ORDER BY name COLLATE NOCASE LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
    head($edit ? 'Edit contact' : 'New contact', 'contacts', $u);
    echo '<div class="pagehead"><h1>' . ($edit ? 'Edit contact' : 'New contact') . '</h1></div>';
    echo '<div class="card" style="max-width:640px"><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">';
    echo '<div class="row"><div><label>First name</label><input name="first_name" value="' . h($c['first_name']) . '"></div><div><label>Last name</label><input name="last_name" value="' . h($c['last_name']) . '"></div></div>';
    echo '<div class="row"><div><label>Email</label><input type="email" name="email" value="' . h($c['email']) . '"></div><div><label>Phone</label><input name="phone" value="' . h($c['phone']) . '"></div></div>';
    echo '<label>Company</label><input name="company" value="' . h($c['company']) . '" list="colist" autocomplete="off" placeholder="Start typing - pick an existing company or enter a new one">';
    echo '<datalist id="colist">'; foreach ($coNames as $cn) echo '<option value="' . h($cn) . '">'; echo '</datalist>';
    echo '<label>Address</label><input name="address" value="' . h($c['address']) . '">';
    echo '<label>Tags (comma separated)</label><input name="tags" value="' . h(implode(', ', jdec($c['tags']))) . '">';
    echo '<div class="row"><div><label>Source</label><input name="source" value="' . h($c['source']) . '"></div><div><label>Owner</label><select name="owner_id">' . user_options((int) $c['owner_id']) . '</select></div></div>';
    echo '<label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="dnd_email" style="width:auto" ' . ((int) $c['dnd_email'] === 1 ? 'checked' : '') . '> Do not email (unsubscribed)</label>';
    echo '<div style="margin-top:16px;display:flex;gap:8px"><button class="btn">Save contact</button><a class="btn ghost" href="' . h(url($edit ? 'contact.php?id=' . (int) $edit['id'] : 'contacts.php')) . '">Cancel</a></div>';
    echo '</form></div>';
    foot(); exit;
}

/* ---------- list ---------- */
$q = trim((string) ($_GET['q'] ?? '')); $tag = trim((string) ($_GET['tag'] ?? ''));
$who = who_filter('contacts');
$where = []; $params = [];
if ($q !== '') { $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ? OR phone LIKE ?)"; $like = '%' . $q . '%'; array_push($params, $like, $like, $like, $like, $like); }
if ($tag !== '') { [$cond, $tp] = tag_sql($tag); $where[] = $cond; array_push($params, ...$tp); }
[$whoSql, $whoParams] = who_sql('owner_id', $who, (int) $u['id']);
if ($whoSql !== '') { $where[] = $whoSql; $params = array_merge($params, $whoParams); }
$rows = go_all('contacts', implode(' AND ', $where), $params, 'updated_at DESC, id DESC', 500);
$filtered = $q !== '' || $tag !== '' || $who !== 'all';
$team = team_map();
$allTags = [];
foreach (db()->query("SELECT tags FROM contacts WHERE tags!='[]'") as $r) foreach (jdec($r['tags']) as $tg) $allTags[$tg] = ($allTags[$tg] ?? 0) + 1;
ksort($allTags);

head('Contacts', 'contacts', $u);
?>
<div class="pagehead"><h1>Contacts <span class="muted" style="font-size:.9rem;font-weight:400">(<?= number_format(count($rows)) ?>)</span></h1>
  <div class="sp">
    <a class="btn ghost sm" href="<?= h(url('contacts.php?export=1')) ?>">Export CSV</a>
    <button class="btn ghost sm" onclick="document.getElementById('imp').classList.toggle('open')">Import CSV</button>
    <a class="btn" href="<?= h(url('contacts.php?new=1')) ?>">+ New contact</a>
  </div></div>

<div id="imp" class="card modalbg" style="position:static;display:none;background:none;padding:0;align-items:stretch">
  <div class="card" style="margin:0 0 16px"><h2>Import contacts from CSV</h2>
  <p class="small muted">Columns recognised: first_name, last_name, email, phone, company, address, tags, owner. Existing contacts are matched by email. An owner column names a team member by email address or name; contacts without one follow the lead routing rule in Settings.</p>
  <form method="post" enctype="multipart/form-data" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="do" value="import">
    <input type="file" name="csv" accept=".csv" required style="width:auto"><button class="btn sm">Import</button></form></div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <input name="q" value="<?= h($q) ?>" placeholder="Search name, email, phone, company..." style="flex:1;min-width:200px">
    <?php if ($allTags): ?><select name="tag" onchange="this.form.submit()" style="width:auto"><option value="">All tags</option>
      <?php foreach ($allTags as $tg => $cnt): ?><option value="<?= h($tg) ?>" <?= $tag === $tg ? 'selected' : '' ?>><?= h($tg) ?> (<?= $cnt ?>)</option><?php endforeach; ?></select><?php endif; ?>
    <?= who_select($who, $u, 'name="who" onchange="this.form.submit()"') ?>
    <button class="btn ghost">Filter</button>
  </form>
  <?php if (!$rows && $filtered): ?>
    <div class="empty"><div class="big">&#128100;</div>No contacts match. <a href="<?= h(url('contacts.php?who=all')) ?>">Show all contacts</a>.</div>
  <?php elseif (!$rows): ?>
    <div class="empty"><div class="big">&#128100;</div>No contacts yet. <a href="<?= h(url('contacts.php?new=1')) ?>">Add one</a> or import a CSV.</div>
  <?php else: ?>
  <form method="post" id="bulk"><?= csrf_field() ?><input type="hidden" name="do" value="bulk"><input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="tag" value="<?= h($tag) ?>"><input type="hidden" name="confirm" value="">
  <div class="scrollx"><table class="tbl"><thead><tr><th style="width:26px"><input type="checkbox" id="pickall" aria-label="Select all contacts shown" onclick="pickAll(this)"></th><th>Name</th><th>Email</th><th class="hide-sm">Phone</th><th>Company</th><th>Tags</th><th>Owner</th><th class="hide-sm">Added</th></tr></thead><tbody>
    <?php foreach ($rows as $c): $oid = (int) $c['owner_id']; ?>
    <tr><td><input type="checkbox" class="pick" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="Select <?= h(contact_name($c)) ?>" onclick="bulkCount()"></td>
        <td><a href="<?= h(url('contact.php?id=' . (int) $c['id'])) ?>"><b><?= h(contact_name($c)) ?></b></a></td>
        <td class="muted"><?= h($c['email']) ?></td><td class="muted hide-sm"><?= h($c['phone']) ?></td>
        <td class="muted"><?php if ((int) $c['company_id']): ?><a href="<?= h(url('company.php?id=' . (int) $c['company_id'])) ?>"><?= h($c['company']) ?></a><?php else: ?><?= h($c['company']) ?><?php endif; ?></td>
        <td><?php foreach (jdec($c['tags']) as $tg): ?><span class="tag"><?= h($tg) ?></span> <?php endforeach; ?></td>
        <td style="white-space:nowrap"><?php if (isset($team[$oid])): ?><?= who_chip($oid, $team) ?><span class="muted small hide-sm"> <?= h($team[$oid]) ?></span><?php else: ?><span class="muted small">-</span><?php endif; ?></td>
        <td class="muted small hide-sm"><?= h(date('j M Y', (int) $c['created_at'])) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php /* Shown once something is ticked. It sits under the list and sticks to the bottom of the window, so the rows do not jump when it appears. */ ?>
  <div class="bulkbar" id="bulkbar">
    <b><span id="bulkn">0</span> selected</b>
    <select name="owner_id" aria-label="Assign the selected contacts to"><option value="">Assign to...</option><?= user_options(-1, 'Nobody (unassigned)') ?></select>
    <button class="btn ghost sm" name="bulk" value="assign" onclick="return bulkGo('assign')">Assign</button>
    <span class="gap"></span>
    <input type="text" name="newtag" placeholder="Tag to add" maxlength="60" aria-label="Tag to add to the selected contacts" onkeydown="if(event.key==='Enter'){event.preventDefault();this.nextElementSibling.click();}">
    <button class="btn ghost sm" name="bulk" value="tag" onclick="return bulkGo('tag')">Add tag</button>
    <span class="gap"></span>
    <button class="btn danger sm" name="bulk" value="delete" onclick="return bulkGo('delete')">Delete</button>
  </div>
  </form>
  <script>
  function picked(){return document.querySelectorAll('#bulk .pick:checked').length;}
  function bulkCount(){
   var n=picked(),all=document.querySelectorAll('#bulk .pick').length,box=document.getElementById('pickall');
   document.getElementById('bulkn').textContent=n;document.getElementById('bulkbar').classList.toggle('open',n>0);
   box.checked=n>0&&n===all;box.indeterminate=n>0&&n<all;
  }
  function pickAll(box){document.querySelectorAll('#bulk .pick').forEach(function(c){c.checked=box.checked;});bulkCount();}
  function bulkGo(what){
   var f=document.getElementById('bulk'),n=picked();
   if(!n)return false;
   if(what==='assign'&&f.elements['owner_id'].value===''){alert('Choose who to assign them to first.');return false;}
   if(what==='tag'&&!f.elements['newtag'].value.trim()){alert('Type the tag to add first.');f.elements['newtag'].focus();return false;}
   if(what==='delete'){
    if(!confirm('Delete '+n+' contact'+(n===1?'':'s')+'? Their notes, tasks and history are deleted too. This cannot be undone.'))return false;
    f.elements['confirm'].value='yes';
   }
   return true;
  }
  window.addEventListener('pageshow',bulkCount);
  </script>
  <?php endif; ?>
</div>
<?php foot();

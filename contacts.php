<?php
/** CRMax Core - Contacts: list, search, add/edit, CSV import/export. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'tags', 'source', 'created']);
    foreach (db()->query("SELECT * FROM contacts ORDER BY id") as $c) {
        fputcsv($out, [csv_safe($c['first_name']), csv_safe($c['last_name']), csv_safe($c['email']), csv_safe($c['phone']), csv_safe($c['company']), csv_safe($c['address']), csv_safe(implode(', ', jdec($c['tags']))), csv_safe($c['source']), date('Y-m-d', (int) $c['created_at'])]);
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
        if ($id && go_get('contacts', $id)) { go_update('contacts', $id, $data); flash_set('Contact updated.'); }
        else { $data['created_at'] = time(); $id = go_insert('contacts', $data); act_log($id, 'contact_created', ['source' => 'manual']); wf_fire('contact_created', $id); flash_set('Contact added.'); }
        redirect('contact.php?id=' . $id);
    }
    if ($do === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        foreach (['notes', 'tasks', 'activities', 'form_submissions', 'wf_enrollments'] as $tbl) db()->prepare("DELETE FROM $tbl WHERE contact_id=?")->execute([$id]);
        db()->prepare("UPDATE deals SET contact_id=0 WHERE contact_id=?")->execute([$id]);
        go_del('contacts', $id);
        flash_set('Contact deleted.'); redirect('contacts.php');
    }
    if ($do === 'import' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r'); $head = null; $n = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if ($head === null) { $head = array_map(fn($h) => strtolower(trim((string) $h)), $row); continue; }
            $r = @array_combine($head, array_pad($row, count($head), '')); if (!$r) continue;
            $tags = isset($r['tags']) ? array_map('trim', explode(',', (string) $r['tags'])) : [];
            contact_upsert([
                'first_name' => $r['first_name'] ?? ($r['first name'] ?? ''), 'last_name' => $r['last_name'] ?? ($r['last name'] ?? ''),
                'email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '', 'company' => $r['company'] ?? '',
                'address' => $r['address'] ?? ($r['street'] ?? ''), 'tags' => $tags,
            ], 'import');
            $n++;
        }
        fclose($fh); flash_set("Imported $n row(s)."); redirect('contacts.php');
    }
}

/* ---------- add / edit form ---------- */
$edit = null;
if (isset($_GET['edit'])) { $edit = go_get('contacts', (int) $_GET['edit']); if (!$edit) redirect('contacts.php'); }
if (isset($_GET['new']) || $edit) {
    $c = $edit ?: ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'company' => '', 'address' => '', 'source' => '', 'dnd_email' => 0, 'tags' => '[]'];
    head($edit ? 'Edit contact' : 'New contact', 'contacts', $u);
    echo '<div class="pagehead"><h1>' . ($edit ? 'Edit contact' : 'New contact') . '</h1></div>';
    echo '<div class="card" style="max-width:640px"><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int) ($edit['id'] ?? 0) . '">';
    echo '<div class="row"><div><label>First name</label><input name="first_name" value="' . h($c['first_name']) . '"></div><div><label>Last name</label><input name="last_name" value="' . h($c['last_name']) . '"></div></div>';
    echo '<div class="row"><div><label>Email</label><input type="email" name="email" value="' . h($c['email']) . '"></div><div><label>Phone</label><input name="phone" value="' . h($c['phone']) . '"></div></div>';
    echo '<label>Company</label><input name="company" value="' . h($c['company']) . '">';
    echo '<label>Address</label><input name="address" value="' . h($c['address']) . '">';
    echo '<div class="row"><div><label>Tags (comma separated)</label><input name="tags" value="' . h(implode(', ', jdec($c['tags']))) . '"></div><div><label>Source</label><input name="source" value="' . h($c['source']) . '"></div></div>';
    echo '<label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="dnd_email" style="width:auto" ' . ((int) $c['dnd_email'] === 1 ? 'checked' : '') . '> Do not email (unsubscribed)</label>';
    echo '<div style="margin-top:16px;display:flex;gap:8px"><button class="btn">Save contact</button><a class="btn ghost" href="' . h(url($edit ? 'contact.php?id=' . (int) $edit['id'] : 'contacts.php')) . '">Cancel</a></div>';
    echo '</form></div>';
    foot(); exit;
}

/* ---------- list ---------- */
$q = trim((string) ($_GET['q'] ?? '')); $tag = trim((string) ($_GET['tag'] ?? ''));
$where = []; $params = [];
if ($q !== '') { $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ? OR phone LIKE ?)"; $like = '%' . $q . '%'; array_push($params, $like, $like, $like, $like, $like); }
if ($tag !== '') { $where[] = "tags LIKE ?"; $params[] = '%"' . $tag . '"%'; }
$rows = go_all('contacts', implode(' AND ', $where), $params, 'updated_at DESC, id DESC', 500);
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
  <p class="small muted">Columns recognised: first_name, last_name, email, phone, company, address, tags. Existing contacts are matched by email.</p>
  <form method="post" enctype="multipart/form-data" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="do" value="import">
    <input type="file" name="csv" accept=".csv" required style="width:auto"><button class="btn sm">Import</button></form></div>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <input name="q" value="<?= h($q) ?>" placeholder="Search name, email, phone, company..." style="flex:1;min-width:200px">
    <?php if ($allTags): ?><select name="tag" onchange="this.form.submit()" style="width:auto"><option value="">All tags</option>
      <?php foreach ($allTags as $tg => $cnt): ?><option value="<?= h($tg) ?>" <?= $tag === $tg ? 'selected' : '' ?>><?= h($tg) ?> (<?= $cnt ?>)</option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn ghost">Filter</button>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big">&#128100;</div>No contacts yet. <a href="<?= h(url('contacts.php?new=1')) ?>">Add one</a> or import a CSV.</div>
  <?php else: ?>
  <div class="scrollx"><table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Company</th><th>Tags</th><th>Added</th></tr></thead><tbody>
    <?php foreach ($rows as $c): ?>
    <tr><td><a href="<?= h(url('contact.php?id=' . (int) $c['id'])) ?>"><b><?= h(contact_name($c)) ?></b></a></td>
        <td class="muted"><?= h($c['email']) ?></td><td class="muted"><?= h($c['phone']) ?></td><td class="muted"><?= h($c['company']) ?></td>
        <td><?php foreach (jdec($c['tags']) as $tg): ?><span class="tag"><?= h($tg) ?></span> <?php endforeach; ?></td>
        <td class="muted small"><?= h(date('j M Y', (int) $c['created_at'])) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php foot();

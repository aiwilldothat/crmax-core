<?php
/** CRMax Core - Companies: list, search, add, CSV import/export. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="companies-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    // Every fputcsv / fgetcsv argument is spelled out: PHP 8.4 reports a call that leaves the last one to its default.
    fputcsv($out, ['name', 'website', 'domain', 'phone', 'address', 'industry', 'notes', 'people', 'created'], ',', '"', '');
    $rows = db()->query("SELECT co.*, (SELECT COUNT(*) FROM contacts c WHERE c.company_id=co.id) people FROM companies co ORDER BY co.name COLLATE NOCASE")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [csv_safe($r['name']), csv_safe($r['website']), csv_safe($r['domain']), csv_safe($r['phone']), csv_safe($r['address']),
            csv_safe($r['industry']), csv_safe($r['notes']), (int) $r['people'], date('Y-m-d', (int) $r['created_at'])], ',', '"', '');
    }
    fclose($out);
    audit('export', 'Exported ' . count($rows) . ' compan' . (count($rows) === 1 ? 'y' : 'ies') . ' as CSV', 'companies');
    exit;
}

/* ---------- writes ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $name = company_clean((string) ($_POST['name'] ?? ''));
        if ($name === '') { flash_set('A company needs a name.', 'err'); redirect('companies.php?new=1'); }
        $st = db()->prepare("SELECT id FROM companies WHERE name=? COLLATE NOCASE LIMIT 1"); $st->execute([$name]);
        if ($existing = (int) $st->fetchColumn()) { flash_set('That company already exists - here it is.'); redirect('company.php?id=' . $existing); }
        $id = go_insert('companies', ['name' => $name, 'owner_id' => (int) $u['id'], 'created_at' => time(), 'updated_at' => time()] + company_input($_POST));
        flash_set('Company added.');
        redirect('company.php?id=' . $id);
    }
    if ($do === 'import' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r'); $head = null; $n = 0;
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($head === null) { $head = array_map(fn($h) => strtolower(trim((string) $h)), $row); continue; }
            $r = @array_combine($head, array_pad($row, count($head), '')); if (!$r) continue;
            $name = (string) ($r['name'] ?? ($r['company'] ?? ($r['company name'] ?? '')));
            if (company_clean($name) === '') continue;
            company_upsert($name, company_input($r));
            $n++;
        }
        fclose($fh);
        audit('import', 'Imported ' . $n . ' row' . ($n === 1 ? '' : 's') . ' of companies from the CSV file "' . mb_substr(basename((string) ($_FILES['csv']['name'] ?? '')), 0, 80) . '"', 'companies');
        flash_set("Imported $n row(s)."); redirect('companies.php');
    }
}

/* ---------- add form ---------- */
if (isset($_GET['new'])) {
    head('New company', 'companies', $u);
    echo '<div class="pagehead"><h1>New company</h1></div>';
    echo '<div class="card" style="max-width:640px"><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="add">';
    echo '<label>Company name</label><input name="name" required maxlength="160" autofocus>';
    echo '<div class="row"><div><label>Website</label><input name="website" placeholder="example.com"></div><div><label>Phone</label><input name="phone"></div></div>';
    echo '<div class="row"><div><label>Industry</label><input name="industry"></div><div><label>Address</label><input name="address"></div></div>';
    echo '<label>Notes</label><textarea name="notes" style="min-height:80px"></textarea>';
    echo '<div style="margin-top:16px;display:flex;gap:8px"><button class="btn">Save company</button><a class="btn ghost" href="' . h(url('companies.php')) . '">Cancel</a></div>';
    echo '</form></div>';
    foot(); exit;
}

/* ---------- list ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$where = ''; $params = [];
if ($q !== '') { $where = "WHERE co.name LIKE ? OR co.domain LIKE ? OR co.industry LIKE ?"; $like = '%' . $q . '%'; $params = [$like, $like, $like]; }
$st = db()->prepare("SELECT co.*,
        (SELECT COUNT(*) FROM contacts c WHERE c.company_id=co.id) people,
        (SELECT COUNT(*) FROM deals d WHERE d.company_id=co.id AND d.status='open') open_deals,
        (SELECT COALESCE(SUM(d.value_cents),0) FROM deals d WHERE d.company_id=co.id AND d.status='open') open_value
    FROM companies co $where ORDER BY co.name COLLATE NOCASE LIMIT 500");
$st->execute($params);
$rows = $st->fetchAll();
$team = team_map();

head('Companies', 'companies', $u);
?>
<div class="pagehead"><h1>Companies <span class="muted" style="font-size:.9rem;font-weight:400">(<?= number_format(count($rows)) ?>)</span></h1>
  <div class="sp">
    <a class="btn ghost sm" href="<?= h(url('companies.php?export=1')) ?>">Export CSV</a>
    <button class="btn ghost sm" onclick="document.getElementById('imp').style.display=document.getElementById('imp').style.display==='none'?'block':'none'">Import CSV</button>
    <a class="btn" href="<?= h(url('companies.php?new=1')) ?>">+ New company</a>
  </div></div>

<div id="imp" class="card" style="display:none"><h2>Import companies from CSV</h2>
  <p class="small muted">Columns recognised: name, website, domain, phone, address, industry, notes. Existing companies are matched by name.</p>
  <form method="post" enctype="multipart/form-data" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="do" value="import">
    <input type="file" name="csv" accept=".csv" required style="width:auto"><button class="btn sm">Import</button></form>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <input name="q" value="<?= h($q) ?>" placeholder="Search name, domain, industry..." style="flex:1;min-width:200px">
    <button class="btn ghost">Search</button>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big">&#127970;</div><?= $q !== '' ? 'No companies match that search.' : 'No companies yet. <a href="' . h(url('companies.php?new=1')) . '">Add one</a>, or type a company name on any contact and it appears here.' ?></div>
  <?php else: ?>
  <div class="scrollx"><table class="tbl"><thead><tr><th>Company</th><th class="hide-sm">Website</th><th>Industry</th><th class="hide-sm">Owner</th><th style="text-align:right">People</th><th style="text-align:right" class="hide-sm">Open deals</th><th style="text-align:right">Open value</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= h(url('company.php?id=' . (int) $r['id'])) ?>"><b><?= h($r['name']) ?></b></a></td>
        <td class="muted hide-sm"><?= h($r['domain'] !== '' ? $r['domain'] : $r['website']) ?></td>
        <td class="muted"><?= h($r['industry']) ?></td>
        <td class="hide-sm"><?= who_chip((int) $r['owner_id'], $team) ?: '<span class="muted small">-</span>' ?></td>
        <td style="text-align:right"><?= (int) $r['people'] ?></td>
        <td style="text-align:right" class="hide-sm"><?= (int) $r['open_deals'] ?></td>
        <td style="text-align:right"><?= (int) $r['open_value'] ? money((int) $r['open_value']) : '<span class="muted">-</span>' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php foot();

<?php
/** CRMax Core - Email campaigns: compose, segment, and send through your own SMTP account. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'save' || $do === 'send') {
        $id = (int) ($_POST['id'] ?? 0);
        $segType = ($_POST['seg_type'] ?? 'all') === 'tag' ? 'tag' : 'all';
        $seg = $segType === 'tag' ? ['type' => 'tag', 'tag' => trim((string) ($_POST['seg_tag'] ?? ''))] : ['type' => 'all'];
        $data = ['name' => trim((string) ($_POST['name'] ?? 'Campaign')) ?: 'Campaign', 'subject' => trim((string) ($_POST['subject'] ?? '')),
            'body' => sanitize_html((string) ($_POST['body'] ?? '')), 'from_account' => (int) ($_POST['from_account'] ?? 0), 'segment' => json_encode($seg, JSON_UNESCAPED_SLASHES)];
        if ($id && go_get('campaigns', $id)) go_update('campaigns', $id, $data);
        else { $data['status'] = 'draft'; $data['created_at'] = time(); $id = go_insert('campaigns', $data); }
        if ($do === 'send') {
            if (!email_ready()) { flash_set('Connect an email account in Settings before sending.', 'err'); redirect('campaigns.php?edit=' . $id); }
            if ($data['subject'] === '' || $data['body'] === '') { flash_set('Add a subject and body first.', 'err'); redirect('campaigns.php?edit=' . $id); }
            $n = camp_queue($id);
            go_update('campaigns', $id, ['status' => 'sending', 'scheduled_at' => time()]);
            flash_set("Sending to $n recipient(s). Delivery runs in the background" . (DEV ? ' (running now).' : ' via cron.'));
            if (DEV) camp_run_due(500);
            redirect('campaigns.php?edit=' . $id);
        }
        flash_set('Campaign saved.'); redirect('campaigns.php?edit=' . $id);
    }
    if ($do === 'ai') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!ai_available()) { flash_set('Add your AI API key in Settings to use AI drafting.', 'err'); }
        else {
            $brief = trim((string) ($_POST['brief'] ?? ''));
            $out = ai_complete("You write marketing emails for \"" . org_name() . "\". Write a short, friendly promotional email for this brief. Return ONLY a JSON object {\"subject\":\"...\",\"body\":\"<p>...</p>\"} where body is simple inline HTML (p, strong, a, ul/li), no <html>/<head>, no styles. Brief: " . $brief, 1200);
            $j = json_decode(trim(preg_replace('/^```json|```$/m', '', $out)), true);
            if (is_array($j) && !empty($j['body'])) { go_update('campaigns', $id, ['subject' => (string) ($j['subject'] ?? ''), 'body' => sanitize_html((string) $j['body'])]); flash_set('AI draft added - review it before sending.'); }
            else flash_set('The AI did not return a usable draft. Try again.', 'err');
        }
        redirect('campaigns.php?edit=' . $id);
    }
    if ($do === 'delete') { $id = (int) ($_POST['id'] ?? 0); db()->prepare("DELETE FROM campaign_recipients WHERE campaign_id=?")->execute([$id]); go_del('campaigns', $id); flash_set('Campaign deleted.'); redirect('campaigns.php'); }
}

$edit = null;
if (isset($_GET['edit'])) { $edit = go_get('campaigns', (int) $_GET['edit']); if (!$edit) redirect('campaigns.php'); }
if (isset($_GET['new']) || $edit) {
    $c = $edit ?: ['name' => '', 'subject' => '', 'body' => '', 'from_account' => 0, 'segment' => '{"type":"all"}', 'status' => 'draft'];
    $seg = jdec($c['segment']); $accounts = email_accounts();
    $allTags = [];
    foreach (db()->query("SELECT tags FROM contacts WHERE tags!='[]'") as $r) foreach (jdec($r['tags']) as $tg) $allTags[$tg] = true;
    $reach = count(segment_contacts($seg));
    $sent = $edit ? (int) db()->query("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id=" . (int) $edit['id'] . " AND status='sent'")->fetchColumn() : 0;
    $editable = ($c['status'] === 'draft');
    head($edit ? $c['name'] : 'New campaign', 'campaigns', $u);
    ?>
    <div class="pagehead"><a href="<?= h(url('campaigns.php')) ?>" class="muted">&larr; Campaigns</a>
      <div class="sp"><span class="tag <?= $c['status'] === 'sent' ? 'ok' : ($c['status'] === 'sending' ? 'warn' : '') ?>"><?= h($c['status']) ?></span></div></div>
    <?php if (!email_ready()): ?><div class="flash err">No email account connected. <a href="<?= h(url('settings.php#email')) ?>">Add one</a> to send campaigns.</div><?php endif; ?>
    <div class="grid2"><div>
      <div class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <label>Campaign name</label><input name="name" value="<?= h($c['name']) ?>" required <?= $editable ? '' : 'disabled' ?>>
        <label>Subject</label><input name="subject" value="<?= h($c['subject']) ?>" <?= $editable ? '' : 'disabled' ?>>
        <label>Body (HTML allowed; merge tags {{first_name}} {{company}} work)</label>
        <textarea name="body" style="min-height:220px" <?= $editable ? '' : 'disabled' ?>><?= h($c['body']) ?></textarea>
        <div class="row" style="margin-top:12px">
          <div><label>Send from</label><select name="from_account" <?= $editable ? '' : 'disabled' ?>><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $c['from_account'] === (int) $a['id'] ? 'selected' : '' ?>><?= h($a['from_email'] ?: $a['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Audience</label><select name="seg_type" id="segt" onchange="document.getElementById('segtag').style.display=this.value==='tag'?'block':'none'" <?= $editable ? '' : 'disabled' ?>><option value="all" <?= ($seg['type'] ?? 'all') === 'all' ? 'selected' : '' ?>>All contacts</option><option value="tag" <?= ($seg['type'] ?? '') === 'tag' ? 'selected' : '' ?>>By tag</option></select></div>
        </div>
        <div id="segtag" style="<?= ($seg['type'] ?? '') === 'tag' ? '' : 'display:none' ?>"><label>Tag</label><select name="seg_tag" <?= $editable ? '' : 'disabled' ?>><?php foreach (array_keys($allTags) as $tg): ?><option <?= ($seg['tag'] ?? '') === $tg ? 'selected' : '' ?>><?= h($tg) ?></option><?php endforeach; ?></select></div>
        <?php if ($editable): ?><button class="btn" style="margin-top:16px">Save draft</button><?php endif; ?>
      </form></div>
    </div><div>
      <div class="card"><h2>Audience</h2><p>This campaign reaches <b><?= number_format($reach) ?></b> contact(s) (excludes unsubscribed and those without an email).</p>
        <?php if ($edit): ?><p class="small muted" style="margin-top:8px">Sent: <?= $sent ?> &middot; Opens: <?= (int) $edit['open_count'] ?> &middot; Failed: <?= (int) $edit['fail_count'] ?></p><?php endif; ?>
      </div>
      <?php if ($editable): ?>
      <div class="card"><h2>Send</h2>
        <form method="post" onsubmit="return confirm('Send this campaign to '+<?= (int) $reach ?>+' contacts?')"><?= csrf_field() ?><input type="hidden" name="do" value="send"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <input type="hidden" name="name" value="<?= h($c['name']) ?>"><input type="hidden" name="subject" value="<?= h($c['subject']) ?>"><textarea name="body" style="display:none"><?= h($c['body']) ?></textarea>
          <input type="hidden" name="from_account" value="<?= (int) $c['from_account'] ?>"><input type="hidden" name="seg_type" value="<?= h($seg['type'] ?? 'all') ?>"><input type="hidden" name="seg_tag" value="<?= h($seg['tag'] ?? '') ?>">
          <p class="small muted" style="margin-bottom:10px">Save your draft first, then send. Sends go through your SMTP account.</p>
          <button class="btn" <?= email_ready() ? '' : 'disabled' ?>>Send now</button>
        </form>
      </div>
      <?php if (ai_available()): ?>
      <div class="card"><h2>&#10024; Draft with AI</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="ai"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <textarea name="brief" placeholder="What is this email about? e.g. Announce our spring 20% off sale ending Friday." style="min-height:70px"></textarea>
          <button class="btn sm" style="margin-top:8px">Generate draft</button></form></div>
      <?php endif; ?>
      <div class="card"><h2>Delete</h2><form method="post" onsubmit="return confirm('Delete this campaign?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><button class="btn danger sm">Delete</button></form></div>
      <?php endif; ?>
    </div></div>
    <?php foot(); exit;
}

/* list */
$camps = db()->query("SELECT * FROM campaigns ORDER BY id DESC")->fetchAll();
head('Campaigns', 'campaigns', $u);
?>
<div class="pagehead"><h1>Campaigns</h1><div class="sp"><a class="btn" href="<?= h(url('campaigns.php?new=1')) ?>">+ New campaign</a></div></div>
<div class="card">
<?php if (!$camps): ?><div class="empty"><div class="big">&#128227;</div>No campaigns yet. Create one to email your contacts.</div>
<?php else: ?><table class="tbl"><thead><tr><th>Name</th><th>Status</th><th>Sent</th><th>Opens</th><th>Created</th></tr></thead><tbody>
  <?php foreach ($camps as $c): ?><tr><td><a href="<?= h(url('campaigns.php?edit=' . (int) $c['id'])) ?>"><b><?= h($c['name']) ?></b></a><div class="muted small"><?= h($c['subject']) ?></div></td>
    <td><span class="tag <?= $c['status'] === 'sent' ? 'ok' : ($c['status'] === 'sending' ? 'warn' : '') ?>"><?= h($c['status']) ?></span></td>
    <td><?= (int) $c['sent_count'] ?></td><td><?= (int) $c['open_count'] ?></td><td class="muted small"><?= h(date('j M Y', (int) $c['created_at'])) ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php foot();

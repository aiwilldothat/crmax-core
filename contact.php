<?php
/** CRMax Core - a single contact: details, notes, tasks, activity, and a quick email. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

$id = (int) ($_GET['id'] ?? 0);
$c = go_get('contacts', $id);
if (!$c) redirect('contacts.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'note') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body !== '') { go_insert('notes', ['contact_id' => $id, 'user_id' => (int) $u['id'], 'body' => $body, 'created_at' => time()]); act_log($id, 'note_added', []); flash_set('Note added.'); }
        redirect('contact.php?id=' . $id);
    }
    if ($do === 'owner') {   // the owner picker in Details
        $to = posted_user('owner_id', (int) $c['owner_id']);
        if (contact_assign($id, $to, $u)) flash_set($to ? 'Owner changed to ' . user_label(user_by_id($to)) . '.' : 'This contact is now unassigned.');
        redirect('contact.php?id=' . $id);
    }
    if ($do === 'task') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') { task_add($title, (string) ($_POST['due_date'] ?? ''), $id, posted_user('assigned_to', (int) $u['id']), $u); flash_set('Task added.'); }
        redirect('contact.php?id=' . $id);
    }
    if ($do === 'task_assign') {   // the assignee badge on a task
        $t = go_get('tasks', (int) ($_POST['task_id'] ?? 0));
        if ($t && (int) $t['contact_id'] === $id) {
            $to = posted_user('assigned_to', (int) $t['assigned_to']);
            if (task_assign((int) $t['id'], $to, $u)) flash_set($to ? 'Task assigned to ' . user_label(user_by_id($to)) . '.' : 'Task is now unassigned.');
        }
        redirect('contact.php?id=' . $id);
    }
    if ($do === 'task_done') { go_update('tasks', (int) ($_POST['task_id'] ?? 0), ['status' => 'done']); redirect('contact.php?id=' . $id); }
    if ($do === 'email') {
        $subject = trim((string) ($_POST['subject'] ?? '')); $msg = trim((string) ($_POST['body'] ?? ''));
        if (($c['email'] ?? '') === '') { flash_set('This contact has no email address.', 'err'); }
        elseif (!rl_hit('sendmail', 3600, 200)) { flash_set('Sending limit reached, try later.', 'err'); }
        else {
            $res = send_mail((string) $c['email'], $subject, email_wrap(nl2br_html($msg)));
            if ($res['ok']) { act_log($id, 'email_sent', ['subject' => $subject]); flash_set('Email sent.'); }
            else flash_set('Could not send: ' . $res['err'], 'err');
        }
        redirect('contact.php?id=' . $id);
    }
}

$notes = db()->prepare("SELECT * FROM notes WHERE contact_id=? ORDER BY id DESC"); $notes->execute([$id]); $notes = $notes->fetchAll();
$tasks = db()->prepare("SELECT * FROM tasks WHERE contact_id=? ORDER BY status, (due_date=''), due_date"); $tasks->execute([$id]); $tasks = $tasks->fetchAll();
$acts  = db()->prepare("SELECT * FROM activities WHERE contact_id=? ORDER BY id DESC LIMIT 40"); $acts->execute([$id]); $acts = $acts->fetchAll();
$deals = db()->prepare("SELECT d.*, p.name pname, p.stages FROM deals d LEFT JOIN pipelines p ON p.id=d.pipeline_id WHERE d.contact_id=? ORDER BY d.id DESC"); $deals->execute([$id]); $deals = $deals->fetchAll();
$actLabel = ['contact_created' => 'Created', 'form_submitted' => 'Submitted a form', 'workflow_email' => 'Workflow email', 'email_sent' => 'Email sent', 'note_added' => 'Note added', 'deal_created' => 'Deal created', 'deal_won' => 'Deal won', 'deal_lost' => 'Deal lost'];
$team = team_map();

head(contact_name($c), 'contacts', $u);
?>
<div class="pagehead"><a href="<?= h(url('contacts.php')) ?>" class="muted">&larr; Contacts</a>
  <div class="sp"><a class="btn ghost sm" href="<?= h(url('contacts.php?edit=' . $id)) ?>">Edit</a>
    <form method="post" action="<?= h(url('contacts.php')) ?>" onsubmit="return confirm('Delete this contact?')" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn danger sm">Delete</button></form></div></div>

<h1 style="font-size:1.5rem;margin-bottom:2px"><?= h(contact_name($c)) ?></h1>
<p class="muted" style="margin-bottom:16px"><?php if ((int) $c['company_id']): ?><a href="<?= h(url('company.php?id=' . (int) $c['company_id'])) ?>"><?= h($c['company']) ?></a><?php else: ?><?= h($c['company']) ?><?php endif; ?><?php foreach (jdec($c['tags']) as $tg): ?> <span class="tag"><?= h($tg) ?></span><?php endforeach; ?><?php if ((int) $c['dnd_email'] === 1): ?> <span class="tag bad">unsubscribed</span><?php endif; ?></p>

<div class="grid2 stack">
 <div>
  <div class="card"><h2>Details</h2>
    <table class="tbl">
      <?php if ($c['email']): ?><tr><td class="muted">Email</td><td><a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a></td></tr><?php endif; ?>
      <?php if ($c['phone']): ?><tr><td class="muted">Phone</td><td><?= h($c['phone']) ?></td></tr><?php endif; ?>
      <?php if ($c['address']): ?><tr><td class="muted">Address</td><td><?= h($c['address']) ?></td></tr><?php endif; ?>
      <?php if ($c['source']): ?><tr><td class="muted">Source</td><td><?= h($c['source']) ?></td></tr><?php endif; ?>
      <tr><td class="muted">Owner</td><td><form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="do" value="owner">
        <select name="owner_id" aria-label="Owner" onchange="this.form.submit()" style="width:auto;max-width:100%;padding:6px 10px"><?= user_options((int) $c['owner_id']) ?></select><noscript><button class="btn ghost sm">Save</button></noscript></form></td></tr>
      <tr><td class="muted">Added</td><td><?= h(date('j M Y', (int) $c['created_at'])) ?></td></tr>
    </table>
  </div>
  <?= addon_hook('contact_panels', $c, $u) ?>
  <?php if ($c['email']): ?>
  <div class="card"><h2>Send an email</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="email">
      <label>Subject</label><input name="subject" required>
      <label>Message</label><textarea name="body" required></textarea>
      <button class="btn sm" style="margin-top:12px" <?= email_ready() ? '' : 'disabled title="Add an email account in Settings first"' ?>>Send</button>
      <?php if (!email_ready()): ?><span class="muted small" style="margin-left:8px">Connect an email account in <a href="<?= h(url('settings.php#email')) ?>">Settings</a>.</span><?php endif; ?>
    </form>
  </div>
  <?php endif; ?>
  <div class="card"><h2>Deals</h2>
    <?php if (!$deals): ?><p class="muted small">No deals. <a href="<?= h(url('pipelines.php')) ?>">Create one</a>.</p>
    <?php else: foreach ($deals as $d): $stages = jdec($d['stages']); ?>
      <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--line)"><span><a href="<?= h(url('pipelines.php?id=' . (int) $d['pipeline_id'] . '&deal=' . (int) $d['id'])) ?>"><?= h($d['name'] ?: 'Deal') ?></a>
        <?php if ($d['status'] === 'won'): ?><span class="tag ok">won</span><?php elseif ($d['status'] === 'lost'): ?><span class="tag bad">lost</span><?php else: ?><span class="muted small"><?= h($stages[(int) $d['stage']] ?? '') ?><?= $d['close_date'] !== '' ? ' &middot; closes ' . h(date('j M Y', strtotime($d['close_date']))) : '' ?></span><?php endif; ?></span><b><?= money((int) $d['value_cents']) ?></b></div>
    <?php endforeach; endif; ?>
  </div>
 </div>
 <div>
  <div class="card"><h2>Tasks</h2>
    <form method="post" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="do" value="task">
      <input name="title" placeholder="Add a task..." required style="flex:1;min-width:140px"><input type="date" name="due_date" style="width:auto">
      <select name="assigned_to" aria-label="Assign the task to" title="Assign the task to" style="width:auto;max-width:150px"><?= user_options((int) $u['id']) ?></select><button class="btn sm">Add</button></form>
    <?php if (!$tasks): ?><p class="muted small">No tasks yet.</p>
    <?php else: foreach ($tasks as $t): $done = $t['status'] === 'done'; $overdue = !$done && $t['due_date'] !== '' && $t['due_date'] < date('Y-m-d'); ?>
      <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--line)">
        <?php if (!$done): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="task_done"><input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>"><button class="btn ghost sm" style="padding:3px 8px" title="Mark done">&#10003;</button></form><?php else: ?><span style="color:var(--ok)">&#10003;</span><?php endif; ?>
        <span style="flex:1<?= $done ? ';text-decoration:line-through;color:var(--muted)' : '' ?>"><?= h($t['title']) ?></span>
        <?= $done ? who_chip((int) $t['assigned_to'], $team, 'Assigned to') : who_button((int) $t['assigned_to'], $team, ['do' => 'task_assign', 'task_id' => (int) $t['id']], 'assigned_to') ?>
        <?php if ($t['due_date']): ?><span class="tag <?= $overdue ? 'bad' : '' ?>"><?= h($t['due_date']) ?></span><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="card"><h2>Notes</h2>
    <form method="post" style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="do" value="note">
      <textarea name="body" placeholder="Add a note..." style="min-height:70px"></textarea><button class="btn sm" style="margin-top:8px">Add note</button></form>
    <?php foreach ($notes as $n): ?><div style="padding:8px 0;border-bottom:1px solid var(--line)"><?= nl2br(h($n['body'])) ?><div class="muted small" style="margin-top:3px"><?= h(ago((int) $n['created_at'])) ?></div></div><?php endforeach; ?>
  </div>
  <div class="card"><h2>Activity</h2>
    <?php if (!$acts): ?><p class="muted small">No activity yet.</p>
    <?php else: foreach ($acts as $a): ?><div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--line)"><span><?= h($actLabel[$a['type']] ?? $a['type']) ?><?php $m = jdec($a['meta']); if (!empty($m['subject'])): ?> <span class="muted small">&middot; <?= h($m['subject']) ?></span><?php endif; ?></span><span class="muted small"><?= h(ago((int) $a['created_at'])) ?></span></div><?php endforeach; endif; ?>
  </div>
 </div>
</div>
<?= pick_user_js() ?>
<?php foot();

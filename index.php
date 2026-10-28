<?php
/** CRMax Core - entry point: first-run install, login, and the dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$installed = user_count() > 0;

/* ---------------------------------------------------------------- install -- */
if (!$installed) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        post_guard();
        $name = trim((string) ($_POST['name'] ?? '')); $org = trim((string) ($_POST['org'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? ''))); $pass = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
        elseif (strlen($pass) < 8) $err = 'Choose a password of at least 8 characters.';
        elseif (user_count() > 0) $err = 'This CRM is already set up.';
        else {
            go_insert('users', ['email' => $email, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'name' => $name, 'role' => 'admin', 'status' => 'active', 'created_at' => time()]);
            org_set(['name' => $org !== '' ? $org : 'My Company', 'timezone' => date_default_timezone_get()]);
            do_login($email, $pass);
            flash_set('Welcome to ' . APP_NAME . '. Your CRM is ready.');
            redirect('index.php');
        }
    }
    pub_head('Set up');
    echo '<div class="pcard"><h1>Set up ' . h(APP_NAME) . '</h1><p class="pmuted">Create the administrator account. You can add your team afterwards.</p>';
    if ($err) echo '<div class="flash err" style="margin-top:12px">' . h($err) . '</div>';
    echo '<form method="post" style="margin-top:12px">' . csrf_field()
        . '<label>Your name</label><input name="name" required>'
        . '<label>Organization name</label><input name="org" placeholder="My Company" required>'
        . '<label>Email</label><input type="email" name="email" required>'
        . '<label>Password</label><input type="password" name="password" minlength="8" required>'
        . '<button class="pbtn" style="margin-top:16px">Create my CRM</button></form>';
    echo '</div>';
    pub_foot(); exit;
}

/* ------------------------------------------------------------------ login -- */
$u = current_user();
if (!$u || isset($_GET['login'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        post_guard();
        $lock = login_locked_secs();
        if ($lock > 0) $err = 'Too many attempts. Try again in ' . ceil($lock / 60) . ' minute(s).';
        elseif (do_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) { login_clear(); redirect('index.php'); }
        else { login_fail(); $err = 'Incorrect email or password.'; }
    }
    pub_head('Sign in');
    echo '<div class="pcard"><h1>Sign in</h1><p class="pmuted">Welcome back to ' . h(org_name()) . '.</p>';
    if ($err) echo '<div class="flash err" style="margin-top:12px">' . h($err) . '</div>';
    echo '<form method="post" style="margin-top:12px">' . csrf_field()
        . '<label>Email</label><input type="email" name="email" required>'
        . '<label>Password</label><input type="password" name="password" required>'
        . '<button class="pbtn" style="margin-top:16px">Sign in</button></form></div>';
    pub_foot(); exit;
}

/* -------------------------------------------------------------- dashboard -- */
$contacts = (int) db()->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$newWeek  = (int) db()->query("SELECT COUNT(*) FROM contacts WHERE created_at>" . (time() - 604800))->fetchColumn();
$openDeals = db()->query("SELECT COUNT(*) n, COALESCE(SUM(value_cents),0) v FROM deals WHERE status='open'")->fetch();
$td = db()->prepare("SELECT COUNT(*) FROM tasks WHERE status='open' AND due_date!='' AND due_date<=?"); $td->execute([date('Y-m-d')]); $tasksDue = (int) $td->fetchColumn();
$recent = db()->query("SELECT a.*, c.first_name, c.last_name, c.email FROM activities a LEFT JOIN contacts c ON c.id=a.contact_id ORDER BY a.id DESC LIMIT 12")->fetchAll();
$myTasks = db()->query("SELECT t.*, c.first_name, c.last_name, c.email FROM tasks t LEFT JOIN contacts c ON c.id=t.contact_id WHERE t.status='open' ORDER BY (t.due_date='') , t.due_date LIMIT 8")->fetchAll();

$actLabel = ['contact_created' => 'New contact', 'form_submitted' => 'Form submitted', 'workflow_email' => 'Workflow email sent', 'note_added' => 'Note added', 'deal_created' => 'Deal created', 'deal_won' => 'Deal won'];

head('Dashboard', 'dash', $u);
?>
<div class="pagehead"><h1>Good day, <?= h(explode(' ', $u['name'] ?: 'there')[0]) ?></h1>
  <div class="sp"><a class="btn" href="<?= h(url('contacts.php?new=1')) ?>">+ New contact</a></div></div>

<?php if (!email_ready()): ?>
  <div class="flash err">No email account connected yet. <a href="<?= h(url('settings.php#email')) ?>">Add your SMTP account</a> so the CRM can send campaigns and notifications.</div>
<?php endif; ?>

<div class="grid4" style="margin-bottom:16px">
  <div class="kpi"><div class="n"><?= number_format($contacts) ?></div><div class="l">Contacts</div></div>
  <div class="kpi"><div class="n"><?= number_format((int) $openDeals['n']) ?></div><div class="l">Open deals</div></div>
  <div class="kpi"><div class="n"><?= money((int) $openDeals['v']) ?></div><div class="l">Pipeline value</div></div>
  <div class="kpi"><div class="n"><?= number_format($tasksDue) ?></div><div class="l">Tasks due</div></div>
</div>

<div class="grid2">
  <div class="card">
    <h2>Recent activity</h2>
    <?php if (!$recent): ?><div class="empty"><div class="big">&#128075;</div>Nothing yet. Add a contact or publish a form to get started.</div>
    <?php else: ?><table class="tbl"><?php foreach ($recent as $a): ?>
      <tr><td><span class="tag"><?= h($actLabel[$a['type']] ?? $a['type']) ?></span></td>
          <td><?php $cn = $a['first_name'] || $a['last_name'] || $a['email'] ? contact_name($a) : ''; ?>
              <?php if ($cn && $a['contact_id']): ?><a href="<?= h(url('contact.php?id=' . (int) $a['contact_id'])) ?>"><?= h($cn) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
          <td class="muted small" style="text-align:right"><?= h(ago((int) $a['created_at'])) ?></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>
  <div class="card">
    <h2>Open tasks</h2>
    <?php if (!$myTasks): ?><div class="empty">No open tasks. <a href="<?= h(url('tasks.php')) ?>">Add one</a>.</div>
    <?php else: ?><table class="tbl"><?php foreach ($myTasks as $t): $overdue = $t['due_date'] !== '' && $t['due_date'] < date('Y-m-d'); ?>
      <tr><td><?= h($t['title']) ?><?php if ($t['contact_id'] && ($t['first_name'] || $t['email'])): ?> <span class="muted small">&middot; <a href="<?= h(url('contact.php?id=' . (int) $t['contact_id'])) ?>"><?= h(contact_name($t)) ?></a></span><?php endif; ?></td>
          <td style="text-align:right"><?php if ($t['due_date']): ?><span class="tag <?= $overdue ? 'bad' : '' ?>"><?= h($t['due_date']) ?></span><?php else: ?><span class="muted small">no date</span><?php endif; ?></td></tr>
    <?php endforeach; ?></table><?php endif; ?>
  </div>
</div>
<?php foot();

<?php
/** CRMax Core - Settings: organisation, team, email (SMTP) accounts, AI key, REST API, password. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';

    // Any signed-in user can change their own password.
    if ($do === 'passwd') {
        $cur = (string) ($_POST['current'] ?? ''); $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, $u['pass_hash'])) flash_set('Current password is incorrect.', 'err');
        elseif (strlen($new) < 8) flash_set('New password must be at least 8 characters.', 'err');
        else { db()->prepare("UPDATE users SET pass_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), (int) $u['id']]); flash_set('Password changed.'); }
        redirect('settings.php#account');
    }

    // Everything else is admin-only.
    if (!is_admin($u)) { http_response_code(403); exit('Admins only.'); }

    if ($do === 'org') {
        $o = org();
        $o['name'] = trim((string) ($_POST['name'] ?? $o['name'] ?? 'My Company')) ?: 'My Company';
        $o['timezone'] = in_array(($_POST['timezone'] ?? ''), timezone_identifiers_list(), true) ? $_POST['timezone'] : ($o['timezone'] ?? 'UTC');
        $o['notify_email'] = trim((string) ($_POST['notify_email'] ?? ''));
        org_set($o); flash_set('Organization saved.'); redirect('settings.php');
    }
    if ($do === 'add_user') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? ''))); $name = trim((string) ($_POST['uname'] ?? ''));
        $pass = (string) ($_POST['upass'] ?? ''); $role = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) flash_set('Enter a valid email and a password of 8+ characters.', 'err');
        elseif (user_by_email($email)) flash_set('A user with that email already exists.', 'err');
        else { go_insert('users', ['email' => $email, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'name' => $name, 'role' => $role, 'status' => 'active', 'created_at' => time()]); flash_set('Team member added.'); }
        redirect('settings.php#team');
    }
    if ($do === 'del_user') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $u['id']) flash_set('You cannot remove yourself.', 'err');
        elseif (($t = user_by_id($id)) && $t['role'] === 'admin' && (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn() <= 1) flash_set('There must be at least one admin.', 'err');
        else { go_del('users', $id); flash_set('Team member removed.'); }
        redirect('settings.php#team');
    }
    if ($do === 'email_save') {
        $id = (int) ($_POST['id'] ?? 0);
        $data = ['name' => trim((string) ($_POST['acc_name'] ?? '')), 'from_email' => trim((string) ($_POST['from_email'] ?? '')), 'from_name' => trim((string) ($_POST['from_name'] ?? '')),
            'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')), 'smtp_port' => max(1, (int) ($_POST['smtp_port'] ?? 587)),
            'smtp_secure' => in_array(($_POST['smtp_secure'] ?? 'tls'), ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls',
            'smtp_user' => trim((string) ($_POST['smtp_user'] ?? ''))];
        if (($_POST['smtp_pass'] ?? '') !== '') $data['smtp_pass'] = (string) $_POST['smtp_pass'];   // only overwrite when provided
        $makeDefault = isset($_POST['is_default']) || (int) db()->query("SELECT COUNT(*) FROM email_accounts")->fetchColumn() === 0;
        if ($id && email_account_get($id)) go_update('email_accounts', $id, $data);
        else { $data['created_at'] = time(); if (!isset($data['smtp_pass'])) $data['smtp_pass'] = ''; $id = go_insert('email_accounts', $data); }
        if ($makeDefault) { db()->exec("UPDATE email_accounts SET is_default=0"); db()->prepare("UPDATE email_accounts SET is_default=1 WHERE id=?")->execute([$id]); }
        flash_set('Email account saved.'); redirect('settings.php#email');
    }
    if ($do === 'email_default') { db()->exec("UPDATE email_accounts SET is_default=0"); db()->prepare("UPDATE email_accounts SET is_default=1 WHERE id=?")->execute([(int) ($_POST['id'] ?? 0)]); flash_set('Default sender updated.'); redirect('settings.php#email'); }
    if ($do === 'email_del') { go_del('email_accounts', (int) ($_POST['id'] ?? 0)); flash_set('Email account removed.'); redirect('settings.php#email'); }
    if ($do === 'email_test') {
        $acc = email_account_get((int) ($_POST['id'] ?? 0));
        if ($acc) { $res = smtp_send($acc, (string) $u['email'], 'CRMax Core test email', email_wrap('<p>This is a test email from <b>' . h(org_name()) . '</b>. If you received it, your SMTP settings work.</p>'));
            flash_set($res['ok'] ? 'Test email sent to ' . $u['email'] . '.' : 'Test failed: ' . $res['err'], $res['ok'] ? 'ok' : 'err'); }
        redirect('settings.php#email');
    }
    if ($do === 'ai') { setting_set('ai_key', trim((string) ($_POST['ai_key'] ?? ''))); setting_set('ai_model', trim((string) ($_POST['ai_model'] ?? 'claude-haiku-4-5')) ?: 'claude-haiku-4-5'); flash_set('AI settings saved.'); redirect('settings.php#ai'); }
    if ($do === 'api_reset') { api_token_reset(); flash_set('A new API token was generated.'); redirect('settings.php#api'); }
}

$o = org(); $accounts = email_accounts(); $team = users_all();
$tz = $o['timezone'] ?? 'UTC';
head('Settings', 'settings', $u);
?>
<div class="pagehead"><h1>Settings</h1></div>

<?php if (is_admin($u)): ?>
<div class="card"><h2>Organization</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="org">
    <div class="row"><div><label>Name</label><input name="name" value="<?= h($o['name'] ?? 'My Company') ?>"></div>
      <div><label>Timezone</label><select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option <?= $z === $tz ? 'selected' : '' ?>><?= h($z) ?></option><?php endforeach; ?></select></div></div>
    <label>Notifications email (where form alerts are sent; blank = first admin)</label><input name="notify_email" value="<?= h($o['notify_email'] ?? '') ?>" placeholder="you@company.com">
    <button class="btn sm" style="margin-top:14px">Save</button>
  </form>
</div>

<div class="card" id="email"><h2>Email accounts (SMTP)</h2>
  <p class="small muted">CRMax Core sends campaigns and notifications through your own mail server. Add the SMTP details from your email provider. Nothing is sent anywhere else.</p>
  <?php if ($accounts): ?><table class="tbl" style="margin:12px 0"><thead><tr><th>From</th><th>Host</th><th>Default</th><th></th></tr></thead><tbody>
    <?php foreach ($accounts as $a): ?><tr>
      <td><b><?= h($a['from_email']) ?></b><?= $a['from_name'] ? ' <span class="muted small">(' . h($a['from_name']) . ')</span>' : '' ?></td>
      <td class="muted small"><?= h($a['smtp_host']) ?>:<?= (int) $a['smtp_port'] ?> <?= h($a['smtp_secure']) ?></td>
      <td><?php if ((int) $a['is_default'] === 1): ?><span class="tag ok">default</span><?php else: ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="email_default"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn ghost sm">Make default</button></form><?php endif; ?></td>
      <td style="text-align:right;white-space:nowrap">
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="email_test"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn ghost sm">Test</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Remove this email account?')"><?= csrf_field() ?><input type="hidden" name="do" value="email_del"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn danger sm">&times;</button></form>
      </td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <details <?= $accounts ? '' : 'open' ?>><summary class="btn ghost sm" style="display:inline-block">+ Add / connect an email account</summary>
  <form method="post" style="margin-top:12px;max-width:560px"><?= csrf_field() ?><input type="hidden" name="do" value="email_save">
    <div class="row"><div><label>From email</label><input type="email" name="from_email" placeholder="you@company.com" required></div><div><label>From name</label><input name="from_name" placeholder="<?= h($o['name'] ?? 'My Company') ?>"></div></div>
    <div class="row"><div><label>SMTP host</label><input name="smtp_host" placeholder="smtp.gmail.com" required></div>
      <div class="grow0" style="flex:0 0 100px"><label>Port</label><input name="smtp_port" type="number" value="587"></div>
      <div class="grow0" style="flex:0 0 120px"><label>Security</label><select name="smtp_secure"><option value="tls">STARTTLS</option><option value="ssl">SSL</option><option value="none">None</option></select></div></div>
    <div class="row"><div><label>SMTP username</label><input name="smtp_user" placeholder="you@company.com" autocomplete="off"></div><div><label>SMTP password</label><input type="password" name="smtp_pass" autocomplete="new-password"></div></div>
    <label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="is_default" style="width:auto" checked> Use as the default sender</label>
    <button class="btn sm" style="margin-top:12px">Save account</button>
    <p class="small muted" style="margin-top:8px">Tip: for Gmail/Google Workspace use an App Password; most providers use port 587 (STARTTLS) or 465 (SSL).</p>
  </form></details>
</div>

<div class="card" id="team"><h2>Team</h2>
  <table class="tbl" style="margin-bottom:12px"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead><tbody>
    <?php foreach ($team as $m): ?><tr><td><?= h($m['name'] ?: '-') ?><?= (int) $m['id'] === (int) $u['id'] ? ' <span class="tag">you</span>' : '' ?></td><td class="muted"><?= h($m['email']) ?></td><td><span class="tag <?= $m['role'] === 'admin' ? 'ok' : '' ?>"><?= h($m['role']) ?></span></td>
      <td style="text-align:right"><?php if ((int) $m['id'] !== (int) $u['id']): ?><form method="post" onsubmit="return confirm('Remove this team member?')"><?= csrf_field() ?><input type="hidden" name="do" value="del_user"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn danger sm">Remove</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <details><summary class="btn ghost sm" style="display:inline-block">+ Add team member</summary>
  <form method="post" style="margin-top:12px;max-width:520px"><?= csrf_field() ?><input type="hidden" name="do" value="add_user">
    <div class="row"><div><label>Name</label><input name="uname"></div><div><label>Email</label><input type="email" name="email" required></div></div>
    <div class="row"><div><label>Temporary password</label><input name="upass" minlength="8" required></div><div class="grow0" style="flex:0 0 140px"><label>Role</label><select name="role"><option value="member">Member</option><option value="admin">Admin</option></select></div></div>
    <button class="btn sm" style="margin-top:12px">Add member</button></form></details>
</div>

<div class="card" id="ai"><h2>&#10024; AI (bring your own key)</h2>
  <p class="small muted">AI features (like drafting campaign emails) are optional and use your own Anthropic API key. No key is included and nothing runs without one you provide.</p>
  <form method="post" style="max-width:560px;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="do" value="ai">
    <label>Anthropic API key</label><input type="password" name="ai_key" value="<?= ai_key() !== '' ? '********************' : '' ?>" placeholder="sk-ant-..." autocomplete="off">
    <label>Model</label><input name="ai_model" value="<?= h(ai_model()) ?>">
    <button class="btn sm" style="margin-top:12px">Save</button>
    <?php if (ai_key() !== ''): ?><span class="tag ok" style="margin-left:8px">key set</span><?php endif; ?>
    <p class="small muted" style="margin-top:8px">Get a key at console.anthropic.com. Leave the key field blank to keep the current one; type a new value to replace it.</p>
  </form>
</div>

<div class="card" id="api"><h2>REST API</h2>
  <p class="small muted">A simple JSON API for integrations. Send the token as a Bearer header. See the README for endpoints.</p>
  <label>API token</label><input class="mono" readonly value="<?= h(api_token()) ?>" onclick="this.select()">
  <div style="margin-top:10px"><form method="post" onsubmit="return confirm('Rotating the token breaks existing integrations. Continue?')"><?= csrf_field() ?><input type="hidden" name="do" value="api_reset"><button class="btn ghost sm">Regenerate token</button></form></div>
  <p class="small mono" style="margin-top:10px;color:var(--muted)">curl -H "Authorization: Bearer &lt;token&gt;" <?= h(base_url()) ?>/api.php/contacts</p>
</div>
<?php endif; /* admin */ ?>

<div class="card" id="account"><h2>Your account</h2>
  <form method="post" style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="do" value="passwd">
    <label>Current password</label><input type="password" name="current" required>
    <label>New password</label><input type="password" name="new" minlength="8" required>
    <button class="btn sm" style="margin-top:12px">Change password</button>
  </form>
</div>
<?php foot();

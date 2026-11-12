<?php
/**
 * CRMax Core - Two-factor sign-in for your own account: turn it on with an authenticator app, save
 * your recovery codes, make new ones, move to a new phone, turn it off.
 */
declare(strict_types=1);
define('CRMAX_TWOFA_PAGE', true);   // people who must set it up are sent here, so this page must not send them on again
require_once __DIR__ . '/lib.php';
$u = require_login();
$uid = (int) $u['id'];
$on = (int) $u['totp_enabled'] === 1;
$must = twofa_must_enrol($u);
sess_start();
// A set-up that was started and not finished: the new secret waits in the session, for 15 minutes, and
// is only saved to the account once a code from it has been typed back.
$new = $_SESSION['twofa_new'] ?? null;
if (!is_array($new) || (int) ($new['at'] ?? 0) < time() - 900 || !is_string($new['secret'] ?? null)) { $new = null; unset($_SESSION['twofa_new']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    $back = function (string $msg, string $kind = 'err', string $to = 'twofa.php') { flash_set($msg, $kind); redirect($to); };
    if ($do === 'cancel') { unset($_SESSION['twofa_new']); redirect($must ? 'twofa.php' : 'settings.php#account'); }
    if (!rl_hit('twofa' . $uid, 600, 15)) $back('Too many attempts. Try again in a few minutes.');
    $passOk = password_verify((string) ($_POST['password'] ?? ''), (string) $u['pass_hash']);

    if ($do === 'begin') {   // start a set-up; when it is already on, this is "move to a new phone" and needs a current code too
        if (!$passOk) $back('That is not your password.');
        if ($on && twofa_check($u, (string) ($_POST['code'] ?? '')) === '') $back('That code is not right. Use a current code from your authenticator app, or a recovery code.');
        $_SESSION['twofa_new'] = ['secret' => b32_encode(random_bytes(20)), 'at' => time()];
        redirect('twofa.php');
    }
    if ($do === 'confirm') {
        if (!$new) $back('That set-up timed out. Start again.');
        $secret = b32_decode($new['secret']);
        $step = totp_match($secret, (string) preg_replace('/\s+/', '', (string) ($_POST['code'] ?? '')));
        if (!$step) $back('That code is not right. Type the 6 digits your app shows now for this account.');
        $codes = twofa_enable($uid, $secret, $step);
        unset($_SESSION['twofa_new']);
        audit('2fa_enabled', $on ? 'Set up two-factor sign-in again, with a new authenticator' : 'Turned on two-factor sign-in', 'users', $uid);
        $_SESSION['twofa_codes'] = $codes;
        redirect('twofa.php?codes=1');
    }
    if ($do === 'codes' && $on) {
        if (!$passOk) $back('That is not your password.');
        if (twofa_check($u, (string) ($_POST['code'] ?? '')) === '') $back('That code is not right. Use a current code from your authenticator app, or a recovery code.');
        $_SESSION['twofa_codes'] = recovery_codes_reset($uid);
        audit('2fa_codes', 'Made new recovery codes', 'users', $uid);
        redirect('twofa.php?codes=1');
    }
    if ($do === 'disable' && $on) {
        if (twofa_required()) $back('Your team requires two-factor sign-in, so it cannot be turned off. You can move it to a new phone instead.');
        if (!$passOk) $back('That is not your password.');
        if (twofa_check($u, (string) ($_POST['code'] ?? '')) === '') $back('That code is not right. Use a current code from your authenticator app, or a recovery code.');
        twofa_disable($uid);
        audit('2fa_disabled', 'Turned off two-factor sign-in', 'users', $uid);
        $back('Two-factor sign-in is off. Your other sessions were signed out.', 'ok', 'settings.php#account');
    }
    redirect('twofa.php');
}

header('Cache-Control: no-store');   // this page can show a secret or recovery codes: never keep a copy

/* ---------- the recovery codes, shown once ---------- */
if (isset($_GET['codes'])) {
    $codes = $_SESSION['twofa_codes'] ?? null;
    unset($_SESSION['twofa_codes']);
    if (!is_array($codes) || !$codes) redirect('twofa.php');
    head('Recovery codes', 'settings', $u);
    ?>
    <div class="pagehead"><h1>Save your recovery codes</h1></div>
    <div class="card" style="max-width:640px">
      <p>Two-factor sign-in is on. If you ever cannot use your authenticator app, one of these codes signs you in instead. <b>Each code works once, and this is the only time they are shown.</b></p>
      <pre class="mono" id="rcodes" style="margin:14px 0;padding:14px 16px;background:var(--surface2);border:1px solid var(--line);border-radius:12px;font-size:.95rem;line-height:1.9;columns:2;white-space:pre-wrap"><?= h(implode("\n", $codes)) ?></pre>
      <p class="small muted">Keep them somewhere safe that is not the phone with your authenticator app: a password manager, or printed and locked away. Your other sessions were signed out.</p>
      <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn ghost" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('rcodes').textContent).then(()=>{this.textContent='Copied'})">Copy</button>
        <button type="button" class="btn ghost" onclick="window.print()">Print</button>
        <a class="btn" href="<?= h(url('settings.php#account')) ?>">I have saved them</a>
      </div>
    </div>
    <?php foot(); exit;
}

/* ---------- a set-up in progress: scan, then type a code back ---------- */
if ($new) {
    require_once __DIR__ . '/qr.php';
    $qr = qr_svg(totp_uri($new['secret'], (string) $u['email']), 208, 'QR code to scan with your authenticator app');
    head('Two-factor sign-in', 'settings', $u);
    ?>
    <div class="pagehead"><h1>Set up two-factor sign-in</h1></div>
    <div class="card" style="max-width:640px">
      <p><b>1.</b> Install an authenticator app on your phone if you do not have one: Google Authenticator, Microsoft Authenticator, Authy and most password managers all work.</p>
      <p style="margin-top:8px"><b>2.</b> In the app, add an account and scan this code.</p>
      <?php if ($qr !== ''): ?><div style="margin:14px 0;line-height:0"><?= $qr ?></div><?php endif; ?>
      <p class="small muted"><?= $qr !== '' ? 'Cannot scan it? Add the account by hand with this key' : 'Add the account by hand with this key' ?> (time-based, 6 digits):</p>
      <p class="mono" style="margin:6px 0 4px;font-size:.95rem;letter-spacing:.04em"><?= h(trim(chunk_split($new['secret'], 4, ' '))) ?></p>
      <p style="margin-top:14px"><b>3.</b> Type the 6-digit code the app now shows.</p>
      <form method="post" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="do" value="confirm">
        <span style="flex:0 0 160px"><label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus></span>
        <button class="btn">Turn on two-factor sign-in</button>
      </form>
      <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><button class="btn ghost sm">Cancel</button></form>
      <p class="small muted" style="margin-top:12px">The code and key are made on this server and are not sent anywhere else.<?= $on ? ' Until you finish, your current authenticator keeps working.' : '' ?></p>
    </div>
    <?php foot(); exit;
}

/* ---------- on: what you can do with it ---------- */
head('Two-factor sign-in', 'settings', $u);
if ($on):
    $left = count(jdec($u['recovery_codes']));
    $proof = '<div class="row"><div><label>Your password</label><input type="password" name="password" autocomplete="current-password" required></div>'
        . '<div><label>A code from your app, or a recovery code</label><input name="code" autocomplete="one-time-code" maxlength="20" required></div></div>';
    ?>
    <div class="pagehead"><a href="<?= h(url('settings.php#account')) ?>" class="muted">&larr; Settings</a></div>
    <div class="card" style="max-width:640px"><h2>Two-factor sign-in <span class="tag ok">on</span></h2>
      <p>Signing in to <?= h($u['email']) ?> takes your password and a code from your authenticator app.</p>
      <p style="margin-top:8px">Recovery codes left: <b><?= $left ?></b> of 10.<?= $left <= 2 ? ' <span class="tag bad">make new ones</span>' : '' ?></p>
    </div>
    <div class="card" style="max-width:640px"><h2>New recovery codes</h2>
      <p class="small muted">Makes 10 new codes. The ones you have now stop working.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="codes"><?= $proof ?><button class="btn sm" style="margin-top:12px">Make new recovery codes</button></form>
    </div>
    <div class="card" style="max-width:640px"><h2>New phone or new app</h2>
      <p class="small muted">Sets two-factor sign-in up again from the start. Your current authenticator keeps working until the new one is confirmed.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="begin"><?= $proof ?><button class="btn sm" style="margin-top:12px">Set up again</button></form>
    </div>
    <?php if (!twofa_required()): ?>
    <div class="card" style="max-width:640px"><h2>Turn off</h2>
      <p class="small muted">Your password alone will sign you in again.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="disable"><?= $proof ?><button class="btn danger sm" style="margin-top:12px">Turn off two-factor sign-in</button></form>
    </div>
    <?php else: ?><p class="small muted" style="max-width:640px">Your team requires two-factor sign-in, so it cannot be turned off here.</p><?php endif; ?>
<?php else: /* ---------- off: turn it on ---------- */ ?>
    <div class="pagehead"><?php if (!$must): ?><a href="<?= h(url('settings.php#account')) ?>" class="muted">&larr; Settings</a><?php else: ?><h1>Two-factor sign-in</h1><?php endif; ?></div>
    <?php if ($must): ?><div class="flash err">Your team requires two-factor sign-in. Set it up to carry on: it takes about a minute.</div><?php endif; ?>
    <div class="card" style="max-width:640px"><h2>Two-factor sign-in <span class="tag">off</span></h2>
      <p>With two-factor sign-in, your password is not enough on its own: signing in also takes a 6-digit code from an authenticator app on your phone. A stolen or guessed password then opens nothing.</p>
      <form method="post" style="margin-top:12px;max-width:340px"><?= csrf_field() ?><input type="hidden" name="do" value="begin">
        <label>Your password, to start</label><input type="password" name="password" autocomplete="current-password" required>
        <button class="btn" style="margin-top:12px">Set up two-factor sign-in</button>
      </form>
    </div>
<?php endif;
foot();

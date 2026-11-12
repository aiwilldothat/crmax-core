<?php
/**
 * CRMax Core - getting back in when you are locked out. Command line only: run it on the server.
 *
 *   php recover.php                       list the accounts (email, role, two-factor on or off)
 *   php recover.php 2fa-off <email>       turn two-factor sign-in off for that account
 *   php recover.php password <email>      give that account a new random password, printed once
 *
 * An admin can already reset another person's two-factor sign-in in Settings > Team. This is for when
 * nobody can: the only admin has lost their phone and their recovery codes, or forgotten their password.
 * Whoever can run commands on the server already has the whole database, so this opens nothing new.
 * Both commands sign the account out everywhere and are recorded in the audit log as "Command line".
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

$cmd = (string) ($argv[1] ?? ''); $email = mb_strtolower(trim((string) ($argv[2] ?? '')));
$say = function (string $line): void { fwrite(STDOUT, $line . "\n"); };

if ($cmd === '') {
    $say(APP_NAME . ' ' . APP_VERSION . ' - accounts:');
    foreach (users_all() as $x) $say(sprintf('  %-40s %-7s two-factor %s%s', $x['email'], $x['role'], (int) $x['totp_enabled'] === 1 ? 'on' : 'off', $x['status'] !== 'active' ? '  (' . $x['status'] . ')' : ''));
    if (!users_all()) $say('  (none yet: open the CRM in a browser to set it up)');
    $say('');
    $say('  php recover.php 2fa-off <email>     turn two-factor sign-in off for that account');
    $say('  php recover.php password <email>    give that account a new random password');
    exit(0);
}
if (!in_array($cmd, ['2fa-off', 'password'], true) || $email === '') { fwrite(STDERR, "Usage: php recover.php [2fa-off|password] <email>\n"); exit(2); }
$t = user_by_email($email);
if (!$t) { fwrite(STDERR, "No account with the email address $email. Run php recover.php to list them.\n"); exit(1); }
$tid = (int) $t['id'];

if ($cmd === '2fa-off') {
    if ((int) $t['totp_enabled'] !== 1) { $say("Two-factor sign-in is already off for $email. Nothing changed."); exit(0); }
    twofa_disable($tid);
    audit('2fa_reset', 'Turned off two-factor sign-in for ' . user_label($t) . ' (' . $t['email'] . ') from the server', 'users', $tid);
    $say("Two-factor sign-in is off for $email. They sign in with their password alone and were signed out everywhere.");
    if (twofa_required()) $say('Two-factor sign-in is required for this team, so they will be asked to set it up again when they sign in.');
    exit(0);
}

$new = '';
$abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
for ($i = 0; $i < 16; $i++) $new .= $abc[random_int(0, strlen($abc) - 1)];
db()->prepare("UPDATE users SET pass_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $tid]);
sessions_revoke($tid, false);
audit('password_reset', 'Reset the password of ' . user_label($t) . ' (' . $t['email'] . ') from the server', 'users', $tid);
addon_hook('password_changed', $tid);
$say("New password for $email: $new");
$say('It is shown this once. Sign in with it, then choose your own under Settings > Your account.' . ((int) $t['totp_enabled'] === 1 ? ' Two-factor sign-in is still on for this account.' : ''));
exit(0);

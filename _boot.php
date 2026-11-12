<?php
/**
 * CRMax Core - a free, self-hosted CRM.
 * Single PHP + SQLite application. No framework, no Composer, no build step.
 * Point any PHP 8.1+ web server at this folder and open it in a browser: the database
 * installs itself on the first request and the first person to register becomes the admin.
 *
 * This file is the core: database schema + migrations, authentication, sessions, CSRF,
 * rate limiting, small helpers, and the page layout. Everything is configured in the app
 * (Settings) and stored in the database - there are no secrets in the code.
 *
 * MIT licensed. https://crmax.au
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ config -- */
// Optional overrides live in config.php (copy config.sample.php). Nothing here is required.
$CFG = is_file(__DIR__ . '/config.php') ? (include __DIR__ . '/config.php') : [];
if (!is_array($CFG)) $CFG = [];

const APP_VERSION = '1.3.0';
define('APP_NAME', (string) ($CFG['app_name'] ?? 'CRMax Core'));
define('DATA_DIR', (string) ($CFG['data_dir'] ?? (__DIR__ . '/data')));
define('DB_FILE', (string) ($CFG['db_file'] ?? (DATA_DIR . '/crm.sqlite')));
define('DEV', (bool) ($CFG['dev'] ?? (PHP_SAPI === 'cli-server')));
// Amounts show as "$1,234.00 USD": config.php 'currency' is the code after the amount ('' shows none) and
// 'currency_symbol' the sign before it (e.g. 'EUR' and the euro sign).
// New constants get a CRMAX_ prefix: PHP itself defines some generic names, and only on some systems.
// 1.2.0 called this one CURRENCY_SYMBOL, which PHP on Linux with glibc already has (an nl_langinfo() item,
// 262145), so define() failed there and every amount printed as "262145" followed by the number.
define('CURRENCY', strtoupper(trim((string) ($CFG['currency'] ?? 'USD'))));
define('CRMAX_CURRENCY_SYMBOL', (string) ($CFG['currency_symbol'] ?? '$'));
// config.php 'allow_install' => false: while there are no accounts, the first visitor gets "Not set up yet"
// instead of the set-up form that makes them the admin.
define('ALLOW_INSTALL', ($CFG['allow_install'] ?? true) !== false);
define('SESSION_IDLE', 1209600);        // 14 days
const LOGIN_MAX_FAILS = 6;
const LOGIN_LOCK_SECS = 600;

// A config.php 'timezone' fixes the timezone. Without one, the timezone chosen in Settings > Organization
// applies as soon as the database is open (tz_apply()); UTC until then.
date_default_timezone_set((string) ($CFG['timezone'] ?? 'UTC'));
define('TZ_FROM_CONFIG', trim((string) ($CFG['timezone'] ?? '')) !== '');
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0750, true);
// The data folder holds the database and sessions. On Apache it is kept private by an .htaccess file;
// write one if it is missing (a git clone or a partial upload may not carry it). nginx needs its own deny rule.
if (is_dir(DATA_DIR) && !is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "# Block all web access to the database + sessions (Apache). nginx users: deny /data in the server block.\nRequire all denied\nDeny from all\n");
}

/* URL base path, so the app works installed at the web root OR in a subfolder, on any host,
 * with no rewrite rules (plain .php URLs). e.g. SCRIPT_NAME /free/contacts.php -> BASE "/free".
 * The command line (php cron.php, which sends campaign and workflow email) has no web request to learn
 * the address from, so there config.php 'base_url' (e.g. https://example.com/crm) supplies it. Without
 * it, unsubscribe and open-tracking links in that email would point at http://localhost.
 * BASE_URL_CFG is that address without a trailing slash: set only on the command line, and only when it
 * is a full http(s) address. */
$__base_url = PHP_SAPI === 'cli' ? rtrim(trim((string) ($CFG['base_url'] ?? '')), '/') : '';
define('BASE_URL_CFG', preg_match('#^https?://[^/\s?\#@]+(/[^\s?\#]*)?$#i', $__base_url) ? $__base_url : '');
unset($__base_url);
define('BASE', BASE_URL_CFG !== '' ? rtrim((string) parse_url(BASE_URL_CFG, PHP_URL_PATH), '/') : rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/'));
function url(string $p = ''): string { return BASE . '/' . ltrim($p, '/'); }
function base_url(): string {
    if (BASE_URL_CFG !== '') return BASE_URL_CFG;
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https://' : 'http://') . $host . BASE;
}
function redirect(string $p): void { header('Location: ' . (preg_match('#^https?://#', $p) ? $p : url($p))); exit; }

/* ------------------------------------------------------------------ DB ------ */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $fresh = !is_file(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    if ($fresh) @chmod(DB_FILE, 0640);
    db_migrate($pdo);
    tz_apply();
    return $pdo;
}
function db_migrate(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    $t = [
        // Team members. role: admin | member. The first to register becomes admin.
        'users' => "id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, pass_hash TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '', role TEXT NOT NULL DEFAULT 'member', status TEXT NOT NULL DEFAULT 'active',
            created_at INTEGER NOT NULL, last_login INTEGER NOT NULL DEFAULT 0",
        // Key/value: org profile, app secret, API tokens, BYO AI key (all set in the app).
        'settings' => "k TEXT PRIMARY KEY, v TEXT NOT NULL",
        // Outbound email accounts (bring your own SMTP). Passwords are stored for the server to log in.
        'email_accounts' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '',
            from_email TEXT NOT NULL DEFAULT '', from_name TEXT NOT NULL DEFAULT '',
            smtp_host TEXT NOT NULL DEFAULT '', smtp_port INTEGER NOT NULL DEFAULT 587,
            smtp_secure TEXT NOT NULL DEFAULT 'tls', smtp_user TEXT NOT NULL DEFAULT '',
            smtp_pass TEXT NOT NULL DEFAULT '', is_default INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL",
        'contacts' => "id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT NOT NULL DEFAULT '', last_name TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', company TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '[]', custom TEXT NOT NULL DEFAULT '{}',
            source TEXT NOT NULL DEFAULT '', owner_id INTEGER NOT NULL DEFAULT 0, dnd_email INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0",
        // Companies your contacts work for. contacts.company keeps a text copy of the linked company's name.
        'companies' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', domain TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '', website TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '',
            industry TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', custom TEXT NOT NULL DEFAULT '{}',
            owner_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0",
        'notes' => "id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER NOT NULL, user_id INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL",
        'tasks' => "id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '',
            due_date TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'open', assigned_to INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL",
        'activities' => "id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT '',
            meta TEXT NOT NULL DEFAULT '{}', created_at INTEGER NOT NULL",
        'pipelines' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', stages TEXT NOT NULL DEFAULT '[]',
            created_at INTEGER NOT NULL",
        'deals' => "id INTEGER PRIMARY KEY AUTOINCREMENT, pipeline_id INTEGER NOT NULL, contact_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL DEFAULT '', stage INTEGER NOT NULL DEFAULT 0, value_cents INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'open', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0",
        'forms' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', slug TEXT NOT NULL DEFAULT '',
            fields TEXT NOT NULL DEFAULT '[]', thankyou TEXT NOT NULL DEFAULT 'Thanks - we will be in touch shortly.',
            notify INTEGER NOT NULL DEFAULT 1, redirect TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active',
            submits INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL",
        'form_submissions' => "id INTEGER PRIMARY KEY AUTOINCREMENT, form_id INTEGER NOT NULL, contact_id INTEGER NOT NULL DEFAULT 0,
            data TEXT NOT NULL DEFAULT '{}', created_at INTEGER NOT NULL",
        'campaigns' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '', from_account INTEGER NOT NULL DEFAULT 0, segment TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'draft', scheduled_at INTEGER NOT NULL DEFAULT 0, sent_count INTEGER NOT NULL DEFAULT 0,
            open_count INTEGER NOT NULL DEFAULT 0, fail_count INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL",
        'campaign_recipients' => "id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, contact_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', opened INTEGER NOT NULL DEFAULT 0, sent_at INTEGER NOT NULL DEFAULT 0",
        'workflows' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', trigger_on TEXT NOT NULL DEFAULT '',
            config TEXT NOT NULL DEFAULT '{}', steps TEXT NOT NULL DEFAULT '[]', status TEXT NOT NULL DEFAULT 'paused',
            enrolled_count INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL",
        'wf_enrollments' => "id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER NOT NULL, contact_id INTEGER NOT NULL,
            step INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'active',
            ctx TEXT NOT NULL DEFAULT '{}', created_at INTEGER NOT NULL",
        'templates' => "id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL",
        // Who did what, and from where (see audit()). Lines are only ever added, and pruned after 12 months.
        // user_id is the signed-in person (0 for the API, the command line, or a sign-in that failed);
        // actor is their name as it was at the time, so the line still reads right after they have left.
        'audit_log' => "id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL DEFAULT 0, actor TEXT NOT NULL DEFAULT '',
            action TEXT NOT NULL DEFAULT '', entity TEXT NOT NULL DEFAULT '', entity_id INTEGER NOT NULL DEFAULT 0,
            summary TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL",
    ];
    foreach ($t as $name => $cols) $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($cols)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contacts_upd ON contacts(updated_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_acts_contact ON activities(contact_id, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_deals_pipe ON deals(pipeline_id, stage)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_enroll_due ON wf_enrollments(status, next_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_recip ON campaign_recipients(campaign_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status, due_date)");

    if (setting_get('app_secret') === null) setting_set('app_secret', bin2hex(random_bytes(32)));

    // Versioned upgrades. The tables above are the v1.0 shape; later versions only ever add to it.
    // A v1.0 database has no schema_version row and counts as version 1.
    if ((int) (setting_get('schema_version') ?? '1') < 2) migrate_v2($pdo);
    if ((int) (setting_get('schema_version') ?? '1') < 3) migrate_v3($pdo);
    if ((int) (setting_get('schema_version') ?? '1') < 4) migrate_v4($pdo);
}
/**
 * v1.1 (schema 2): company records, richer deals (expected close date, win probability, owner,
 * notes, lost reason) and a win probability per pipeline stage. Additive only - no existing column
 * changes. Safe to run twice, and safe when two requests arrive together: the transaction makes the
 * second one wait, then skip.
 */
function migrate_v2(PDO $pdo): void {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ((int) (setting_get('schema_version') ?? '1') >= 2) { $pdo->exec('COMMIT'); return; }
        $add = function (string $tbl, array $cols) use ($pdo): void {
            $have = [];
            foreach ($pdo->query("PRAGMA table_info($tbl)")->fetchAll() as $c) $have[$c['name']] = true;
            foreach ($cols as $col => $def) if (!isset($have[$col])) $pdo->exec("ALTER TABLE $tbl ADD COLUMN $col $def");
        };
        $add('contacts', ['company_id' => "INTEGER NOT NULL DEFAULT 0"]);
        $add('deals', [
            'company_id'  => "INTEGER NOT NULL DEFAULT 0",
            'close_date'  => "TEXT NOT NULL DEFAULT ''",      // expected close, YYYY-MM-DD
            'probability' => "INTEGER NOT NULL DEFAULT -1",   // 0-100, or -1 = use the stage's default
            'owner_id'    => "INTEGER NOT NULL DEFAULT 0",
            'closed_at'   => "INTEGER NOT NULL DEFAULT 0",    // when it was marked won or lost
            'lost_reason' => "TEXT NOT NULL DEFAULT ''",
            'notes'       => "TEXT NOT NULL DEFAULT ''",
        ]);
        $add('pipelines', ['stage_probs' => "TEXT NOT NULL DEFAULT '[]'"]);   // win % per stage, aligned to stages
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contacts_company ON contacts(company_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_deals_company ON deals(company_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_deals_close ON deals(status, close_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_companies_name ON companies(name)");

        // Backfill 1: every distinct company name already typed on a contact becomes a company record.
        $ids = [];
        foreach ($pdo->query("SELECT id, name FROM companies")->fetchAll() as $r) $ids[mb_strtolower(trim((string) $r['name']))] = (int) $r['id'];
        $ins = $pdo->prepare("INSERT INTO companies (name, created_at, updated_at) VALUES (?,?,?)");
        $upd = $pdo->prepare("UPDATE contacts SET company_id=? WHERE id=?");
        foreach ($pdo->query("SELECT id, company FROM contacts WHERE company_id=0 AND TRIM(company)!='' ORDER BY id")->fetchAll() as $c) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) $c['company']));
            $key = mb_strtolower($name);
            if (!isset($ids[$key])) { $ins->execute([$name, time(), time()]); $ids[$key] = (int) $pdo->lastInsertId(); }
            $upd->execute([$ids[$key], (int) $c['id']]);
        }
        // Backfill 2: deals already won or lost get a closed time (their last change); deals take their contact's company.
        $pdo->exec("UPDATE deals SET closed_at = CASE WHEN updated_at>0 THEN updated_at ELSE created_at END WHERE status IN ('won','lost') AND closed_at=0");
        $pdo->exec("UPDATE deals SET company_id = COALESCE((SELECT c.company_id FROM contacts c WHERE c.id=deals.contact_id), 0) WHERE company_id=0 AND contact_id>0");

        setting_set('schema_version', '2');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
/**
 * v1.2 (schema 3): ownership and assignment. Contacts, companies and deals already have an owner
 * and tasks an assignee, so this only adds who created each task and the indexes behind the
 * "Mine / a person" filters. It also tidies up after earlier versions, where removing a team member
 * left their records pointing at nobody: those records become unassigned, so they show under
 * "Unassigned" and can be handed to someone. Additive, safe to run twice, safe under two requests at once.
 */
function migrate_v3(PDO $pdo): void {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ((int) (setting_get('schema_version') ?? '1') >= 3) { $pdo->exec('COMMIT'); return; }
        $have = [];
        foreach ($pdo->query("PRAGMA table_info(tasks)")->fetchAll() as $c) $have[$c['name']] = true;
        if (!isset($have['created_by'])) $pdo->exec("ALTER TABLE tasks ADD COLUMN created_by INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contacts_owner ON contacts(owner_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_deals_owner ON deals(owner_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tasks_assignee ON tasks(assigned_to, status)");

        // Until now a task could only be assigned to the person who added it, so that person is its creator.
        $pdo->exec("UPDATE tasks SET created_by=assigned_to WHERE created_by=0 AND assigned_to>0");
        // Owners and assignees who are no longer on the team: the record becomes unassigned.
        foreach (['contacts' => 'owner_id', 'companies' => 'owner_id', 'deals' => 'owner_id', 'tasks' => 'assigned_to'] as $tbl => $col) {
            $pdo->exec("UPDATE $tbl SET $col=0 WHERE $col!=0 AND $col NOT IN (SELECT id FROM users)");
        }

        setting_set('schema_version', '3');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
/**
 * v1.3 (schema 4): two-factor sign-in and the audit log. Each user gains what two-factor sign-in needs
 * (the authenticator secret, whether it is on, the last code used, the recovery codes) and a session
 * version, which "Sign out my other sessions" moves on. The audit_log table itself is created with the
 * other tables above; this adds its indexes. Nothing existing changes: every account starts with
 * two-factor off, and sessions that were open before the upgrade stay signed in. Additive, safe to run
 * twice, safe under two requests at once.
 */
function migrate_v4(PDO $pdo): void {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ((int) (setting_get('schema_version') ?? '1') >= 4) { $pdo->exec('COMMIT'); return; }
        $have = [];
        foreach ($pdo->query("PRAGMA table_info(users)")->fetchAll() as $c) $have[$c['name']] = true;
        foreach ([
            'totp_secret'     => "TEXT NOT NULL DEFAULT ''",      // the authenticator secret, sealed (see secret_seal())
            'totp_enabled'    => "INTEGER NOT NULL DEFAULT 0",
            'totp_last_step'  => "INTEGER NOT NULL DEFAULT 0",    // the 30-second step of the last code accepted: a code works once
            'recovery_codes'  => "TEXT NOT NULL DEFAULT '[]'",    // hashes of the unused recovery codes
            'session_version' => "INTEGER NOT NULL DEFAULT 0",    // a session signed in under an earlier number is no longer accepted
        ] as $col => $def) if (!isset($have[$col])) $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_log(created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log(action, id)");

        setting_set('schema_version', '4');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/* --------------------------------------------------------------- settings -- */
function setting_get(string $k, ?string $default = null): ?string {
    $st = db()->prepare("SELECT v FROM settings WHERE k=?"); $st->execute([$k]);
    $v = $st->fetchColumn(); return $v === false ? $default : (string) $v;
}
function setting_set(string $k, string $v): void {
    db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$k, $v]);
}
function app_secret(): string { return (string) setting_get('app_secret', 'x'); }
/** Org profile (name, timezone, reply_to...) stored as one JSON settings row. */
function org(): array { $j = json_decode((string) setting_get('org', '{}'), true); return is_array($j) ? $j : []; }
function org_set(array $o): void { setting_set('org', json_encode($o, JSON_UNESCAPED_SLASHES)); }
function org_name(): string { return (string) (org()['name'] ?? APP_NAME); }
/** Use the organisation's timezone (Settings > Organization), unless config.php fixes one. Runs once, from db(). */
function tz_apply(): void {
    if (TZ_FROM_CONFIG) return;
    $tz = (string) (org()['timezone'] ?? '');
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);
}

/* ------------------------------------------------------------ cron status -- */
/** Note that cron.php just ran: when, how ($via 'cli' or 'web'), and whether the links in its email can work. */
function cron_record(string $via): void {
    $links = $via === 'cli' ? BASE_URL_CFG : base_url();
    setting_set('cron_last', json_encode(['at' => time(), 'via' => $via, 'base_url' => $links,
        'links_ok' => $links !== '' && !url_is_local($links)], JSON_UNESCAPED_SLASHES));
}
/** The last cron.php run: ['at' => int, 'via' => 'cli'|'web', 'base_url' => string, 'links_ok' => bool], or [] before the first. */
function cron_last(): array { return jdec(setting_get('cron_last', '')); }
/** Why links in email sent by cron.php cannot work (unsubscribe links included), or '' when they can or cron has not run. */
function cron_links_problem(): string {
    $last = cron_last();
    if (!$last || !empty($last['links_ok'])) return '';
    if (($last['via'] ?? '') === 'cli') return "cron.php runs from the command line and config.php has no public 'base_url', so the links in the email it sends, unsubscribe links included, point at this server's own address and cannot work.";
    return 'cron.php is called at ' . ($last['base_url'] ?? '') . ', a local address, so the links in the email it sends, unsubscribe links included, point there and cannot work.';
}
/** True when $url points at this machine (localhost, 127.x.x.x, ::1): nobody reading an email can open it. */
function url_is_local(string $url): bool {
    $h = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    if ($h === '' || $h === 'localhost' || str_ends_with($h, '.localhost')) return true;
    return $h === '::1' || (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($h, '127.'));
}

/* ------------------------------------------------------------------ users -- */
function user_by_email(string $e): ?array { $st = db()->prepare("SELECT * FROM users WHERE email=?"); $st->execute([mb_strtolower(trim($e))]); return $st->fetch() ?: null; }
function user_by_id(int $id): ?array { $st = db()->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$id]); return $st->fetch() ?: null; }
function users_all(): array { return db()->query("SELECT * FROM users ORDER BY id")->fetchAll(); }
function user_count(): int { return (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn(); }
function is_admin(?array $u): bool { return $u && ($u['role'] ?? '') === 'admin'; }
/** How a team member is shown in the app: their name, or their email address when no name was entered. */
function user_label(?array $u): string { return $u ? ((string) ($u['name'] ?? '') !== '' ? (string) $u['name'] : (string) ($u['email'] ?? '')) : ''; }
/** The id of a team member who exists and may be given records, or 0. */
function valid_user_id(int $id): int { return $id > 0 && user_by_id($id) ? $id : 0; }

/* ------------------------------------------------------------- sessions ---- */
function sess_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('CRMSESS');
    $sp = DATA_DIR . '/sessions';
    if (!is_dir($sp)) @mkdir($sp, 0700, true);
    if (is_dir($sp) && is_writable($sp)) { session_save_path($sp); ini_set('session.gc_maxlifetime', (string) SESSION_IDLE); }
    $secure = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['SERVER_PORT'] ?? '') === '443') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => SESSION_IDLE, 'path' => (BASE === '' ? '/' : BASE), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function current_user(): ?array {
    sess_start();
    if (empty($_SESSION['uid'])) return null;
    if (($_SESSION['seen'] ?? 0) < time() - SESSION_IDLE) { do_logout(); return null; }
    $u = user_by_id((int) $_SESSION['uid']);
    if (!$u || $u['status'] !== 'active') { do_logout(); return null; }
    // "Sign out my other sessions", a new password and a two-factor change all move the person's session
    // version on. A session that signed in under an earlier number is over.
    if ((int) ($_SESSION['sv'] ?? 0) !== (int) $u['session_version']) { do_logout(); flash_set('You were signed out of this session. Sign in again to carry on.', 'err'); return null; }
    $_SESSION['seen'] = time();
    audit_as($u);
    addon_hook('authed', $u);
    return $u;
}
/**
 * Check an email address and password. True when they are right - and then the session is either signed
 * in, or, for an account with two-factor sign-in, only waiting: it remembers who gave the right
 * password (login_pending()) and nothing in the app opens until login_finish() has accepted a code.
 */
function do_login(string $email, string $pass): bool {
    $typed = mb_substr(mb_strtolower(trim($email)), 0, 120);
    $u = user_by_email($email);
    if (!$u) { password_hash($pass, PASSWORD_DEFAULT); audit('login_failed', 'Wrong email or password', '', 0, $typed); return false; }   // constant-ish time
    if (!password_verify($pass, $u['pass_hash']) || $u['status'] !== 'active') { audit('login_failed', 'Wrong email or password', 'users', (int) $u['id'], $typed); return false; }
    sess_start(); session_regenerate_id(true);
    unset($_SESSION['uid'], $_SESSION['pending_uid'], $_SESSION['pending_at']);
    if ((int) $u['totp_enabled'] === 1) { $_SESSION['pending_uid'] = (int) $u['id']; $_SESSION['pending_at'] = time(); return true; }
    session_sign_in($u);
    audit('login', 'Signed in', 'users', (int) $u['id'], $u);
    return true;
}
/** Make this session $u's: the last step of every sign-in. */
function session_sign_in(array $u): void {
    unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
    $_SESSION['uid'] = (int) $u['id']; $_SESSION['seen'] = time(); $_SESSION['sv'] = (int) $u['session_version'];
    db()->prepare("UPDATE users SET last_login=? WHERE id=?")->execute([time(), (int) $u['id']]);
}
/** The person who has given the right password and still owes a two-factor code, or null. They have 10 minutes. */
function login_pending(): ?array {
    sess_start();
    if (empty($_SESSION['pending_uid'])) return null;
    $u = (int) ($_SESSION['pending_at'] ?? 0) > time() - 600 ? user_by_id((int) $_SESSION['pending_uid']) : null;
    if (!$u || $u['status'] !== 'active' || (int) $u['totp_enabled'] !== 1) { unset($_SESSION['pending_uid'], $_SESSION['pending_at']); return null; }
    return $u;
}
/** Give up a sign-in that is waiting for its code. */
function login_cancel(): void { sess_start(); unset($_SESSION['pending_uid'], $_SESSION['pending_at']); }
/**
 * The second step of a two-factor sign-in: $code is the six digits from the authenticator app, or one
 * of the person's recovery codes. Returns '' when the session is now signed in, otherwise what to tell them.
 */
function login_finish(string $code): string {
    $u = login_pending();
    if (!$u) return 'That sign-in timed out. Enter your email and password again.';
    $uid = (int) $u['id'];
    if (twofa_locked_secs($uid) > 0) return 'Too many wrong codes. Try again in a few minutes.';
    $how = twofa_check($u, $code);
    if ($how === '') {
        twofa_fail($uid);
        audit('login_failed', 'Wrong two-factor code', 'users', $uid, mb_strtolower((string) $u['email']));
        return 'That code is not right. Codes change every 30 seconds and each one works once.';
    }
    twofa_clear($uid);
    session_regenerate_id(true);
    $u = user_by_id($uid) ?? $u;
    session_sign_in($u);
    if ($how === 'recovery') {
        $left = count(jdec($u['recovery_codes']));
        audit('login', 'Signed in with a recovery code (' . $left . ' left)', 'users', $uid, $u);
        flash_set('You signed in with a recovery code. ' . ($left === 0 ? 'That was your last one' : 'You have ' . $left . ' left') . ': make new ones under Settings, Two-factor sign-in.', $left <= 2 ? 'err' : 'ok');
    } else {
        audit('login', 'Signed in with a two-factor code', 'users', $uid, $u);
    }
    return '';
}
/**
 * End the session: everything in it is dropped and its id is thrown away. The browser is handed a new,
 * empty session straight away rather than none at all, because the sign-in form is often drawn later in
 * the same request (someone signed out elsewhere opens the dashboard) and needs a session to keep its
 * CSRF token in. Until 1.3 that form could not be submitted: "Bad request (CSRF)".
 */
function do_logout(): void {
    sess_start();
    $_SESSION = [];
    session_regenerate_id(true);
}
function require_login(): array { $u = current_user(); if (!$u) redirect('index.php?login=1'); twofa_gate($u); return $u; }
function require_admin(): array { $u = require_login(); if (!is_admin($u)) { http_response_code(403); pub_head('Access denied'); echo '<div class="pcard" style="text-align:center"><h1>Access denied</h1><p class="pmuted">This area is for administrators.</p><p style="margin-top:14px"><a class="pbtn" href="' . h(url('index.php')) . '">Back to dashboard</a></p></div>'; pub_foot(); exit; } return $u; }

/* ---------------------------------------------------------------- csrf ------ */
function csrf(): string { sess_start(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_ok(?string $t): bool { sess_start(); return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function post_guard(): void { if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok($_POST['csrf'] ?? null)) { http_response_code(400); exit('Bad request (CSRF)'); } }

/* ------------------------------------------------------------- throttle ---- */
function throttle_file(): string { return DATA_DIR . '/rl_login_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'x')) . '.json'; }
function login_locked_secs(): int { $f = throttle_file(); if (!is_file($f)) return 0; $d = json_decode((string) file_get_contents($f), true) ?: [];
    $d = array_values(array_filter($d, fn($t) => $t > time() - LOGIN_LOCK_SECS)); return count($d) >= LOGIN_MAX_FAILS ? LOGIN_LOCK_SECS - (time() - min($d)) : 0; }
function login_fail(): void { $f = throttle_file(); $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $d[] = time();
    $d = array_slice(array_values(array_filter($d, fn($t) => $t > time() - LOGIN_LOCK_SECS)), -20); @file_put_contents($f, json_encode($d), LOCK_EX); }
function login_clear(): void { @unlink(throttle_file()); }
/** Generic per-IP sliding-window rate limit (keyed on REMOTE_ADDR only). */
function rl_hit(string $key, int $window, int $max): bool {
    if (random_int(1, 100) === 1) { foreach ((glob(DATA_DIR . '/rl_*.json') ?: []) as $g) { $m = @filemtime($g); if ($m !== false && $m < time() - 86400) @unlink($g); } }
    $f = DATA_DIR . '/rl_' . md5($key . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'x')) . '.json';
    $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $d = array_values(array_filter($d, fn($t) => $t > time() - $window));
    if (count($d) >= $max) return false;
    $d[] = time(); @file_put_contents($f, json_encode($d), LOCK_EX); return true;
}

/* ------------------------------------------------------- two-factor sign-in -- */
// Two-factor sign-in uses the time-based codes every authenticator app speaks (TOTP, RFC 6238: HMAC-SHA1,
// a new six-digit code every 30 seconds), in plain PHP. Each person turns it on for their own account
// (twofa.php); an admin can require it for everyone and can reset somebody else's. Someone locked out of
// the only admin account gets back in from the server with recover.php.

/** Base32 (RFC 4648, no padding): how an authenticator secret is written down. */
function b32_encode(string $bin): string {
    if ($bin === '') return '';
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $abc[(int) bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function b32_decode(string $s): string {
    $s = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $s));
    if ($s === '') return '';
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($s) as $ch) $bits .= str_pad(decbin((int) strpos($abc, $ch)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr((int) bindec($byte));
    return $out;
}
/** The six-digit code for a secret (raw bytes) at one 30-second step, where step = floor(unix time / 30). */
function totp_at(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), $secret, true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}
/**
 * The step a six-digit code belongs to, or 0 when it is wrong. The current step and the ones either
 * side are accepted, so a clock that is half a minute out still works. Only steps after $after count:
 * that is what makes a code good for one use.
 */
function totp_match(string $secret, string $code, int $after = 0): int {
    $now = intdiv(time(), 30); $hit = 0;
    foreach ([$now - 1, $now, $now + 1] as $s) if (hash_equals(totp_at($secret, $s), $code) && $s > $after && !$hit) $hit = $s;
    return $hit;
}
/**
 * Seal a short secret for storage, and open it again. The key comes from this install's app secret.
 * Be clear about what that gives you: the app secret lives in the same database, so this keeps an
 * authenticator secret out of sight in a table dump, an export or a support screenshot - it does not
 * protect it from someone who has the whole database file. Keep data/ private and backed up securely.
 * (An HMAC-SHA256 keystream with a random nonce, and an HMAC over the result: no PHP extension needed.)
 */
function secret_key(string $use): string { return hash_hmac('sha256', 'crmax-core:' . $use, app_secret(), true); }
function secret_stream(string $nonce, int $len): string {
    $out = '';
    for ($i = 0; strlen($out) < $len; $i++) $out .= hash_hmac('sha256', $nonce . pack('N', $i), secret_key('seal'), true);
    return substr($out, 0, $len);
}
function secret_seal(string $plain): string {
    $nonce = random_bytes(16);
    $ct = $plain ^ secret_stream($nonce, strlen($plain));
    return 'v1.' . base64_encode($nonce . $ct . substr(hash_hmac('sha256', $nonce . $ct, secret_key('seal-check'), true), 0, 16));
}
function secret_open(string $sealed): string {
    $raw = str_starts_with($sealed, 'v1.') ? base64_decode(substr($sealed, 3), true) : false;
    if ($raw === false || strlen($raw) < 33) return '';
    $nonce = substr($raw, 0, 16); $ct = substr($raw, 16, -16);
    if (!hash_equals(substr(hash_hmac('sha256', $nonce . $ct, secret_key('seal-check'), true), 0, 16), substr($raw, -16))) return '';
    return $ct ^ secret_stream($nonce, strlen($ct));
}
/** A user's authenticator secret as raw bytes, or '' when they have none (or it cannot be opened). */
function totp_secret_of(array $u): string { return (string) ($u['totp_secret'] ?? '') !== '' ? secret_open((string) $u['totp_secret']) : ''; }
/** The address an authenticator app reads from the QR code: who it is for, the secret, and how the codes are made. */
function totp_uri(string $secretB32, string $account): string {
    $issuer = trim(str_replace(':', ' ', org_name())) ?: 'CRM';
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
/** $n fresh recovery codes, like "k7m2p-x9q4r": for signing in when the phone is not to hand. Each works once. */
function recovery_codes_new(int $n = 10): array {
    $abc = 'abcdefghjkmnpqrstuvwxyz23456789'; $out = [];   // no i, l, o, 0 or 1: nothing to misread
    for ($k = 0; $k < $n; $k++) {
        $c = '';
        for ($i = 0; $i < 10; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        $out[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    return $out;
}
function recovery_normalize(string $code): string { return (string) preg_replace('/[^a-z0-9]/', '', strtolower($code)); }
/** What is stored for a recovery code: a keyed hash, never the code itself. */
function recovery_hash(string $code): string { return hash_hmac('sha256', recovery_normalize($code), secret_key('recovery')); }
/**
 * Check what someone typed at the second step against their account, and use it up: 'totp' for a good
 * authenticator code, 'recovery' for a good recovery code, '' when it is neither. Each is taken with a
 * compare-and-swap, so the same code cannot pass twice even when two requests arrive together.
 */
function twofa_check(array $u, string $typed): string {
    $uid = (int) $u['id'];
    $digits = (string) preg_replace('/\s+/', '', $typed);
    if (preg_match('/^\d{6}$/', $digits)) {
        $secret = totp_secret_of($u);
        $step = $secret !== '' ? totp_match($secret, $digits, (int) $u['totp_last_step']) : 0;
        if (!$step) return '';
        $st = db()->prepare("UPDATE users SET totp_last_step=? WHERE id=? AND totp_last_step<?");
        $st->execute([$step, $uid, $step]);
        return $st->rowCount() === 1 ? 'totp' : '';
    }
    $code = recovery_normalize($typed);
    if (strlen($code) !== 10) return '';
    $left = jdec($u['recovery_codes']); $hash = recovery_hash($code);
    foreach ($left as $i => $h) {
        if (!is_string($h) || !hash_equals($h, $hash)) continue;
        unset($left[$i]);
        $st = db()->prepare("UPDATE users SET recovery_codes=? WHERE id=? AND recovery_codes=?");
        $st->execute([json_encode(array_values($left)), $uid, (string) $u['recovery_codes']]);
        return $st->rowCount() === 1 ? 'recovery' : '';
    }
    return '';
}
/** Turn two-factor sign-in on for a user, with the secret (raw bytes) they have just proved their app holds. Returns their recovery codes, to show once. */
function twofa_enable(int $uid, string $secret, int $step): array {
    $codes = recovery_codes_new();
    db()->prepare("UPDATE users SET totp_secret=?, totp_enabled=1, totp_last_step=?, recovery_codes=? WHERE id=?")
        ->execute([secret_seal($secret), $step, json_encode(array_map('recovery_hash', $codes)), $uid]);
    sessions_revoke($uid);
    return $codes;
}
/** Turn it off and forget the secret and the recovery codes. */
function twofa_disable(int $uid): void {
    db()->prepare("UPDATE users SET totp_secret='', totp_enabled=0, totp_last_step=0, recovery_codes='[]' WHERE id=?")->execute([$uid]);
    twofa_clear($uid);
    sessions_revoke($uid);
}
/** Replace a user's recovery codes. Returns the new ones, to show once; the old ones stop working. */
function recovery_codes_reset(int $uid): array {
    $codes = recovery_codes_new();
    db()->prepare("UPDATE users SET recovery_codes=? WHERE id=?")->execute([json_encode(array_map('recovery_hash', $codes)), $uid]);
    return $codes;
}
/** Whether an admin has made two-factor sign-in compulsory for the whole team (Settings > Security). */
function twofa_required(): bool { return setting_get('require_2fa', '0') === '1'; }
function twofa_must_enrol(array $u): bool { return (int) ($u['totp_enabled'] ?? 0) !== 1 && twofa_required(); }
/**
 * While two-factor sign-in is compulsory, someone who has not set it up is sent to the set-up page
 * whatever they open. Only page views are turned away: a form they were in the middle of still saves.
 */
function twofa_gate(array $u): void {
    if (defined('CRMAX_TWOFA_PAGE') || PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (twofa_must_enrol($u)) redirect('twofa.php');
}
// Wrong codes are counted per person as well as per address (login_fail()), so guessing from many
// addresses at once gets nowhere: 8 wrong codes lock the second step for that person for 10 minutes.
function twofa_throttle_file(int $uid): string { return DATA_DIR . '/rl_2fa_' . $uid . '.json'; }
function twofa_locked_secs(int $uid): int {
    $f = twofa_throttle_file($uid); if (!is_file($f)) return 0;
    $d = array_values(array_filter(json_decode((string) file_get_contents($f), true) ?: [], fn($t) => $t > time() - LOGIN_LOCK_SECS));
    return count($d) >= 8 ? LOGIN_LOCK_SECS - (time() - min($d)) : 0;
}
function twofa_fail(int $uid): void {
    $f = twofa_throttle_file($uid);
    $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $d[] = time();
    @file_put_contents($f, json_encode(array_slice(array_values(array_filter($d, fn($t) => $t > time() - LOGIN_LOCK_SECS)), -20)), LOCK_EX);
}
function twofa_clear(int $uid): void { @unlink(twofa_throttle_file($uid)); }
/**
 * Sign a person out everywhere, by moving their session version on. The session making this request
 * stays signed in when it is theirs (so "Sign out my other sessions" keeps this one), unless $keepThis is false.
 */
function sessions_revoke(int $uid, bool $keepThis = true): void {
    db()->prepare("UPDATE users SET session_version=session_version+1 WHERE id=?")->execute([$uid]);
    if (!$keepThis || PHP_SAPI === 'cli') return;
    sess_start();
    if ((int) ($_SESSION['uid'] ?? 0) === $uid && ($u = user_by_id($uid))) $_SESSION['sv'] = (int) $u['session_version'];
}

/* -------------------------------------------------------------- audit log -- */
/** Say who is acting for the rest of this request: a user (their row), or a label such as 'API'. current_user() does this for a signed-in person. */
function audit_as($who): void {
    $GLOBALS['AUDIT_ACTOR'] = is_array($who) ? ['id' => (int) ($who['id'] ?? 0), 'label' => user_label($who)] : ['id' => 0, 'label' => (string) $who];
}
/**
 * Add a line to the audit log (Settings > Security > Audit log): who did what, to what, from where.
 * $action is a short code ('login', 'delete', 'owner_changed'...; audit_actions() lists them), $summary
 * one plain sentence, $entity and $entityId the record it is about, if any. $who names the actor for
 * this one line - a user row, or a label such as the address typed at a failed sign-in - instead of the
 * person signed in. It never throws: nothing may fail because its log line could not be written.
 * Never put a password, a code, a key or a token in $summary.
 */
function audit(string $action, string $summary = '', string $entity = '', int $entityId = 0, $who = null): void {
    try {
        if ($who !== null) $a = is_array($who) ? ['id' => (int) ($who['id'] ?? 0), 'label' => user_label($who)] : ['id' => 0, 'label' => (string) $who];
        else $a = $GLOBALS['AUDIT_ACTOR'] ?? ['id' => 0, 'label' => PHP_SAPI === 'cli' ? 'Command line' : ''];
        $clean = fn(string $s, int $n): string => mb_substr(trim((string) preg_replace('/\s+/', ' ', $s)), 0, $n);
        db()->prepare("INSERT INTO audit_log (user_id, actor, action, entity, entity_id, summary, ip, created_at) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$a['id'], $clean($a['label'], 120), $clean($action, 40), $clean($entity, 40), $entityId, $clean($summary, 400),
                PHP_SAPI === 'cli' ? 'command line' : mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), time()]);
    } catch (Throwable $e) { /* the action itself stands */ }
}
/** Every action the app logs: code => [what the viewer calls it, the group it is filtered under]. */
function audit_actions(): array {
    return [
        'login'            => ['Signed in', 'Signing in'],
        'login_failed'     => ['Sign-in failed', 'Signing in'],
        'logout'           => ['Signed out', 'Signing in'],
        'sessions_revoked' => ['Other sessions signed out', 'Signing in'],
        'password_changed' => ['Password changed', 'Security'],
        'password_reset'   => ['Password reset', 'Security'],
        '2fa_enabled'      => ['Two-factor turned on', 'Security'],
        '2fa_disabled'     => ['Two-factor turned off', 'Security'],
        '2fa_codes'        => ['New recovery codes', 'Security'],
        '2fa_reset'        => ['Two-factor reset', 'Security'],
        '2fa_policy'       => ['Two-factor rule changed', 'Security'],
        'api_token'        => ['API token changed', 'Security'],
        'user_added'       => ['Team member added', 'Team'],
        'user_removed'     => ['Team member removed', 'Team'],
        'org'              => ['Organization changed', 'Settings'],
        'routing'          => ['Lead routing changed', 'Settings'],
        'email_account'    => ['Email account changed', 'Settings'],
        'ai_key'           => ['AI settings changed', 'Settings'],
        'owner_changed'    => ['Owner changed', 'Records'],
        'bulk_assign'      => ['Bulk assign', 'Records'],
        'bulk_tag'         => ['Bulk tag', 'Records'],
        'bulk_delete'      => ['Bulk delete', 'Records'],
        'delete'           => ['Deleted', 'Records'],
        'merge'            => ['Companies merged', 'Records'],
        'import'           => ['CSV import', 'Records'],
        'export'           => ['CSV export', 'Records'],
        'campaign_sent'    => ['Campaign sent', 'Records'],
    ];
}
/** A team member's name for a log line, or "nobody" for 0. */
function audit_who(int $userId): string { return $userId > 0 ? (user_label(user_by_id($userId)) ?: 'user #' . $userId) : 'nobody'; }
/** Drop log lines older than 12 months. At most once a day; cron.php and the audit log page both call it. */
function audit_prune(): int {
    if (setting_get('audit_pruned', '') === date('Y-m-d')) return 0;
    setting_set('audit_pruned', date('Y-m-d'));
    $st = db()->prepare("DELETE FROM audit_log WHERE created_at<?");
    $st->execute([time() - 366 * 86400]);
    return $st->rowCount();
}

/* ---------------------------------------------------------------- flash ---- */
function flash_set(string $m, string $kind = 'ok'): void { sess_start(); $_SESSION['flash'] = [$m, $kind]; }
function flash_html(): string { sess_start(); if (empty($_SESSION['flash'])) return ''; [$m, $k] = $_SESSION['flash']; unset($_SESSION['flash']); return '<div class="flash ' . h($k) . '">' . h($m) . '</div>'; }

/* -------------------------------------------------------------- helpers ---- */
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function jscript($v): string { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); }
function csv_safe($v): string { $s = (string) $v; return $s !== '' && preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s; }
function sanitize_html(string $html): string {
    $html = preg_replace('#<\s*(script|iframe|object|embed|form|meta|link|base|style)\b[\s\S]*?<\s*/\s*\1\s*>#i', '', $html);
    $html = preg_replace('#<\s*(script|iframe|object|embed|meta|link|base)\b[^>]*>#i', '', $html);
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(href|src|action|formaction)\s*=\s*("|\')\s*javascript:[^"\']*(\2)#i', '$1=$2#$2', $html);
    return (string) $html;
}
/** An amount for display, e.g. "$1,234.00 USD", with the sign and code from config.php. $cur overrides the code ('' = none). */
function money(int $cents, ?string $cur = null): string { $cur ??= CURRENCY; return CRMAX_CURRENCY_SYMBOL . number_format($cents / 100, 2) . ($cur !== '' ? ' ' . strtoupper($cur) : ''); }
function ago(int $ts): string {
    if ($ts <= 0) return '-'; $d = time() - $ts;
    if ($d < 60) return 'just now'; if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago'; if ($d < 604800) return floor($d / 86400) . 'd ago';
    return date('j M Y', $ts);
}
function contact_name(array $c): string { $n = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); return $n !== '' ? $n : (($c['email'] ?? '') !== '' ? $c['email'] : 'Unnamed contact'); }
function slugify(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim(substr($s ?: '', 0, 60), '-') ?: 'x' . bin2hex(random_bytes(3)); }
function token(): string { return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='); }
function jdec($s): array { $a = json_decode((string) $s, true); return is_array($a) ? $a : []; }
function act_log(int $contactId, string $type, array $meta = []): void {
    db()->prepare("INSERT INTO activities (contact_id,type,meta,created_at) VALUES (?,?,?,?)")->execute([$contactId, $type, json_encode($meta, JSON_UNESCAPED_SLASHES), time()]);
}
function err404(string $msg = 'That page does not exist.'): void { http_response_code(404); pub_head('Not found'); echo '<div class="pcard" style="text-align:center"><h1 style="font-size:3rem">404</h1><p class="pmuted">' . h($msg) . '</p></div>'; pub_foot(); exit; }

require __DIR__ . '/layout.php';

/* ---------------------------------------------------------------- add-ons -- */
// Optional add-ons (see "Add-ons" in the README): every addons/<name>/addon.php is loaded here, after the core, on
// every request, and registers callbacks with addon_on(). lib.php is not loaded yet at this point, so an add-on only
// defines functions and hooks. An add-on file should start with
//   if (!defined('APP_VERSION')) { http_response_code(404); exit; }
// so that opening it directly over the web does nothing. Add-ons run with full access: install only ones you trust.
// Events (side effects): authed($user), password_changed($userId), user_added($userId), contact_deleted($contactId).
// HTML (returned strings are printed): contact_panels($contact,$user), dashboard($user), settings_cards($user),
// campaign_editor($campaign,$segment,$user).
// Filters (value in, value out): nav($nav,$user), icons($paths), password_problem($msg,$new,$user),
// form_elsewhere($url,$form).
$GLOBALS['ADDON_HOOKS'] = [];
function addon_on(string $event, callable $fn): void { $GLOBALS['ADDON_HOOKS'][$event][] = $fn; }
function addon_hook(string $event, ...$args): string {
    $out = '';
    foreach ($GLOBALS['ADDON_HOOKS'][$event] ?? [] as $fn) { $r = $fn(...$args); if (is_string($r)) $out .= $r; }
    return $out;
}
function addon_filter(string $event, $value, ...$args) {
    foreach ($GLOBALS['ADDON_HOOKS'][$event] ?? [] as $fn) $value = $fn($value, ...$args);
    return $value;
}
foreach (glob(__DIR__ . '/addons/*/addon.php') ?: [] as $__addon) require_once $__addon;
unset($__addon);

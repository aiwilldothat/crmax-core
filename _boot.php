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

const APP_VERSION = '1.1.1';
define('APP_NAME', (string) ($CFG['app_name'] ?? 'CRMax Core'));
define('DATA_DIR', (string) ($CFG['data_dir'] ?? (__DIR__ . '/data')));
define('DB_FILE', (string) ($CFG['db_file'] ?? (DATA_DIR . '/crm.sqlite')));
define('DEV', (bool) ($CFG['dev'] ?? (PHP_SAPI === 'cli-server')));
// Amounts show as "$1,234.00 USD": config.php 'currency' is the code after the amount ('' shows none) and
// 'currency_symbol' the sign before it (e.g. 'EUR' and the euro sign).
define('CURRENCY', strtoupper(trim((string) ($CFG['currency'] ?? 'USD'))));
define('CURRENCY_SYMBOL', (string) ($CFG['currency_symbol'] ?? '$'));
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
    $_SESSION['seen'] = time();
    addon_hook('authed', $u);
    return $u;
}
function do_login(string $email, string $pass): bool {
    $u = user_by_email($email);
    if (!$u) { password_hash($pass, PASSWORD_DEFAULT); return false; }   // constant-ish time
    if (!password_verify($pass, $u['pass_hash']) || $u['status'] !== 'active') return false;
    sess_start(); session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id']; $_SESSION['seen'] = time();
    db()->prepare("UPDATE users SET last_login=? WHERE id=?")->execute([time(), $u['id']]);
    return true;
}
function do_logout(): void {
    sess_start(); $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']); }
    session_destroy();
}
function require_login(): array { $u = current_user(); if (!$u) redirect('index.php?login=1'); return $u; }
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
function money(int $cents, ?string $cur = null): string { $cur ??= CURRENCY; return CURRENCY_SYMBOL . number_format($cents / 100, 2) . ($cur !== '' ? ' ' . strtoupper($cur) : ''); }
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

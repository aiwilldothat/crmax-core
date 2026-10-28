<?php
/**
 * CRMax Core — a free, self-hosted CRM.
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

define('APP_NAME', (string) ($CFG['app_name'] ?? 'CRMax Core'));
define('DATA_DIR', (string) ($CFG['data_dir'] ?? (__DIR__ . '/data')));
define('DB_FILE', (string) ($CFG['db_file'] ?? (DATA_DIR . '/crm.sqlite')));
define('DEV', (bool) ($CFG['dev'] ?? (PHP_SAPI === 'cli-server')));
define('SESSION_IDLE', 1209600);        // 14 days
const LOGIN_MAX_FAILS = 6;
const LOGIN_LOCK_SECS = 600;

date_default_timezone_set((string) ($CFG['timezone'] ?? 'UTC'));
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0750, true);

/* URL base path, so the app works installed at the web root OR in a subfolder, on any host,
 * with no rewrite rules (plain .php URLs). e.g. SCRIPT_NAME /free/contacts.php -> BASE "/free". */
define('BASE', rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/'));
function url(string $p = ''): string { return BASE . '/' . ltrim($p, '/'); }
function base_url(): string {
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
function money(int $cents, string $cur = 'USD'): string { return '$' . number_format($cents / 100, 2) . ($cur !== '' ? ' ' . strtoupper($cur) : ''); }
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

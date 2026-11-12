<?php
/**
 * CRMax Core - end-to-end smoke test. Command line only.
 *
 *   php tests/smoke.php            fresh installs + upgrades from v1.0, v1.1 and v1.2
 *   php tests/smoke.php --keep     keep the temp folders afterwards (for debugging)
 *
 * Nothing here touches your real data: the app is copied to a temp folder, served with PHP's
 * built-in server and driven over HTTP like a browser. Email goes to a small SMTP sink on
 * 127.0.0.1 (this file again, run with --smtp-sink), so the test can read what cron.php sends from
 * the command line. The upgrade tests check out the first release and the 1.1.0 and 1.2.1 releases
 * from git, build a small database with each, then open it with the current code.
 * Two-factor sign-in is tested with real time-based codes: the test has its own code generator,
 * checked against the RFC 6238 test values.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
$KEEP = in_array('--keep', $argv, true);
$results = []; $servers = []; $tmpDirs = [];
const DS = DIRECTORY_SEPARATOR;
if (($argv[1] ?? '') === '--smtp-sink') { smtp_sink_run((int) ($argv[2] ?? 0), (string) ($argv[3] ?? '')); exit(0); }

function check(string $name, bool $ok, string $detail = ''): bool {
    global $results;
    $results[] = [$name, $ok];
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : '  -> ' . $detail) . "\n";
    return $ok;
}
function section(string $t): void { echo "\n$t\n"; }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }   // as the app's h() prints it
function rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) { if ($f === '.' || $f === '..') continue; $p = $d . DS . $f; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($d);
}
function rcopy(string $src, string $dst): void {
    @mkdir($dst, 0700, true);
    foreach (scandir($src) as $f) { if ($f === '.' || $f === '..') continue; $s = $src . DS . $f; $d = $dst . DS . $f; is_dir($s) ? rcopy($s, $d) : copy($s, $d); }
}
function tmpdir(string $tag): string {
    global $tmpDirs;
    $d = rtrim(sys_get_temp_dir(), '/\\') . DS . "crmax-core-$tag-" . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    return $tmpDirs[] = $d;
}
/** Copy the app's own files (the root-level PHP files) into $dst. */
function copy_app(string $src, string $dst): void {
    foreach (scandir($src) as $f) if (is_file($src . DS . $f) && str_ends_with($f, '.php')) copy($src . DS . $f, $dst . DS . $f);
}
/** Start the built-in server on a free port for $dir. Returns [base url, handle]. */
function serve(string $dir): array {
    global $servers;
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $log = $dir . DS . 'server.log';
    // No opcode cache, so a config.php rewritten between requests is read afresh. Every PHP warning, notice and
    // deprecation is printed into the page, whatever this machine's php.ini says, so the tests see it on any PHP version.
    $proc = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'display_errors=1', '-d', 'html_errors=1', '-d', 'error_reporting=-1',
        '-S', "127.0.0.1:$port", '-t', $dir], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
    $servers[] = $proc;
    return ["http://127.0.0.1:$port", $proc];
}
function stop($proc): void { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); }

/* ------------------------------------------------------------------ SMTP sink -- */
/** The sink itself: accept mail on 127.0.0.1:$port and save each message to $dir as msg-NNN.json. */
function smtp_sink_run(int $port, string $dir): void {
    $srv = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
    if (!$srv || !is_dir($dir)) { fwrite(STDERR, "smtp sink: cannot listen on $port ($errstr)\n"); exit(1); }
    $n = 0;
    while (true) {
        $conn = @stream_socket_accept($srv, 3600);
        if (!$conn) continue;
        stream_set_timeout($conn, 20);
        $say = function (string $s) use ($conn): void { fwrite($conn, $s . "\r\n"); };
        $say('220 sink.test ESMTP');
        $ehlo = ''; $from = ''; $rcpt = [];
        while (($line = fgets($conn, 8192)) !== false) {
            $cmd = rtrim($line, "\r\n"); $verb = strtoupper(substr($cmd, 0, 4));
            if ($verb === 'EHLO' || $verb === 'HELO') { $ehlo = trim(substr($cmd, 5)); $say('250 sink.test'); }
            elseif ($verb === 'MAIL') { $from = trim(substr($cmd, 10)); $say('250 ok'); }
            elseif ($verb === 'RCPT') { $rcpt[] = trim(substr($cmd, 8)); $say('250 ok'); }
            elseif ($verb === 'DATA') {
                $say('354 end with <CRLF>.<CRLF>');
                $data = '';
                while (($l = fgets($conn, 8192)) !== false && rtrim($l, "\r\n") !== '.') $data .= str_starts_with($l, '..') ? substr($l, 1) : $l;
                file_put_contents($dir . DS . sprintf('msg-%03d.json', ++$n), json_encode(['ehlo' => $ehlo, 'from' => $from, 'rcpt' => $rcpt, 'data' => $data]));
                $from = ''; $rcpt = [];
                $say('250 queued');
            }
            elseif ($verb === 'QUIT') { $say('221 bye'); break; }
            else $say('250 ok');
        }
        fclose($conn);
    }
}
/** Start the sink on a free port. Returns [port, the folder the messages land in]. */
function smtp_sink(): array {
    global $servers;
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $dir = tmpdir('mail');
    $log = $dir . DS . 'sink.log';
    $servers[] = proc_open([PHP_BINARY, __FILE__, '--smtp-sink', (string) $port, $dir], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
    return [$port, $dir];
}
/** Split a raw message from smtp_send() into its headers (lower-case name => value) and decoded text + HTML parts. */
function mail_decode(string $raw): array {
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $headers = [];
    foreach (explode("\r\n", $head) as $l) if (($p = strpos($l, ':')) !== false) $headers[strtolower(substr($l, 0, $p))] = trim(substr($l, $p + 1));
    if (preg_match('/^=\?UTF-8\?B\?(.*)\?=$/', $headers['subject'] ?? '', $s)) $headers['subject'] = (string) base64_decode($s[1]);
    $parts = ['text' => '', 'html' => ''];
    preg_match_all('#Content-Type: text/(plain|html); charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--#s', $body, $m, PREG_SET_ORDER);
    foreach ($m as $p) $parts[$p[1] === 'plain' ? 'text' : 'html'] = (string) base64_decode(str_replace("\r\n", '', $p[2]));
    return ['headers' => $headers] + $parts;
}
/** The newest message the sink received for $email, decoded (see mail_decode()), or [] if none. */
function mail_to(string $dir, string $email): array {
    $files = glob($dir . DS . 'msg-*.json') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $m = json_decode((string) file_get_contents($f), true);
        if (in_array('<' . $email . '>', $m['rcpt'] ?? [], true)) return $m + mail_decode((string) $m['data']);
    }
    return [];
}
function mail_count(string $dir): int { return count(glob($dir . DS . 'msg-*.json') ?: []); }
/** The one-click unsubscribe link in an email's HTML that starts with $base, or ''. */
function unsub_link(array $m, string $base): string {
    return preg_match('#href="(' . preg_quote($base, '#') . '/u\.php\?c=[0-9]+\.[0-9a-f]{16})"#', $m['html'] ?? '', $x) ? $x[1] : '';
}

final class Client {
    public string $csrf = '';
    public function __construct(private string $base, private string $jar) {}
    /** @return array{code:int, body:string, location:string} */
    public function req(string $method, string $path, $body = null, array $headers = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
        if (is_array($body) && isset($body['__multipart'])) { unset($body['__multipart']); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        elseif ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $hs), 'location' => preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m) ? $m[1] : ''];
    }
    public function get(string $path): array { return $this->req('GET', $path); }
    /** POST a form with one file (multipart), like a browser upload. */
    public function upload(string $path, array $fields, string $field, string $file): array {
        $fields['csrf'] = $this->csrf; $fields[$field] = new CURLFile($file, 'text/csv', basename($file));
        return $this->req('POST', $path, $fields + ['__multipart' => true]);
    }
    public function post(string $path, array $fields, bool $withCsrf = true): array { if ($withCsrf) $fields['csrf'] = $this->csrf; return $this->req('POST', $path, $fields); }
    public function loadCsrf(string $path): bool { $r = $this->get($path); if (preg_match('/name="csrf" value="([a-f0-9]+)"/', $r['body'], $m)) { $this->csrf = $m[1]; return true; } return false; }
    public function api(string $method, string $path, string $token, ?array $json = null): array {
        $h = ["Authorization: Bearer $token"]; if ($json !== null) $h[] = 'Content-Type: application/json';
        $r = $this->req($method, '/api.php/' . ltrim($path, '/'), $json !== null ? json_encode($json) : null, $h);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }
}
function pdo_for(string $dir): PDO {
    $p = new PDO('sqlite:' . $dir . DS . 'data' . DS . 'crm.sqlite');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $p->exec('PRAGMA busy_timeout=5000');
    return $p;
}
function one(string $dir, string $sql, array $params = []) { $st = pdo_for($dir)->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
function row(string $dir, string $sql, array $params = []): array { $st = pdo_for($dir)->prepare($sql); $st->execute($params); return $st->fetch() ?: []; }
function id_from(array $r, string $pattern): int { return preg_match($pattern, $r['location'], $m) ? (int) $m[1] : 0; }
function schema_hash(string $dir): string { return md5(json_encode(pdo_for($dir)->query("SELECT name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name")->fetchAll())); }
function run_php(string $file): string { $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out); return implode("\n", $out); }
/** Run a PHP file on the command line with arguments. Returns [its output, its exit code]. */
function run_cmd(string $file, string ...$args): array {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
    return [implode("\n", $out), $rc];
}
/** True when a page came back 200 with no PHP warning, notice or error printed into it. */
function page_clean(array $r): bool { return $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']); }
/**
 * This test's own base32 decoder and time-based code (RFC 4648, RFC 6238), written separately from the
 * app's so the two check each other. $step is the 30-second step, floor(unix time / 30).
 */
function t_b32_decode(string $s): string {
    $abc = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567')); $acc = 0; $n = 0; $out = '';
    foreach (str_split(strtoupper($s)) as $ch) {
        if (!isset($abc[$ch])) continue;
        $acc = ($acc << 5) | $abc[$ch]; $n += 5;
        if ($n >= 8) { $n -= 8; $out .= chr(($acc >> $n) & 0xFF); $acc &= (1 << $n) - 1; }
    }
    return $out;
}
function t_totp(string $secret, int $step): string {
    $msg = '';
    for ($i = 7; $i >= 0; $i--) $msg .= chr(($step >> ($i * 8)) & 0xFF);
    $mac = array_values(unpack('C*', hash_hmac('sha1', $msg, $secret, true)));
    $at = $mac[19] & 15;
    $bin = (($mac[$at] & 127) << 24) + ($mac[$at + 1] << 16) + ($mac[$at + 2] << 8) + $mac[$at + 3];
    return sprintf('%06d', $bin % 1000000);
}
function setup_admin(Client $c, string $email, string $pass): array {
    $c->loadCsrf('/index.php');
    return $c->post('/index.php', ['name' => 'Ada Admin', 'org' => 'Smoke Org', 'email' => $email, 'password' => $pass]);
}
/**
 * Create a workflow through the editor and switch it on. Returns its id.
 * $steps: [['tag', 'vip'], ['task', 'Call {{first_name}}']] - tag and task steps only.
 */
function make_workflow(Client $c, string $dir, string $name, string $trigger, string $tag, array $steps): int {
    $f = ['do' => 'save', 'id' => 0, 'name' => $name, 'trigger_on' => $trigger, 'cfg_tag' => $tag, 's_type' => [], 's_tag' => [], 's_title' => [], 's_due' => []];
    foreach ($steps as $i => [$type, $value]) {
        $f['s_type'][$i] = $type; $f['s_tag'][$i] = $type === 'tag' ? $value : ''; $f['s_title'][$i] = $type === 'task' ? $value : ''; $f['s_due'][$i] = 0;
    }
    $c->post('/workflows.php', $f);
    $id = (int) one($dir, "SELECT id FROM workflows WHERE name=? ORDER BY id DESC LIMIT 1", [$name]);
    $c->post('/workflows.php', ['do' => 'toggle', 'id' => $id]);
    return $id;
}
function enrolled(string $dir, int $workflowId, string $status = ''): int {
    return (int) one($dir, "SELECT COUNT(*) FROM wf_enrollments WHERE workflow_id=?" . ($status !== '' ? " AND status='$status'" : ''), [$workflowId]);
}
function tags_of(string $dir, string $email): array { return json_decode((string) one($dir, "SELECT tags FROM contacts WHERE email=?", [$email]), true) ?: []; }
/** Sign in as an existing user and pick up that session's CSRF token. Returns the answer to the sign-in form (302 when it worked). */
function sign_in(Client $c, string $email, string $pass): array {
    $c->loadCsrf('/index.php?login=1');
    $r = $c->post('/index.php?login=1', ['email' => $email, 'password' => $pass]);
    $c->loadCsrf('/settings.php');
    return $r;
}
/** The message a page shows after an action, as "ok: ..." or "err: ...", or '' when there is none. Reading it uses it up. */
function flash_of(Client $c, string $path): string {
    return preg_match('/<div class="flash (ok|err)">([^<]*)<\/div>/', $c->get($path)['body'], $m) ? $m[1] . ': ' . html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : '';
}
/** Write the app files (the root-level PHP files) of one released commit into $dst. */
function export_release(string $root, string $commit, string $dst): void {
    foreach (array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-tree -r --name-only ' . $commit))) as $f) {
        $f = trim($f);
        if ($f === '' || !str_ends_with($f, '.php') || str_contains($f, '/')) continue;
        file_put_contents($dst . DS . $f, (string) shell_exec('git -C ' . escapeshellarg($root) . ' show ' . $commit . ':' . escapeshellarg($f)));
    }
}
/**
 * Compare two answers from the API: everything the old one held must still be there with the same value
 * (new fields are fine). Lists of records are matched by id, not by position. Returns each difference
 * as "path: old -> new"; an empty list means nothing an integration relies on has changed.
 */
function api_diff($old, $new, string $path): array {
    if (!is_array($old)) return $old === $new ? [] : ["$path: " . json_encode($old) . ' -> ' . json_encode($new)];
    if (!is_array($new)) return ["$path: " . json_encode($old) . ' -> ' . json_encode($new)];
    $diff = [];
    if ($old && array_is_list($old) && is_array($old[0]) && isset($old[0]['id'])) {
        $byId = [];
        foreach ($new as $rec) if (is_array($rec) && isset($rec['id'])) $byId[$rec['id']] = $rec;
        foreach ($old as $rec) $diff = array_merge($diff, isset($byId[$rec['id']]) ? api_diff($rec, $byId[$rec['id']], $path . '#' . $rec['id']) : [$path . '#' . $rec['id'] . ': gone']);
        return $diff;
    }
    foreach ($old as $k => $v) $diff = array_merge($diff, array_key_exists($k, $new) ? api_diff($v, $new[$k], "$path.$k") : ["$path.$k: gone"]);
    return $diff;
}

try {
    /* ====================================================== A. fresh install === */
    $dir = tmpdir('fresh');
    copy_app($ROOT, $dir);
    [$base, $srv] = serve($dir);
    $c = new Client($base, $dir . DS . 'jar.txt');

    section('Fresh install');
    $r = $c->get('/index.php');
    check('first visit shows the set-up form', $r['code'] === 200 && strpos($r['body'], 'Set up') !== false, 'HTTP ' . $r['code']);
    $r = setup_admin($c, 'ada@example.test', 'smoke-pass-1');
    check('set-up creates the admin and signs in', $r['code'] === 302, 'HTTP ' . $r['code']);
    $r = $c->get('/index.php');
    check('dashboard renders', $r['code'] === 200 && strpos($r['body'], 'Good day') !== false, 'HTTP ' . $r['code']);
    $adminId = (int) one($dir, "SELECT id FROM users WHERE email='ada@example.test'");
    check('database is at schema version 4', one($dir, "SELECT v FROM settings WHERE k='schema_version'") === '4');
    check('the data folder protects itself on Apache', str_contains((string) @file_get_contents($dir . DS . 'data' . DS . '.htaccess'), 'Require all denied'));
    $h1 = schema_hash($dir);
    file_put_contents($dir . DS . 'again.php', "<?php require __DIR__ . '/_boot.php'; db(); echo 'ok';");
    $o1 = run_php($dir . DS . 'again.php'); $o2 = run_php($dir . DS . 'again.php');
    check('opening the database again changes nothing', $o1 === 'ok' && $o2 === 'ok' && schema_hash($dir) === $h1, "$o1 | $o2");
    $c->loadCsrf('/contacts.php?new=1');
    $r = $c->post('/contacts.php', ['do' => 'save', 'first_name' => 'No', 'email' => 'no@example.test'], false);
    check('POST without a CSRF token is refused', $r['code'] === 400, 'HTTP ' . $r['code']);

    section('PHP compatibility');
    // Every constant Core defines (define('X', ...) / const X = ... in the app's own files).
    $consts = [];
    foreach (glob($ROOT . DS . '*.php') ?: [] as $f) {
        if (preg_match_all("/^\s*(?:define\('([A-Z0-9_]+)'|const ([A-Z0-9_]+)\s*=)/m", (string) file_get_contents($f), $cm, PREG_SET_ORDER)) {
            foreach ($cm as $x) $consts[] = ($x[1] ?? '') !== '' ? $x[1] : $x[2];
        }
    }
    $taken = array_values(array_filter($consts, 'defined'));   // this test has not loaded Core: anything defined is PHP's own
    check('Core defines no constant that this PHP already has', count($consts) >= 10 && !$taken, implode(', ', $taken));
    // PHP on Linux and macOS (not Windows) defines nl_langinfo() items with generic names, e.g. CURRENCY_SYMBOL = 262145.
    // 1.2.0 used that name and printed "262145" in front of every amount there; this catches such a name on Windows too.
    $langinfo = '/^((AB)?DAY_[1-7]|(AB)?MON_([1-9]|1[0-2])|AM_STR|PM_STR|D_T_FMT|D_FMT|T_FMT|T_FMT_AMPM|ERA|ERA_YEAR|ERA_D_FMT|ERA_D_T_FMT|ERA_T_FMT'
        . '|ALT_DIGITS|INT_CURR_SYMBOL|CURRENCY_SYMBOL|CRNCYSTR|MON_DECIMAL_POINT|MON_THOUSANDS_SEP|MON_GROUPING|POSITIVE_SIGN|NEGATIVE_SIGN'
        . '|INT_FRAC_DIGITS|FRAC_DIGITS|[PN]_CS_PRECEDES|[PN]_SEP_BY_SPACE|[PN]_SIGN_POSN|DECIMAL_POINT|RADIXCHAR|THOUSANDS_SEP|THOUSEP|GROUPING'
        . '|YESEXPR|NOEXPR|YESSTR|NOSTR|CODESET)$/';
    $unixOnly = array_values(array_filter($consts, fn($n) => preg_match($langinfo, $n) === 1));
    check('... nor one that PHP defines only on Linux and macOS', !$unixOnly, implode(', ', $unixOnly));
    file_put_contents($dir . DS . 'boot.php', '<?php $w = []; set_error_handler(function ($no, $msg) use (&$w) { $w[] = $msg; return true; });'
        . " require __DIR__ . '/lib.php'; restore_error_handler(); echo json_encode(['warnings' => \$w, 'money' => money(4100000)]);");
    $o = run_php($dir . DS . 'boot.php');
    $bj = json_decode($o, true) ?: [];
    check('the app starts without a single PHP warning', ($bj['warnings'] ?? null) === [], $o);
    check('amounts print as "$41,000.00 USD"', ($bj['money'] ?? '') === '$41,000.00 USD', $o);

    section('Companies and contacts');
    $r = $c->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Cara', 'last_name' => 'Contact', 'email' => 'cara@example.test', 'company' => 'Acme Pty Ltd', 'tags' => 'lead']);
    $cara = id_from($r, '/contact\.php\?id=(\d+)/');
    $acme = (int) one($dir, "SELECT id FROM companies WHERE name='Acme Pty Ltd'");
    check('typing a company on a contact creates it', $cara > 0 && $acme > 0);
    check('the contact is linked to the company', (int) one($dir, "SELECT company_id FROM contacts WHERE id=?", [$cara]) === $acme);
    check('a new contact is owned by whoever added it', (int) one($dir, "SELECT owner_id FROM contacts WHERE id=?", [$cara]) === $adminId);
    $r = $c->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Dan', 'email' => 'dan@example.test', 'company' => '  acme   pty ltd ']);
    $dan = id_from($r, '/contact\.php\?id=(\d+)/');
    check('the same company in different case and spacing is reused', (int) one($dir, "SELECT COUNT(*) FROM companies") === 1 && (int) one($dir, "SELECT company_id FROM contacts WHERE id=?", [$dan]) === $acme);
    check("the contact's company text follows the company's name", one($dir, "SELECT company FROM contacts WHERE id=?", [$dan]) === 'Acme Pty Ltd');
    $c->post('/contacts.php', ['do' => 'save', 'id' => $dan, 'first_name' => 'Dan', 'email' => 'dan@example.test', 'company' => '']);
    $d = row($dir, "SELECT company, company_id FROM contacts WHERE id=?", [$dan]);
    check('clearing the company unlinks the contact', $d['company'] === '' && (int) $d['company_id'] === 0);
    $r = $c->get('/companies.php');
    check('companies list shows the company', $r['code'] === 200 && strpos($r['body'], 'Acme Pty Ltd') !== false, 'HTTP ' . $r['code']);
    $r = $c->get('/company.php?id=' . $acme);
    check('company page shows its people', $r['code'] === 200 && strpos($r['body'], 'Cara Contact') !== false && strpos($r['body'], 'Dan') === false, 'HTTP ' . $r['code']);
    $r = $c->post('/companies.php', ['do' => 'add', 'name' => 'Beta Co', 'website' => 'https://www.Beta.example/about', 'industry' => 'Retail']);
    $beta = id_from($r, '/company\.php\?id=(\d+)/');
    check('a company can be added directly, with its domain worked out', $beta > 0 && one($dir, "SELECT domain FROM companies WHERE id=?", [$beta]) === 'beta.example');
    $r = $c->post('/companies.php', ['do' => 'add', 'name' => 'beta co']);
    check('adding a company that exists goes to the existing one', id_from($r, '/company\.php\?id=(\d+)/') === $beta && (int) one($dir, "SELECT COUNT(*) FROM companies") === 2);
    $c->post('/company.php?id=' . $acme, ['do' => 'save', 'name' => 'Acme Australia', 'website' => '', 'phone' => '', 'industry' => '', 'address' => '', 'notes' => 'Key account', 'owner_id' => $adminId]);
    check('renaming a company updates its contacts', one($dir, "SELECT company FROM contacts WHERE id=?", [$cara]) === 'Acme Australia');
    $r = $c->post('/company.php?id=' . $acme, ['do' => 'save', 'name' => 'Beta Co', 'website' => '', 'phone' => '', 'industry' => '', 'address' => '', 'notes' => '', 'owner_id' => 0]);
    check('a company cannot be renamed onto another company', one($dir, "SELECT name FROM companies WHERE id=?", [$acme]) === 'Acme Australia');
    $r = $c->post('/companies.php', ['do' => 'add', 'name' => '<script>alert(1)</script>Evil Co']);
    $evil = id_from($r, '/company\.php\?id=(\d+)/');
    $r = $c->get('/company.php?id=' . $evil);
    check('a company name is escaped on the page', strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], '&lt;script&gt;') !== false);
    $r = $c->get('/companies.php?export=1');
    check('companies export as CSV', $r['code'] === 200 && str_starts_with($r['body'], 'name,website,domain') && strpos($r['body'], 'Beta Co') !== false);

    section('Deals');
    $c->get('/pipelines.php');
    $pid = (int) one($dir, "SELECT id FROM pipelines ORDER BY id LIMIT 1");
    $next = date('Y-m-d', mktime(0, 0, 0, (int) date('n') + 1, 15));
    $c->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $pid, 'name' => 'Smoke deal', 'value' => '2000', 'stage' => 1, 'close_date' => $next, 'probability' => '', 'company' => '', 'contact_id' => $cara, 'owner_id' => $adminId, 'notes' => 'first']);
    $deal = row($dir, "SELECT * FROM deals WHERE name='Smoke deal'");
    $did = (int) ($deal['id'] ?? 0);
    check('a deal is created with value, stage, close date and owner', $did > 0 && (int) $deal['value_cents'] === 200000 && (int) $deal['stage'] === 1 && $deal['close_date'] === $next && (int) $deal['owner_id'] === $adminId);
    check("a new deal takes its contact's company", (int) $deal['company_id'] === $acme);
    check('win probability defaults to the stage', (int) $deal['probability'] === -1);
    $c->post('/pipelines.php', ['do' => 'save_deal', 'id' => $did, 'name' => 'Smoke deal 2', 'value' => '2500.50', 'stage' => 2, 'close_date' => 'not-a-date', 'probability' => '70', 'company' => 'Beta Co', 'contact_id' => $cara, 'owner_id' => 0, 'notes' => 'edited']);
    $deal = row($dir, "SELECT * FROM deals WHERE id=?", [$did]);
    check('a deal can be edited', $deal['name'] === 'Smoke deal 2' && (int) $deal['value_cents'] === 250050 && (int) $deal['stage'] === 2 && (int) $deal['probability'] === 70 && (int) $deal['company_id'] === $beta && $deal['notes'] === 'edited' && (int) $deal['owner_id'] === 0);
    check('an invalid close date is not stored', $deal['close_date'] === '');
    $r = $c->post('/pipelines.php', ['do' => 'move', 'id' => $did, 'stage' => 3]);
    check('a deal moves to another stage', strpos($r['body'], '"ok":true') !== false && (int) one($dir, "SELECT stage FROM deals WHERE id=?", [$did]) === 3, $r['body']);
    $r = $c->post('/pipelines.php', ['do' => 'move', 'id' => $did, 'stage' => 99]);
    check('a move to a stage that does not exist is refused', strpos($r['body'], '"ok":false') !== false && (int) one($dir, "SELECT stage FROM deals WHERE id=?", [$did]) === 3, $r['body']);
    $c->post('/pipelines.php', ['do' => 'deal_status', 'id' => $did, 'status' => 'lost', 'lost_reason' => 'Price']);
    $deal = row($dir, "SELECT * FROM deals WHERE id=?", [$did]);
    check('a deal is marked lost with a reason and a closed time', $deal['status'] === 'lost' && $deal['lost_reason'] === 'Price' && (int) $deal['closed_at'] > 0);
    $c->post('/pipelines.php', ['do' => 'deal_status', 'id' => $did, 'status' => 'open']);
    $deal = row($dir, "SELECT * FROM deals WHERE id=?", [$did]);
    check('a closed deal can be reopened', $deal['status'] === 'open' && $deal['lost_reason'] === '' && (int) $deal['closed_at'] === 0);
    $c->post('/pipelines.php', ['do' => 'deal_status', 'id' => $did, 'status' => 'won']);
    check('a deal is marked won', one($dir, "SELECT status FROM deals WHERE id=?", [$did]) === 'won' && (int) one($dir, "SELECT closed_at FROM deals WHERE id=?", [$did]) > 0);
    $c->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $pid, 'name' => 'x</script><img src=x onerror=alert(1)>', 'value' => '0', 'stage' => 0, 'contact_id' => 0, 'owner_id' => 0]);
    $xss = (int) one($dir, "SELECT id FROM deals WHERE name LIKE 'x</script>%'");
    $r = $c->get('/pipelines.php?id=' . $pid . '&deal=' . $xss);
    check('a deal name cannot break out of the page script', $r['code'] === 200 && strpos($r['body'], '</script><img src=x') === false && strpos($r['body'], 'FOCUS=' . $xss) !== false);
    $c->post('/pipelines.php', ['do' => 'del_deal', 'id' => $xss]);
    check('a deal can be deleted', (int) one($dir, "SELECT COUNT(*) FROM deals WHERE id=?", [$xss]) === 0);

    section('Forecast');
    $c->post('/pipelines.php', ['do' => 'save_pipeline', 'pipeline_id' => $pid, 'name' => 'Sales', 'stages' => 'A, B, C', 'probs' => '10, 50']);
    check('stage win probabilities are saved, with gaps filled in', one($dir, "SELECT stage_probs FROM pipelines WHERE id=?", [$pid]) === '[10,50,75]');
    $thisMonth = date('Y-m-d', mktime(0, 0, 0, (int) date('n'), 28));
    $c->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $pid, 'name' => 'Forecast X', 'value' => '1000', 'stage' => 2, 'close_date' => $thisMonth, 'probability' => '50', 'contact_id' => 0, 'owner_id' => 0]);
    $c->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $pid, 'name' => 'Forecast Y', 'value' => '3000', 'stage' => 0, 'close_date' => '', 'probability' => '', 'contact_id' => 0, 'owner_id' => 0]);
    $r = $c->get('/pipelines.php?id=' . $pid);   // X: 1000 x 50% = 500, Y: 3000 x 10% (stage default) = 300
    check('the board shows the weighted total', $r['code'] === 200 && strpos($r['body'], 'weighted <b>$800.00 USD</b>') !== false);
    $r = $c->get('/reports.php');
    check('reports show the forecast', $r['code'] === 200 && strpos($r['body'], 'Forecast') !== false && strpos($r['body'], '$800.00 USD</b> of $4,000.00 USD open') !== false);
    check('reports show sales performance', strpos($r['body'], 'Win rate') !== false && strpos($r['body'], '100%') !== false && strpos($r['body'], 'No close date') !== false);

    section('REST API');
    $c->get('/settings.php');
    $tok = (string) one($dir, "SELECT v FROM settings WHERE k='api_token'");
    $r = $c->api('GET', 'me', 'wrong-token');
    check('a bad token is rejected', $r['code'] === 401, 'HTTP ' . $r['code']);
    $r = $c->api('GET', 'me', $tok);
    $version = preg_match("/const APP_VERSION = '([^']+)'/", (string) file_get_contents($ROOT . DS . '_boot.php'), $vm) ? $vm[1] : '?';
    check('/me reports the version and counts', $r['code'] === 200 && ($r['json']['version'] ?? '') === $version && (int) ($r['json']['companies'] ?? 0) === 3, $r['body']);
    $r = $c->api('GET', 'contacts', $tok);
    $first = $r['json']['data'][0] ?? [];
    $v10 = ['id', 'first_name', 'last_name', 'email', 'phone', 'company', 'address', 'tags', 'source', 'unsubscribed', 'created_at'];
    check('contacts keep every v1.0 field and gain company_id', !array_diff($v10, array_keys($first)) && array_key_exists('company_id', $first));
    $r = $c->api('POST', 'contacts', $tok, ['first_name' => 'Gia', 'email' => 'gia@example.test', 'company' => 'Gamma LLC']);
    $gia = (int) ($r['json']['contact']['id'] ?? 0);
    $gamma = (int) one($dir, "SELECT id FROM companies WHERE name='Gamma LLC'");
    check('API contact with a company links it', $r['code'] === 201 && $gamma > 0 && (int) ($r['json']['contact']['company_id'] ?? 0) === $gamma, $r['body']);
    $r = $c->api('POST', 'companies', $tok, ['name' => 'gamma llc', 'website' => 'gamma.example']);
    check('API company update matches by name', $r['code'] === 200 && ($r['json']['created'] ?? null) === false && ($r['json']['company']['domain'] ?? '') === 'gamma.example', $r['body']);
    $r = $c->api('POST', 'deals', $tok, ['name' => 'API deal', 'value' => 1200, 'company' => 'Gamma LLC', 'close_date' => '2027-03-31', 'contact_id' => $gia]);
    $apiDeal = (int) ($r['json']['deal']['id'] ?? 0);
    check('API creates a deal', $r['code'] === 201 && $apiDeal > 0 && (int) $r['json']['deal']['company_id'] === $gamma && $r['json']['deal']['status'] === 'open' && $r['json']['deal']['close_date'] === '2027-03-31', $r['body']);
    $r = $c->api('POST', 'deals', $tok, ['name' => 'Bad date', 'close_date' => '31/03/2027']);
    check('API refuses a badly formatted close date', $r['code'] === 422, 'HTTP ' . $r['code']);
    $r = $c->api('POST', "deals/$apiDeal", $tok, ['status' => 'won']);
    check('API updates only what is sent', $r['code'] === 200 && $r['json']['deal']['status'] === 'won' && (int) $r['json']['deal']['closed_at'] > 0 && $r['json']['deal']['name'] === 'API deal' && (float) $r['json']['deal']['value'] === 1200.0, $r['body']);
    $r = $c->api('GET', 'deals', $tok);
    $names = array_column($r['json']['data'] ?? [], 'name');
    check('deals list is open deals by default, as in v1.0', $r['code'] === 200 && in_array('Forecast X', $names, true) && !in_array('API deal', $names, true) && !array_diff(['id', 'name', 'value', 'stage', 'contact_id'], array_keys($r['json']['data'][0] ?? [])));
    $r = $c->api('GET', 'deals?status=won', $tok);
    check('deals can be listed by status', in_array('API deal', array_column($r['json']['data'] ?? [], 'name'), true));
    $r = $c->api('GET', 'pipelines', $tok);
    check('pipelines list their stage probabilities', ($r['json']['data'][0]['stage_probabilities'] ?? null) === [10, 50, 75], $r['body']);

    section('Merging and deleting companies');
    $c->post('/company.php?id=' . $gamma, ['do' => 'merge', 'into' => $beta]);
    check('merging moves people and deals and removes the old company',
        (int) one($dir, "SELECT COUNT(*) FROM companies WHERE id=?", [$gamma]) === 0
        && (int) one($dir, "SELECT company_id FROM contacts WHERE id=?", [$gia]) === $beta && one($dir, "SELECT company FROM contacts WHERE id=?", [$gia]) === 'Beta Co'
        && (int) one($dir, "SELECT company_id FROM deals WHERE id=?", [$apiDeal]) === $beta);
    check('merging keeps details the surviving company lacked', one($dir, "SELECT domain FROM companies WHERE id=?", [$beta]) === 'beta.example');
    $c->post('/company.php?id=' . $beta, ['do' => 'delete']);
    check('deleting a company keeps its people and deals, unlinked',
        (int) one($dir, "SELECT COUNT(*) FROM companies WHERE id=?", [$beta]) === 0 && (int) one($dir, "SELECT COUNT(*) FROM contacts WHERE id=?", [$gia]) === 1
        && (int) one($dir, "SELECT company_id FROM contacts WHERE id=?", [$gia]) === 0 && one($dir, "SELECT company FROM contacts WHERE id=?", [$gia]) === 'Beta Co'
        && (int) one($dir, "SELECT company_id FROM deals WHERE id=?", [$apiDeal]) === 0);
    foreach (['index.php', 'contacts.php', 'contact.php?id=' . $cara, 'companies.php', 'company.php?id=' . $acme . '&edit=1', 'pipelines.php', 'tasks.php', 'reports.php', 'settings.php'] as $p) {
        $r = $c->get('/' . $p);
        check("page loads without warnings: $p", $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']), 'HTTP ' . $r['code']);
    }

    section('Timezone');
    $cfg = $dir . DS . 'config.php';
    file_put_contents($dir . DS . 'tz.php', "<?php require __DIR__ . '/_boot.php'; db(); echo date_default_timezone_get();");
    $c->post('/settings.php', ['do' => 'org', 'name' => 'Smoke Org', 'timezone' => 'Pacific/Auckland', 'notify_email' => '']);
    check('the timezone chosen in Settings is saved', (json_decode((string) one($dir, "SELECT v FROM settings WHERE k='org'"), true)['timezone'] ?? '') === 'Pacific/Auckland');
    $r = $c->get('/tz.php');
    check('the timezone chosen in Settings applies to pages', $r['body'] === 'Pacific/Auckland', $r['body']);
    $o = run_php($dir . DS . 'tz.php');
    check('the timezone chosen in Settings applies on the command line', $o === 'Pacific/Auckland', $o);
    file_put_contents($cfg, "<?php return ['timezone' => 'Asia/Tokyo'];");
    $r = $c->get('/tz.php');
    check('a timezone in config.php wins over Settings', $r['body'] === 'Asia/Tokyo', $r['body']);
    $r = $c->get('/settings.php');
    check('Settings shows that config.php sets the timezone', str_contains($r['body'], "Set by 'timezone' in config.php") && str_contains($r['body'], '<select disabled><option>Asia/Tokyo</option>'));

    section('Email links from the command line');
    [$smtpPort, $mail] = smtp_sink();
    $c->post('/settings.php', ['do' => 'email_save', 'id' => 0, 'acc_name' => 'Smoke SMTP', 'from_email' => 'crm@example.test', 'from_name' => 'Smoke Org',
        'smtp_host' => '127.0.0.1', 'smtp_port' => $smtpPort, 'smtp_secure' => 'none', 'smtp_user' => '', 'smtp_pass' => '', 'is_default' => 'on']);
    $accId = (int) one($dir, "SELECT id FROM email_accounts WHERE from_email='crm@example.test'");
    $c->post('/settings.php', ['do' => 'email_test', 'id' => $accId]);
    $m = mail_to($mail, 'ada@example.test');
    check('mail sent from a web page greets the server as the web host, without its port', ($m['ehlo'] ?? '') === '[127.0.0.1]' && str_ends_with($m['headers']['message-id'] ?? '', '@[127.0.0.1]>'), ($m['ehlo'] ?? 'no mail') . ' | ' . ($m['headers']['message-id'] ?? ''));
    $r = $c->get('/settings.php');
    check('Settings says cron has not run yet', str_contains($r['body'], 'cron.php has not run yet') && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']));

    file_put_contents($cfg, "<?php return ['dev' => false];");   // no base_url; dev off, so campaigns wait for cron
    $o = run_php($dir . DS . 'cron.php');
    check('cron on the command line without base_url warns', str_contains($o, 'WARNING:') && str_contains($o, "'base_url'"), $o);
    $auckland = (new DateTime('now', new DateTimeZone('Pacific/Auckland')))->format('P');
    check('cron uses the timezone chosen in Settings', preg_match('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d([+-]\d\d:\d\d) workflows_processed=0 campaign_emails_sent=0/', $o, $om) === 1 && $om[1] === $auckland, $o);
    $r = $c->get('/settings.php');
    check('Settings explains the broken links and the line to add', str_contains($r['body'], esc("config.php has no public 'base_url'")) && str_contains($r['body'], "'base_url' =&gt; 'https://crm.example.com',"));
    $r = $c->get('/index.php');
    check('admins see the warning on every page', str_contains($r['body'], 'The links in email sent by cron.php cannot work'));

    file_put_contents($cfg, "<?php return ['dev' => false, 'cron_key' => 'smoke-cron-key'];");
    $r = $c->get('/cron.php?key=wrong');
    check('cron over the web needs the key', $r['code'] === 403, 'HTTP ' . $r['code']);
    $r = $c->get('/cron.php?key=smoke-cron-key');
    check('cron over the web runs with the key', $r['code'] === 200 && str_contains($r['body'], 'workflows_processed=0'), $r['body']);
    $r = $c->get('/settings.php');
    check('Settings warns when cron is called at a local address', str_contains($r['body'], 'cron.php is called at ' . $base . ', a local address'));

    file_put_contents($cfg, "<?php return ['dev' => false, 'base_url' => 'https://crm.example.test/sub/'];");
    $sent = mail_count($mail);
    $c->post('/campaigns.php', ['do' => 'send', 'id' => 0, 'name' => 'Smoke campaign', 'subject' => 'Hello {{first_name}}', 'body' => '<p>Hi {{first_name}}, news from {{org}}.</p>',
        'from_account' => 0, 'seg_type' => 'tag', 'seg_tag' => 'lead']);
    $camp = row($dir, "SELECT * FROM campaigns WHERE name='Smoke campaign'");
    check('with dev off a campaign waits for cron', ($camp['status'] ?? '') === 'sending' && mail_count($mail) === $sent);
    $o = run_php($dir . DS . 'cron.php');
    check('cron with base_url sends the campaign, without a warning', str_contains($o, 'campaign_emails_sent=1') && !str_contains($o, 'WARNING'), $o);
    $m = mail_to($mail, 'cara@example.test');
    $unsub = unsub_link($m, 'https://crm.example.test/sub');
    check('the campaign reached the tagged contact, merge tags filled in', ($m['headers']['subject'] ?? '') === 'Hello Cara' && str_contains($m['html'] ?? '', 'Hi Cara, news from Smoke Org.'));
    check('its unsubscribe link uses base_url', $unsub !== '', substr($m['html'] ?? 'no mail', 0, 400));
    check('its List-Unsubscribe header uses base_url', ($m['headers']['list-unsubscribe'] ?? '') === "<$unsub>", $m['headers']['list-unsubscribe'] ?? '');
    check('its open-tracking pixel uses base_url', str_contains($m['html'] ?? '', 'src="https://crm.example.test/sub/t.php?c=' . (int) ($camp['id'] ?? 0) . '&amp;r='));
    check('nothing in it points at this machine', !preg_match('/localhost|127\.0\.0\.1/i', ($m['html'] ?? '') . implode("\n", $m['headers'] ?? [])));
    check('EHLO and Message-ID use the host of base_url', ($m['ehlo'] ?? '') === 'crm.example.test' && str_ends_with($m['headers']['message-id'] ?? '', '@crm.example.test>'), ($m['ehlo'] ?? '') . ' | ' . ($m['headers']['message-id'] ?? ''));
    $r = $c->get(substr($unsub, strlen('https://crm.example.test/sub')));   // the same link, on the test server
    check('the unsubscribe link in the email works', str_contains($r['body'], "You're unsubscribed") && (int) one($dir, "SELECT dnd_email FROM contacts WHERE id=?", [$cara]) === 1);
    $r = $c->get('/settings.php');
    check('Settings shows the last run and its address, and no warning', str_contains($r['body'], 'last ran just now from the command line. Links in the email it sends use https://crm.example.test/sub.') && !str_contains($r['body'], 'cannot work'));
    check('web pages keep using their own address, not base_url', str_contains($r['body'], $base . '/api.php/contacts'));

    section('Workflow email');
    $c->post('/workflows.php', ['do' => 'save', 'id' => 0, 'name' => 'Welcome', 'trigger_on' => 'contact_created', 's_type' => ['email'], 's_subject' => ['Welcome {{first_name}}'], 's_body' => ['Thanks for joining {{org}}.']]);
    $wfId = (int) one($dir, "SELECT id FROM workflows WHERE name='Welcome'");
    $c->post('/workflows.php', ['do' => 'toggle', 'id' => $wfId]);
    $c->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Wren', 'email' => 'wren@example.test']);
    $c->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Yul', 'email' => 'yul@example.test', 'dnd_email' => 'on']);
    $o = run_php($dir . DS . 'cron.php');
    $m = mail_to($mail, 'wren@example.test');
    $wun = unsub_link($m, 'https://crm.example.test/sub');
    check('cron sends the workflow email', ($m['headers']['subject'] ?? '') === 'Welcome Wren' && str_contains($m['html'] ?? '', 'Thanks for joining Smoke Org.'), $o);
    check('a workflow email carries an unsubscribe link, using base_url', $wun !== '' && str_contains($m['html'] ?? '', 'You are receiving this from Smoke Org.'), substr($m['html'] ?? 'no mail', 0, 400));
    check('a workflow email has a List-Unsubscribe header', $wun !== '' && ($m['headers']['list-unsubscribe'] ?? '') === "<$wun>", $m['headers']['list-unsubscribe'] ?? '');
    check('workflow email replies go to the sending account', ($m['headers']['reply-to'] ?? '') === 'crm@example.test');
    check('an unsubscribed contact gets no workflow email', mail_to($mail, 'yul@example.test') === [] && (int) one($dir, "SELECT COUNT(*) FROM wf_enrollments WHERE workflow_id=? AND status='done'", [$wfId]) === 2);
    $r = $c->get(substr($wun, strlen('https://crm.example.test/sub')));
    check('the workflow unsubscribe link works', str_contains($r['body'], "You're unsubscribed") && (int) one($dir, "SELECT dnd_email FROM contacts WHERE email='wren@example.test'") === 1);

    section('Reply-To');
    file_put_contents($dir . DS . 'mail.php', <<<'PHP'
        <?php require __DIR__ . '/lib.php';
        send_mail('r1@example.test', 'Own reply-to', '<p>1</p>', ['reply_to' => 'replies@example.test']);
        send_mail('r2@example.test', 'Default reply-to', '<p>2</p>');
        send_mail('r3@example.test', 'Bad reply-to', '<p>3</p>', ['reply_to' => "x@example.test\r\nBcc: evil@example.test"]);
        echo 'sent';
        PHP);
    $o = run_php($dir . DS . 'mail.php');
    check('send_mail lets the caller choose Reply-To', (mail_to($mail, 'r1@example.test')['headers']['reply-to'] ?? '') === 'replies@example.test', $o);
    check('send_mail replies go to the sending account by default', (mail_to($mail, 'r2@example.test')['headers']['reply-to'] ?? '') === 'crm@example.test');
    $m = mail_to($mail, 'r3@example.test');
    check('a Reply-To that would add a header of its own is left out', $m !== [] && !isset($m['headers']['reply-to']) && !isset($m['headers']['bcc']));

    section('Public forms');
    $c->post('/forms.php', ['do' => 'save', 'id' => 0, 'name' => 'Contact us', 'thankyou' => 'Thanks, talk soon.', 'redirect' => '',
        'fld_label' => ['Email', 'First name'], 'fld_name' => ['email', 'first_name'], 'fld_type' => ['email', 'text'], 'fld_req' => ['1', '']]);
    $fid = (int) one($dir, "SELECT id FROM forms WHERE slug='contact-us'");
    $visitor = new Client($base, $dir . DS . 'visitor-jar.txt');
    $r = $visitor->post('/f.php?s=contact-us', ['f_email' => 'lea@example.test', 'f_first_name' => 'Lea'], false);
    check('a visitor who submits a form sees the thank-you message', $r['code'] === 200 && str_contains($r['body'], 'Thanks, talk soon.') && !str_contains($r['body'], 'name="f_email"'), 'HTTP ' . $r['code']);
    check('... without PHP warnings on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']));
    check('the submission becomes a contact and a form submission', one($dir, "SELECT first_name FROM contacts WHERE email='lea@example.test'") === 'Lea' && (int) one($dir, "SELECT COUNT(*) FROM form_submissions WHERE form_id=?", [$fid]) === 1);
    $r = $visitor->post('/f.php?s=contact-us', ['f_email' => 'not-an-email', 'f_first_name' => 'X'], false);
    check('an invalid email shows the error and the form again, and saves nothing', str_contains($r['body'], 'Please enter a valid email address.') && str_contains($r['body'], 'name="f_email"') && (int) one($dir, "SELECT COUNT(*) FROM form_submissions WHERE form_id=?", [$fid]) === 1);
    $c->post('/forms.php', ['do' => 'save', 'id' => $fid, 'name' => 'Contact us', 'thankyou' => 'Thanks, talk soon.', 'redirect' => 'https://example.test/thanks',
        'fld_label' => ['Email'], 'fld_name' => ['email'], 'fld_type' => ['email'], 'fld_req' => ['1']]);
    $r = $visitor->post('/f.php?s=contact-us', ['f_email' => 'max@example.test'], false);
    check('a form with a redirect sends the visitor there after submitting', $r['code'] === 302 && $r['location'] === 'https://example.test/thanks', 'HTTP ' . $r['code'] . ' ' . $r['location']);
    stop($srv);

    /* ============================================ C. tag-added workflows === */
    section('Workflows triggered by a tag');
    $tdir = tmpdir('tags');
    copy_app($ROOT, $tdir);
    [$tbase, $tsrv] = serve($tdir);
    $t = new Client($tbase, $tdir . DS . 'jar.txt');
    setup_admin($t, 'tess@example.test', 'smoke-pass-3');
    $t->loadCsrf('/workflows.php?new=1');
    $vip = make_workflow($t, $tdir, 'VIP follow-up', 'tag_added', 'vip', [['task', 'VIP call {{first_name}}']]);
    check('a workflow on "A tag is added" is saved and switched on', $vip > 0 && one($tdir, "SELECT status FROM workflows WHERE id=?", [$vip]) === 'active');
    $t->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Ula', 'email' => 'ula@example.test', 'tags' => 'vip']);
    check('a contact created with the tag enters the workflow', enrolled($tdir, $vip) === 1);
    $r = $t->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Vik', 'email' => 'vik@example.test', 'tags' => 'lead']);
    $vik = id_from($r, '/contact\.php\?id=(\d+)/');
    check('a contact with a different tag does not', $vik > 0 && enrolled($tdir, $vip) === 1);
    $t->post('/contacts.php', ['do' => 'save', 'id' => $vik, 'first_name' => 'Vik', 'email' => 'vik@example.test', 'tags' => 'lead, vip']);
    check('adding the tag to an existing contact enrols it', enrolled($tdir, $vip) === 2);
    $t->post('/contacts.php', ['do' => 'save', 'id' => $vik, 'first_name' => 'Vik', 'email' => 'vik@example.test', 'tags' => 'vip, lead']);
    check('saving a contact that already has the tag does not enrol it again', enrolled($tdir, $vip) === 2);
    $o = run_php($tdir . DS . 'cron.php');
    check('cron runs the tag workflow for both', (int) one($tdir, "SELECT COUNT(*) FROM tasks WHERE title IN ('VIP call Ula', 'VIP call Vik')") === 2 && enrolled($tdir, $vip, 'done') === 2, $o);

    $t->get('/settings.php');
    $ttok = (string) one($tdir, "SELECT v FROM settings WHERE k='api_token'");
    $t->api('POST', 'contacts', $ttok, ['first_name' => 'Wes', 'email' => 'wes@example.test', 'tags' => ['lead']]);
    $r = $t->api('POST', 'contacts', $ttok, ['email' => 'wes@example.test', 'phone' => '0400 000 000']);
    check('an API update without "tags" keeps the contact\'s tags', $r['code'] === 201 && tags_of($tdir, 'wes@example.test') === ['lead'], json_encode(tags_of($tdir, 'wes@example.test')));
    $t->api('POST', 'contacts', $ttok, ['email' => 'wes@example.test', 'tags' => ['lead', 'vip']]);
    check('a tag added through the API enrols the contact', enrolled($tdir, $vip) === 3);

    file_put_contents($tdir . DS . 'with-tags.csv', "email,first_name,tags\nxia@example.test,Xia,\"vip, lead\"\n");
    $t->upload('/contacts.php', ['do' => 'import'], 'csv', $tdir . DS . 'with-tags.csv');
    check('a contact imported with the tag enters the workflow', tags_of($tdir, 'xia@example.test') === ['vip', 'lead'] && enrolled($tdir, $vip) === 4);
    file_put_contents($tdir . DS . 'no-tags.csv', "email,phone\nvik@example.test,0411 111 111\n");
    $t->upload('/contacts.php', ['do' => 'import'], 'csv', $tdir . DS . 'no-tags.csv');
    check('importing a file without a tags column keeps the contact\'s tags', one($tdir, "SELECT phone FROM contacts WHERE email='vik@example.test'") === '0411 111 111' && tags_of($tdir, 'vik@example.test') === ['vip', 'lead'] && enrolled($tdir, $vip) === 4);

    $tagger = make_workflow($t, $tdir, 'Tag new contacts', 'contact_created', '', [['tag', 'vip']]);
    $t->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Yan', 'email' => 'yan@example.test', 'tags' => '']);
    run_php($tdir . DS . 'cron.php');
    check("a workflow's tag step starts the workflow on that tag", tags_of($tdir, 'yan@example.test') === ['vip'] && enrolled($tdir, $vip) === 5);
    $o = run_php($tdir . DS . 'cron.php');
    check('... which then runs on the next cron run', (int) one($tdir, "SELECT COUNT(*) FROM tasks WHERE title='VIP call Yan'") === 1, $o);
    $t->post('/workflows.php', ['do' => 'toggle', 'id' => $tagger]);   // pause it again

    $ping = make_workflow($t, $tdir, 'Ping', 'tag_added', 'ping', [['tag', 'pong']]);
    $pong = make_workflow($t, $tdir, 'Pong', 'tag_added', 'pong', [['tag', 'ping']]);
    $any = make_workflow($t, $tdir, 'Any tag', 'tag_added', '', [['tag', 'seen']]);
    $t->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Zed', 'email' => 'zed@example.test', 'tags' => 'ping']);
    for ($i = 0; $i < 4; $i++) run_php($tdir . DS . 'cron.php');
    $zed = tags_of($tdir, 'zed@example.test'); sort($zed);
    // Ping adds pong, Pong adds ping (already there), "Any tag" adds seen: each runs once (Any at most twice), then all is quiet.
    check('workflows that add each other\'s trigger tags settle instead of looping', $zed === ['ping', 'pong', 'seen']
        && enrolled($tdir, $ping) === 1 && enrolled($tdir, $pong) === 1 && enrolled($tdir, $any) <= 2
        && (int) one($tdir, "SELECT COUNT(*) FROM wf_enrollments WHERE status='active'") === 0,
        json_encode([$zed, enrolled($tdir, $ping), enrolled($tdir, $pong), enrolled($tdir, $any)]));
    foreach (['contacts.php', 'workflows.php', 'workflows.php?edit=' . $vip] as $p) {
        $r = $t->get('/' . $p);
        check("page loads without warnings: $p", $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']), 'HTTP ' . $r['code']);
    }
    stop($tsrv);

    /* ================================== D. add-ons, currency, install lock === */
    section('Install lock');
    $adir = tmpdir('addons');
    copy_app($ROOT, $adir);
    $acfg = $adir . DS . 'config.php';
    file_put_contents($acfg, "<?php return ['allow_install' => false];");
    [$abase, $asrv] = serve($adir);
    $a = new Client($abase, $adir . DS . 'jar.txt');
    $r = $a->get('/index.php');
    check('with allow_install off, a CRM without accounts says "Not set up yet"', $r['code'] === 503 && str_contains($r['body'], 'Not set up yet') && !str_contains($r['body'], 'Create my CRM'), 'HTTP ' . $r['code']);
    setup_admin($a, 'ann@example.test', 'smoke-pass-4');
    check('... and posting the set-up form creates no account', (int) one($adir, "SELECT COUNT(*) FROM users") === 0);
    file_put_contents($acfg, "<?php return ['currency' => 'aud'];");
    $r = setup_admin($a, 'ann@example.test', 'smoke-pass-4');
    check('without the lock, set-up works as before', $r['code'] === 302 && (int) one($adir, "SELECT COUNT(*) FROM users") === 1, 'HTTP ' . $r['code']);

    section('Currency');
    $r = $a->get('/index.php');
    check('config.php currency sets the code after amounts', str_contains($r['body'], '<div class="n">$0.00 AUD</div>'));
    $euro = html_entity_decode('&euro;', ENT_QUOTES, 'UTF-8');
    file_put_contents($acfg, "<?php return ['currency' => '', 'currency_symbol' => '$euro'];");
    $r = $a->get('/index.php');
    check('currency_symbol sets the sign, and an empty code shows none', str_contains($r['body'], '<div class="n">' . $euro . '0.00</div>'));
    file_put_contents($acfg, "<?php return [];");

    section('Add-ons');
    mkdir($adir . DS . 'addons' . DS . 'smoke', 0700, true);
    file_put_contents($adir . DS . 'addons' . DS . 'smoke' . DS . 'addon.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        if (!defined('APP_VERSION')) { http_response_code(404); exit; }
        function smoke_addon_log(string $line): void { file_put_contents(DATA_DIR . '/addon.log', $line . "\n", FILE_APPEND); }
        addon_on('authed', function (array $u): void { smoke_addon_log('authed ' . $u['email']); });
        addon_on('user_added', function (int $id): void { smoke_addon_log("user_added $id"); });
        addon_on('password_problem', fn(string $msg, string $new, array $u): string => $msg !== '' ? $msg : (str_contains($new, 'password') ? 'Smoke add-on: no "password" in passwords.' : ''));
        addon_on('password_changed', function (int $id): void { smoke_addon_log("password_changed $id"); });
        addon_on('contact_deleted', function (int $id): void { smoke_addon_log("contact_deleted $id"); });
        addon_on('nav', function (array $nav, ?array $u): array { $nav['smoke'] = ['index.php?smoke=1', 'smoke', 'Smoke page']; return $nav; });
        addon_on('icons', fn(array $p): array => $p + ['smoke' => '<circle cx="12" cy="12" r="3" data-smoke-icon="1"/>']);
        addon_on('dashboard', fn(array $u): string => '<div class="card" id="smoke-dash">Smoke dashboard for ' . h($u['email']) . '</div>');
        addon_on('contact_panels', fn(array $c, array $u): string => '<div class="card" id="smoke-panel">Smoke panel for ' . h(contact_name($c)) . '</div>');
        addon_on('settings_cards', fn(array $u): string => '<div class="card" id="smoke-settings">Smoke settings</div>');
        addon_on('campaign_editor', fn(array $camp, array $seg, array $u): string => '<div id="smoke-camp">Smoke audience: ' . h((string) ($seg['type'] ?? '')) . '</div>');
        addon_on('form_elsewhere', fn(string $url, array $form): string => $form['slug'] === 'elsewhere' ? 'https://forms.example.test/contact' : $url);
        PHP);
    $alog = fn(): string => (string) @file_get_contents($adir . DS . 'data' . DS . 'addon.log');
    $annId = (int) one($adir, "SELECT id FROM users WHERE email='ann@example.test'");
    $r = $a->get('/index.php');
    check('the dashboard prints the add-on\'s HTML', str_contains($r['body'], 'id="smoke-dash">Smoke dashboard for ann@example.test'));
    check('the nav filter adds a menu item, with the icon from the icons filter', str_contains($r['body'], 'Smoke page') && str_contains($r['body'], 'data-smoke-icon="1"'));
    check('authed runs on a signed-in request', str_contains($alog(), 'authed ann@example.test'));
    $a->loadCsrf('/contacts.php?new=1');
    $r = $a->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Pia', 'email' => 'pia@example.test']);
    $pia = id_from($r, '/contact\.php\?id=(\d+)/');
    $r = $a->get('/contact.php?id=' . $pia);
    check('the contact page prints the add-on\'s panel', str_contains($r['body'], 'id="smoke-panel">Smoke panel for Pia'));
    $a->post('/contacts.php', ['do' => 'delete', 'id' => $pia]);
    check('contact_deleted runs when a contact is deleted', str_contains($alog(), "contact_deleted $pia") && (int) one($adir, "SELECT COUNT(*) FROM contacts WHERE id=?", [$pia]) === 0);
    $pat = id_from($a->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Pat', 'email' => 'pat@example.test']), '/contact\.php\?id=(\d+)/');
    $pru = id_from($a->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Pru', 'email' => 'pru@example.test']), '/contact\.php\?id=(\d+)/');
    $a->post('/contacts.php', ['do' => 'bulk', 'bulk' => 'delete', 'confirm' => 'yes', 'ids' => [$pat, $pru]]);
    check('... and once for each contact in a bulk delete', $pat > 0 && $pru > 0 && str_contains($alog(), "contact_deleted $pat") && str_contains($alog(), "contact_deleted $pru")
        && (int) one($adir, "SELECT COUNT(*) FROM contacts WHERE id IN (?, ?)", [$pat, $pru]) === 0);
    $r = $a->get('/settings.php');
    check('Settings prints the add-on\'s cards', str_contains($r['body'], 'id="smoke-settings"'));
    $a->post('/settings.php', ['do' => 'add_user', 'uname' => 'Ben', 'email' => 'ben@example.test', 'upass' => 'smoke-pass-5', 'role' => 'member']);
    $benId = (int) one($adir, "SELECT id FROM users WHERE email='ben@example.test'");
    check('user_added runs for a new team member', $benId > 0 && str_contains($alog(), "user_added $benId"));
    $hash = (string) one($adir, "SELECT pass_hash FROM users WHERE id=?", [$annId]);
    $a->post('/settings.php', ['do' => 'passwd', 'current' => 'smoke-pass-4', 'new' => 'my-password-1']);
    $r = $a->get('/settings.php');
    check('password_problem can refuse a new password', str_contains($r['body'], esc('Smoke add-on: no "password" in passwords.')) && one($adir, "SELECT pass_hash FROM users WHERE id=?", [$annId]) === $hash);
    $a->post('/settings.php', ['do' => 'passwd', 'current' => 'smoke-pass-4', 'new' => 'smoke-pass-6']);
    check('password_changed runs after a password change', one($adir, "SELECT pass_hash FROM users WHERE id=?", [$annId]) !== $hash && str_contains($alog(), "password_changed $annId"));
    $r = $a->get('/campaigns.php?new=1');
    check('the campaign editor prints the add-on\'s HTML', str_contains($r['body'], 'id="smoke-camp">Smoke audience: all'));
    $form = ['do' => 'save', 'id' => 0, 'thankyou' => 'Thanks', 'redirect' => '', 'fld_label' => ['Email'], 'fld_name' => ['email'], 'fld_type' => ['email']];
    $a->post('/forms.php', $form + ['name' => 'Elsewhere']);
    $a->post('/forms.php', $form + ['name' => 'Hidden']);
    $elsewhere = (int) one($adir, "SELECT id FROM forms WHERE slug='elsewhere'");
    pdo_for($adir)->exec("UPDATE forms SET status='site' WHERE slug IN ('elsewhere', 'hidden')");   // as an add-on would
    $r = $a->get('/f.php?s=elsewhere');
    check('form_elsewhere sends visitors of a form that lives elsewhere there', $r['code'] === 302 && $r['location'] === 'https://forms.example.test/contact', 'HTTP ' . $r['code'] . ' ' . $r['location']);
    $r = $a->get('/f.php?s=hidden');
    check('a form that is not active and not claimed is not shown', $r['code'] === 404);
    $a->post('/forms.php', ['id' => $elsewhere, 'name' => 'Elsewhere (renamed)'] + $form);
    check('editing such a form keeps its status', one($adir, "SELECT status FROM forms WHERE id=?", [$elsewhere]) === 'site' && one($adir, "SELECT name FROM forms WHERE id=?", [$elsewhere]) === 'Elsewhere (renamed)');
    foreach (['index.php', 'contacts.php', 'companies.php', 'pipelines.php', 'tasks.php', 'campaigns.php', 'workflows.php', 'forms.php', 'reports.php', 'settings.php'] as $p) {
        $r = $a->get('/' . $p);
        check("page loads without warnings with an add-on: $p", $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']), 'HTTP ' . $r['code']);
    }
    $o = run_php($adir . DS . 'cron.php');
    check('cron runs with an add-on installed', str_contains($o, 'workflows_processed=0'), $o);

    section('Tag matching (campaign audiences and the contacts filter)');
    $cafe = html_entity_decode('caf&eacute;', ENT_QUOTES, 'UTF-8');   // a tag with a non-ASCII letter
    foreach ([['Under', 'vip_2026'], ['Decoy', 'vipx2026'], ['Percent', '50%off'], ['Wild', '50xyzoff'], ['Accent', $cafe], ['Plain', 'cafe']] as [$fn, $tg]) {
        $a->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => $fn, 'email' => strtolower($fn) . '@example.test', 'tags' => $tg]);
    }
    $want = ['vip_2026' => ['under@example.test'], 'VIP_2026' => ['under@example.test'], '50%off' => ['percent@example.test'],
        $cafe => ['accent@example.test'], 'cafe' => ['plain@example.test']];
    file_put_contents($adir . DS . 'seg.php', "<?php require __DIR__ . '/lib.php'; \$out = [];\n"
        . 'foreach (' . var_export(array_keys($want), true) . " as \$t) \$out[\$t] = array_column(segment_contacts(['type' => 'tag', 'tag' => \$t]), 'email');\n"
        . 'echo json_encode($out);');
    $o = run_php($adir . DS . 'seg.php');
    $got = json_decode($o, true) ?: [];
    foreach ($want as $tg => $emails) check("a campaign to tag \"" . ($tg === $cafe ? 'cafe (accented)' : $tg) . '" reaches exactly the contacts with it', ($got[$tg] ?? null) === $emails, $o);
    $r = $a->get('/contacts.php?tag=' . rawurlencode($cafe));
    check('the contacts list filters by an accented tag', str_contains($r['body'], 'Accent') && !str_contains($r['body'], '>Plain<') && !str_contains($r['body'], 'Plain</'));
    $r = $a->get('/contacts.php?tag=' . rawurlencode('vip_2026'));
    check('the contacts list treats _ in a tag as a plain character', str_contains($r['body'], 'Under') && !str_contains($r['body'], 'Decoy'));
    stop($asrv);

    /* ============================ E. ownership and assignment (new in 1.2) === */
    section('Owners and assignees');
    $odir = tmpdir('owners');
    copy_app($ROOT, $odir);
    file_put_contents($odir . DS . 'config.php', "<?php return ['base_url' => 'https://crm.example.test'];");   // for cron on the command line
    [$obase, $osrv] = serve($odir);
    $w = new Client($obase, $odir . DS . 'jar.txt');
    setup_admin($w, 'ada@example.test', 'smoke-pass-7');
    $w->loadCsrf('/settings.php');
    $ada = (int) one($odir, "SELECT id FROM users WHERE email='ada@example.test'");
    $member = function (string $name, string $email) use ($w, $odir): int {   // add a team member; returns their id
        $w->post('/settings.php', ['do' => 'add_user', 'uname' => $name, 'email' => $email, 'upass' => 'smoke-pass-8', 'role' => 'member']);
        return (int) one($odir, "SELECT id FROM users WHERE email=?", [$email]);
    };
    $person = function (string $first, string $email, array $more = []) use ($w): int {   // add a contact by hand; returns its id
        return id_from($w->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => $first, 'email' => $email] + $more), '/contact\.php\?id=(\d+)/');
    };
    $ownerOf = fn(int $id): int => (int) one($odir, "SELECT owner_id FROM contacts WHERE id=?", [$id]);
    $ownerByEmail = fn(string $email): int => (int) one($odir, "SELECT owner_id FROM contacts WHERE email=?", [$email]);
    $assignee = fn(string $title): int => (int) one($odir, "SELECT assigned_to FROM tasks WHERE title=?", [$title]);
    $taskId = fn(string $title): int => (int) one($odir, "SELECT id FROM tasks WHERE title=?", [$title]);
    $bob = $member('Bob Member', 'bob@example.test'); $cy = $member('Cy Seller', 'cy@example.test');

    $r = $w->get('/contacts.php?new=1');
    check('the contact form has an owner picker that starts on the person adding the contact', preg_match('/<select name="owner_id">.*?<option value="' . $ada . '" selected>Ada Admin<\/option>/s', $r['body']) === 1);
    $olgaFields = ['first_name' => 'Olga', 'last_name' => 'Owned', 'email' => 'olga@example.test'];
    $olga = $person('Olga', 'olga@example.test', ['last_name' => 'Owned', 'owner_id' => $bob]);
    check('a contact can be added for another owner', $olga > 0 && $ownerOf($olga) === $bob);
    $w->post('/contacts.php', ['do' => 'save', 'id' => $olga, 'owner_id' => $cy] + $olgaFields);
    check('editing a contact can change its owner', $ownerOf($olga) === $cy);
    $w->post('/contacts.php', ['do' => 'save', 'id' => $olga, 'owner_id' => 99999] + $olgaFields);
    check('an owner who does not exist is ignored', $ownerOf($olga) === $cy);
    $w->post('/contacts.php', ['do' => 'save', 'id' => $olga] + $olgaFields);
    check('saving without an owner field keeps the owner', $ownerOf($olga) === $cy);
    $w->post('/contacts.php', ['do' => 'save', 'id' => $olga, 'owner_id' => 0] + $olgaFields);
    check('a contact can be left unassigned', $ownerOf($olga) === 0);
    $w->post('/contact.php?id=' . $olga, ['do' => 'owner', 'owner_id' => $bob]);
    $r = $w->get('/contact.php?id=' . $olga);
    check('the contact page changes the owner and shows who it is', $ownerOf($olga) === $bob && str_contains($r['body'], '<div class="flash ok">Owner changed to Bob Member.</div>')
        && preg_match('/<select name="owner_id" aria-label="Owner"[^>]*>.*?<option value="' . $bob . '" selected>Bob Member<\/option>/s', $r['body']) === 1);
    $cara = $person('Cara', 'cara@example.test');
    $nell = $person('Nell', 'nell@example.test', ['owner_id' => 0]);
    check('a contact added without choosing belongs to whoever added it', $ownerOf($cara) === $ada && $ownerOf($nell) === 0);

    $w->post('/tasks.php', ['do' => 'add', 'title' => 'Call Olga', 'due_date' => date('Y-m-d'), 'assigned_to' => $bob]);
    $t1 = row($odir, "SELECT * FROM tasks WHERE title='Call Olga'");
    check('a task can be given to someone else when it is added, and remembers who added it', (int) ($t1['assigned_to'] ?? 0) === $bob && (int) ($t1['created_by'] ?? 0) === $ada);
    $w->post('/tasks.php', ['do' => 'add', 'title' => 'My own task', 'due_date' => '']);
    check('a task added without choosing goes to whoever added it', $assignee('My own task') === $ada);
    $w->post('/tasks.php', ['do' => 'assign', 'id' => $taskId('Call Olga'), 'assigned_to' => $cy, 'f' => 'open']);
    $said = flash_of($w, '/tasks.php');
    check('a task can be reassigned from the task list', $assignee('Call Olga') === $cy && $said === 'ok: Task assigned to Cy Seller.', $said);
    $w->post('/tasks.php', ['do' => 'assign', 'id' => $taskId('Call Olga'), 'assigned_to' => 0]);
    check('a task can be left unassigned', $assignee('Call Olga') === 0);
    $w->post('/contact.php?id=' . $olga, ['do' => 'task', 'title' => 'Send Olga a quote', 'due_date' => '', 'assigned_to' => $bob]);
    $t2 = row($odir, "SELECT * FROM tasks WHERE title='Send Olga a quote'");
    check('a task added on a contact page takes an assignee', (int) ($t2['assigned_to'] ?? 0) === $bob && (int) ($t2['contact_id'] ?? 0) === $olga && (int) ($t2['created_by'] ?? 0) === $ada);
    $w->post('/contact.php?id=' . $olga, ['do' => 'task_assign', 'task_id' => (int) $t2['id'], 'assigned_to' => $cy]);
    check('a task can be reassigned on the contact page', $assignee('Send Olga a quote') === $cy);
    $w->post('/contact.php?id=' . $cara, ['do' => 'task_assign', 'task_id' => (int) $t2['id'], 'assigned_to' => $bob]);
    check("a task cannot be reassigned through another contact's page", $assignee('Send Olga a quote') === $cy);
    $w->post('/contact.php?id=' . $olga, ['do' => 'task_assign', 'task_id' => (int) $t2['id'], 'assigned_to' => $bob]);

    $r = $w->get('/contacts.php');
    check('the contacts list shows each owner', str_contains($r['body'], 'title="Owner: Bob Member"') && str_contains($r['body'], 'title="Owner: Ada Admin"'));
    $r = $w->get('/tasks.php');
    check('the task list shows who each task is for and who it came from', str_contains($r['body'], 'title="Assigned to: Bob Member - click to change"') && str_contains($r['body'], 'from Ada Admin') && str_contains($r['body'], 'title="Unassigned - click to assign"'));
    $r = $w->get('/contact.php?id=' . $olga);
    check('a contact page shows who its tasks are for', str_contains($r['body'], 'title="Assigned to: Bob Member - click to change"') && str_contains($r['body'], 'function pickUser('));
    $r = $w->get('/index.php');
    check('the dashboard shows who each open task is for', str_contains($r['body'], 'title="Assigned to: Bob Member"'));
    $oco = id_from($w->post('/companies.php', ['do' => 'add', 'name' => 'Owner Co']), '/company\.php\?id=(\d+)/');
    $ocoSave = ['do' => 'save', 'name' => 'Owner Co', 'website' => '', 'phone' => '', 'industry' => '', 'address' => '', 'notes' => ''];
    $w->post('/company.php?id=' . $oco, $ocoSave + ['owner_id' => $cy]);
    check('the companies list shows each owner', str_contains($w->get('/companies.php')['body'], 'title="Owner: Cy Seller"'));

    section('Mine, everyone, unassigned or one person');
    $shows = fn(string $body, string $name): bool => str_contains($body, '<b>' . $name . '</b>');
    $b = $w->get('/contacts.php?who=me')['body'];
    check('contacts: Mine shows only what I own', $shows($b, 'Cara') && !$shows($b, 'Olga Owned') && !$shows($b, 'Nell'));
    $b = $w->get('/contacts.php?who=none')['body'];
    check('contacts: Unassigned shows only contacts nobody owns', $shows($b, 'Nell') && !$shows($b, 'Cara') && !$shows($b, 'Olga Owned'));
    $b = $w->get('/contacts.php?who=' . $bob)['body'];
    check('contacts: a person shows only what they own', $shows($b, 'Olga Owned') && !$shows($b, 'Cara') && !$shows($b, 'Nell'));
    $b = $w->get('/contacts.php')['body'];
    check('the choice is remembered on the next visit', $shows($b, 'Olga Owned') && !$shows($b, 'Cara') && str_contains($b, '<option value="' . $bob . '" selected>Bob Member</option>'));
    $b = $w->get('/contacts.php?q=cara')['body'];
    check('a search inside a filter that finds nothing says so', str_contains($b, 'No contacts match.') && !$shows($b, 'Cara'));
    $b = $w->get('/contacts.php?who=all')['body'];
    check('contacts: Everyone shows them all again', $shows($b, 'Cara') && $shows($b, 'Olga Owned') && $shows($b, 'Nell'));
    $b = $w->get('/contacts.php?who=99999')['body'];
    check('a filter for someone who is not on the team shows everyone', $shows($b, 'Cara') && $shows($b, 'Olga Owned') && $shows($b, 'Nell'));

    $b = $w->get('/tasks.php?who=me')['body'];
    check('tasks: Mine shows only my tasks, and counts only those', str_contains($b, 'My own task') && !str_contains($b, 'Send Olga a quote') && !str_contains($b, 'Call Olga') && str_contains($b, 'Open (1)'));
    $b = $w->get('/tasks.php?who=none')['body'];
    check('tasks: Unassigned shows only tasks nobody has', str_contains($b, 'Call Olga') && !str_contains($b, 'My own task') && !str_contains($b, 'Send Olga a quote') && str_contains($b, 'Due today (1)'));
    $b = $w->get('/tasks.php?who=' . $bob)['body'];
    check('tasks: a person shows only their tasks', str_contains($b, 'Send Olga a quote') && !str_contains($b, 'My own task') && !str_contains($b, 'Call Olga'));
    check('a task added while looking at one person starts out as theirs', preg_match('/<select name="assigned_to"[^>]*>.*?<option value="' . $bob . '" selected>Bob Member<\/option>/s', $b) === 1);
    $b = $w->get('/tasks.php?f=done')['body'];
    check('an empty filtered list offers the way back to everyone', str_contains($b, 'Nothing here.') && str_contains($b, "Show everyone's tasks"));
    $w->get('/tasks.php?who=all');

    $w->get('/pipelines.php');
    $wpid = (int) one($odir, "SELECT id FROM pipelines ORDER BY id LIMIT 1");
    $newDeal = fn(string $name, string $value, int $owner, int $contactId = 0) => $w->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $wpid, 'name' => $name, 'value' => $value, 'stage' => 0, 'contact_id' => $contactId, 'owner_id' => $owner]);
    $newDeal('Deal for Bob', '500', $bob); $newDeal('Deal for Ada', '700', $ada); $newDeal('Deal for nobody', '100', 0);
    $b = $w->get('/pipelines.php?id=' . $wpid . '&who=' . $bob)['body'];
    check('pipeline: a person shows only their deals, and totals only those', str_contains($b, 'Deal for Bob') && !str_contains($b, 'Deal for Ada') && !str_contains($b, 'Deal for nobody')
        && str_contains($b, '1 open deal &middot; $500.00 USD') && str_contains($b, 'showing only deals owned by Bob Member'));
    $starts = [(string) $bob => str_contains($b, 'NEWOWNER=' . $bob . ',')];   // who a new deal starts out with, per filter
    $b = $w->get('/pipelines.php?id=' . $wpid . '&who=me')['body'];
    check('pipeline: Mine shows only my deals', str_contains($b, 'Deal for Ada') && !str_contains($b, 'Deal for Bob') && !str_contains($b, 'Deal for nobody'));
    $starts['me'] = str_contains($b, 'NEWOWNER=' . $ada . ',');
    $b = $w->get('/pipelines.php?id=' . $wpid . '&who=none')['body'];
    check('pipeline: Unassigned shows only deals with no owner', str_contains($b, 'Deal for nobody') && !str_contains($b, 'Deal for Ada') && !str_contains($b, 'Deal for Bob'));
    $starts['none'] = str_contains($b, 'NEWOWNER=0,');
    $b = $w->get('/pipelines.php?id=' . $wpid . '&who=all')['body'];
    check('pipeline: Everyone shows the whole board', str_contains($b, 'Deal for Bob') && str_contains($b, 'Deal for Ada') && str_contains($b, 'Deal for nobody') && str_contains($b, '3 open deals') && !str_contains($b, 'showing only'));
    $starts['all'] = str_contains($b, 'NEWOWNER=' . $ada . ',');
    check('a new deal starts out with the person the board is showing, so it stays in view', !in_array(false, $starts, true) && count($starts) === 4, json_encode($starts));

    $b = $w->get('/index.php?who=' . $bob)['body'];
    check("dashboard: a person's numbers and lists are theirs alone", str_contains($b, '<div class="n">1</div><div class="l">Contacts</div>') && str_contains($b, '<div class="n">$500.00 USD</div><div class="l">Pipeline value</div>')
        && str_contains($b, 'Send Olga a quote') && !str_contains($b, 'My own task') && str_contains($b, '>Olga Owned</a>') && !str_contains($b, '>Cara</a>'));
    $b = $w->get('/index.php?who=none')['body'];
    check('dashboard: Unassigned counts what nobody owns', str_contains($b, '<div class="n">1</div><div class="l">Contacts</div>') && str_contains($b, '<div class="n">$100.00 USD</div><div class="l">Pipeline value</div>') && str_contains($b, 'Call Olga') && str_contains($b, '>Nell</a>'));
    $b = $w->get('/index.php?who=all')['body'];
    check('dashboard: Everyone is the whole organisation, as before', str_contains($b, '<div class="n">3</div><div class="l">Contacts</div>') && str_contains($b, '<div class="n">$1,300.00 USD</div><div class="l">Pipeline value</div>') && str_contains($b, 'My own task') && str_contains($b, 'Send Olga a quote'));

    section('Lead routing');
    $w->post('/forms.php', ['do' => 'save', 'id' => 0, 'name' => 'Enquiry', 'thankyou' => 'Thanks', 'redirect' => '', 'notify' => 'on',
        'fld_label' => ['Name', 'Email', 'Message'], 'fld_name' => ['first_name', 'email', 'message'], 'fld_type' => ['text', 'email', 'textarea'], 'fld_req' => [1, 1]]);
    $slug = (string) one($odir, "SELECT slug FROM forms WHERE name='Enquiry'");
    $visitor = new Client($obase, $odir . DS . 'jar-visitor.txt');   // someone who is not signed in
    $lead = function (string $name) use ($visitor, $slug, $odir): array {   // fill in the public form; returns the contact it made
        $visitor->req('POST', '/f.php?s=' . $slug, ['f_first_name' => $name, 'f_email' => strtolower($name) . '@lead.test', 'f_message' => 'Hello from ' . $name]);
        return row($odir, "SELECT * FROM contacts WHERE email=?", [strtolower($name) . '@lead.test']);
    };
    $turn = fn(): int => (int) one($odir, "SELECT v FROM settings WHERE k='lead_routing_ptr'");
    $route = fn(array $rule): array => $w->post('/settings.php', ['do' => 'routing', 'assign_emails' => 'on'] + $rule);
    $import = function (string $csv) use ($w, $odir): array { file_put_contents($odir . DS . 'import.csv', $csv); return $w->upload('/contacts.php', ['do' => 'import'], 'csv', $odir . DS . 'import.csv'); };
    $l = $lead('Una');
    check('with no routing rule a form lead is left unassigned', ($l['source'] ?? '') === 'form:' . $slug && (int) $l['owner_id'] === 0);
    $b = $w->get('/settings.php')['body'];
    check('Settings has a lead routing card', str_contains($b, 'id="routing"') && str_contains($b, 'name="mode" value="round_robin"') && str_contains($b, 'name="assign_emails" checked'));
    $mb = new Client($obase, $odir . DS . 'jar-bob.txt');   // Bob, a member
    $r = sign_in($mb, 'bob@example.test', 'smoke-pass-8');
    $r2 = $mb->post('/settings.php', ['do' => 'routing', 'mode' => 'fixed', 'user_id' => $bob]);
    check('a member cannot change lead routing', $r['code'] === 302 && $r2['code'] === 403 && one($odir, "SELECT v FROM settings WHERE k='lead_routing'") === false, 'HTTP ' . $r['code'] . ' ' . $r2['code']);
    check('... and is not shown the card', !str_contains($mb->get('/settings.php')['body'], 'id="routing"'));
    $route(['mode' => 'fixed']);
    check('"one person" needs a person', flash_of($w, '/settings.php') === 'err: Choose the person who gets every new lead.' && one($odir, "SELECT v FROM settings WHERE k='lead_routing'") === false);

    $route(['mode' => 'fixed', 'user_id' => $bob]);
    check('one person: a form lead goes to them', (int) $lead('Vic')['owner_id'] === $bob);
    $w->get('/settings.php');
    $wtok = (string) one($odir, "SELECT v FROM settings WHERE k='api_token'");
    $r = $w->api('POST', 'contacts', $wtok, ['first_name' => 'Wes', 'email' => 'wes@lead.test']);
    check('one person: an API lead goes to them', $r['code'] === 201 && ($r['json']['contact']['owner_id'] ?? null) === $bob, $r['body']);
    $import("first_name,email\nXia,xia@lead.test\nYan,yan@lead.test\n");
    check('one person: imported contacts go to them', $ownerByEmail('xia@lead.test') === $bob && $ownerByEmail('yan@lead.test') === $bob);

    $route(['mode' => 'round_robin', 'user_ids' => [$bob, $cy, $ada]]);
    $got = [];
    foreach (['Rr1', 'Rr2', 'Rr3', 'Rr4'] as $nm) $got[] = (int) $lead($nm)['owner_id'];
    check('round-robin: form leads go to each person in turn', $got === [$bob, $cy, $ada, $bob], implode(',', $got));
    $r = $w->api('POST', 'contacts', $wtok, ['first_name' => 'Rr5', 'email' => 'rr5@lead.test']);
    check('round-robin: an API lead takes the next turn', ($r['json']['contact']['owner_id'] ?? null) === $cy, $r['body']);
    $import("first_name,email\nRr6,rr6@lead.test\nRr7,rr7@lead.test\n");
    check('round-robin: imported contacts take the next turns', [$ownerByEmail('rr6@lead.test'), $ownerByEmail('rr7@lead.test')] === [$ada, $bob]);
    $t0 = $turn();
    $again = $lead('Rr1');
    check('a lead who is already a contact keeps their owner and takes no turn', (int) $again['owner_id'] === $bob && $t0 === 7 && $turn() === $t0, "turn $t0 -> " . $turn());
    $w->api('POST', 'contacts', $wtok, ['first_name' => 'Zed', 'email' => 'zed@lead.test', 'owner_id' => $ada]);
    $w->api('POST', 'contacts', $wtok, ['first_name' => 'Zero', 'email' => 'zero@lead.test', 'owner_id' => 0]);
    check('an owner sent with an API contact wins over the rule and takes no turn', $ownerByEmail('zed@lead.test') === $ada && $ownerByEmail('zero@lead.test') === 0 && $turn() === $t0);
    $import("first_name,email,owner\nOwn1,own1@lead.test,cy@example.test\nOwn2,own2@lead.test,Bob Member\nOwn3,own3@lead.test,nobody we know\n");
    check('an owner column in a CSV names the owner by email or name; anyone else follows the rule', $ownerByEmail('own1@lead.test') === $cy && $ownerByEmail('own2@lead.test') === $bob
        && $ownerByEmail('own3@lead.test') === $cy && $turn() === $t0 + 1);
    check('Settings shows who is next in line', str_contains($w->get('/settings.php')['body'], 'Next in line: <b>Ada Admin</b>'));
    $csvOut = $w->get('/contacts.php?export=1')['body'];
    check('the contacts export has an owner column', str_contains(strtok($csvOut, "\n"), 'created,owner') && preg_match('/^Own2,.*,"?Bob Member"?\s*$/m', $csvOut) === 1);

    // Five processes each take 30 turns at the same moment: 150 turns, and nobody may get one twice.
    file_put_contents($odir . DS . 'turns.php', <<<'PHP'
        <?php
        require __DIR__ . '/lib.php';
        db();
        while (microtime(true) < (float) $argv[1]) usleep(200);
        $got = [];
        for ($i = 0; $i < (int) $argv[2]; $i++) $got[] = lead_route_next();
        echo json_encode($got);
        PHP);
    $before = $turn(); $go = microtime(true) + 2; $procs = []; $outs = [];
    for ($i = 0; $i < 5; $i++) { $procs[$i] = proc_open([PHP_BINARY, $odir . DS . 'turns.php', (string) $go, '30'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp); $outs[$i] = $pp; }
    $tally = [$bob => 0, $cy => 0, $ada => 0]; $taken = 0; $noise = '';
    foreach ($procs as $i => $pr) {
        $out = (string) stream_get_contents($outs[$i][1]); $noise .= $out . stream_get_contents($outs[$i][2]); proc_close($pr);
        foreach ((array) json_decode($out, true) as $uid) { $taken++; if (isset($tally[$uid])) $tally[$uid]++; }
    }
    check('round-robin turns are never given twice or skipped when leads arrive at the same moment', $taken === 150 && array_values($tally) === [50, 50, 50] && $turn() - $before === 150,
        'taken ' . $taken . ', tally ' . json_encode(array_values($tally)) . ', counter moved ' . ($turn() - $before) . ' ' . substr($noise, 0, 200));

    $welcome = make_workflow($w, $odir, 'Welcome call', 'contact_created', '', [['task', 'Welcome {{first_name}}']]);
    $l = $lead('Wanda');
    $o = run_php($odir . DS . 'cron.php');
    check("a task made by a workflow goes to the contact's owner", (int) $l['owner_id'] > 0 && $assignee('Welcome Wanda') === (int) $l['owner_id'], $o);
    $w->post('/workflows.php', ['do' => 'toggle', 'id' => $welcome]);   // pause it again

    section('Bulk actions on contacts');
    $b1 = $person('Bulk1', 'bulk1@example.test', ['tags' => 'lead']); $b2 = $person('Bulk2', 'bulk2@example.test'); $b3 = $person('Bulk3', 'bulk3@example.test');
    $bulk = fn(array $f, bool $csrf = true): array => $w->post('/contacts.php', ['do' => 'bulk'] + $f, $csrf);
    $b = $w->get('/contacts.php')['body'];
    check('the contacts list has tick boxes, a bulk bar and a question before deleting', str_contains($b, 'name="ids[]" value="' . $b1 . '"') && str_contains($b, 'id="bulkbar"') && str_contains($b, "confirm('Delete '") && str_contains($b, 'name="confirm" value=""'));
    $r = $bulk(['bulk' => 'assign', 'owner_id' => $cy, 'ids' => [$b1, $b2, $b3]], false);
    check('a bulk action without a CSRF token is refused', $r['code'] === 400 && $ownerOf($b1) === $ada, 'HTTP ' . $r['code']);
    $r = $bulk(['bulk' => 'assign', 'owner_id' => $cy, 'ids' => [$b1, $b2, $b3], 'q' => 'bulk']);
    $said = flash_of($w, '/contacts.php');
    check('bulk assign gives every ticked contact to one person', $ownerOf($b1) === $cy && $ownerOf($b2) === $cy && $ownerOf($b3) === $cy && $said === 'ok: 3 contacts assigned to Cy Seller.', $said);
    check('... and goes back to the search the list was showing', str_ends_with($r['location'], '/contacts.php?q=bulk'), $r['location']);
    $bulk(['bulk' => 'assign', 'owner_id' => $cy, 'ids' => [$b1, $b2]]);
    check('assigning contacts to the owner they already have changes nothing', flash_of($w, '/contacts.php') === 'ok: Those contacts already belong to Cy Seller.');
    $bulk(['bulk' => 'assign', 'owner_id' => '', 'ids' => [$b1]]);
    $said = flash_of($w, '/contacts.php');
    $bulk(['bulk' => 'assign', 'owner_id' => 99999, 'ids' => [$b1]]);
    check('bulk assign needs a real person', $said === 'err: Choose who to assign the contacts to.' && flash_of($w, '/contacts.php') === $said && $ownerOf($b1) === $cy, $said);
    $bulk(['bulk' => 'assign', 'owner_id' => 0, 'ids' => [$b3]]);
    check('bulk assign can leave contacts unassigned', $ownerOf($b3) === 0 && flash_of($w, '/contacts.php') === 'ok: 1 contact now unassigned.');
    $vip = make_workflow($w, $odir, 'VIP follow-up', 'tag_added', 'vip', [['task', 'VIP call {{first_name}}']]);
    $bulk(['bulk' => 'tag', 'newtag' => 'vip, lead', 'ids' => [$b1, $b2]]);
    $said = flash_of($w, '/contacts.php');
    check('bulk add tag adds the new tags and keeps the ones already there', tags_of($odir, 'bulk1@example.test') === ['lead', 'vip'] && tags_of($odir, 'bulk2@example.test') === ['vip', 'lead'] && tags_of($odir, 'bulk3@example.test') === []
        && $said === 'ok: Tag added to 2 contacts.', $said . ' ' . json_encode([tags_of($odir, 'bulk1@example.test'), tags_of($odir, 'bulk2@example.test')]));
    check('a tag added in bulk starts the workflows waiting for that tag', enrolled($odir, $vip) === 2);
    $bulk(['bulk' => 'tag', 'newtag' => 'vip', 'ids' => [$b1, $b2]]);
    check('adding a tag the contacts already have changes nothing', flash_of($w, '/contacts.php') === 'ok: Those contacts already have that tag.' && enrolled($odir, $vip) === 2);
    $bulk(['bulk' => 'tag', 'newtag' => ' , ', 'ids' => [$b1]]);
    check('bulk add tag needs a tag', flash_of($w, '/contacts.php') === 'err: Type the tag to add.');
    $w->post('/contact.php?id=' . $b2, ['do' => 'note', 'body' => 'A note on Bulk2']);
    $w->post('/contact.php?id=' . $b2, ['do' => 'task', 'title' => 'A task on Bulk2', 'due_date' => '']);
    $newDeal('Deal with Bulk3', '5', 0, $b3);
    $has = fn(int $id): bool => (int) one($odir, "SELECT COUNT(*) FROM contacts WHERE id=?", [$id]) === 1;
    $bulk(['bulk' => 'delete', 'ids' => [$b2, $b3]]);
    check('bulk delete does nothing until the question has been answered', $has($b2) && $has($b3) && flash_of($w, '/contacts.php') === 'err: Nothing was deleted. Deleting contacts has to be confirmed first.');
    $bulk(['bulk' => 'delete', 'confirm' => 'yes', 'ids' => [$b2, $b3, 99999]]);
    $said = flash_of($w, '/contacts.php');
    check('bulk delete removes the ticked contacts with their notes and tasks', !$has($b2) && !$has($b3) && $has($b1) && $said === 'ok: 2 contacts deleted.'
        && (int) one($odir, "SELECT COUNT(*) FROM notes WHERE contact_id=?", [$b2]) === 0 && (int) one($odir, "SELECT COUNT(*) FROM tasks WHERE contact_id=?", [$b2]) === 0, $said);
    check('... and keeps their deals, no longer linked to anyone', (int) one($odir, "SELECT contact_id FROM deals WHERE name='Deal with Bulk3'") === 0 && (int) one($odir, "SELECT COUNT(*) FROM deals WHERE name='Deal with Bulk3'") === 1);
    $bulk(['bulk' => 'delete', 'confirm' => 'yes']);
    $said = flash_of($w, '/contacts.php');
    $bulk(['ids' => [$b1]]);
    check('a bulk action with nothing ticked, or no action chosen, does nothing', $said === 'err: Tick the contacts you want to change first.' && flash_of($w, '/contacts.php') === 'err: Choose what to do with the ticked contacts.' && $has($b1));

    section('Assignment emails');
    $w->post('/contact.php?id=' . $olga, ['do' => 'owner', 'owner_id' => $cy]);
    $b = $w->get('/contact.php?id=' . $olga)['body'];
    check('with no email account, assigning still works and nothing complains', (int) one($odir, "SELECT COUNT(*) FROM email_accounts") === 0 && $ownerOf($olga) === $cy
        && str_contains($b, '<div class="flash ok">Owner changed to Cy Seller.</div>') && !preg_match('/<div class="flash err">[^<]*<\/div>/', $b));
    [$oport, $omail] = smtp_sink();
    $smtp = ['do' => 'email_save', 'acc_name' => 'Smoke SMTP', 'from_email' => 'crm@example.test', 'from_name' => 'Smoke Org', 'smtp_host' => '127.0.0.1', 'smtp_secure' => 'none', 'smtp_user' => '', 'smtp_pass' => '', 'is_default' => 'on'];
    $w->post('/settings.php', $smtp + ['id' => 0, 'smtp_port' => $oport]);
    $mails = fn(): int => mail_count($omail);
    $subject = fn(array $m): string => (string) ($m['headers']['subject'] ?? 'no mail');

    $n0 = $mails();
    $w->post('/contact.php?id=' . $olga, ['do' => 'owner', 'owner_id' => $bob]);
    $m = mail_to($omail, 'bob@example.test');
    check('giving someone a contact emails them, with a link to it', $mails() === $n0 + 1 && $subject($m) === 'Contact assigned to you: Olga Owned' && str_contains($m['text'] ?? '', 'Ada Admin assigned this contact to you.')
        && str_contains($m['html'] ?? '', 'href="' . $obase . '/contact.php?id=' . $olga . '"') && str_contains($m['text'] ?? '', $obase . '/contact.php?id=' . $olga), $subject($m));
    $n0 = $mails();
    $w->post('/contact.php?id=' . $olga, ['do' => 'owner', 'owner_id' => $ada]);
    check('nobody is emailed about something they give to themselves', $ownerOf($olga) === $ada && $mails() === $n0);
    $n0 = $mails();
    $w->post('/tasks.php', ['do' => 'add', 'title' => 'Ring the supplier', 'due_date' => '2027-01-15', 'assigned_to' => $bob]);
    $m = mail_to($omail, 'bob@example.test');
    check('giving someone a task emails them', $mails() === $n0 + 1 && $subject($m) === 'Task for you: Ring the supplier' && str_contains($m['text'] ?? '', 'Ada Admin gave you a task.') && str_contains($m['text'] ?? '', '15 Jan 2027'), $subject($m));
    $n0 = $mails();
    $w->post('/tasks.php', ['do' => 'assign', 'id' => $taskId('Ring the supplier'), 'assigned_to' => $cy]);
    check('reassigning a task emails the new assignee', $mails() === $n0 + 1 && $subject(mail_to($omail, 'cy@example.test')) === 'Task for you: Ring the supplier');
    $n0 = $mails();
    $newDeal('Big deal', '900', $bob);
    $m = mail_to($omail, 'bob@example.test');
    $big = (int) one($odir, "SELECT id FROM deals WHERE name='Big deal'");
    check('a new deal emails its owner', $mails() === $n0 + 1 && $subject($m) === 'Deal assigned to you: Big deal' && str_contains($m['text'] ?? '', '$900.00 USD') && str_contains($m['html'] ?? '', 'pipelines.php?id=' . $wpid . '&amp;deal=' . $big . '"'), $subject($m));
    $n0 = $mails();
    $w->post('/pipelines.php', ['do' => 'save_deal', 'id' => $big, 'name' => 'Big deal', 'value' => '900', 'stage' => 0, 'contact_id' => 0, 'owner_id' => $cy]);
    $w->post('/pipelines.php', ['do' => 'save_deal', 'id' => $big, 'name' => 'Big deal again', 'value' => '900', 'stage' => 0, 'contact_id' => 0, 'owner_id' => $cy]);
    check('changing a deal\'s owner emails the new owner, once', $mails() === $n0 + 1 && $subject(mail_to($omail, 'cy@example.test')) === 'Deal assigned to you: Big deal');
    $n0 = $mails();
    $w->post('/company.php?id=' . $oco, $ocoSave + ['owner_id' => $bob]);
    check('changing a company\'s owner emails the new owner', $mails() === $n0 + 1 && $subject(mail_to($omail, 'bob@example.test')) === 'Company assigned to you: Owner Co');
    $m1 = $person('Many1', 'many1@example.test'); $m2 = $person('Many2', 'many2@example.test'); $m3 = $person('Many3', 'many3@example.test');
    $n0 = $mails();
    $bulk(['bulk' => 'assign', 'owner_id' => $cy, 'ids' => [$m1, $m2, $m3]]);
    $m = mail_to($omail, 'cy@example.test');
    check('a bulk assign sends one email, not one per contact', $mails() === $n0 + 1 && $subject($m) === '3 contacts assigned to you' && str_contains($m['text'] ?? '', 'Ada Admin assigned 3 contacts to you.') && str_contains($m['text'] ?? '', $obase . '/contacts.php?who=me'), $subject($m));

    $route(['mode' => 'fixed', 'user_id' => $bob]);
    $n0 = $mails();
    $l = $lead('Nia');
    $m = mail_to($omail, 'bob@example.test');
    check('a routed form lead is emailed to its new owner, with what was submitted', (int) $l['owner_id'] === $bob && $subject($m) === 'New lead for you: Nia' && str_contains($m['text'] ?? '', 'the form "Enquiry"')
        && str_contains($m['text'] ?? '', 'Hello from Nia') && str_contains($m['html'] ?? '', 'href="' . $obase . '/contact.php?id=' . (int) $l['id'] . '"'), $subject($m));
    check('... and the form notification still goes to the admin', $mails() === $n0 + 2 && $subject(mail_to($omail, 'ada@example.test')) === 'New form submission: Enquiry');
    $route(['mode' => 'fixed', 'user_id' => $ada]);
    $n0 = $mails();
    $l = $lead('Ned');
    check('an owner who would also get the form notification is emailed once, not twice', (int) $l['owner_id'] === $ada && $mails() === $n0 + 1 && $subject(mail_to($omail, 'ada@example.test')) === 'New lead for you: Ned');

    $route(['mode' => 'round_robin', 'user_ids' => [$cy, $bob]]);   // a changed rule starts again with the first person
    $l = $lead('Pia');
    $m = mail_to($omail, 'cy@example.test');
    $mc = new Client($obase, $odir . DS . 'jar-cy.txt');   // Cy signs in to look
    sign_in($mc, 'cy@example.test', 'smoke-pass-8');
    $mine = $mc->get('/contacts.php?who=me')['body']; $dash = $mc->get('/index.php?who=me')['body'];
    check('a form lead lands on the next person in the round-robin, they get the email, and their Mine views show it', (int) $l['owner_id'] === $cy && $subject($m) === 'New lead for you: Pia'
        && $shows($mine, 'Pia') && !$shows($mine, 'Nia') && str_contains($dash, '>Pia</a>'), $subject($m));
    $n0 = $mails();
    $r = $w->api('POST', 'contacts', $wtok, ['first_name' => 'Api', 'last_name' => 'Lead', 'email' => 'api@lead.test']);
    $m = mail_to($omail, 'bob@example.test');
    check('a routed API lead is emailed to its new owner', ($r['json']['contact']['owner_id'] ?? null) === $bob && $mails() === $n0 + 1 && $subject($m) === 'New lead for you: Api Lead' && str_contains($m['text'] ?? '', 'came in through the API'), $subject($m));
    $n0 = $mails();
    $r = $w->api('POST', 'contacts', $wtok, ['first_name' => 'Quiet', 'email' => 'quiet@lead.test', 'notify' => false]);
    check('"notify": false routes the lead but sends no email', ($r['json']['contact']['owner_id'] ?? null) === $cy && $mails() === $n0, $r['body']);
    $n0 = $mails();
    $r = $w->api('POST', 'deals', $wtok, ['name' => 'API deal', 'value' => 50, 'owner_id' => $bob]);
    $m = mail_to($omail, 'bob@example.test');
    $apiDealId = (int) ($r['json']['deal']['id'] ?? 0);
    check('a deal given to someone through the API emails them', $r['code'] === 201 && ($r['json']['deal']['owner_id'] ?? null) === $bob && $mails() === $n0 + 1 && $subject($m) === 'Deal assigned to you: API deal' && str_contains($m['text'] ?? '', 'You are now the owner of this deal.'), $subject($m));
    $n0 = $mails();
    $import("first_name,email\nI1,i1@lead.test\nI2,i2@lead.test\nI3,i3@lead.test\nI4,i4@lead.test\n");
    $toBob = mail_to($omail, 'bob@example.test'); $toCy = mail_to($omail, 'cy@example.test');
    check('an import sends each new owner one summary, not an email per contact', $mails() === $n0 + 2 && $subject($toBob) === '2 contacts assigned to you' && $subject($toCy) === '2 contacts assigned to you'
        && str_contains($toBob['text'] ?? '', '2 contacts from a CSV import were assigned to you.'), $subject($toBob) . ' | ' . $subject($toCy) . ' | ' . ($mails() - $n0));
    $w->post('/settings.php', ['do' => 'routing', 'mode' => 'none']);   // the email box is not ticked: switched off
    $n0 = $mails();
    $w->post('/contact.php?id=' . $olga, ['do' => 'owner', 'owner_id' => $cy]);
    check('assignment emails can be switched off in Settings', $ownerOf($olga) === $cy && $mails() === $n0 && one($odir, "SELECT v FROM settings WHERE k='assign_emails'") === '0'
        && !str_contains($w->get('/settings.php')['body'], 'name="assign_emails" checked'));
    $route(['mode' => 'none']);   // and on again

    section('Removing a team member');
    $rae = $member('Rae Leaver', 'rae@example.test');
    $r1 = $person('Rae1', 'rae1@example.test', ['owner_id' => $rae]); $r2 = $person('Rae2', 'rae2@example.test', ['owner_id' => $rae]);
    $rco = id_from($w->post('/companies.php', ['do' => 'add', 'name' => 'Rae Co']), '/company\.php\?id=(\d+)/');
    $w->post('/company.php?id=' . $rco, ['do' => 'save', 'name' => 'Rae Co', 'website' => '', 'phone' => '', 'industry' => '', 'address' => '', 'notes' => '', 'owner_id' => $rae]);
    $newDeal('Rae deal', '10', $rae);
    $w->post('/tasks.php', ['do' => 'add', 'title' => 'Rae open task', 'due_date' => '', 'assigned_to' => $rae]);
    $w->post('/tasks.php', ['do' => 'add', 'title' => 'Rae done task', 'due_date' => '', 'assigned_to' => $rae]);
    $w->post('/tasks.php', ['do' => 'done', 'id' => $taskId('Rae done task')]);
    $route(['mode' => 'round_robin', 'user_ids' => [$rae, $bob]]);
    $onTeam = fn(int $id): bool => (int) one($odir, "SELECT COUNT(*) FROM users WHERE id=?", [$id]) === 1;
    $rule = fn(): array => json_decode((string) one($odir, "SELECT v FROM settings WHERE k='lead_routing'"), true) ?: [];
    $r = $w->get('/settings.php?remove=' . $rae);
    check('removing a member first shows what they own and asks who takes it over', $r['code'] === 200 && str_contains($r['body'], 'Remove Rae Leaver from the team?')
        && str_contains($r['body'], 'They own <b>2 contacts</b>, <b>1 company</b>, <b>1 deal</b> and <b>2 tasks</b> (1 still open).')
        && str_contains($r['body'], '<select name="inherit" id="inherit" required>') && str_contains($r['body'], 'taken out of the round-robin'), 'HTTP ' . $r['code']);
    check('the Team list links to that question instead of removing straight away', str_contains($w->get('/settings.php')['body'], 'settings.php?remove=' . $rae . '"'));
    $w->post('/settings.php', ['do' => 'del_user', 'id' => $rae, 'inherit' => $rae]);
    $w->post('/settings.php', ['do' => 'del_user', 'id' => $rae, 'inherit' => 99999]);
    check('their records cannot go to themselves or to someone who does not exist', $onTeam($rae) && $ownerOf($r1) === $rae && flash_of($w, '/settings.php') === 'err: Choose someone else on the team to take over their records.');
    $r = $mb->post('/settings.php', ['do' => 'del_user', 'id' => $rae, 'inherit' => $bob]);
    check('a member cannot remove anyone', $r['code'] === 403 && $onTeam($rae), 'HTTP ' . $r['code']);
    $n0 = $mails();
    $w->post('/settings.php', ['do' => 'del_user', 'id' => $rae, 'inherit' => $cy]);
    $said = flash_of($w, '/settings.php');
    $stillTheirs = fn(string $tbl, string $col): int => (int) one($odir, "SELECT COUNT(*) FROM $tbl WHERE $col=?", [$rae]);
    check('the member is removed and everything they owned passes to the person chosen', !$onTeam($rae) && $ownerOf($r1) === $cy && $ownerOf($r2) === $cy
        && (int) one($odir, "SELECT owner_id FROM companies WHERE id=?", [$rco]) === $cy && (int) one($odir, "SELECT owner_id FROM deals WHERE name='Rae deal'") === $cy
        && $assignee('Rae open task') === $cy && $assignee('Rae done task') === $cy
        && $stillTheirs('contacts', 'owner_id') + $stillTheirs('companies', 'owner_id') + $stillTheirs('deals', 'owner_id') + $stillTheirs('tasks', 'assigned_to') === 0
        && $said === 'ok: Team member removed. Their 6 records now belong to Cy Seller.', $said);
    check('they are taken out of the lead routing rule', ($rule()['mode'] ?? '') === 'round_robin' && ($rule()['user_ids'] ?? null) === [$bob], json_encode($rule()));
    $m = mail_to($omail, 'cy@example.test');
    check('whoever takes over is told in one email', $mails() === $n0 + 1 && $subject($m) === 'Records passed to you'
        && str_contains($m['text'] ?? '', 'Ada Admin removed Rae Leaver from the team and passed their records to you: 2 contacts, 1 company, 1 deal, 2 tasks.'), $subject($m) . ' ' . ($m['text'] ?? ''));
    $tom = $member('Tom Temp', 'tom@example.test');
    $t1 = $person('Tom1', 'tom1@example.test', ['owner_id' => $tom]);
    $b = $w->get('/settings.php?remove=' . $tom)['body'];
    $w->post('/settings.php', ['do' => 'del_user', 'id' => $tom, 'inherit' => 0]);
    check("a removed member's records can be left unassigned", str_contains($b, 'They own <b>1 contact</b>.') && !$onTeam($tom) && $ownerOf($t1) === 0 && flash_of($w, '/settings.php') === 'ok: Team member removed. Their 1 record is now unassigned.');
    $fay = $member('Fay Fixed', 'fay@example.test');
    $route(['mode' => 'fixed', 'user_id' => $fay]);
    $b = $w->get('/settings.php?remove=' . $fay)['body'];
    $w->post('/settings.php', ['do' => 'del_user', 'id' => $fay, 'inherit' => $bob]);
    check('when the one person who got every lead is removed, the question is who gets them next', str_contains($b, 'They do not own any contacts, companies, deals or tasks.') && str_contains($b, 'Who gets new leads instead?')
        && !$onTeam($fay) && ($rule()['mode'] ?? '') === 'fixed' && ($rule()['user_id'] ?? null) === $bob && (int) $lead('Fern')['owner_id'] === $bob, json_encode($rule()));
    $w->post('/settings.php', ['do' => 'del_user', 'id' => $ada, 'inherit' => $bob]);
    check('you still cannot remove yourself', $onTeam($ada) && flash_of($w, '/settings.php') === 'err: You cannot remove yourself.');
    $b = $w->get('/contacts.php?who=' . $rae)['body'];
    check('a filter left on someone who has gone shows everyone again', $shows($b, 'Olga Owned') && $shows($b, 'Cara'));

    section('REST API: owners and the team');
    $r = $w->api('GET', 'users', $wtok);
    $team = $r['json']['data'] ?? [];
    check('GET /users lists the team by id, name and role, and nothing else', $r['code'] === 200 && array_values(array_unique(array_map(fn($x) => implode(',', array_keys($x)), $team))) === ['id,name,role']
        && in_array(['id' => $bob, 'name' => 'Bob Member', 'role' => 'member'], $team, true) && in_array(['id' => $ada, 'name' => 'Ada Admin', 'role' => 'admin'], $team, true)
        && !str_contains($r['body'], '@') && stripos($r['body'], 'pass') === false && stripos($r['body'], 'hash') === false, $r['body']);
    $r = $w->api('GET', 'users/' . $bob, $wtok);
    check('GET /users/{id} returns one person, or 404', $r['json'] === ['id' => $bob, 'name' => 'Bob Member', 'role' => 'member'] && $w->api('GET', 'users/99999', $wtok)['code'] === 404 && $w->api('GET', 'users/' . $rae, $wtok)['code'] === 404, $r['body']);
    $count = (int) one($odir, "SELECT COUNT(*) FROM users");
    $r = $w->api('POST', 'users', $wtok, ['name' => 'Eve', 'email' => 'eve@example.test', 'role' => 'admin']);
    check('the team cannot be changed through the API', $r['code'] === 405 && (int) one($odir, "SELECT COUNT(*) FROM users") === $count, 'HTTP ' . $r['code']);
    $r = $w->api('GET', 'contacts/' . $olga, $wtok);
    check('a contact in the API shows its owner', ($r['json']['owner_id'] ?? null) === $cy && $ownerOf($olga) === $cy, $r['body']);
    $r = $w->api('POST', 'contacts', $wtok, ['email' => 'olga@example.test', 'owner_id' => $ada]);
    check('owner_id on a contact that exists changes its owner and nothing else', $r['code'] === 201 && ($r['json']['contact']['owner_id'] ?? null) === $ada && $ownerOf($olga) === $ada && ($r['json']['contact']['last_name'] ?? '') === 'Owned', $r['body']);
    $r = $w->api('POST', 'contacts', $wtok, ['email' => 'olga@example.test', 'owner_id' => 99999]);
    $r2 = $w->api('POST', 'contacts', $wtok, ['email' => 'olga@example.test', 'owner_id' => 'abc']);
    check('an owner who does not exist, or is not an id, is refused', $r['code'] === 422 && $r2['code'] === 422 && $ownerOf($olga) === $ada, 'HTTP ' . $r['code'] . ' ' . $r2['code']);
    $r = $w->api('GET', 'contacts?limit=500&owner_id=' . $ada, $wtok);
    $list = $r['json']['data'] ?? [];
    check('contacts can be listed for one owner', $r['code'] === 200 && array_values(array_unique(array_column($list, 'owner_id'))) === [$ada] && in_array('olga@example.test', array_column($list, 'email'), true) && !in_array('una@lead.test', array_column($list, 'email'), true));
    $r = $w->api('GET', 'contacts?limit=500&owner_id=0', $wtok);
    $list = $r['json']['data'] ?? [];
    check('owner_id=0 lists the contacts nobody owns', array_values(array_unique(array_column($list, 'owner_id'))) === [0] && in_array('una@lead.test', array_column($list, 'email'), true));
    check('a filter that is not an id is refused', $w->api('GET', 'contacts?owner_id=abc', $wtok)['code'] === 422 && $w->api('GET', 'deals?owner_id=-1', $wtok)['code'] === 422);
    $r = $w->api('GET', 'deals?owner_id=' . $bob, $wtok);
    $list = $r['json']['data'] ?? [];
    check('deals can be listed for one owner', $r['code'] === 200 && array_values(array_unique(array_column($list, 'owner_id'))) === [$bob] && in_array('API deal', array_column($list, 'name'), true) && !in_array('Deal for Ada', array_column($list, 'name'), true));
    $r = $w->api('POST', 'deals/' . $apiDealId, $wtok, ['owner_id' => $cy, 'notify' => false]);
    check('a deal\'s owner can be changed through the API', $r['code'] === 200 && ($r['json']['deal']['owner_id'] ?? null) === $cy && ($r['json']['deal']['name'] ?? '') === 'API deal', $r['body']);
    $was = [   // the fields each answer had in 1.1, in the order it had them
        'me' => ['org', 'version', 'contacts', 'companies', 'open_deals'],
        'contacts/' . $olga => ['id', 'first_name', 'last_name', 'email', 'phone', 'company', 'company_id', 'address', 'tags', 'source', 'unsubscribed', 'created_at'],
        'companies/' . $oco => ['id', 'name', 'domain', 'website', 'phone', 'address', 'industry', 'notes', 'owner_id', 'created_at', 'updated_at', 'contacts', 'open_deals'],
        'deals/' . $apiDealId => ['id', 'name', 'value', 'stage', 'contact_id', 'company_id', 'pipeline_id', 'status', 'close_date', 'probability', 'probability_is_stage_default', 'weighted_value', 'owner_id', 'lost_reason', 'notes', 'created_at', 'updated_at', 'closed_at'],
    ];
    $changed = [];
    foreach ($was as $path => $fields) { $now = array_keys((array) $w->api('GET', $path, $wtok)['json']); if (array_slice($now, 0, count($fields)) !== $fields) $changed[] = $path . ': ' . implode(',', $now); }
    $pl = array_keys($w->api('GET', 'pipelines', $wtok)['json']['data'][0] ?? []);
    check('every field the 1.1 API returned is still there, in the same order', $changed === [] && $pl === ['id', 'name', 'stages', 'stage_probabilities'], implode(' | ', $changed) . ' ' . implode(',', $pl));

    $sock = stream_socket_server('tcp://127.0.0.1:0'); $deadPort = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock);   // a port nothing listens on
    $w->post('/settings.php', $smtp + ['id' => (int) one($odir, "SELECT id FROM email_accounts LIMIT 1"), 'smtp_port' => $deadPort]);
    $n0 = $mails();
    $w->post('/contact.php?id=' . $olga, ['do' => 'owner', 'owner_id' => $bob]);
    $b = $w->get('/contact.php?id=' . $olga)['body'];
    check('when the mail server cannot be reached the assignment still goes through, quietly', $ownerOf($olga) === $bob && $mails() === $n0
        && str_contains($b, '<div class="flash ok">Owner changed to Bob Member.</div>') && !preg_match('/<div class="flash err">[^<]*<\/div>/', $b));
    foreach (['index.php?who=me', 'index.php?who=' . $bob, 'contacts.php?who=none', 'contacts.php?who=all', 'contacts.php?new=1', 'contacts.php?edit=' . $olga, 'contact.php?id=' . $olga, 'companies.php',
        'tasks.php?who=' . $bob, 'tasks.php?f=done&who=all', 'pipelines.php?who=me', 'pipelines.php?who=all', 'settings.php', 'settings.php?remove=' . $bob] as $p) {
        $r = $w->get('/' . $p);
        check("page loads without warnings: $p", $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']), 'HTTP ' . $r['code']);
    }
    stop($osrv);

    /* ================ G. two-factor sign-in and the audit log (new in 1.3) === */
    section('QR code and code maths');
    $sdir = tmpdir('secure');
    copy_app($ROOT, $sdir);
    file_put_contents($sdir . DS . 'config.php', "<?php return ['base_url' => 'https://crm.example.test'];");   // for cron on the command line
    [$sbase, $ssrv] = serve($sdir);
    $s = new Client($sbase, $sdir . DS . 'jar.txt');
    setup_admin($s, 'sam@example.test', 'smoke-pass-s1');
    $s->loadCsrf('/settings.php');
    $sam = (int) one($sdir, "SELECT id FROM users WHERE email='sam@example.test'");
    // The pieces are run inside the app, on the command line, and answer as JSON.
    file_put_contents($sdir . DS . 'maths.php', <<<'PHP'
        <?php
        require __DIR__ . '/lib.php'; require __DIR__ . '/qr.php';
        $fmt = function (array $m): string {   // the 15 format bits next to the top-left corner, most significant first
            $at = []; for ($i = 0; $i <= 5; $i++) $at[$i] = [8, $i]; $at[6] = [8, 7]; $at[7] = [8, 8]; $at[8] = [7, 8]; for ($i = 9; $i < 15; $i++) $at[$i] = [14 - $i, 8];
            $s = ''; for ($i = 14; $i >= 0; $i--) $s .= $m[$at[$i][1]][$at[$i][0]];
            return $s; };
        $fill = function (int $n, int $i): string { $t = ''; for ($k = 0; $k < $n; $k++) $t .= chr(33 + ($k * 7 + $i) % 90); return $t; };
        $hash = fn(string $text): string => count($m = qr_matrix($text)) . ' ' . md5(implode("\n", array_map('implode', $m)));
        $m7 = qr_matrix(str_repeat('x', 110)); $v7 = '';
        for ($i = 17; $i >= 0; $i--) $v7 .= $m7[count($m7) - 11 + $i % 3][intdiv($i, 3)];
        $sealed = secret_seal('twenty bytes of key..');
        echo json_encode([
            'rs'    => qr_rs_remainder([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], qr_rs_generator(10)),
            'fmt'   => [$fmt(qr_matrix('A', 0)), $fmt(qr_matrix('A', 5))],
            'v7'    => count($m7) . ' ' . $v7,
            'sizes' => [count(qr_matrix('a')), count(qr_matrix(str_repeat('a', 14))), count(qr_matrix(str_repeat('a', 15))), count(qr_matrix(str_repeat('z', 666))), count(qr_matrix(str_repeat('z', 667)))],
            'gold'  => [$hash('otpauth://totp/Acme%20Pty%20Ltd:ada.admin%40example.test?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Acme%20Pty%20Ltd&algorithm=SHA1&digits=6&period=30'),
                        $hash($fill(14, 0)), $hash($fill(331, 12)), $hash($fill(666, 19)), $hash("Caf\xC3\xA9 \xE2\x9C\x93 two-factor")],
            'svg'   => qr_svg('CRMax', 120),
            'totp'  => array_map(fn($t) => totp_at('12345678901234567890', intdiv($t, 30)), [59, 1111111109, 1111111111, 1234567890, 2000000000, 20000000000]),
            'b32'   => [b32_encode('foobar'), b32_decode('MZXW6YTBOI'), b32_encode(''), b32_decode('mzxw 6ytb oi'), strlen(b32_encode(random_bytes(20)))],
            'seal'  => [secret_open($sealed), str_contains($sealed, 'twenty'), secret_open(substr($sealed, 0, -3) . 'AAA'), secret_open('nonsense'), secret_seal('x') !== secret_seal('x')],
            'codes' => recovery_codes_new(),
            'uri'   => totp_uri('JBSWY3DPEHPK3PXP', 'sam@example.test'),
        ]);
        PHP);
    $o = run_php($sdir . DS . 'maths.php');
    $mj = json_decode($o, true) ?: [];
    check('error correction matches the published worked example', ($mj['rs'] ?? null) === [196, 35, 39, 119, 235, 215, 231, 226, 93, 23], substr($o, 0, 200));
    check('format bits match the standard for masks 0 and 5', ($mj['fmt'] ?? null) === ['101010000010010', '100000011001110'], json_encode($mj['fmt'] ?? null));
    check('a version 7 code carries the right version bits', ($mj['v7'] ?? '') === '45 000111110010010100', (string) ($mj['v7'] ?? ''));
    check('the code grows with the text, up to 666 bytes', ($mj['sizes'] ?? null) === [21, 21, 25, 97, 0], json_encode($mj['sizes'] ?? null));
    // These five were read back correctly by an independent QR reader when 1.3 was built. If one changes, qr.php changed what it draws: scan the new codes with a phone before trusting them.
    check('five reference codes are drawn exactly as when a reader last verified them', ($mj['gold'] ?? null) === ['53 24f41183b222b5fc095c0142a514df3d', '21 4ddf6a5862c43962440708981af15042', '69 9ac2dcf28f51b782a817cdbe5eee7cff', '97 018826d2cb56fb53529cdd7a9a4a1a61', '25 4896f13be2af64126f8554308ef8e417'], json_encode($mj['gold'] ?? null));
    check('the code is drawn as an SVG with a quiet zone', str_starts_with($mj['svg'] ?? '', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" width="120" height="120"') && str_contains($mj['svg'], '<path d="M4 4h7v1h-7z'));
    check('sign-in codes match the RFC 6238 test values, in the app and in this test', ($mj['totp'] ?? null) === ['287082', '081804', '050471', '005924', '279037', '353130']
        && t_totp('12345678901234567890', 1) === '287082' && t_totp('12345678901234567890', intdiv(20000000000, 30)) === '353130', json_encode($mj['totp'] ?? null));
    check('base32 matches RFC 4648, ignoring case and spaces', ($mj['b32'] ?? null) === ['MZXW6YTBOI', 'foobar', '', 'foobar', 32] && t_b32_decode('MZXW6YTBOI') === 'foobar', json_encode($mj['b32'] ?? null));
    check('a sealed secret opens again, is not readable as stored, and does not open if altered', ($mj['seal'] ?? null) === ['twenty bytes of key..', false, '', '', true], json_encode($mj['seal'] ?? null));
    check('recovery codes are ten different easy-to-read codes', count($mj['codes'] ?? []) === 10 && count(array_unique($mj['codes'])) === 10 && count(preg_grep('/^[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}$/', $mj['codes'])) === 10, implode(' ', $mj['codes'] ?? []));
    check('the set-up address names the organisation and the account', ($mj['uri'] ?? '') === 'otpauth://totp/Smoke%20Org%3Asam%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=Smoke%20Org&algorithm=SHA1&digits=6&period=30', (string) ($mj['uri'] ?? ''));
    check('qr.php and recover.php do nothing when opened in a browser', $s->get('/qr.php')['code'] === 404 && $s->get('/recover.php')['code'] === 404 && $s->get('/recover.php?password=sam@example.test')['code'] === 404);

    section('Two-factor sign-in: turning it on');
    $step = fn(): int => intdiv(time(), 30);
    $code = fn(string $key, int $off = 0): string => t_totp(t_b32_decode($key), $step() + $off);
    $wrong = function (string $key) use ($code): string {   // six digits that are not a valid code right now
        $bad = '123456';
        while (in_array($bad, [$code($key, -2), $code($key, -1), $code($key), $code($key, 1), $code($key, 2)], true)) $bad = sprintf('%06d', ((int) $bad + 1) % 1000000);
        return $bad;
    };
    $urow = fn(int $uid): array => row($sdir, "SELECT * FROM users WHERE id=?", [$uid]);
    // The test signs in many times a minute, and a code only works once: this lets the next one through.
    $fresh = function (int $uid) use ($sdir): void { pdo_for($sdir)->prepare("UPDATE users SET totp_last_step=0 WHERE id=?")->execute([$uid]); };
    $unlock = function () use ($sdir): void { foreach (glob($sdir . DS . 'data' . DS . 'rl_*.json') ?: [] as $f) @unlink($f); };   // forget the wrong attempts so far
    $keyOn = fn(Client $c): string => preg_match('/<p class="mono"[^>]*>([A-Z2-7 ]+)<\/p>/', $c->get('/twofa.php')['body'], $km) ? str_replace(' ', '', $km[1]) : '';
    $codesOn = function (Client $c): array {   // the recovery codes on the page that shows them once
        return preg_match('/<pre class="mono" id="rcodes"[^>]*>([^<]+)<\/pre>/', $c->get('/twofa.php?codes=1')['body'], $cm) ? array_values(array_filter(array_map('trim', explode("\n", $cm[1])))) : [];
    };
    $enable = function (Client $c, string $pass) use ($keyOn, $codesOn, $code): array {   // turn two-factor on for whoever $c is; returns [key, recovery codes]
        $c->post('/twofa.php', ['do' => 'begin', 'password' => $pass]);
        $key = $keyOn($c);
        $c->post('/twofa.php', ['do' => 'confirm', 'code' => $code($key)]);
        return [$key, $codesOn($c)];
    };
    $signIn2 = function (Client $c, string $email, string $pass, string $key, int $uid) use ($fresh, $code): array {   // password, then a code
        sign_in($c, $email, $pass);
        $fresh($uid);
        $r = $c->post('/index.php?login=1', ['code' => $code($key)]);
        $c->loadCsrf('/settings.php');
        return $r;
    };
    $in = fn(Client $c): bool => $c->get('/contacts.php')['code'] === 200;   // is this browser signed in?
    $r = $s->get('/twofa.php');
    check('your account offers two-factor sign-in, off to start with', $r['code'] === 200 && str_contains($r['body'], 'Two-factor sign-in <span class="tag">off</span>')
        && str_contains($s->get('/settings.php')['body'], '>Set up two-factor sign-in</a>') && (int) $urow($sam)['totp_enabled'] === 0);
    $s->post('/twofa.php', ['do' => 'begin', 'password' => 'not-my-password']);
    check('starting the set-up takes your password', flash_of($s, '/twofa.php') === 'err: That is not your password.' && $keyOn($s) === '');
    $s->post('/twofa.php', ['do' => 'begin', 'password' => 'smoke-pass-s1']);
    $r = $s->get('/twofa.php');
    $key = $keyOn($s);
    check('set-up shows a QR code and the key to type by hand, and loads nothing from outside', $r['code'] === 200 && str_contains($r['body'], '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ')
        && strlen($key) === 32 && strlen(t_b32_decode($key)) === 20 && !preg_match('/<img|<link|src=/', $r['body']), 'key length ' . strlen($key));
    check('nothing is saved on the account until a code has been typed back', (int) $urow($sam)['totp_enabled'] === 0 && $urow($sam)['totp_secret'] === '');
    $s->post('/twofa.php', ['do' => 'confirm', 'code' => $wrong($key)]);
    check('a wrong code does not turn it on', flash_of($s, '/twofa.php') === 'err: That code is not right. Type the 6 digits your app shows now for this account.' && (int) $urow($sam)['totp_enabled'] === 0);
    $r = $s->post('/twofa.php', ['do' => 'confirm', 'code' => $code($key)]);
    $rc = $codesOn($s);
    $ur = $urow($sam);
    check('the right code turns it on and shows ten recovery codes', str_ends_with($r['location'], 'twofa.php?codes=1') && count($rc) === 10 && count(array_unique($rc)) === 10 && (int) $ur['totp_enabled'] === 1, 'codes ' . count($rc));
    check('the recovery codes are shown that once only', $s->get('/twofa.php?codes=1')['code'] === 302 && !str_contains($s->get('/twofa.php')['body'], $rc[0] ?? 'none'));
    check('the secret is stored sealed and the recovery codes hashed', str_starts_with($ur['totp_secret'], 'v1.') && !str_contains($ur['totp_secret'], $key)
        && !str_contains((string) base64_decode(substr($ur['totp_secret'], 3)), t_b32_decode($key)) && count(json_decode($ur['recovery_codes'], true) ?: []) === 10
        && !str_contains($ur['recovery_codes'], $rc[0] ?? 'none') && !str_contains($ur['recovery_codes'], str_replace('-', '', $rc[0] ?? 'none')));
    check('the browser that set it up stays signed in', $in($s));
    $s->get('/settings.php');
    $stok = (string) one($sdir, "SELECT v FROM settings WHERE k='api_token'");

    section('Two-factor sign-in: signing in');
    $s2 = new Client($sbase, $sdir . DS . 'jar-2.txt');
    $r = sign_in($s2, 'sam@example.test', 'smoke-pass-s1');
    $b = $s2->get('/index.php')['body'];
    check('the right password alone opens nothing: the next screen asks for a code', $r['code'] === 302 && str_contains($b, '<h1>Enter your code</h1>') && !str_contains($b, 'Good day')
        && !$in($s2) && $s2->get('/audit.php')['code'] === 302 && $s2->get('/twofa.php')['code'] === 302 && $s2->get('/contacts.php?export=1')['code'] === 302 && $s2->get('/settings.php')['code'] === 302);
    $r = $s2->post('/index.php?login=1', ['code' => $wrong($key)]);
    check('a wrong code is refused', $r['code'] === 200 && str_contains($r['body'], 'That code is not right. Codes change every 30 seconds and each one works once.') && !$in($s2));
    $fresh($sam);
    $r1 = $s2->post('/index.php?login=1', ['code' => $code($key, -10)]); $r2 = $s2->post('/index.php?login=1', ['code' => $code($key, 10)]);
    $r3 = $s2->post('/index.php?login=1', ['code' => $code($key, -2)]); $r4 = $s2->post('/index.php?login=1', ['code' => $code($key, 3)]);
    check('a code from five minutes ago or five minutes ahead is refused, and so is one a minute out', str_contains($r1['body'], 'That code is not right') && str_contains($r2['body'], 'That code is not right')
        && str_contains($r3['body'], 'That code is not right') && str_contains($r4['body'], 'That code is not right') && !$in($s2));
    $used = $code($key);
    $r = $s2->post('/index.php?login=1', ['code' => $used]);
    check('the right code finishes signing in', $r['code'] === 302 && $in($s2) && str_contains($s2->get('/index.php')['body'], 'Good day'), 'HTTP ' . $r['code']);
    $s3 = new Client($sbase, $sdir . DS . 'jar-3.txt');
    sign_in($s3, 'sam@example.test', 'smoke-pass-s1');
    $r = $s3->post('/index.php?login=1', ['code' => $used]);
    check('a code that has been used does not work a second time', str_contains($r['body'], 'That code is not right') && !$in($s3));
    $r = $s3->post('/index.php?login=1', ['code' => ' ' . substr($code($key, 1), 0, 3) . ' ' . substr($code($key, 1), 3) . ' ']);
    check('the next code does, typed with a space in the middle', $r['code'] === 302 && $in($s3), 'HTTP ' . $r['code']);
    check('the API token keeps working: it is not a sign-in', $s->api('GET', 'me', $stok)['code'] === 200);
    $s4 = new Client($sbase, $sdir . DS . 'jar-4.txt');
    sign_in($s4, 'sam@example.test', 'smoke-pass-s1');
    $r = $s4->post('/index.php?login=1', ['code' => strtoupper(str_replace('-', ' ', $rc[0]))]);
    $said = flash_of($s4, '/index.php');
    check('a recovery code signs in instead, however it is typed, and says how many are left', $r['code'] === 302 && $in($s4)
        && $said === 'ok: You signed in with a recovery code. You have 9 left: make new ones under Settings, Two-factor sign-in.', $said);
    $s5 = new Client($sbase, $sdir . DS . 'jar-5.txt');
    sign_in($s5, 'sam@example.test', 'smoke-pass-s1');
    $r = $s5->post('/index.php?login=1', ['code' => $rc[0]]);
    check('each recovery code works once', str_contains($r['body'], 'That code is not right') && !$in($s5) && count(json_decode($urow($sam)['recovery_codes'], true)) === 9);
    $r = $s5->get('/index.php?login=1&cancel=1');
    check('"Use a different account" drops the half-finished sign-in', $r['code'] === 302 && str_contains($s5->get('/index.php?login=1')['body'], '<h1>Sign in</h1>'));
    $unlock();
    $s6 = new Client($sbase, $sdir . DS . 'jar-6.txt');
    sign_in($s6, 'sam@example.test', 'smoke-pass-s1');
    for ($i = 0; $i < 5; $i++) $s6->post('/index.php?login=1', ['code' => $wrong($key)]);
    $s6->get('/index.php?login=1&cancel=1');
    sign_in($s6, 'sam@example.test', 'smoke-pass-s1');   // the right password again, in between
    $s6->post('/index.php?login=1', ['code' => $wrong($key)]);
    $fresh($sam);
    $r = $s6->post('/index.php?login=1', ['code' => $code($key)]);
    check('six wrong codes lock that address out, and a right password in between does not reset the count', str_contains($r['body'], 'Too many attempts. Try again in') && !$in($s6));
    $unlock();
    file_put_contents($sdir . DS . 'data' . DS . 'rl_2fa_' . $sam . '.json', json_encode(array_fill(0, 8, time())));   // eight wrong codes against this account, from anywhere
    $fresh($sam);
    $r = $s6->post('/index.php?login=1', ['code' => $code($key)]);
    check('eight wrong codes against one account lock its second step, whatever address they came from', str_contains($r['body'], 'Too many wrong codes. Try again in a few minutes.') && !$in($s6));
    $unlock(); $fresh($sam);
    $r = $s6->post('/index.php?login=1', ['code' => $code($key)]);
    check('once the lock has passed, the right code signs in', $r['code'] === 302 && $in($s6));
    $unlock();

    section('Two-factor sign-in: looking after it');
    $b = $s->get('/twofa.php')['body'];
    check('your account shows it is on and how many recovery codes are left', str_contains($b, 'Two-factor sign-in <span class="tag ok">on</span>') && str_contains($b, 'Recovery codes left: <b>9</b> of 10')
        && str_contains($s->get('/settings.php')['body'], '9 of 10 recovery codes left'));
    $s->post('/twofa.php', ['do' => 'codes', 'password' => 'not-my-password', 'code' => $code($key)]);
    $said = flash_of($s, '/twofa.php');
    $fresh($sam);
    $s->post('/twofa.php', ['do' => 'codes', 'password' => 'smoke-pass-s1', 'code' => $wrong($key)]);
    check('new recovery codes take your password and a code', $said === 'err: That is not your password.' && flash_of($s, '/twofa.php') === 'err: That code is not right. Use a current code from your authenticator app, or a recovery code.'
        && count(json_decode($urow($sam)['recovery_codes'], true)) === 9);
    $fresh($sam);
    $s->post('/twofa.php', ['do' => 'codes', 'password' => 'smoke-pass-s1', 'code' => $code($key)]);
    $rc2 = $codesOn($s);
    $s7 = new Client($sbase, $sdir . DS . 'jar-7.txt');
    sign_in($s7, 'sam@example.test', 'smoke-pass-s1');
    $r1 = $s7->post('/index.php?login=1', ['code' => $rc[1]]);
    $r2 = $s7->post('/index.php?login=1', ['code' => $rc2[0] ?? '']);
    check('they replace the old ones, which stop working', count($rc2) === 10 && !array_intersect($rc, $rc2) && str_contains($r1['body'], 'That code is not right') && $r2['code'] === 302 && $in($s7));
    $unlock(); $fresh($sam);
    $s->post('/twofa.php', ['do' => 'begin', 'password' => 'smoke-pass-s1', 'code' => $code($key)]);   // a new phone
    $key2 = $keyOn($s);
    $s8 = new Client($sbase, $sdir . DS . 'jar-8.txt');
    $r = $signIn2($s8, 'sam@example.test', 'smoke-pass-s1', $key, $sam);
    check('moving to a new phone starts a fresh set-up, and the old phone works until it is finished', strlen($key2) === 32 && $key2 !== $key && $r['code'] === 302 && $in($s8));
    $s->post('/twofa.php', ['do' => 'confirm', 'code' => $code($key2)]);
    $rc3 = $codesOn($s);
    $s9 = new Client($sbase, $sdir . DS . 'jar-9.txt');
    sign_in($s9, 'sam@example.test', 'smoke-pass-s1');
    $fresh($sam);
    $r1 = $s9->post('/index.php?login=1', ['code' => $code($key)]);
    $fresh($sam);
    $r2 = $s9->post('/index.php?login=1', ['code' => $code($key2)]);
    check('once the new phone is confirmed the old one stops working, and the other browsers are signed out', count($rc3) === 10 && str_contains($r1['body'], 'That code is not right') && $r2['code'] === 302
        && $in($s9) && $in($s) && !$in($s8) && !$in($s2));
    $unlock();
    $s->post('/twofa.php', ['do' => 'disable', 'password' => 'smoke-pass-s1', 'code' => $wrong($key2)]);
    $said = flash_of($s, '/twofa.php');
    $fresh($sam);
    $s->post('/twofa.php', ['do' => 'disable', 'password' => 'smoke-pass-s1', 'code' => $code($key2)]);
    $ur = $urow($sam);
    $s10 = new Client($sbase, $sdir . DS . 'jar-10.txt');
    $r = sign_in($s10, 'sam@example.test', 'smoke-pass-s1');
    check('turning it off takes your password and a code, forgets the secret, and the password alone signs in again', str_starts_with($said, 'err: That code is not right.')
        && flash_of($s, '/settings.php') === 'ok: Two-factor sign-in is off. Your other sessions were signed out.' && (int) $ur['totp_enabled'] === 0 && $ur['totp_secret'] === '' && $ur['recovery_codes'] === '[]'
        && $r['code'] === 302 && $in($s10) && !$in($s9));

    section('Two-factor sign-in: the team');
    $s->post('/settings.php', ['do' => 'add_user', 'uname' => 'Mel Member', 'email' => 'mel@example.test', 'upass' => 'smoke-pass-m1', 'role' => 'member']);
    $mel = (int) one($sdir, "SELECT id FROM users WHERE email='mel@example.test'");
    $m = new Client($sbase, $sdir . DS . 'jar-mel.txt');
    sign_in($m, 'mel@example.test', 'smoke-pass-m1');
    $b = $m->get('/index.php')['body']; $b2 = $m->get('/settings.php')['body'];
    check('a member has an Account page for their own password, two-factor sign-in and sessions', str_contains($b, 'Account</a>') && !str_contains($b, 'Settings</a>') && str_contains($s->get('/index.php')['body'], 'Settings</a>')
        && str_contains($b2, 'id="account"') && str_contains($b2, 'Sign out my other sessions') && !str_contains($b2, 'id="team"') && !str_contains($b2, 'id="security"'));
    [$mkey, $mrc] = $enable($m, 'smoke-pass-m1');
    $b = $s->get('/settings.php')['body'];
    check('Settings shows who on the team has two-factor sign-in', (int) $urow($mel)['totp_enabled'] === 1 && count($mrc) === 10 && str_contains($b, '1 of 2 people do not have it on yet.') && str_contains($b, 'name="do" value="totp_reset"'));
    $r = $m->post('/settings.php', ['do' => 'totp_reset', 'id' => $mel]);
    $s->post('/settings.php', ['do' => 'totp_reset', 'id' => $sam]);
    check('a member cannot reset anyone, and an admin uses their own account page for theirs', $r['code'] === 403 && (int) $urow($mel)['totp_enabled'] === 1
        && flash_of($s, '/settings.php') === 'err: To change your own two-factor sign-in, use Your account below.');
    $s->post('/settings.php', ['do' => 'totp_reset', 'id' => $mel]);
    $said = flash_of($s, '/settings.php');
    $out = !$in($m);
    $r = sign_in($m, 'mel@example.test', 'smoke-pass-m1');
    check('an admin can reset a member who lost their phone: they are signed out and sign in with their password', $said === 'ok: Two-factor sign-in is off for Mel Member, and they were signed out everywhere.'
        && $out && (int) $urow($mel)['totp_enabled'] === 0 && $urow($mel)['totp_secret'] === '' && $r['code'] === 302 && $in($m), $said);
    $s->post('/settings.php', ['do' => 'security', 'require_2fa' => 'on']);
    check('an admin without two-factor sign-in cannot require it of everyone else', flash_of($s, '/settings.php') === 'err: Turn on two-factor sign-in for your own account first, then require it for the team.'
        && one($sdir, "SELECT v FROM settings WHERE k='require_2fa'") !== '1');
    [$key3, $rc4] = $enable($s, 'smoke-pass-s1');
    $s->post('/settings.php', ['do' => 'security', 'require_2fa' => 'on']);
    $said = flash_of($s, '/settings.php');
    $r = $m->get('/contacts.php'); $r2 = $m->get('/index.php'); $b = $m->get('/twofa.php')['body'];
    check('once it is required, someone without it is taken to the set-up page whatever they open', str_starts_with($said, 'ok: Two-factor sign-in is now required.') && one($sdir, "SELECT v FROM settings WHERE k='require_2fa'") === '1'
        && $r['code'] === 302 && str_ends_with($r['location'], 'twofa.php') && $r2['code'] === 302 && str_ends_with($r2['location'], 'twofa.php') && $m->get('/contacts.php?export=1')['code'] === 302
        && str_contains($b, 'Your team requires two-factor sign-in. Set it up to carry on') && $in($s), 'HTTP ' . $r['code'] . ' ' . $r['location']);
    $r = $m->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Gate', 'email' => 'gate@example.test']);
    check('a form they were in the middle of still saves, and the API is not affected', (int) one($sdir, "SELECT COUNT(*) FROM contacts WHERE email='gate@example.test'") === 1 && $s->api('GET', 'me', $stok)['code'] === 200);
    [$mkey, $mrc] = $enable($m, 'smoke-pass-m1');
    check('after setting it up they are back in', count($mrc) === 10 && $in($m) && $m->get('/index.php')['code'] === 200);
    $m->post('/twofa.php', ['do' => 'disable', 'password' => 'smoke-pass-m1', 'code' => $code($mkey)]);
    check('while it is required it cannot be turned off', flash_of($m, '/twofa.php') === 'err: Your team requires two-factor sign-in, so it cannot be turned off. You can move it to a new phone instead.'
        && (int) $urow($mel)['totp_enabled'] === 1 && !str_contains($m->get('/twofa.php')['body'], 'value="disable"'));
    $s->post('/settings.php', ['do' => 'security']);
    check('an admin can make it optional again', flash_of($s, '/settings.php') === 'ok: Two-factor sign-in is optional again.' && one($sdir, "SELECT v FROM settings WHERE k='require_2fa'") === '0'
        && str_contains($m->get('/twofa.php')['body'], 'value="disable"'));
    $unlock();

    section('Signing out other sessions');
    $x = new Client($sbase, $sdir . DS . 'jar-x.txt');
    $r = $signIn2($x, 'mel@example.test', 'smoke-pass-m1', $mkey, $mel);
    $m->post('/settings.php', ['do' => 'signout_others']);
    $said = flash_of($m, '/settings.php');
    $b = $x->get('/contacts.php'); $b2 = $x->get('/index.php?login=1')['body'];
    check('"Sign out my other sessions" ends every other session of yours and keeps this one', $r['code'] === 302 && $b['code'] === 302 && !$in($x) && $in($m) && $in($s)
        && $said === 'ok: Your other sessions were signed out. This one stays signed in.', $said);
    check('the browser that was signed out is told so, and its sign-in form works straight away', str_contains($b2, 'You were signed out of this session. Sign in again to carry on.')
        && $signIn2($x, 'mel@example.test', 'smoke-pass-m1', $mkey, $mel)['code'] === 302 && $in($x));
    $ok = $in($x);
    $m->post('/settings.php', ['do' => 'passwd', 'current' => 'smoke-pass-m1', 'new' => 'smoke-pass-m2']);
    $said = flash_of($m, '/settings.php');
    check('changing your password signs out your other sessions too', $ok && !$in($x) && $in($m) && $said === 'ok: Password changed. Your other sessions were signed out.', $said);
    $r = $m->get('/logout.php?t=' . $m->csrf);
    check('signing out ends the session', $r['code'] === 302 && !$in($m));

    section('Locked out: recover.php on the server');
    [$o, $rc0] = run_cmd($sdir . DS . 'recover.php');
    check('it lists the accounts and whether two-factor sign-in is on', $rc0 === 0 && preg_match('/sam@example\.test\s+admin\s+two-factor on/', $o) === 1 && preg_match('/mel@example\.test\s+member\s+two-factor on/', $o) === 1, $o);
    [$o, $rc0] = run_cmd($sdir . DS . 'recover.php', '2fa-off', 'SAM@example.test');
    $r = sign_in($s, 'sam@example.test', 'smoke-pass-s1');
    check('2fa-off turns two-factor sign-in off: the password alone signs in again', $rc0 === 0 && str_contains($o, 'Two-factor sign-in is off for sam@example.test.') && (int) $urow($sam)['totp_enabled'] === 0
        && $r['code'] === 302 && $in($s), $o);
    [$o, $rc0] = run_cmd($sdir . DS . 'recover.php', 'password', 'sam@example.test');
    $newPass = preg_match('/^New password for sam@example\.test: (\S{16})$/m', $o, $pm) ? $pm[1] : '';
    $gone = !$in($s);
    $r1 = sign_in($s, 'sam@example.test', 'smoke-pass-s1');
    $r2 = sign_in($s, 'sam@example.test', $newPass);
    check('password prints a new password once: the old one stops working, every session ends, the new one signs in', $rc0 === 0 && $newPass !== '' && $gone && $r1['code'] === 200 && $r2['code'] === 302 && $in($s), $o);
    [$o, $rc0] = run_cmd($sdir . DS . 'recover.php', 'password', 'nobody@example.test');
    [$o2, $rcB] = run_cmd($sdir . DS . 'recover.php', 'delete-everything', 'sam@example.test');
    check('an address that has no account, or a command it does not know, changes nothing', $rc0 === 1 && str_contains($o, 'No account with the email address nobody@example.test') && $rcB === 2 && str_contains($o2, 'Usage:')
        && sign_in(new Client($sbase, $sdir . DS . 'jar-11.txt'), 'sam@example.test', $newPass)['code'] === 302);
    $unlock();

    section('Audit log: what is recorded');
    $logged = fn(string $action, string $more = '1=1', array $p = []): int => (int) one($sdir, "SELECT COUNT(*) FROM audit_log WHERE action=? AND $more", array_merge([$action], $p));
    $last = fn(string $action): array => row($sdir, "SELECT * FROM audit_log WHERE action=? ORDER BY id DESC LIMIT 1", [$action]);
    $since = fn(): int => (int) one($sdir, "SELECT COALESCE(MAX(id), 0) FROM audit_log");
    $after = fn(int $id): array => pdo_for($sdir)->query("SELECT action, actor, summary, entity, entity_id, user_id, ip FROM audit_log WHERE id>$id ORDER BY id")->fetchAll();
    $line = fn(array $r): string => $r['actor'] . ' | ' . $r['action'] . ' | ' . $r['summary'];
    $lines = fn(int $id): array => array_map($line, $after($id));
    $first = row($sdir, "SELECT * FROM audit_log ORDER BY id LIMIT 1");
    check('the log starts with the set-up of the first account', ($first['action'] ?? '') === 'user_added' && $first['summary'] === 'Set-up: the first account, an admin (sam@example.test)' && (int) $first['entity_id'] === $sam);
    check('sign-ins are recorded with how they were made', $logged('login', "summary='Signed in' AND user_id=?", [$sam]) >= 2 && $logged('login', "summary='Signed in with a two-factor code' AND user_id=?", [$sam]) >= 3
        && $logged('login', "summary='Signed in with a recovery code (9 left)' AND actor='Ada Admin'") === 2 && $logged('logout', 'user_id=?', [$mel]) === 1);
    $n0 = $since();
    $bad = new Client($sbase, $sdir . DS . 'jar-bad.txt');
    sign_in($bad, 'Sam@Example.test', 'guess-one');
    sign_in($bad, 'stranger@example.test', 'guess-two');
    $got = $after($n0);
    check('a failed sign-in records the address that was typed, never the password', count($got) === 2 && $got[0]['action'] === 'login_failed' && $got[0]['actor'] === 'sam@example.test' && $got[0]['summary'] === 'Wrong email or password'
        && (int) $got[0]['user_id'] === 0 && $got[0]['entity'] === 'users' && (int) $got[0]['entity_id'] === $sam && $got[1]['actor'] === 'stranger@example.test' && (int) $got[1]['entity_id'] === 0 && $got[0]['ip'] === '127.0.0.1', json_encode($got));
    check('wrong codes are recorded against the account', $logged('login_failed', "summary='Wrong two-factor code' AND entity_id=?", [$sam]) >= 8);
    check('every two-factor change is recorded', $logged('2fa_enabled', "summary='Turned on two-factor sign-in' AND user_id=?", [$sam]) === 2 && $logged('2fa_enabled', "summary='Set up two-factor sign-in again, with a new authenticator'") === 1
        && $logged('2fa_codes', 'user_id=?', [$sam]) === 1 && $logged('2fa_disabled', 'user_id=?', [$sam]) === 1 && $logged('2fa_enabled', 'user_id=?', [$mel]) === 2
        && $last('2fa_policy')['summary'] === 'Made two-factor sign-in optional again' && $logged('2fa_policy') === 2
        && $logged('2fa_reset', "summary='Reset two-factor sign-in for Mel Member (mel@example.test)' AND actor='Ada Admin' AND entity_id=?", [$mel]) === 1);
    check('what was done from the server is recorded as the command line', $logged('2fa_reset', "actor='Command line' AND ip='command line' AND summary='Turned off two-factor sign-in for Ada Admin (sam@example.test) from the server'") === 1
        && $logged('password_reset', "actor='Command line' AND summary='Reset the password of Ada Admin (sam@example.test) from the server' AND entity_id=?", [$sam]) === 1);
    check('password changes, signing out other sessions and new team members are recorded', $logged('password_changed', 'user_id=?', [$mel]) === 1 && $logged('sessions_revoked', 'user_id=?', [$mel]) === 1
        && $last('user_added')['summary'] === 'Added Mel Member (mel@example.test) as a member' && $last('user_added')['actor'] === 'Ada Admin');

    // Settings
    $s->loadCsrf('/settings.php');
    [$sport, $smail] = smtp_sink();
    $n0 = $since();
    $s->post('/settings.php', ['do' => 'org', 'name' => 'Smoke Org', 'timezone' => 'Australia/Sydney', 'notify_email' => 'alerts@example.test']);
    $s->post('/settings.php', ['do' => 'org', 'name' => 'Smoke Org', 'timezone' => 'Australia/Sydney', 'notify_email' => 'alerts@example.test']);   // nothing changed: no line
    $s->post('/settings.php', ['do' => 'routing', 'mode' => 'fixed', 'user_id' => $mel, 'assign_emails' => 'on']);
    $acct = ['do' => 'email_save', 'acc_name' => 'Smoke SMTP', 'from_email' => 'crm@example.test', 'from_name' => 'Smoke Org', 'smtp_host' => '127.0.0.1', 'smtp_port' => $sport, 'smtp_secure' => 'none', 'smtp_user' => 'crm', 'smtp_pass' => 'sink-secret-pw', 'is_default' => 'on'];
    $s->post('/settings.php', $acct);
    $s->post('/settings.php', ['from_email' => 'second@example.test', 'smtp_pass' => ''] + $acct);
    $acc2 = (int) one($sdir, "SELECT id FROM email_accounts WHERE from_email='second@example.test'");
    $s->post('/settings.php', ['do' => 'email_default', 'id' => $acc2]);
    $s->post('/settings.php', ['do' => 'email_del', 'id' => $acc2]);
    pdo_for($sdir)->exec("UPDATE email_accounts SET is_default=1, smtp_user=''");   // the sink takes no login
    $s->post('/settings.php', ['do' => 'ai', 'ai_key' => 'sk-ant-smoke-key-1', 'ai_model' => 'claude-haiku-4-5']);
    $s->post('/settings.php', ['do' => 'ai', 'ai_key' => '********************', 'ai_model' => 'claude-haiku-4-5']);   // saved with the stars still in the field
    $kept = one($sdir, "SELECT v FROM settings WHERE k='ai_key'");
    $s->post('/settings.php', ['do' => 'ai', 'ai_key' => 'sk-ant-smoke-key-2', 'ai_model' => 'claude-sonnet-5']);
    $s->post('/settings.php', ['do' => 'ai', 'ai_key' => '', 'ai_model' => 'claude-sonnet-5', 'ai_key_remove' => 'on']);
    $s->post('/settings.php', ['do' => 'api_reset']);
    $stok2 = (string) one($sdir, "SELECT v FROM settings WHERE k='api_token'");
    $got = $lines($n0);
    check('settings changes are recorded once each, in plain words', $got === [
        'Ada Admin | org | Changed the organization timezone, notifications email',
        'Ada Admin | routing | Lead routing: every new lead goes to Mel Member. Assignment emails on',
        'Ada Admin | email_account | Added the email account crm@example.test (127.0.0.1:' . $sport . '), password set, default sender',
        'Ada Admin | email_account | Added the email account second@example.test (127.0.0.1:' . $sport . '), default sender',
        'Ada Admin | email_account | Made second@example.test the default sender',
        'Ada Admin | email_account | Removed the email account second@example.test (127.0.0.1)',
        'Ada Admin | ai_key | AI settings: key set',
        'Ada Admin | ai_key | AI settings: key replaced, model claude-sonnet-5',
        'Ada Admin | ai_key | AI settings: key removed',
        'Ada Admin | api_token | Generated a new API token. The old one stopped working',
    ], implode("\n", $got));
    check('saving the AI settings with the stars still in the key field keeps the key', $kept === 'sk-ant-smoke-key-1' && one($sdir, "SELECT v FROM settings WHERE k='ai_key'") === '' && $stok2 !== $stok && $s->api('GET', 'me', $stok)['code'] === 401);
    $s->post('/settings.php', ['do' => 'routing', 'mode' => 'none', 'assign_emails' => 'on']);

    // Records
    $mk = fn(string $first, string $email, array $more = []): int => id_from($s->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => $first, 'email' => $email] + $more), '/contact\.php\?id=(\d+)/');
    $c1 = $mk('Owen', 'owen@example.test', ['last_name' => 'Owned']); $c2 = $mk('Bella', 'bella@example.test'); $c3 = $mk('Bruno', 'bruno@example.test'); $c4 = $mk('Dee', 'dee@example.test');
    $co1 = id_from($s->post('/companies.php', ['do' => 'add', 'name' => 'Keep Co']), '/company\.php\?id=(\d+)/');
    $co2 = id_from($s->post('/companies.php', ['do' => 'add', 'name' => 'Fold Co']), '/company\.php\?id=(\d+)/');
    $co3 = id_from($s->post('/companies.php', ['do' => 'add', 'name' => 'Gone Co']), '/company\.php\?id=(\d+)/');
    $s->get('/pipelines.php');
    $spid = (int) one($sdir, "SELECT id FROM pipelines ORDER BY id LIMIT 1");
    $s->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $spid, 'name' => 'Log deal', 'value' => '500', 'stage' => 0, 'contact_id' => $c1, 'owner_id' => $sam]);
    $deal = (int) one($sdir, "SELECT id FROM deals WHERE name='Log deal'");
    $s->post('/tasks.php', ['do' => 'add', 'title' => 'Log task', 'due_date' => '', 'assigned_to' => $sam]);
    $task = (int) one($sdir, "SELECT id FROM tasks WHERE title='Log task'");
    $n0 = $since();
    $s->post('/contact.php?id=' . $c1, ['do' => 'owner', 'owner_id' => $mel]);
    $s->post('/contacts.php', ['do' => 'save', 'id' => $c2, 'first_name' => 'Bella', 'email' => 'bella@example.test', 'owner_id' => 0]);
    $s->post('/company.php?id=' . $co1, ['do' => 'save', 'name' => 'Keep Co', 'website' => '', 'phone' => '', 'industry' => '', 'address' => '', 'notes' => '', 'owner_id' => $mel]);
    $s->post('/pipelines.php', ['do' => 'save_deal', 'id' => $deal, 'pipeline_id' => $spid, 'name' => 'Log deal', 'value' => '500', 'stage' => 0, 'contact_id' => $c1, 'owner_id' => $mel]);
    $s->post('/tasks.php', ['do' => 'assign', 'id' => $task, 'assigned_to' => $mel]);
    $s->post('/contact.php?id=' . $c1, ['do' => 'owner', 'owner_id' => $mel]);   // already theirs: no line
    $got = $lines($n0);
    check('every change of owner is recorded with who had it and who has it now', $got === [
        'Ada Admin | owner_changed | Owner of the contact "Owen Owned" (owen@example.test): Ada Admin -> Mel Member',
        'Ada Admin | owner_changed | Owner of the contact "Bella" (bella@example.test): Ada Admin -> nobody',
        'Ada Admin | owner_changed | Owner of the company "Keep Co": Ada Admin -> Mel Member',
        'Ada Admin | owner_changed | Owner of the deal "Log deal": Ada Admin -> Mel Member',
        'Ada Admin | owner_changed | Assignee of the task "Log task": Ada Admin -> Mel Member',
    ], implode("\n", $got));
    $n0 = $since();
    $r = $s->api('POST', 'contacts', $stok2, ['email' => 'bella@example.test', 'owner_id' => $mel, 'notify' => false]);
    $r2 = $s->api('POST', 'deals/' . $deal, $stok2, ['owner_id' => $sam, 'notify' => false]);
    $got = $after($n0);
    check('a change made through the API is recorded as the API', $r['code'] === 201 && $r2['code'] === 200 && array_map($line, $got) === [
        'API | owner_changed | Owner of the contact "Bella" (bella@example.test): nobody -> Mel Member',
        'API | owner_changed | Owner of the deal "Log deal": Mel Member -> Ada Admin',
    ] && (int) $got[0]['user_id'] === 0 && $got[0]['entity'] === 'contacts' && (int) $got[0]['entity_id'] === $c2, json_encode($got));
    $n0 = $since();
    $s->post('/contacts.php', ['do' => 'bulk', 'bulk' => 'assign', 'owner_id' => $mel, 'ids' => [$c1, $c2, $c3, $c4]]);
    $s->post('/contacts.php', ['do' => 'bulk', 'bulk' => 'tag', 'newtag' => 'audit, vip', 'ids' => [$c3, $c4]]);
    $s->post('/contacts.php', ['do' => 'bulk', 'bulk' => 'delete', 'confirm' => 'yes', 'ids' => [$c3, $c4]]);
    $got = $lines($n0);
    check('bulk actions are recorded as one action, and a bulk delete names each contact too', $got === [
        'Ada Admin | bulk_assign | 2 contacts assigned to Mel Member in one go',
        'Ada Admin | bulk_tag | Tagged 2 contacts "audit", "vip" in one go',
        'Ada Admin | delete | Deleted the contact "Bruno" (bruno@example.test)',
        'Ada Admin | delete | Deleted the contact "Dee" (dee@example.test)',
        'Ada Admin | bulk_delete | Deleted 2 contacts in one go',
    ], implode("\n", $got));
    $s->post('/campaigns.php', ['do' => 'save', 'id' => 0, 'name' => 'Draft only', 'subject' => 'Hi', 'body' => '<p>Hello</p>', 'seg_type' => 'all']);
    $camp = (int) one($sdir, "SELECT id FROM campaigns WHERE name='Draft only'");
    $s->post('/forms.php', ['do' => 'save', 'id' => 0, 'name' => 'Log form', 'thankyou' => 'Thanks', 'redirect' => '', 'fld_label' => ['Email'], 'fld_name' => ['email'], 'fld_type' => ['email']]);
    $form = (int) one($sdir, "SELECT id FROM forms WHERE name='Log form'");
    $wf = make_workflow($s, $sdir, 'Log workflow', 'contact_created', '', [['tag', 'seen']]);
    $n0 = $since();
    $s->post('/company.php?id=' . $co2, ['do' => 'merge', 'into' => $co1]);
    $s->post('/company.php?id=' . $co3, ['do' => 'delete']);
    $s->post('/pipelines.php', ['do' => 'del_deal', 'id' => $deal]);
    $s->post('/tasks.php', ['do' => 'del', 'id' => $task]);
    $s->post('/campaigns.php', ['do' => 'delete', 'id' => $camp]);
    $s->post('/forms.php', ['do' => 'delete', 'id' => $form]);
    $s->post('/workflows.php', ['do' => 'delete', 'id' => $wf]);
    $s->post('/contacts.php', ['do' => 'delete', 'id' => $c2]);
    $s->post('/contacts.php', ['do' => 'delete', 'id' => 999999]);   // nothing there: no line
    $got = $lines($n0);
    check('every delete is recorded with what was deleted, and a merge as a merge', $got === [
        'Ada Admin | merge | Merged the company "Fold Co" into "Keep Co"',
        'Ada Admin | delete | Deleted the company "Gone Co"',
        'Ada Admin | delete | Deleted the deal "Log deal"',
        'Ada Admin | delete | Deleted the task "Log task"',
        'Ada Admin | delete | Deleted the campaign "Draft only"',
        'Ada Admin | delete | Deleted the form "Log form"',
        'Ada Admin | delete | Deleted the workflow "Log workflow"',
        'Ada Admin | delete | Deleted the contact "Bella" (bella@example.test)',
    ], implode("\n", $got));
    $n0 = $since();
    $csv = $sdir . DS . 'people.csv';
    file_put_contents($csv, "first_name,email,owner\nIvy,ivy@example.test,mel@example.test\nIan,ian@example.test,\n");
    $s->upload('/contacts.php', ['do' => 'import'], 'csv', $csv);
    $ccsv = $sdir . DS . 'firms.csv';
    file_put_contents($ccsv, "name,website\nImport Co,import.example\n");
    $s->upload('/companies.php', ['do' => 'import'], 'csv', $ccsv);
    $e1 = $s->get('/contacts.php?export=1'); $e2 = $s->get('/companies.php?export=1');
    $nContacts = (int) one($sdir, "SELECT COUNT(*) FROM contacts"); $nCompanies = (int) one($sdir, "SELECT COUNT(*) FROM companies");
    $s->post('/campaigns.php', ['do' => 'send', 'id' => 0, 'name' => 'Autumn news', 'subject' => 'News', 'body' => '<p>Hello {{first_name}}</p>', 'seg_type' => 'tag', 'seg_tag' => 'nobody-has-this']);
    $got = $lines($n0);
    check('imports, exports and campaign sends are recorded with how many rows they covered', $got === [
        'Ada Admin | import | Imported 2 rows of contacts from the CSV file "people.csv"',
        'Ada Admin | import | Imported 1 row of companies from the CSV file "firms.csv"',
        'Ada Admin | export | Exported ' . $nContacts . ' contacts as CSV',
        'Ada Admin | export | Exported ' . $nCompanies . ' companies as CSV',
        'Ada Admin | campaign_sent | Sent the campaign "Autumn news" to 0 recipients tagged "nobody-has-this"',
    ], implode("\n", $got));
    check('the exports are clean CSV files, on any PHP version', $e1['code'] === 200 && str_starts_with($e1['body'], "first_name,last_name,email,phone,company,address,tags,source,created,owner\n") && substr_count(trim($e1['body']), "\n") === $nContacts
        && str_starts_with($e2['body'], "name,website,domain,phone,address,industry,notes,people,created\n") && substr_count(trim($e2['body']), "\n") === $nCompanies
        && !preg_match('/Deprecated|Warning|Notice/', $e1['body'] . $e2['body']), substr($e1['body'], 0, 160));
    $all = implode("\n", array_map(fn($r) => implode(' ', $r), pdo_for($sdir)->query("SELECT actor, action, summary, entity, ip FROM audit_log")->fetchAll()));
    $secrets = array_merge(['smoke-pass-s1', 'smoke-pass-m1', 'smoke-pass-m2', $newPass, 'guess-one', 'guess-two', 'not-my-password', 'sink-secret-pw', 'sk-ant-smoke-key-1', 'sk-ant-smoke-key-2', $stok, $stok2, $key, $key2, $key3, $mkey],
        $rc, $rc2, $rc3, $rc4, $mrc, array_map(fn($x) => str_replace('-', '', $x), $rc));
    $leaks = array_values(array_filter($secrets, fn($x) => $x !== '' && stripos($all, $x) !== false));
    check('no password, code, key or token is ever written to the log', count($secrets) > 70 && !$leaks, implode(', ', $leaks));

    section('Audit log: reading it');
    $day = fn(int $shift = 0): string => (new DateTime('now', new DateTimeZone('Australia/Sydney')))->modify(($shift < 0 ? '' : '+') . $shift . ' day')->format('Y-m-d');   // a date as the app sees it
    $r = $s->get('/audit.php');
    $total = (int) one($sdir, "SELECT COUNT(*) FROM audit_log");
    check('an admin opens the log from Settings, newest first, 50 lines a page', page_clean($r) && str_contains($s->get('/settings.php')['body'], 'audit.php">Open the audit log</a>') && str_contains($r['body'], number_format($total) . ' lines, page 1 of ' . (int) ceil($total / 50))
        && substr_count($r['body'], '<tr class="arow">') === 50 && strpos($r['body'], 'Sent the campaign') < strpos($r['body'], 'Imported 2 rows of contacts'), 'lines ' . $total);
    $mx = new Client($sbase, $sdir . DS . 'jar-mx.txt');
    $signIn2($mx, 'mel@example.test', 'smoke-pass-m2', $mkey, $mel);
    $r = $mx->get('/audit.php'); $r2 = $mx->get('/audit.php?export=1');
    check('a member cannot read or download it', $r['code'] === 403 && $r2['code'] === 403 && !str_contains($r['body'], 'Signed in'));
    $rows = fn(string $query): int => substr_count($s->get('/audit.php?' . $query)['body'], '<tr class="arow">');
    check('it filters by what happened: one kind, or a whole group', $rows('kind=merge') === 1 && $rows('kind=bulk_delete') === 1 && $rows('kind=' . rawurlencode('g:Team')) === $logged('user_added') + $logged('user_removed')
        && $rows('kind=2fa_policy') === 2 && $rows('kind=no-such-kind') === 50);
    check('by who did it, with failed sign-ins as them, or by nobody signed in', $rows('user=' . $mel . '&kind=login_failed') === $logged('login_failed', 'entity_id=?', [$mel]) && $rows('user=' . $mel . '&kind=2fa_enabled') === 2
        && $rows('user=other&kind=owner_changed') === 2 && $rows('user=other&kind=password_reset') === 1 && $rows('user=' . $sam . '&kind=password_reset') === 0);
    check('by words and by date', $rows('q=' . rawurlencode('Log deal')) === 3 && $rows('q=' . rawurlencode('command line')) === 2 && $rows('q=100%25') === 0 && $rows('q=zzzz-nothing') === 0
        && $rows('from=' . $day() . '&to=' . $day() . '&kind=merge') === 1 && $rows('to=' . $day(-1)) === 0 && $rows('from=' . $day(1)) === 0 && $rows('from=not-a-date&kind=merge') === 1);
    $r = $s->get('/audit.php?p=2');
    check('older lines are on the next page', page_clean($r) && str_contains($r['body'], 'page 2 of ') && str_contains($s->get('/audit.php?p=99999')['body'], 'Set-up: the first account'));
    $n0 = $since();
    $r = $s->get('/audit.php?export=1&kind=owner_changed');
    $csvLines = explode("\n", trim($r['body']));
    check('the lines the filter shows download as CSV, and the download is itself recorded', $r['code'] === 200 && $csvLines[0] === 'time,who,what,details,record,address' && count($csvLines) === 1 + $logged('owner_changed')
        && str_contains($r['body'], ',API,"Owner changed",') && !preg_match('/Deprecated|Warning|Notice/', $r['body']) && $lines($n0) === ['Ada Admin | export | Exported ' . $logged('owner_changed') . ' audit log lines (filtered)'], $csvLines[0] . ' ' . count($csvLines));
    $before = (int) one($sdir, "SELECT COUNT(*) FROM audit_log"); $h0 = md5(json_encode(pdo_for($sdir)->query("SELECT * FROM audit_log ORDER BY id")->fetchAll()));
    foreach ([['do' => 'delete', 'id' => 1], ['do' => 'clear'], ['do' => 'save', 'id' => 1, 'summary' => 'edited'], ['delete' => 'all']] as $try) $s->post('/audit.php', $try);
    check('the app offers no way to edit or delete a line', (int) one($sdir, "SELECT COUNT(*) FROM audit_log") === $before && md5(json_encode(pdo_for($sdir)->query("SELECT * FROM audit_log ORDER BY id")->fetchAll())) === $h0
        && !preg_match('/<form method="post"/', $s->get('/audit.php')['body']));
    $old = time() - 400 * 86400; $edge = time() - 300 * 86400;
    pdo_for($sdir)->prepare("INSERT INTO audit_log (user_id, actor, action, summary, created_at) VALUES (0, 'Old', 'login', 'thirteen months ago', ?), (0, 'Edge', 'login', 'ten months ago', ?)")->execute([$old, $edge]);
    pdo_for($sdir)->exec("DELETE FROM settings WHERE k='audit_pruned'");
    $o = run_php($sdir . DS . 'cron.php');
    check('the background job drops lines older than 12 months, and only those', str_contains($o, 'workflows_processed=') && $logged('login', "summary='thirteen months ago'") === 0 && $logged('login', "summary='ten months ago'") === 1
        && (int) one($sdir, "SELECT COUNT(*) FROM audit_log") === $before + 1 && one($sdir, "SELECT v FROM settings WHERE k='audit_pruned'") === $day(), $o);
    $s->post('/settings.php', ['do' => 'del_user', 'id' => $mel, 'inherit' => 0]);
    $r = $s->get('/audit.php?user=' . $mel);
    check('a line still names someone after they have left the team', $last('user_removed')['summary'] === 'Removed Mel Member (mel@example.test, member). What they owned (3 contacts, 1 company) was left unassigned'
        && str_contains($s->get('/audit.php?q=' . rawurlencode('Mel Member'))['body'], '>Mel Member</td>') && $logged('delete', "entity='users'") === 0, $last('user_removed')['summary'] ?? '');
    foreach (['index.php', 'settings.php', 'twofa.php', 'audit.php', 'audit.php?kind=g%3ASecurity&q=two', 'contacts.php', 'tasks.php', 'pipelines.php'] as $p) {
        check("page loads without warnings: $p", page_clean($s->get('/' . $p)));
    }
    $h4 = schema_hash($sdir);
    file_put_contents($sdir . DS . 'again.php', "<?php require __DIR__ . '/_boot.php'; db(); echo 'ok';");
    check('opening the database again changes nothing', run_php($sdir . DS . 'again.php') === 'ok' && schema_hash($sdir) === $h4);
    stop($ssrv);

    /* ================================================== B. upgrade from 1.0 === */
    section('Upgrade from v1.0');
    $git = trim((string) @shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-list --max-parents=0 HEAD 2>&1'));
    if (!preg_match('/^[0-9a-f]{40}$/', $git)) {
        echo "  skip  no git history here, so the v1.0 upgrade test cannot run\n";
    } else {
        $old = tmpdir('v10');
        foreach (array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' ls-tree -r --name-only ' . $git))) as $f) {
            $f = trim($f);
            if ($f === '' || !str_ends_with($f, '.php') || str_contains($f, '/')) continue;
            file_put_contents($old . DS . $f, (string) shell_exec('git -C ' . escapeshellarg($ROOT) . ' show ' . $git . ':' . escapeshellarg($f)));
        }
        [$base, $srv] = serve($old);
        $o = new Client($base, $old . DS . 'jar.txt');
        setup_admin($o, 'old@example.test', 'smoke-pass-2');
        $o->loadCsrf('/contacts.php?new=1');
        $ids = [];
        foreach ([['Una', 'Acme'], ['Vic', 'ACME '], ['Wyn', 'Beta Co'], ['Xan', '']] as [$fn, $co]) {
            $r = $o->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => $fn, 'email' => strtolower($fn) . '@example.test', 'company' => $co]);
            $ids[$fn] = id_from($r, '/contact\.php\?id=(\d+)/');
        }
        $o->get('/pipelines.php');
        $opid = (int) one($old, "SELECT id FROM pipelines ORDER BY id LIMIT 1");
        $o->post('/pipelines.php', ['do' => 'new_deal', 'pipeline_id' => $opid, 'name' => 'Old won', 'value' => '900', 'stage' => 1, 'contact_id' => $ids['Una']]);
        $o->post('/pipelines.php', ['do' => 'new_deal', 'pipeline_id' => $opid, 'name' => 'Old open', 'value' => '400', 'stage' => 0, 'contact_id' => $ids['Wyn']]);
        $o->post('/pipelines.php', ['do' => 'deal_status', 'id' => (int) one($old, "SELECT id FROM deals WHERE name='Old won'"), 'status' => 'won']);
        $o->get('/settings.php');
        $otok = (string) one($old, "SELECT v FROM settings WHERE k='api_token'");
        check('v1.0 database was built with the first release', count(array_filter($ids)) === 4 && one($old, "SELECT v FROM settings WHERE k='schema_version'") === false && $otok !== '');
        $v10api = [];   // what each v1.0 API call answers, to compare after the upgrade
        foreach (['me', 'contacts', 'contacts/' . $ids['Una'], 'deals'] as $call) $v10api[$call] = $o->api('GET', $call, $otok)['json'];
        stop($srv);

        $new = tmpdir('upgraded');
        copy_app($ROOT, $new);
        rcopy($old . DS . 'data', $new . DS . 'data');
        [$base, $srv] = serve($new);
        $n = new Client($base, $new . DS . 'jar.txt');
        $n->loadCsrf('/index.php?login=1');
        $r = $n->post('/index.php?login=1', ['email' => 'old@example.test', 'password' => 'smoke-pass-2']);
        check('existing login still works after the upgrade', $r['code'] === 302, 'HTTP ' . $r['code']);
        check('database upgraded itself to schema version 4', one($new, "SELECT v FROM settings WHERE k='schema_version'") === '4');
        $names = pdo_for($new)->query("SELECT name FROM companies ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        check('company names on contacts became company records, merged case-insensitively', $names === ['Acme', 'Beta Co'], implode(' | ', $names));
        $acmeId = (int) one($new, "SELECT id FROM companies WHERE name='Acme'");
        check('contacts are linked to their companies', (int) one($new, "SELECT company_id FROM contacts WHERE id=?", [$ids['Una']]) === $acmeId && (int) one($new, "SELECT company_id FROM contacts WHERE id=?", [$ids['Vic']]) === $acmeId && (int) one($new, "SELECT company_id FROM contacts WHERE id=?", [$ids['Xan']]) === 0);
        check('contact company text is untouched by the upgrade', one($new, "SELECT company FROM contacts WHERE id=?", [$ids['Vic']]) === 'ACME');
        $won = row($new, "SELECT * FROM deals WHERE name='Old won'"); $open = row($new, "SELECT * FROM deals WHERE name='Old open'");
        check('a deal already won gets a closed time and its company', (int) $won['closed_at'] > 0 && (int) $won['company_id'] === $acmeId && (int) $won['value_cents'] === 90000);
        check('an open deal stays open with the stage default probability', $open['status'] === 'open' && (int) $open['closed_at'] === 0 && (int) $open['probability'] === -1);
        foreach (['index.php', 'contacts.php', 'companies.php', 'company.php?id=' . $acmeId, 'pipelines.php', 'reports.php'] as $p) {
            $r = $n->get('/' . $p);
            check("upgraded page loads without warnings: $p", $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']), 'HTTP ' . $r['code']);
        }
        $r = $n->api('GET', 'deals', $otok);
        check('the v1.0 API token and deals call still work', $r['code'] === 200 && array_column($r['json']['data'] ?? [], 'name') === ['Old open']);
        $changed = [];
        foreach ($v10api as $call => $before) $changed = array_merge($changed, api_diff($before, $n->api('GET', $call, $otok)['json'], $call));
        check('every v1.0 API call answers with the same fields and values as before the upgrade', count($v10api) === 4 && is_array($v10api['contacts']['data'] ?? null) && $changed === [], implode(' | ', $changed));
        $h2 = schema_hash($new);
        $n->get('/index.php');
        check('a second visit changes nothing more', schema_hash($new) === $h2);
        stop($srv);
    }

    /* ================================================== F. upgrade from 1.1 === */
    section('Upgrade from v1.1');
    $v11 = trim((string) @shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-list -n 1 v1.1.0 2>&1'));
    if (!preg_match('/^[0-9a-f]{40}$/', $v11)) {
        echo "  skip  no v1.1.0 tag in the git history here, so the v1.1 upgrade test cannot run\n";
    } else {
        $old = tmpdir('v11');
        export_release($ROOT, $v11, $old);
        [$base, $srv] = serve($old);
        $o = new Client($base, $old . DS . 'jar.txt');
        setup_admin($o, 'old@example.test', 'smoke-pass-2');
        $o->loadCsrf('/settings.php');
        $o->post('/settings.php', ['do' => 'add_user', 'uname' => 'Bo Leaver', 'email' => 'bo@example.test', 'upass' => 'smoke-pass-b', 'role' => 'member']);
        $adm = (int) one($old, "SELECT id FROM users WHERE email='old@example.test'"); $bo = (int) one($old, "SELECT id FROM users WHERE email='bo@example.test'");
        // In 1.1 a contact or a task belongs to whoever adds it, so Bo signs in and adds one of each.
        $q = new Client($base, $old . DS . 'jar-bo.txt');
        sign_in($q, 'bo@example.test', 'smoke-pass-b');
        $bea = id_from($q->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Bea', 'email' => 'bea@example.test', 'tags' => 'lead']), '/contact\.php\?id=(\d+)/');
        $q->post('/tasks.php', ['do' => 'add', 'title' => 'Bo task', 'due_date' => '']);
        // The admin adds a contact and a task of their own, a company and a deal owned by Bo, and a deal of their own.
        $abe = id_from($o->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Abe', 'email' => 'abe@example.test']), '/contact\.php\?id=(\d+)/');
        $o->post('/tasks.php', ['do' => 'add', 'title' => 'Admin task', 'due_date' => '']);
        $own = id_from($o->post('/companies.php', ['do' => 'add', 'name' => 'Own Co']), '/company\.php\?id=(\d+)/');
        $o->post('/company.php?id=' . $own, ['do' => 'save', 'name' => 'Own Co', 'website' => '', 'phone' => '', 'industry' => '', 'address' => '', 'notes' => '', 'owner_id' => $bo]);
        $o->get('/pipelines.php');
        $opid = (int) one($old, "SELECT id FROM pipelines ORDER BY id LIMIT 1");
        $o->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $opid, 'name' => 'Bo deal', 'value' => '300', 'stage' => 0, 'contact_id' => $bea, 'owner_id' => $bo]);
        $o->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $opid, 'name' => 'Admin deal', 'value' => '200', 'stage' => 1, 'contact_id' => $abe, 'owner_id' => $adm]);
        $boDeal = (int) one($old, "SELECT id FROM deals WHERE name='Bo deal'");
        // 1.1 removed a team member without asking, and left everything they owned pointing at nobody.
        $o->post('/settings.php', ['do' => 'del_user', 'id' => $bo]);
        $o->get('/settings.php');
        $otok = (string) one($old, "SELECT v FROM settings WHERE k='api_token'");
        $v11api = [];
        foreach (['me', 'contacts', 'contacts/' . $bea, 'companies', 'companies/' . $own, 'deals?status=all', 'deals/' . $boDeal, 'pipelines'] as $call) $v11api[$call] = $o->api('GET', $call, $otok)['json'];
        check('v1.1 database was built with the 1.1.0 release, with records left behind by a removed member', one($old, "SELECT v FROM settings WHERE k='schema_version'") === '2'
            && (int) one($old, "SELECT COUNT(*) FROM users WHERE id=?", [$bo]) === 0 && (int) one($old, "SELECT owner_id FROM contacts WHERE id=?", [$bea]) === $bo
            && (int) one($old, "SELECT owner_id FROM deals WHERE id=?", [$boDeal]) === $bo && ($v11api['deals/' . $boDeal]['owner_id'] ?? null) === $bo && ($v11api['me']['version'] ?? '') === '1.1.0');
        stop($srv);

        $new = tmpdir('upgraded11');
        copy_app($ROOT, $new);
        rcopy($old . DS . 'data', $new . DS . 'data');
        [$base, $srv] = serve($new);
        $n = new Client($base, $new . DS . 'jar.txt');
        $r = sign_in($n, 'old@example.test', 'smoke-pass-2');
        check('existing login still works after the upgrade from 1.1', $r['code'] === 302, 'HTTP ' . $r['code']);
        check('the 1.1 database upgraded itself to schema version 4', one($new, "SELECT v FROM settings WHERE k='schema_version'") === '4');
        $owner = fn(string $tbl, string $col, string $where): int => (int) one($new, "SELECT $col FROM $tbl WHERE $where");
        check('what a removed member left behind becomes unassigned', $owner('contacts', 'owner_id', "id=$bea") === 0 && $owner('companies', 'owner_id', "id=$own") === 0
            && $owner('deals', 'owner_id', "id=$boDeal") === 0 && $owner('tasks', 'assigned_to', "title='Bo task'") === 0);
        check('everyone still on the team keeps what they own', $owner('contacts', 'owner_id', "id=$abe") === $adm && $owner('deals', 'owner_id', "name='Admin deal'") === $adm && $owner('tasks', 'assigned_to', "title='Admin task'") === $adm);
        check('each task now records who added it', $owner('tasks', 'created_by', "title='Admin task'") === $adm && $owner('tasks', 'created_by', "title='Bo task'") === $bo);
        $idx = pdo_for($new)->query("SELECT name FROM sqlite_master WHERE type='index' AND name IN ('idx_contacts_owner', 'idx_deals_owner', 'idx_tasks_assignee') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        check('the indexes for the owner filters are there', $idx === ['idx_contacts_owner', 'idx_deals_owner', 'idx_tasks_assignee'], implode(',', $idx));
        check('nothing else about the contact changed', tags_of($new, 'bea@example.test') === ['lead'] && $owner('contacts', 'company_id', "id=$bea") === 0);
        $changed = [];
        foreach ($v11api as $call => $before) $changed = array_merge($changed, api_diff($before, $n->api('GET', $call, $otok)['json'], $call));
        sort($changed);
        $expected = ["companies.data#$own.owner_id: $bo -> 0", "companies/$own.owner_id: $bo -> 0", "deals/$boDeal.owner_id: $bo -> 0", "deals?status=all.data#$boDeal.owner_id: $bo -> 0", 'me.version: "1.1.0" -> "' . $version . '"'];
        sort($expected);
        check('every v1.1 API call answers with the same fields and values, apart from the version and the cleared owners', $changed === $expected, implode(' | ', $changed));
        check('contacts in the API gain owner_id', ($n->api('GET', 'contacts/' . $bea, $otok)['json']['owner_id'] ?? null) === 0);
        $b = $n->get('/contacts.php?who=none')['body'];
        check('the Unassigned filter finds what was left behind, ready to hand to someone', str_contains($b, '<b>Bea</b>') && !str_contains($b, '<b>Abe</b>') && str_contains($b, 'id="bulkbar"'));
        foreach (['index.php', 'contacts.php?who=all', 'contact.php?id=' . $bea, 'companies.php', 'pipelines.php', 'tasks.php', 'reports.php', 'settings.php'] as $p) {
            $r = $n->get('/' . $p);
            check("page upgraded from 1.1 loads without warnings: $p", $r['code'] === 200 && !preg_match('/(Warning|Notice|Deprecated|Fatal error)<\/b>:|Uncaught /', $r['body']), 'HTTP ' . $r['code']);
        }
        $h2 = schema_hash($new);
        $n->get('/index.php');
        check('a second visit changes nothing more', schema_hash($new) === $h2);
        stop($srv);
    }

    /* ================================================== H. upgrade from 1.2 === */
    section('Upgrade from v1.2');
    $v12 = trim((string) @shell_exec('git -C ' . escapeshellarg($ROOT) . ' rev-list -n 1 v1.2.1 2>&1'));
    if (!preg_match('/^[0-9a-f]{40}$/', $v12)) {
        echo "  skip  no v1.2.1 tag in the git history here, so the v1.2 upgrade test cannot run\n";
    } else {
        $old = tmpdir('v12');
        export_release($ROOT, $v12, $old);
        [$base, $srv] = serve($old);
        $o = new Client($base, $old . DS . 'jar.txt');
        setup_admin($o, 'old@example.test', 'smoke-pass-2');
        $o->loadCsrf('/settings.php');
        $o->post('/settings.php', ['do' => 'add_user', 'uname' => 'Mo Member', 'email' => 'mo@example.test', 'upass' => 'smoke-pass-b', 'role' => 'member']);
        $adm = (int) one($old, "SELECT id FROM users WHERE email='old@example.test'"); $mo = (int) one($old, "SELECT id FROM users WHERE email='mo@example.test'");
        $kit = id_from($o->post('/contacts.php', ['do' => 'save', 'id' => 0, 'first_name' => 'Kit', 'email' => 'kit@example.test', 'tags' => 'lead', 'owner_id' => $mo]), '/contact\.php\?id=(\d+)/');
        $o->post('/companies.php', ['do' => 'add', 'name' => 'Old Co']);
        $o->get('/pipelines.php');
        $opid = (int) one($old, "SELECT id FROM pipelines ORDER BY id LIMIT 1");
        $o->post('/pipelines.php', ['do' => 'save_deal', 'id' => 0, 'pipeline_id' => $opid, 'name' => 'Old deal', 'value' => '250', 'stage' => 1, 'contact_id' => $kit, 'owner_id' => $mo]);
        $o->post('/tasks.php', ['do' => 'add', 'title' => 'Old task', 'due_date' => '', 'assigned_to' => $mo]);
        $o->post('/settings.php', ['do' => 'routing', 'mode' => 'fixed', 'user_id' => $mo, 'assign_emails' => 'on']);
        $o->get('/settings.php');
        $otok = (string) one($old, "SELECT v FROM settings WHERE k='api_token'");
        $v12api = [];
        foreach (['me', 'contacts', 'contacts/' . $kit, 'companies', 'deals?status=all', 'pipelines', 'users'] as $call) $v12api[$call] = $o->api('GET', $call, $otok)['json'];
        $people = fn(string $dir): array => pdo_for($dir)->query("SELECT id, email, pass_hash, name, role, status, created_at FROM users ORDER BY id")->fetchAll();
        $peopleBefore = $people($old); $ruleBefore = one($old, "SELECT v FROM settings WHERE k='lead_routing'");
        check('v1.2 database was built with the 1.2.1 release', one($old, "SELECT v FROM settings WHERE k='schema_version'") === '3' && ($v12api['me']['version'] ?? '') === '1.2.1' && $kit > 0 && count($peopleBefore) === 2
            && (int) one($old, "SELECT COUNT(*) FROM sqlite_master WHERE name='audit_log'") === 0);
        stop($srv);

        $new = tmpdir('upgraded12');
        copy_app($ROOT, $new);
        rcopy($old . DS . 'data', $new . DS . 'data');
        [$base, $srv] = serve($new);
        $n = new Client($base, $old . DS . 'jar.txt');   // the same browser, with its cookie from before the upgrade
        $r = $n->get('/index.php');
        check('someone signed in before the upgrade is still signed in after it', $r['code'] === 200 && str_contains($r['body'], 'Good day'), 'HTTP ' . $r['code']);
        check('the 1.2 database upgraded itself to schema version 4', one($new, "SELECT v FROM settings WHERE k='schema_version'") === '4');
        $cols = array_column(pdo_for($new)->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        check('each account gains what two-factor sign-in needs, switched off', !array_diff(['totp_secret', 'totp_enabled', 'totp_last_step', 'recovery_codes', 'session_version'], $cols)
            && (int) one($new, "SELECT COUNT(*) FROM users WHERE totp_enabled!=0 OR totp_secret!='' OR totp_last_step!=0 OR session_version!=0 OR recovery_codes!='[]'") === 0);
        check('nothing else about the accounts changed', $people($new) === $peopleBefore);
        check('the audit log starts empty: nothing is made up about the past', (int) one($new, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='audit_log'") === 1 && (int) one($new, "SELECT COUNT(*) FROM audit_log") === 0);
        $q = new Client($base, $new . DS . 'jar-mo.txt');
        $r = sign_in($q, 'mo@example.test', 'smoke-pass-b');
        check('existing passwords sign in as before, with no second step, and that is the first line in the log', $r['code'] === 302 && $q->get('/contacts.php')['code'] === 200
            && pdo_for($new)->query("SELECT action, actor, summary FROM audit_log ORDER BY id")->fetchAll() === [['action' => 'login', 'actor' => 'Mo Member', 'summary' => 'Signed in']]);
        $changed = [];
        foreach ($v12api as $call => $before) $changed = array_merge($changed, api_diff($before, $n->api('GET', $call, $otok)['json'], $call));
        check('every v1.2 API call answers with the same fields and values, apart from the version', count($v12api) === 7 && $changed === ['me.version: "1.2.1" -> "' . $version . '"'], implode(' | ', $changed));
        check('owners, tags and the lead routing rule are untouched', (int) one($new, "SELECT owner_id FROM contacts WHERE id=?", [$kit]) === $mo && tags_of($new, 'kit@example.test') === ['lead']
            && (int) one($new, "SELECT owner_id FROM deals WHERE name='Old deal'") === $mo && (int) one($new, "SELECT assigned_to FROM tasks WHERE title='Old task'") === $mo
            && one($new, "SELECT v FROM settings WHERE k='lead_routing'") === $ruleBefore);
        $n->loadCsrf('/settings.php');
        foreach (['index.php', 'contacts.php', 'contact.php?id=' . $kit, 'companies.php', 'pipelines.php', 'tasks.php', 'reports.php', 'settings.php', 'twofa.php', 'audit.php'] as $p) {
            check("page upgraded from 1.2 loads without warnings: $p", page_clean($n->get('/' . $p)));
        }
        $h2 = schema_hash($new);
        $n->get('/index.php');
        check('a second visit changes nothing more', schema_hash($new) === $h2);
        stop($srv);
    }
} catch (Throwable $e) {
    check('unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine());
}

foreach ($servers as $p) if (is_resource($p)) { @proc_terminate($p); @proc_close($p); }
$fail = count(array_filter($results, fn($r) => !$r[1]));
echo "\n" . (count($results) - $fail) . " passed, $fail failed\n";
if ($KEEP) echo "kept:\n  " . implode("\n  ", $tmpDirs) . "\n"; else { usleep(300000); foreach ($tmpDirs as $d) rrmdir($d); }
exit($fail ? 1 : 0);

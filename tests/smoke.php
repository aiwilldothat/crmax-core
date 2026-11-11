<?php
/**
 * CRMax Core - end-to-end smoke test. Command line only.
 *
 *   php tests/smoke.php            fresh install + upgrade from v1.0
 *   php tests/smoke.php --keep     keep the temp folders afterwards (for debugging)
 *
 * Nothing here touches your real data: the app is copied to a temp folder, served with PHP's
 * built-in server and driven over HTTP like a browser. The upgrade test checks out the first
 * release from git, builds a small v1.0 database with it, then opens that database with the
 * current code.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
$KEEP = in_array('--keep', $argv, true);
$results = []; $servers = []; $tmpDirs = [];
const DS = DIRECTORY_SEPARATOR;

function check(string $name, bool $ok, string $detail = ''): bool {
    global $results;
    $results[] = [$name, $ok];
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : '  -> ' . $detail) . "\n";
    return $ok;
}
function section(string $t): void { echo "\n$t\n"; }
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
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir);
    for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
    $servers[] = $proc;
    return ["http://127.0.0.1:$port", $proc];
}
function stop($proc): void { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); }

final class Client {
    public string $csrf = '';
    public function __construct(private string $base, private string $jar) {}
    /** @return array{code:int, body:string, location:string} */
    public function req(string $method, string $path, $body = null, array $headers = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $hs), 'location' => preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m) ? $m[1] : ''];
    }
    public function get(string $path): array { return $this->req('GET', $path); }
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
function setup_admin(Client $c, string $email, string $pass): array {
    $c->loadCsrf('/index.php');
    return $c->post('/index.php', ['name' => 'Ada Admin', 'org' => 'Smoke Org', 'email' => $email, 'password' => $pass]);
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
    check('database is at schema version 2', one($dir, "SELECT v FROM settings WHERE k='schema_version'") === '2');
    check('the data folder protects itself on Apache', str_contains((string) @file_get_contents($dir . DS . 'data' . DS . '.htaccess'), 'Require all denied'));
    $h1 = schema_hash($dir);
    file_put_contents($dir . DS . 'again.php', "<?php require __DIR__ . '/_boot.php'; db(); echo 'ok';");
    $o1 = run_php($dir . DS . 'again.php'); $o2 = run_php($dir . DS . 'again.php');
    check('opening the database again changes nothing', $o1 === 'ok' && $o2 === 'ok' && schema_hash($dir) === $h1, "$o1 | $o2");
    $c->loadCsrf('/contacts.php?new=1');
    $r = $c->post('/contacts.php', ['do' => 'save', 'first_name' => 'No', 'email' => 'no@example.test'], false);
    check('POST without a CSRF token is refused', $r['code'] === 400, 'HTTP ' . $r['code']);

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
    check('/me reports the version and counts', $r['code'] === 200 && ($r['json']['version'] ?? '') === '1.1.0' && (int) ($r['json']['companies'] ?? 0) === 3, $r['body']);
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
    stop($srv);

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
        stop($srv);

        $new = tmpdir('upgraded');
        copy_app($ROOT, $new);
        rcopy($old . DS . 'data', $new . DS . 'data');
        [$base, $srv] = serve($new);
        $n = new Client($base, $new . DS . 'jar.txt');
        $n->loadCsrf('/index.php?login=1');
        $r = $n->post('/index.php?login=1', ['email' => 'old@example.test', 'password' => 'smoke-pass-2']);
        check('existing login still works after the upgrade', $r['code'] === 302, 'HTTP ' . $r['code']);
        check('database upgraded itself to schema version 2', one($new, "SELECT v FROM settings WHERE k='schema_version'") === '2');
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

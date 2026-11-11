<?php
/**
 * CRMax Core - minimal REST API (JSON). Authenticate with the token from Settings:
 *   Authorization: Bearer <token>
 * Routes (prefix with this file, e.g. /api.php/contacts):
 *   GET  /me                          org, version and counts
 *   GET  /contacts?q=&limit=          list contacts
 *   GET  /contacts/{id}               one contact
 *   POST /contacts                    create/update (de-duped by email); JSON or form body
 *   GET  /companies?q=&limit=         list companies
 *   GET  /companies/{id}              one company
 *   POST /companies                   create/update (matched by name)
 *   GET  /deals?status=&limit=        list deals: status = open (default) | won | lost | all
 *   GET  /deals/{id}                  one deal
 *   POST /deals                       create a deal
 *   POST /deals/{id}                  update a deal - only the fields you send change (PUT and PATCH also work)
 *   GET  /pipelines                   pipelines with their stages and each stage's win probability
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit; }

// ---- auth ----
$hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if ($hdr === '' && function_exists('apache_request_headers')) { $h = apache_request_headers(); $hdr = (string) ($h['Authorization'] ?? ''); }
$token = preg_match('/Bearer\s+(.+)/i', $hdr, $m) ? trim($m[1]) : '';
if (!rl_hit('api', 60, 120)) out(['error' => 'rate_limited'], 429);
if ($token === '' || !hash_equals(api_token(), $token)) out(['error' => 'unauthorized'], 401);

$path = trim((string) ($_SERVER['PATH_INFO'] ?? ($_GET['path'] ?? '')), '/');
$parts = $path === '' ? [] : explode('/', $path);
$res = $parts[0] ?? ''; $rid = isset($parts[1]) ? (int) $parts[1] : 0;
$method = $_SERVER['REQUEST_METHOD'];
$write = in_array($method, ['POST', 'PUT', 'PATCH'], true);
$body = [];
if ($write) {
    $raw = file_get_contents('php://input');
    $body = $raw && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? (json_decode($raw, true) ?: []) : $_POST;
    if (!is_array($body)) $body = [];
}
$limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));

function contact_out(array $c): array {
    return ['id' => (int) $c['id'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['email'],
        'phone' => $c['phone'], 'company' => $c['company'], 'company_id' => (int) $c['company_id'], 'address' => $c['address'], 'tags' => jdec($c['tags']),
        'source' => $c['source'], 'unsubscribed' => (bool) $c['dnd_email'], 'created_at' => (int) $c['created_at']];
}
function company_out(array $c): array {
    return ['id' => (int) $c['id'], 'name' => $c['name'], 'domain' => $c['domain'], 'website' => $c['website'], 'phone' => $c['phone'],
        'address' => $c['address'], 'industry' => $c['industry'], 'notes' => $c['notes'], 'owner_id' => (int) $c['owner_id'],
        'created_at' => (int) $c['created_at'], 'updated_at' => (int) $c['updated_at']];
}
/** Stage win probabilities for a pipeline id (cached for the request). */
function probs_for(int $pipelineId): array {
    static $cache = [];
    if (!isset($cache[$pipelineId])) { $p = go_get('pipelines', $pipelineId); $cache[$pipelineId] = $p ? stage_probs($p) : []; }
    return $cache[$pipelineId];
}
function deal_out(array $d): array {
    $probs = probs_for((int) $d['pipeline_id']);
    return ['id' => (int) $d['id'], 'name' => $d['name'], 'value' => (int) $d['value_cents'] / 100, 'stage' => (int) $d['stage'], 'contact_id' => (int) $d['contact_id'],
        'company_id' => (int) $d['company_id'], 'pipeline_id' => (int) $d['pipeline_id'], 'status' => $d['status'], 'close_date' => $d['close_date'],
        'probability' => deal_prob($d, $probs), 'probability_is_stage_default' => (int) $d['probability'] < 0, 'weighted_value' => deal_weighted($d, $probs) / 100,
        'owner_id' => (int) $d['owner_id'], 'lost_reason' => $d['lost_reason'], 'notes' => $d['notes'],
        'created_at' => (int) $d['created_at'], 'updated_at' => (int) $d['updated_at'], 'closed_at' => (int) $d['closed_at']];
}

if ($res === 'me') {
    out(['org' => org_name(), 'version' => APP_VERSION,
        'contacts' => (int) db()->query("SELECT COUNT(*) FROM contacts")->fetchColumn(),
        'companies' => (int) db()->query("SELECT COUNT(*) FROM companies")->fetchColumn(),
        'open_deals' => (int) db()->query("SELECT COUNT(*) FROM deals WHERE status='open'")->fetchColumn()]);
}

if ($res === 'contacts') {
    if ($write) {
        if (($body['email'] ?? '') === '' && ($body['phone'] ?? '') === '') out(['error' => 'email or phone required'], 422);
        $id = contact_upsert([
            'first_name' => $body['first_name'] ?? '', 'last_name' => $body['last_name'] ?? '', 'email' => $body['email'] ?? '',
            'phone' => $body['phone'] ?? '', 'company' => $body['company'] ?? '', 'address' => $body['address'] ?? '',
            'tags' => isset($body['tags']) ? (array) $body['tags'] : [],
        ], (string) ($body['source'] ?? 'api'));
        out(['ok' => true, 'contact' => contact_out(go_get('contacts', $id))], 201);
    }
    if ($rid) { $c = go_get('contacts', $rid); if (!$c) out(['error' => 'not found'], 404); out(contact_out($c)); }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') { $st = db()->prepare("SELECT * FROM contacts WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ? ORDER BY updated_at DESC LIMIT ?"); $lk = "%$q%"; $st->execute([$lk, $lk, $lk, $lk, $limit]); }
    else { $st = db()->prepare("SELECT * FROM contacts ORDER BY updated_at DESC LIMIT ?"); $st->execute([$limit]); }
    out(['data' => array_map('contact_out', $st->fetchAll())]);
}

if ($res === 'companies') {
    if ($write) {
        $name = company_clean((string) ($body['name'] ?? ''));
        if ($name === '') out(['error' => 'name required'], 422);
        $st = db()->prepare("SELECT id FROM companies WHERE name=? COLLATE NOCASE LIMIT 1"); $st->execute([$name]);
        $existed = (bool) $st->fetchColumn();
        $id = company_upsert($name, company_input($body));
        out(['ok' => true, 'created' => !$existed, 'company' => company_out(go_get('companies', $id))], $existed ? 200 : 201);
    }
    if ($rid) {
        $c = go_get('companies', $rid); if (!$c) out(['error' => 'not found'], 404);
        $n = db()->prepare("SELECT COUNT(*) FROM contacts WHERE company_id=?"); $n->execute([$rid]);
        $o = db()->prepare("SELECT COUNT(*) FROM deals WHERE company_id=? AND status='open'"); $o->execute([$rid]);
        out(company_out($c) + ['contacts' => (int) $n->fetchColumn(), 'open_deals' => (int) $o->fetchColumn()]);
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') { $st = db()->prepare("SELECT * FROM companies WHERE name LIKE ? OR domain LIKE ? ORDER BY name COLLATE NOCASE LIMIT ?"); $lk = "%$q%"; $st->execute([$lk, $lk, $limit]); }
    else { $st = db()->prepare("SELECT * FROM companies ORDER BY name COLLATE NOCASE LIMIT ?"); $st->execute([$limit]); }
    out(['data' => array_map('company_out', $st->fetchAll())]);
}

if ($res === 'deals') {
    if ($write) {
        $existing = $rid ? go_get('deals', $rid) : null;
        if ($rid && !$existing) out(['error' => 'not found'], 404);
        // Pipeline: fixed for an existing deal; for a new one the given id, else the first pipeline (created if there is none yet).
        if ($existing) $pipe = go_get('pipelines', (int) $existing['pipeline_id']);
        else {
            $pipe = !empty($body['pipeline_id']) ? go_get('pipelines', (int) $body['pipeline_id']) : (db()->query("SELECT * FROM pipelines ORDER BY id LIMIT 1")->fetch() ?: null);
            if (!$pipe && empty($body['pipeline_id'])) {
                $pipe = go_get('pipelines', go_insert('pipelines', ['name' => 'Sales', 'stages' => json_encode(['New', 'Contacted', 'Proposal', 'Negotiation'], JSON_UNESCAPED_SLASHES), 'created_at' => time()]));
            }
        }
        if (!$pipe) out(['error' => 'unknown pipeline_id'], 422);
        $nStages = max(1, count(jdec($pipe['stages'])));
        $data = [];
        if (array_key_exists('name', $body)) { $data['name'] = mb_substr(trim((string) $body['name']), 0, 160); if ($data['name'] === '') out(['error' => 'name cannot be empty'], 422); }
        elseif (!$existing) out(['error' => 'name required'], 422);
        if (array_key_exists('value', $body)) $data['value_cents'] = max(0, (int) round((float) $body['value'] * 100));
        if (array_key_exists('stage', $body)) $data['stage'] = max(0, min($nStages - 1, (int) $body['stage']));
        if (array_key_exists('contact_id', $body)) { $cid = (int) $body['contact_id']; if ($cid && !go_get('contacts', $cid)) out(['error' => 'unknown contact_id'], 422); $data['contact_id'] = $cid; }
        if (array_key_exists('company_id', $body)) { $co = (int) $body['company_id']; if ($co && !go_get('companies', $co)) out(['error' => 'unknown company_id'], 422); $data['company_id'] = $co; }
        elseif (array_key_exists('company', $body)) $data['company_id'] = company_upsert((string) $body['company']);
        if (array_key_exists('close_date', $body)) {
            $cd = (string) ($body['close_date'] ?? '');
            if ($cd !== '' && valid_date($cd) === '') out(['error' => 'close_date must be YYYY-MM-DD'], 422);
            $data['close_date'] = $cd;
        }
        if (array_key_exists('probability', $body)) $data['probability'] = ($body['probability'] === null || $body['probability'] === '') ? -1 : max(0, min(100, (int) $body['probability']));
        if (array_key_exists('owner_id', $body)) { $ow = (int) $body['owner_id']; if ($ow && !user_by_id($ow)) out(['error' => 'unknown owner_id'], 422); $data['owner_id'] = $ow; }
        if (array_key_exists('notes', $body)) $data['notes'] = mb_substr(trim((string) $body['notes']), 0, 5000);
        if (array_key_exists('lost_reason', $body)) $data['lost_reason'] = mb_substr(trim((string) $body['lost_reason']), 0, 300);
        $oldStatus = $existing['status'] ?? 'open';
        if (array_key_exists('status', $body)) {
            $s = (string) $body['status'];
            if (!in_array($s, ['open', 'won', 'lost'], true)) out(['error' => 'status must be open, won or lost'], 422);
            $data['status'] = $s;
            if ($s !== $oldStatus || !$existing) $data['closed_at'] = $s === 'open' ? 0 : time();
            if ($s !== 'lost' && !array_key_exists('lost_reason', $body)) $data['lost_reason'] = '';
        }
        $data['updated_at'] = time();
        if ($existing) { go_update('deals', $rid, $data); $id = $rid; }
        else {
            // A new deal with a contact but no company takes the contact's company.
            if (empty($data['company_id']) && !empty($data['contact_id'])) { $c = go_get('contacts', (int) $data['contact_id']); $data['company_id'] = (int) ($c['company_id'] ?? 0); }
            $id = go_insert('deals', $data + ['pipeline_id' => (int) $pipe['id'], 'status' => 'open', 'stage' => 0, 'created_at' => time()]);
            if (!empty($data['contact_id'])) act_log((int) $data['contact_id'], 'deal_created', ['name' => $data['name']]);
        }
        $d = go_get('deals', $id);
        if ((int) $d['contact_id'] && $d['status'] !== 'open' && $d['status'] !== $oldStatus) act_log((int) $d['contact_id'], 'deal_' . $d['status'], ['name' => $d['name']]);
        out(['ok' => true, 'deal' => deal_out($d)], $existing ? 200 : 201);
    }
    if ($rid) { $d = go_get('deals', $rid); if (!$d) out(['error' => 'not found'], 404); out(deal_out($d)); }
    $status = (string) ($_GET['status'] ?? 'open');
    if (!in_array($status, ['open', 'won', 'lost', 'all'], true)) out(['error' => 'status must be open, won, lost or all'], 422);
    if ($status === 'all') { $st = db()->prepare("SELECT * FROM deals ORDER BY updated_at DESC LIMIT ?"); $st->execute([$limit === 100 && !isset($_GET['limit']) ? 500 : $limit]); }
    else { $st = db()->prepare("SELECT * FROM deals WHERE status=? ORDER BY updated_at DESC LIMIT ?"); $st->execute([$status, $limit === 100 && !isset($_GET['limit']) ? 500 : $limit]); }
    out(['data' => array_map('deal_out', $st->fetchAll())]);
}

if ($res === 'pipelines') {
    $rows = db()->query("SELECT * FROM pipelines ORDER BY id")->fetchAll();
    out(['data' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'stages' => jdec($p['stages']), 'stage_probabilities' => stage_probs($p)], $rows)]);
}

out(['error' => 'unknown endpoint', 'see' => 'me, contacts, companies, deals, pipelines'], 404);

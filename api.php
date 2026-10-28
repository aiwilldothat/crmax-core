<?php
/**
 * CRMax Core - minimal REST API (JSON). Authenticate with the token from Settings:
 *   Authorization: Bearer <token>
 * Routes (prefix with this file, e.g. /api.php/contacts):
 *   GET  /me                      org + counts
 *   GET  /contacts?q=&limit=      list contacts
 *   GET  /contacts/{id}           one contact
 *   POST /contacts                create/update (de-duped by email); JSON or form body
 *   GET  /deals                   list open deals
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
$body = [];
if ($method === 'POST') { $raw = file_get_contents('php://input'); $body = $raw && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? (json_decode($raw, true) ?: []) : $_POST; }

function contact_out(array $c): array {
    return ['id' => (int) $c['id'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'email' => $c['email'],
        'phone' => $c['phone'], 'company' => $c['company'], 'address' => $c['address'], 'tags' => jdec($c['tags']),
        'source' => $c['source'], 'unsubscribed' => (bool) $c['dnd_email'], 'created_at' => (int) $c['created_at']];
}

if ($res === 'me') {
    out(['org' => org_name(), 'contacts' => (int) db()->query("SELECT COUNT(*) FROM contacts")->fetchColumn(),
        'open_deals' => (int) db()->query("SELECT COUNT(*) FROM deals WHERE status='open'")->fetchColumn()]);
}
if ($res === 'contacts') {
    if ($method === 'POST') {
        if (($body['email'] ?? '') === '' && ($body['phone'] ?? '') === '') out(['error' => 'email or phone required'], 422);
        $id = contact_upsert([
            'first_name' => $body['first_name'] ?? '', 'last_name' => $body['last_name'] ?? '', 'email' => $body['email'] ?? '',
            'phone' => $body['phone'] ?? '', 'company' => $body['company'] ?? '', 'address' => $body['address'] ?? '',
            'tags' => isset($body['tags']) ? (array) $body['tags'] : [],
        ], (string) ($body['source'] ?? 'api'));
        out(['ok' => true, 'contact' => contact_out(go_get('contacts', $id))], 201);
    }
    if ($rid) { $c = go_get('contacts', $rid); if (!$c) out(['error' => 'not found'], 404); out(contact_out($c)); }
    $q = trim((string) ($_GET['q'] ?? '')); $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
    if ($q !== '') { $st = db()->prepare("SELECT * FROM contacts WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ? ORDER BY updated_at DESC LIMIT ?"); $lk = "%$q%"; $st->execute([$lk, $lk, $lk, $lk, $limit]); }
    else { $st = db()->prepare("SELECT * FROM contacts ORDER BY updated_at DESC LIMIT ?"); $st->execute([$limit]); }
    out(['data' => array_map('contact_out', $st->fetchAll())]);
}
if ($res === 'deals') {
    $rows = db()->query("SELECT * FROM deals WHERE status='open' ORDER BY updated_at DESC LIMIT 500")->fetchAll();
    out(['data' => array_map(fn($d) => ['id' => (int) $d['id'], 'name' => $d['name'], 'value' => (int) $d['value_cents'] / 100, 'stage' => (int) $d['stage'], 'contact_id' => (int) $d['contact_id']], $rows)]);
}
out(['error' => 'unknown endpoint', 'see' => 'me, contacts, deals'], 404);

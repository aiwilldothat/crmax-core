<?php
/** CRMax Core - one-click unsubscribe (public, signed token). */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$cid = unsub_verify((string) ($_GET['c'] ?? ''));
$ok = false;
if ($cid && ($c = go_get('contacts', $cid))) { go_update('contacts', $cid, ['dnd_email' => 1, 'updated_at' => time()]); act_log($cid, 'unsubscribed', []); $ok = true; }
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'");
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Unsubscribe</title>
<style>body{font-family:'Segoe UI',system-ui,sans-serif;background:#f4f8f6;color:#16241f;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
.c{background:#fff;border:1px solid #e2ebe6;border-radius:16px;padding:30px;max-width:420px;text-align:center;box-shadow:0 8px 30px rgba(20,40,33,.07)}</style></head>
<body><div class="c"><?php if ($ok): ?><h1 style="font-size:1.3rem">You're unsubscribed</h1><p style="color:#5f7269;margin-top:8px">You will no longer receive emails from <?= h(org_name()) ?>.</p>
<?php else: ?><h1 style="font-size:1.3rem">Link not recognised</h1><p style="color:#5f7269;margin-top:8px">This unsubscribe link is invalid or has expired.</p><?php endif; ?></div></body></html>

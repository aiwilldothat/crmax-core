<?php
/** CRMax Core - campaign open-tracking pixel. Returns a 1x1 transparent GIF. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$cid = (int) ($_GET['c'] ?? 0); $rid = (int) ($_GET['r'] ?? 0);
if ($cid && $rid) {
    $r = go_get('campaign_recipients', $rid);
    if ($r && (int) $r['campaign_id'] === $cid && (int) $r['opened'] === 0) {
        go_update('campaign_recipients', $rid, ['opened' => 1]);
        db()->prepare("UPDATE campaigns SET open_count=open_count+1 WHERE id=?")->execute([$cid]);
    }
}
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

<?php
/**
 * CRMax Core - background runner. Processes due workflow steps and sends queued campaign email.
 *
 * Run it every minute. Two ways:
 *   CLI (recommended):   * * * * *  php /path/to/crmax/cron.php
 *   HTTP:                set a secret in Settings-less config, then GET cron.php?key=YOUR_KEY
 *
 * In the dev server workflows/campaigns also run inline, so cron is only needed in production.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    // HTTP calls must present the cron key (set 'cron_key' in config.php).
    global $CFG;
    $key = (string) ($CFG['cron_key'] ?? '');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    header('Content-Type: text/plain');
}

$wf = wf_run_due(200);
$camp = camp_run_due(100);
echo date('c') . " workflows_processed=$wf campaign_emails_sent=$camp\n";

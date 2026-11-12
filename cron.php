<?php
/**
 * CRMax Core - background runner. Processes due workflow steps and sends queued campaign email.
 *
 * Run it every minute. Two ways:
 *   CLI (recommended):   * * * * *  php /path/to/crmax/cron.php
 *                        and set 'base_url' in config.php to this CRM's web address (e.g. https://crm.example.com).
 *                        The command line has no web request to learn the address from, and without it the
 *                        unsubscribe and open-tracking links in the email sent from here point at localhost.
 *   HTTP:                set 'cron_key' in config.php, then GET https://your-crm/cron.php?key=YOUR_KEY.
 *                        Call it at the CRM's public address: the links in the email are built from it.
 *
 * On the built-in PHP dev server campaigns also send straight away; workflow steps always wait for this runner.
 * Each run is recorded, and Settings > Background jobs shows the last one (with a warning if its links are broken).
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

cron_record($cli ? 'cli' : 'web');
if ($cli && ($why = cron_links_problem()) !== '') {
    fwrite(STDERR, "WARNING: $why Set 'base_url' in config.php to this CRM's web address, e.g. 'base_url' => 'https://crm.example.com',\n");
}

$wf = wf_run_due(200);
$camp = camp_run_due(100);
audit_prune();   // once a day: audit log lines older than 12 months go
echo date('c') . " workflows_processed=$wf campaign_emails_sent=$camp\n";

<?php
/**
 * CRMax Core - optional configuration.
 *
 * You do NOT need this file to run. The app installs itself with sensible defaults
 * (SQLite database in ./data). Copy it to config.php only if you want to override something.
 *
 *   cp config.sample.php config.php
 */
return [
    // 'app_name'  => 'My CRM',                 // shown in the browser title + sidebar
    // 'data_dir'  => __DIR__ . '/data',        // where the SQLite db + sessions live (keep it non-public)
    // 'db_file'   => __DIR__ . '/data/crm.sqlite',
    // 'timezone'  => 'Australia/Sydney',       // fixes the timezone; leave it out to choose one in Settings > Organization

    // How amounts show. Default "$1,234.00 USD". 'currency' is the code after the amount ('' shows none);
    // 'currency_symbol' is the sign before it.
    // 'currency'        => 'AUD',
    // 'currency_symbol' => '$',

    // false = while there are no accounts yet, visitors see "Not set up yet" instead of the set-up form
    // (where the first person to sign up becomes the admin). To set up, remove the line or set it to true,
    // create your admin account, then put it back.
    // 'allow_install' => false,

    // This CRM's web address, as people open it (no trailing slash needed). Set it when cron.php runs from
    // the command line, the recommended way: there is no web request there to learn the address from, and
    // without it the unsubscribe and open-tracking links in campaign and workflow email point at localhost
    // and do not work. Used on the command line only; web pages always use the address they were opened at.
    // 'base_url'  => 'https://crm.example.com',   // or e.g. https://example.com/crm for a subfolder

    // Needed only if you run the background runner over HTTP instead of the CLI.
    // Then schedule:  GET https://your-crm/cron.php?key=THISVALUE  every minute.
    // 'cron_key'  => 'change-me-to-a-long-random-string',
];

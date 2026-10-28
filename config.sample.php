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
    // 'timezone'  => 'Australia/Sydney',       // default timezone (can also be set in Settings)

    // Needed only if you run the background runner over HTTP instead of the CLI.
    // Then schedule:  GET https://your-crm/cron.php?key=THISVALUE  every minute.
    // 'cron_key'  => 'change-me-to-a-long-random-string',
];

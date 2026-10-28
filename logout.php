<?php
/** CRMax Core - sign out (CSRF-guarded via a token in the link). */
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';
if (!csrf_ok($_GET['t'] ?? null)) { redirect('index.php'); }
do_logout();
redirect('index.php?login=1');

<?php
// inkphp kernel loader: loads the kernel files in order, nothing else.
// The function files come first so that boot.php (which starts the request:
// config, output buffer, error handlers, headers) can use any helper.
declare(strict_types=1);

require __DIR__ . '/route.php';
require __DIR__ . '/http.php';
require __DIR__ . '/view.php';
require __DIR__ . '/data.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/boot.php';

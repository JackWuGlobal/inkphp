<?php
// App configuration. Committed to git, so NO secrets here: put them in .env.php and read them with env().
return [
    'env' => 'prod',            // dev | prod | test; the APP_ENV environment variable or .env.php wins
    'timezone' => 'UTC',
    'base_url' => '',           // e.g. 'https://example.com' for url(); '' = scheme + Host of the request
    'trust_proxy' => false,     // true only behind a proxy you control: is_https() then trusts X-Forwarded-Proto
    'locales' => ['zh', 'en'],  // first = default
    'session_lifetime' => 86400, // seconds a session may stay idle
    'db' => [
        'dsn' => 'sqlite:' . INK_ROOT . '/storage/db.sqlite',
        // 'dsn' => 'pgsql:host=127.0.0.1;dbname=app',
        // 'dsn' => 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
        'user' => env('DB_USER'),
        'pass' => env('DB_PASS'),
    ],
];

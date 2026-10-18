<?php
// The only PHP file in the docroot: front controller for nginx/Apache/宝塔, and router script for
// `php -S 127.0.0.1:8000 -t public public/index.php`. Pages live in ../pages (not reachable directly).
if (PHP_SAPI === 'cli-server') {
    // php -S: let the built-in server send real files, except PHP sources and hidden paths (.well-known is ok).
    $path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
    if (!str_contains($path, "\0") && !preg_match('~/\.(?!well-known/)|\.(php\d?|phtml|phar)$~i', $path)
        && is_file(__DIR__ . $path)) {
        return false;
    }
}
require __DIR__ . '/../ink/ink.php';
require ink_route(); // the page runs here, in global scope
ink_finish();

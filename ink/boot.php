<?php
// Boot: constants, config/env, output buffer, error/exception/shutdown handlers, default headers, logging.
// See docs/SPEC.md §3, §9, §10. Loaded last by ink.php, so every helper already exists.
declare(strict_types=1);

define('INK_VERSION', '1.0.0-dev');
define('INK_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
define('INK_START', hrtime(true));
const INK_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

/** Thrown when a backing service (the database) is down: answered with 503. */
class InkUnavailable extends RuntimeException {}

/** Per-request kernel state. Use `$s = &ink_state();` to change it. */
function &ink_state(): array
{
    static $s = ['config' => [], 'env' => [], 'page' => null, 'params' => [], 'params_called' => false,
        'allow_called' => false, 'layout' => null, 'response' => null, 'finished' => false, 'upload_error' => null,
        'locale_varies' => false, 'locale_url' => null, 'route_path' => null];
    return $s;
}

function ink_set(string $key, mixed $value): void
{
    $s = &ink_state();
    $s[$key] = $value;
}

/** 'dev' | 'prod' | 'test' ('prod' until boot has read the config). */
function ink_env(): string { return defined('APP_ENV') ? APP_ENV : 'prod'; }

/** config('db.dsn') — dot notation into config.php. */
function config(string $key, mixed $default = null): mixed
{
    $v = ink_state()['config'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

/** Secrets: .env.php first, then the process environment (getenv). */
function env(string $key, mixed $default = null): mixed
{
    $env = ink_state()['env'];
    if (array_key_exists($key, $env)) return $env[$key];
    $v = getenv($key);
    return $v === false ? $default : $v;
}

// ---- logging -------------------------------------------------------------------------------------

function log_info(string $msg, array $ctx = []): void { ink_log('info', $msg, $ctx); }

function log_error(string $msg, array $ctx = []): void { ink_log('error', $msg, $ctx); }

/** One JSON line per event in storage/logs/YYYY-MM-DD.log (UTC). */
function ink_log(string $lvl, string $msg, array $ctx = []): void
{
    $req = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    $line = json_encode(['t' => gmdate('Y-m-d\TH:i:s\Z'), 'lvl' => $lvl, 'msg' => $msg, 'rid' => ink_rid(),
        'req' => substr($req, 0, 2000)] + $ctx, INK_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $dir = INK_ROOT . '/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents($dir . '/' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        error_log((string) $line);
    }
}

/** Request id: the CF-Ray header when config('trust_proxy') is true (anyone can send one), else 8 random hex chars. */
function ink_rid(): string
{
    static $rid = null;
    $ray = config('trust_proxy') === true ? (string) ($_SERVER['HTTP_CF_RAY'] ?? '') : '';
    return $rid ??= preg_match('/^[A-Za-z0-9-]{1,64}$/D', $ray) ? $ray : bin2hex(random_bytes(4));
}

// ---- errors --------------------------------------------------------------------------------------

/** Warnings/notices: dev/test throw, prod logs and continues. `@` is respected. Deprecations are only logged. */
function ink_on_error(int $no, string $msg, string $file = '', int $line = 0): bool
{
    if (!(error_reporting() & $no)) return false;
    $deprecated = (bool) ($no & (E_DEPRECATED | E_USER_DEPRECATED));
    if (!$deprecated && ink_env() !== 'prod') throw new ErrorException($msg, 0, $no, $file, $line);
    ink_log('warn', $msg, ['at' => "$file:$line"]);
    return true;
}

function ink_on_shutdown(): void
{
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        ink_fail(new ErrorException($e['message'], 0, $e['type'], $e['file'], $e['line']));
    }
    try {
        ink_finish(); // reached here after exit (json/redirect/abort or the page's own exit)
    } catch (Throwable $t) {
        ink_fail($t);
    }
}

/** Uncaught exception or fatal error: log it, throw away all output, answer 500 (503 when a service is unavailable). */
function ink_fail(Throwable $e): never
{
    static $failing = false;
    $code = ink_unavailable($e) ? 503 : 500;
    if (!$failing) log_error($e->getMessage(), ['ex' => $e::class, 'at' => ink_where($e)]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e::class . ': ' . $e->getMessage() . ' at ' . ink_where($e) . "\n"
            . (ink_env() === 'dev' ? $e->getTraceAsString() . "\n" : ''));
        exit(1);
    }
    if ($failing) { // the error page itself failed: answer without any template
        while (ob_get_level() > 0 && @ob_end_clean()) {}
        if (!headers_sent()) http_response_code($code);
        echo $code === 503 ? '503 Service Unavailable' : '500 Internal Server Error';
        exit(1);
    }
    $failing = true;
    ink_error($code, '', [], $e);
}

/**
 * 503 (try again later) only for InkUnavailable and transient database errors: lost connection (SQLSTATE 08xxx),
 * deadlock / serialization failure, lock timeouts, a busy or locked SQLite file. SQL bugs and constraint
 * violations are 500 like any other bug.
 */
function ink_unavailable(Throwable $e): bool
{
    if ($e instanceof InkUnavailable) return true;
    if (!$e instanceof PDOException) return false;
    $state = (string) ($e->errorInfo[0] ?? $e->getCode());
    $code = (int) ($e->errorInfo[1] ?? 0);
    $driver = strstr((string) config('db.dsn', ''), ':', true);
    return str_starts_with($state, '08') || in_array($state, ['40001', '40P01', '55P03', '57P01', '57P03'], true)
        || ($driver === 'mysql' && in_array($code, [1205, 1213, 2002, 2006, 2013], true))
        || ($driver === 'sqlite' && in_array($code, [5, 6], true));
}

/** 'file:line' of an exception; when it was thrown inside ink/, the calling page line comes first. */
function ink_where(Throwable $e): string
{
    $at = $e->getFile() . ':' . $e->getLine();
    if (!str_starts_with($e->getFile(), __DIR__ . '/')) return $at;
    foreach ($e->getTrace() as $f) {
        if (isset($f['file']) && !str_starts_with($f['file'], __DIR__ . '/')) return $f['file'] . ':' . ($f['line'] ?? 0) . " (in $at)";
    }
    return $at;
}

/** Every error response goes through here: abort(), allow(), rate_limit(), ink_finish(), ink_fail(). */
function ink_error(int $code, string $message = '', array $headers = [], ?Throwable $e = null): never
{
    ink_set('response', 'error');
    $message = $message !== '' ? $message : ink_status_text($code);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "$code $message\n");
        exit(1);
    }
    ink_reset_output();
    http_response_code($code);
    if ($code === 503) $headers += ['Retry-After' => '30'];
    foreach ($headers as $name => $value) header("$name: $value");
    $debug = $e !== null && ink_env() === 'dev'
        ? $e::class . ': ' . $e->getMessage() . "\n" . ink_where($e) . "\n\n" . $e->getTraceAsString()
        : null;
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $body = ['error' => $code, 'message' => $message] + ($e ? ['rid' => ink_rid()] : []) + ($debug ? ['debug' => $debug] : []);
        echo json_encode($body, INK_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo ink_error_html($code, $message, $debug);
    }
    exit;
}

/** layouts/error.php ($code, $message, $rid) if present, else a built-in page; dev details are added here. */
function ink_error_html(int $code, string $message, ?string $debug): string
{
    $html = null;
    $layout = INK_ROOT . '/layouts/error.php';
    if (is_file($layout)) {
        try {
            $html = ink_render($layout, ['code' => $code, 'message' => $message, 'rid' => ink_rid()]);
        } catch (Throwable $t) {
            ink_log('error', 'layouts/error.php failed: ' . $t->getMessage(), ['at' => $t->getFile() . ':' . $t->getLine()]);
        }
    }
    $html ??= '<!doctype html><html><head><meta charset="utf-8"><title>' . $code . '</title></head><body><h1>'
        . $code . '</h1><p>' . e($message) . '</p></body></html>';
    if ($debug !== null) {
        $pre = '<pre style="white-space:pre-wrap;margin:1rem;padding:1rem;background:#fff0f0;color:#600;font:13px/1.4 monospace">'
            . e($debug) . '</pre>';
        $at = strripos($html, '</body>');
        $html = $at === false ? $html . $pre : substr_replace($html, $pre, $at, 0);
    }
    return $html;
}

function ink_status_text(int $code): string
{
    return [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 413 => 'Payload Too Large', 422 => 'Unprocessable Content',
        429 => 'Too Many Requests', 500 => 'Internal Server Error', 503 => 'Service Unavailable'][$code] ?? 'Error';
}

/** Throw away all buffered output and start a fresh buffer (headers are kept). */
function ink_discard(): void
{
    while (ob_get_level() > 0 && @ob_end_clean()) {}
    ob_start();
}

/** For error responses: discard output and every pending header except Set-Cookie, then re-add the defaults. */
function ink_reset_output(): void
{
    ink_discard();
    if (headers_sent()) return;
    $cookies = array_filter(headers_list(), fn (string $h): bool => stripos($h, 'Set-Cookie:') === 0);
    header_remove();
    foreach ($cookies as $h) header($h, false);
    ink_default_headers();
}

/** SPEC §10: always sent. */
function ink_default_headers(): void
{
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: private, no-cache');
}

// ---- start ---------------------------------------------------------------------------------------

(static function (): void {
    error_reporting(E_ALL);
    set_error_handler('ink_on_error');
    set_exception_handler('ink_fail');
    register_shutdown_function('ink_on_shutdown');
    if (PHP_SAPI !== 'cli') {
        ini_set('display_errors', '0'); // errors are rendered by ink_fail(), never by PHP
        ob_start();
        ink_default_headers();
    }
    $s = &ink_state();
    $s['env'] = is_file(INK_ROOT . '/.env.php') ? require INK_ROOT . '/.env.php' : [];
    if (!is_array($s['env'])) throw new UnexpectedValueException('.env.php must return an array');
    $s['config'] = is_file(INK_ROOT . '/config.php') ? require INK_ROOT . '/config.php' : [];
    if (!is_array($s['config'])) throw new UnexpectedValueException('config.php must return an array');
    $env = env('APP_ENV', config('env', 'prod'));
    define('APP_ENV', in_array($env, ['dev', 'prod', 'test'], true) ? $env : 'prod');
    date_default_timezone_set((string) config('timezone', 'UTC'));
})();

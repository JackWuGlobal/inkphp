<?php
// HTTP helpers: request info, responses (json/redirect/abort), host/url, caching, uploads, assets. See docs/SPEC.md §6.
declare(strict_types=1);

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

function is_htmx(): bool { return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true'; }

/** Accept mentions application/json, or the path is /api or under /api/. */
function wants_json(): bool
{
    // the routed path: /en/api/notes counts as /api/notes in prefix mode
    $path = (string) (ink_state()['route_path'] ?? explode('?', (string) ($_SERVER['REQUEST_URI'] ?? ''), 2)[0]);
    return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || preg_match('~^/api(/|$)~', $path) === 1;
}

/** POST form fields, or the decoded JSON body (Content-Type application/json). Values are raw, not trimmed. */
function input(?string $key = null, mixed $default = null): mixed
{
    static $data = null;
    if ($data === null) {
        $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            $raw = (string) file_get_contents('php://input');
            try {
                $data = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                abort(400, 'Invalid JSON body');
            }
            if (!is_array($data)) abort(400, 'JSON body must be an object or an array');
        } else {
            $data = $_POST;
        }
    }
    return $key === null ? $data : ($data[$key] ?? $default);
}

/** Send JSON and stop. Page output so far is discarded; no layout. */
function json(mixed $data, int $code = 200): never
{
    $body = json_encode($data, INK_JSON | JSON_THROW_ON_ERROR | (ink_env() === 'dev' ? JSON_PRETTY_PRINT : 0));
    ink_discard();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    ink_set('response', 'json');
    echo $body;
    exit;
}

/** Redirect to a LOCAL path and stop. Anything else is logged and sent to '/'. $code: 301 302 303 307 308. */
function redirect(string $to, int $code = 302): never
{
    if (!in_array($code, [301, 302, 303, 307, 308], true)) throw new LogicException("redirect(): $code is not a redirect status");
    if (!preg_match('~^/(?![/\\\\])~', $to) || preg_match('/[\x00-\x1f\x7f]/', $to)) {
        ink_log('warn', 'redirect() refused a non-local target', ['to' => $to]);
        $to = '/';
    }
    ink_redirect($to, $code);
}

function ink_redirect(string $to, int $code): never
{
    ink_discard();
    header('Location: ' . $to, true, $code);
    ink_set('response', 'redirect');
    exit;
}

/** Stop with an error page (or JSON when wants_json()). */
function abort(int $code, string $message = ''): never { ink_error($code, $message); }

/** Lowercased Host header without port or trailing dot; anything that is not a hostname → 400. */
function host(): string
{
    static $host = null;
    if ($host !== null) return $host;
    $h = rtrim(strtolower((string) preg_replace('/:\d*$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))), '.');
    $name = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    if (!preg_match("/^(?=.{1,253}$)(?:$name\\.)*$name$/D", $h) && !preg_match('/^\[[0-9a-f:.]+\]$/D', $h)) {
        abort(400, 'Invalid Host header');
    }
    return $host = $h;
}

function is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if (($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (config('trust_proxy') !== true) return false;
    $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https' || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"');
}

/** Absolute URL for a local path: config('base_url') if set, else scheme://host[:port]. */
function url(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');
    $base = rtrim((string) config('base_url', ''), '/');
    if ($base !== '') return $base . $path;
    $https = is_https();
    $port = preg_match('/:(\d+)$/D', (string) ($_SERVER['HTTP_HOST'] ?? ''), $m) ? (int) $m[1] : null;
    $port = $port === null || $port === ($https ? 443 : 80) ? '' : ':' . $port;
    return ($https ? 'https' : 'http') . '://' . host() . $port . $path;
}

/** REMOTE_ADDR only. The web server sets the real client IP (e.g. nginx real_ip for Cloudflare). */
function client_ip(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? ''); }

/**
 * client_ip() for rate_limit() keys: IPv4 as is, IPv6 cut to its /64 (one host usually owns a whole /64, so it
 * could rotate through endless addresses). An IPv4-mapped IPv6 address gives the IPv4 address.
 */
function ip_key(): string
{
    $ip = client_ip();
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) return $ip;
    if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) return (string) inet_ntop(substr($bin, 12));
    return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** Let browsers (≤60s) and CDNs ($seconds) cache this response. Downgraded at finish (see ink_finish()). */
function cache_public(int $seconds): void
{
    $seconds = max(0, $seconds);
    header('Cache-Control: public, max-age=' . min(60, $seconds) . ', s-maxage=' . $seconds);
}

/**
 * Save an uploaded file to public/uploads/<random>.<ext> and return its URL path, or null (see upload_error()).
 * The extension must be in $types AND match the real content type (finfo). Types outside the map below are a bug.
 */
function store_upload(string $field, array $types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], int $maxBytes = 5_000_000): ?string
{
    static $mimes = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'],
        'webp' => ['image/webp'], 'avif' => ['image/avif'], 'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'], 'mp3' => ['audio/mpeg'], 'mp4' => ['video/mp4'],
        'zip' => ['application/zip']];
    $types = array_map('strtolower', $types);
    foreach ($types as $t) {
        if (!isset($mimes[$t])) throw new LogicException("store_upload(): type '$t' is not supported");
    }
    $fail = function (string $zh, string $en): ?string {
        ink_set('upload_error', ink_msg($zh, $en));
        return null;
    };
    ink_set('upload_error', null);
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || ($f['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
        return $fail('请选择文件', 'No file was uploaded');
    }
    $tooBig = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $f['size'] > $maxBytes;
    if ($tooBig) return $fail('文件太大', 'The file is too large');
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return $fail('上传失败', 'Upload failed');
    if ((int) filesize($f['tmp_name']) > $maxBytes) return $fail('文件太大', 'The file is too large');
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($ext, $types, true) || !in_array($mime, $mimes[$ext], true)) {
        return $fail('不支持的文件类型', 'This file type is not allowed');
    }
    $dir = INK_ROOT . '/public/uploads';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) return $fail('上传失败', 'Upload failed');
    @chmod("$dir/$name", 0644);
    return '/uploads/' . $name;
}

/** Why the last store_upload() returned null (a message in the current locale), or null. */
function upload_error(): ?string { return ink_state()['upload_error']; }

/** '/assets/<path>?v=<mtime>' for cache busting. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = INK_ROOT . '/public/assets/' . $path;
    if (is_file($file)) return '/assets/' . $path . '?v=' . filemtime($file);
    if (ink_env() === 'dev') ink_log('warn', "asset(): public/assets/$path does not exist");
    return '/assets/' . $path;
}

<?php
// Sessions (lazy), flash, CSRF, login, rate limiting. See docs/SPEC.md §8.
declare(strict_types=1);

/**
 * Start the session if needed. Nothing else in the kernel starts one. A session idle for longer than
 * config('session_lifetime') is emptied and gets a new id (checked here on every start; GC only deletes old files).
 */
function session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = INK_ROOT . '/storage/sessions';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $https = is_https();
    $life = max(1, (int) config('session_lifetime', 86400));
    session_start([
        'name' => $https ? '__Host-ink' : 'ink',
        'save_path' => $dir,
        'cookie_lifetime' => 0, 'cookie_path' => '/', 'cookie_domain' => '',
        'cookie_secure' => $https, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax',
        'use_strict_mode' => true, 'use_only_cookies' => true, 'use_trans_sid' => false, 'cache_limiter' => '',
        'gc_maxlifetime' => $life, 'gc_probability' => 1, 'gc_divisor' => 100,
    ]);
    $now = time();
    $seen = $_SESSION['_seen'] ?? null;
    if (is_int($seen) && $now - $seen > $life) {
        $_SESSION = [];
        session_regenerate_id(true);
        $seen = null;
    }
    if (!is_int($seen) || $now - $seen > min(60, intdiv($life, 2))) $_SESSION['_seen'] = $now; // not every request: lazy write
}

/**
 * Is there a session to read? Active, or the browser sent our cookie with a well-formed id whose file exists.
 * Reading (user(), flash(), csrf_check(), logout()) never creates a session for a missing, bogus or stale cookie.
 */
function ink_has_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    $id = $_COOKIE[is_https() ? '__Host-ink' : 'ink'] ?? null;
    return is_string($id) && preg_match('/^[A-Za-z0-9,-]{22,256}$/D', $id) === 1
        && is_file(INK_ROOT . '/storage/sessions/sess_' . $id);
}

/** flash('ok', '已保存') stores a message; flash('ok') returns it once (or null). */
function flash(string $key, ?string $msg = null): ?string
{
    if ($msg !== null) {
        session();
        $_SESSION['_flash'][$key] = $msg;
        return null;
    }
    if (!ink_has_session()) return null;
    session();
    $v = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return is_string($v) ? $v : null;
}

function csrf_token(): string
{
    session();
    if (!is_string($_SESSION['_csrf'] ?? null)) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }

/**
 * csrf_exempt('why'): a declaration for readers and `bin/ink check`, does nothing at runtime. Use it only on a page
 * that accepts POST without a session-bound form — e.g. navigator.sendBeacon (cannot send headers) or an endpoint
 * authorised by its own one-time token — and say how it is protected instead.
 */
function csrf_exempt(string $reason): void
{
    if (trim($reason) === '') throw new LogicException('csrf_exempt(): give the reason');
}

/** Token from POST field _csrf or header X-CSRF-Token (never the query string), else 403. */
function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($sent) || $sent === '' || !ink_has_session()) abort(403, 'CSRF token missing or invalid');
    session();
    $token = $_SESSION['_csrf'] ?? null;
    if (!is_string($token) || !hash_equals($token, $sent)) abort(403, 'CSRF token missing or invalid');
}

/** Log in: new session id (prevents fixation), new CSRF token. */
function login(int|string $id): void
{
    session();
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['_uid'] = $id;
}

/** Log out: clear the session data and switch to a new id (flash() still works afterwards). */
function logout(): void
{
    if (!ink_has_session()) return;
    session();
    $_SESSION = [];
    session_regenerate_id(true);
}

/** The logged-in user id, or null. Never starts a session for a visitor without the cookie. */
function user(): int|string|null
{
    if (!ink_has_session()) return null;
    session();
    $id = $_SESSION['_uid'] ?? null;
    return is_int($id) || is_string($id) ? $id : null;
}

/**
 * Return the user id, or stop: 401 JSON for wants_json(), else redirect to $loginPath?next=<this path>.
 * require_login(hidden: true): a visitor who is not logged in gets the same 404 as an unknown URL (JSON or HTML),
 * no redirect and no next= — for a back office that must not reveal that it exists.
 */
function require_login(string $loginPath = '/login', bool $hidden = false): int|string
{
    $id = user();
    if ($id !== null) return $id;
    if ($hidden) abort(404);
    if (wants_json()) abort(401);
    $next = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    redirect($loginPath . (str_contains($loginPath, '?') ? '&' : '?') . 'next=' . rawurlencode($next));
}

/** Fixed window: at most $max calls per $windowSeconds for $key, else 429 with Retry-After. */
function rate_limit(string $key, int $max, int $windowSeconds): void
{
    if ($max < 0 || $windowSeconds < 1) throw new LogicException('rate_limit(): bad limits');
    $dir = INK_ROOT . '/storage/ratelimit';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    if (random_int(1, 100) === 1) { // 1%: delete expired counters (each file's mtime is the end of its window)
        foreach (glob("$dir/*") ?: [] as $f) if (@filemtime($f) < time() - 5) @unlink($f);
    }
    $h = fopen($file = $dir . '/' . sha1($key), 'c+');
    if ($h === false) throw new RuntimeException('rate_limit(): cannot open the counter file');
    $now = time();
    try {
        flock($h, LOCK_EX);
        $parts = explode(' ', trim((string) stream_get_contents($h)));
        $start = (int) ($parts[0] ?? 0);
        $count = (int) ($parts[1] ?? 0);
        if ($now >= $start + $windowSeconds) [$start, $count] = [$now, 0];
        $count++;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, "$start $count");
        fflush($h);
        @touch($file, $start + $windowSeconds);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    if ($count > $max) ink_error(429, '', ['Retry-After' => (string) max(1, $start + $windowSeconds - $now)]);
}

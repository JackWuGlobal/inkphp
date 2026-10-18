<?php
// Routing: canonical redirect, resolver, params(), allow(), page runner, finish. See docs/SPEC.md §3-§4.
declare(strict_types=1);

/**
 * Canonical form of a raw (still percent-encoded) URL path: %XX of unreserved characters decoded and other %XX
 * upper-cased (RFC 3986 §6.2.2), no empty or '.' segments, no trailing '/', no '.php' on the last segment, no leading
 * 'index' segment. Returns null for a '..' segment (→ 400). Applied until stable, so the redirect is a single hop.
 */
function ink_canonical(string $path): ?string
{
    if ($path === '' || $path[0] !== '/') return null;
    $path = preg_replace_callback('/%[0-9a-fA-F]{2}/', function (array $m): string {
        $c = rawurldecode($m[0]);
        return preg_match('/^[A-Za-z0-9._~-]$/D', $c) ? $c : strtoupper($m[0]);
    }, $path);
    do {
        $before = $path;
        $segs = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') return null;
            if ($seg !== '' && $seg !== '.') $segs[] = $seg;
        }
        $last = count($segs) - 1;
        if ($last >= 0 && str_ends_with($segs[$last], '.php')) $segs[$last] = substr($segs[$last], 0, -4);
        if (($segs[0] ?? null) === 'index') array_shift($segs);
        $path = '/' . implode('/', $segs);
    } while ($path !== $before);
    return $path;
}

/** Can this (decoded) segment be part of a page file path? */
function ink_name(string $seg): bool
{
    return $seg === '.well-known' || ($seg !== 'index' && preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $seg) === 1);
}

/**
 * Resolve a canonical path to [absolute page file, params] under $pages, or null (→ 404).
 * Right-to-left, deepest file wins; only descends into directories that exist.
 */
function ink_resolve(string $pages, string $path): ?array
{
    $segs = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));
    if (count($segs) > 12) return null;
    foreach ($segs as $i => $seg) {
        $seg = rawurldecode($seg); // decoded exactly once
        if ($seg === '.' || $seg === '..' || preg_match('/[\/\\\\\x00-\x1f\x7f]/', $seg) || !preg_match('//u', $seg)) return null;
        $segs[$i] = $seg;
    }
    $n = count($segs);
    $max = 0; // deepest file candidate worth a stat
    for ($i = 0, $dir = $pages; $i < $n && ink_name($segs[$i]); $i++) {
        $max = $i + 1;
        if ($max === $n || !is_dir($dir .= '/' . $segs[$i])) break;
    }
    for ($i = $max; $i >= 1; $i--) {
        $file = $pages . '/' . implode('/', array_slice($segs, 0, $i)) . '.php';
        if (is_file($file)) return [$file, array_slice($segs, $i)];
    }
    return is_file($pages . '/index.php') ? [$pages . '/index.php', $segs] : null;
}

/**
 * The Location of a canonical redirect: a relative path + the raw query; '\', spaces, controls and non-ASCII are
 * percent-encoded so it can never be read as '//host' or '/\host'. Also used by `bin/ink test`.
 */
function ink_location(string $canon, string $query): string
{
    return preg_replace_callback('/[^\x21-\x7e]|\\\\/', fn (array $m): string => rawurlencode($m[0]), $canon . $query);
}

/**
 * Language from the URL (config locale_url). Returns [locale|null, path to resolve, redirect|null] where redirect is
 * [Location, code]. Pure (no $_GET/$_SERVER), so `bin/ink test` uses it too.
 * prefix: '/en/about' → ['en', '/about']. The default language has no prefix ('/zh/about' → 301 '/about') unless
 *   locale_default_prefixed, in which case a bare '/about' → 302 '/<Accept-Language best>/about'.
 * query: '/about?lang=en' → ['en', '/about']; ?lang=<default> or an unknown value → 301 without it.
 * off: [null, path, null].
 */
function ink_locale_route(string $canon, string $query, string $acceptLanguage = ''): array
{
    $mode = ink_locale_mode();
    if ($mode === false) return [null, $canon, null];
    $all = array_values((array) config('locales', ['zh', 'en']));
    $def = (string) ($all[0] ?? 'en');
    if ($mode === 'prefix') {
        $prefixed = (bool) config('locale_default_prefixed', false);
        $seg = explode('/', $canon, 3)[1] ?? '';
        if ($seg !== '' && in_array($seg, $all, true)) {
            $rest = substr($canon, strlen($seg) + 1);
            $rest = $rest === '' ? '/' : $rest;
            if (ink_locale_exempt($rest)) return [ink_exempt_locale(), $rest, [ink_location($rest, $query), 301]];
            if ($seg === $def && !$prefixed) return [$def, $rest, [ink_location($rest, $query), 301]];
            return [$seg, $rest, null];
        }
        if (ink_locale_exempt($canon)) return [ink_exempt_locale(), $canon, null];
        if (!$prefixed) return [$def, $canon, null];
        $best = ink_accept_language($all, $acceptLanguage) ?? $def;
        return [$best, $canon, [ink_location('/' . $best . ($canon === '/' ? '' : $canon), $query), 302]];
    }
    $pairs = $query === '' ? [] : explode('&', substr($query, 1));
    $lang = null;
    $other = [];
    foreach ($pairs as $pair) {
        [$k, $v] = explode('=', $pair, 2) + [1 => ''];
        if (urldecode($k) === 'lang') $lang ??= urldecode($v);
        else $other[] = $pair;
    }
    if (ink_locale_exempt($canon)) {
        return [ink_exempt_locale(), $canon, $lang === null ? null : [ink_location($canon, $other ? '?' . implode('&', $other) : ''), 301]];
    }
    if ($lang === null) return [$def, $canon, null];
    if ($lang !== $def && in_array($lang, $all, true)) return [$lang, $canon, null];
    return [$def, $canon, [ink_location($canon, $other ? '?' . implode('&', $other) : ''), 301]];
}

/** Canonical redirect, language from the URL, then resolve; returns the page file for public/index.php to require. */
function ink_route(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $q = strpos($uri, '?');
    $path = $q === false ? $uri : substr($uri, 0, $q);
    $canon = ink_canonical($path);
    if ($canon === null) abort(400);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($canon !== $path) {
        ink_redirect(ink_location($canon, $q === false ? '' : substr($uri, $q)), $method === 'GET' || $method === 'HEAD' ? 301 : 308);
    }
    [$locale, $path, $redirect] = ink_locale_route($canon, $q === false ? '' : substr($uri, $q), (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($redirect !== null) {
        $safe = $method === 'GET' || $method === 'HEAD';
        if ($redirect[1] === 302) header('Vary: Accept-Language'); // depends on the visitor: never cached for everyone
        ink_redirect($redirect[0], $redirect[1] === 302 ? ($safe ? 302 : 307) : ($safe ? 301 : 308));
    }
    [$file, $params] = ink_resolve(INK_ROOT . '/pages', $path) ?? abort(404);
    $s = &ink_state();
    $s['page'] = $file;
    $s['params'] = $params;
    $s['locale_url'] = $locale;
    $s['route_path'] = $path;
    return $file;
}

/** [$a, $b] = params(1, 2): accept min..max path params (strings), padded with null to $max. Else 404. */
function params(int $min = 0, ?int $max = null): array
{
    $max ??= $min;
    if ($min < 0 || $max < $min || $max > 12) throw new LogicException("params($min, $max): need 0 <= min <= max <= 12");
    $s = &ink_state();
    $s['params_called'] = true;
    $n = count($s['params']);
    if ($n < $min || $n > $max) abort(404);
    return array_pad($s['params'], $max, null);
}

/**
 * $id = as_id($raw): a path param that must be a record id. null → null (optional param absent); a canonical positive
 * integer ('42') → int; anything else ('01', '0', '-1', '1e3', 'abc', 19+ digits) → 404. One record, one URL.
 */
function as_id(?string $v): ?int
{
    if ($v === null) return null;
    if (!preg_match('/^[1-9][0-9]{0,17}$/D', $v)) abort(404);
    return (int) $v;
}

/**
 * allow('GET', 'POST'): any other method → 405 with an Allow header. GET implies HEAD.
 * May be called again later to narrow one URL shape: if ($id !== null) allow('GET');
 */
function allow(string ...$methods): void
{
    ink_set('allow_called', true);
    $methods = array_map('strtoupper', $methods);
    if (in_array('GET', $methods, true)) $methods[] = 'HEAD';
    $methods = array_values(array_unique($methods));
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        ink_error(405, '', ['Allow' => implode(', ', $methods)]);
    }
}

/** After the page: enforce params()/allow() defaults, apply the layout, downgrade cache_public() if needed. */
function ink_finish(): void
{
    $s = &ink_state();
    if ($s['finished'] || PHP_SAPI === 'cli') return;
    $s['finished'] = true;
    if ($s['page'] !== null && $s['response'] !== 'error') {
        if ($s['params'] && !$s['params_called']) ink_error(404);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!$s['allow_called'] && $method !== 'GET' && $method !== 'HEAD') ink_error(405, '', ['Allow' => 'GET, HEAD']);
        if ($s['layout'] !== null && $s['response'] === null) {
            [$name, $vars] = $s['layout'];
            $content = '';
            while (ob_get_level() > 0 && ($chunk = ob_get_clean()) !== false) $content = $chunk . $content;
            ob_start();
            echo ink_render(INK_ROOT . "/layouts/$name.php", ['content' => $content] + $vars);
        }
    }
    if (!headers_sent() && preg_grep('/^Cache-Control:\s*public/i', headers_list())) {
        $why = session_status() === PHP_SESSION_ACTIVE || preg_grep('/^Set-Cookie:/i', headers_list()) ? 'a session or a cookie'
            : ($s['locale_varies'] ? 'its language came from the lang cookie, Accept-Language or the default' : null);
        if ($why !== null) {
            header('Cache-Control: private, no-cache');
            ink_log('warn', "cache_public() downgraded to private: $why");
        }
    }
}

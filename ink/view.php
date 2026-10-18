<?php
// Views: escaping, layout, inline translations, locale. See docs/SPEC.md §5.
// There is deliberately no partial(): HTML a page needs lives in that page (see AGENTS.md "页面内片段").
declare(strict_types=1);

/** Escape for HTML text and attributes. null, arrays and non-Stringable objects become ''. */
function e(mixed $v): string
{
    if ($v instanceof Stringable) $v = (string) $v;
    elseif (!is_scalar($v)) return '';
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Wrap this page's output in layouts/<name>.php (as $content) when the page ends. layout(null) cancels. */
function layout(?string $name, array $vars = []): void
{
    if ($name !== null && (!preg_match('~^[a-z0-9_/-]+$~D', $name) || !is_file(INK_ROOT . "/layouts/$name.php"))) {
        throw new LogicException("layout(): layouts/$name.php does not exist");
    }
    ink_set('layout', $name === null ? null : [$name, $vars]);
}

/** Include a template with only $__vars in scope; return its output. */
function ink_render(string $__file, array $__vars): string
{
    extract($__vars, EXTR_SKIP);
    ob_start();
    try {
        require $__file;
    } catch (Throwable $__e) {
        ob_end_clean();
        throw $__e;
    }
    return (string) ob_get_clean();
}

/**
 * t(['zh' => '提交', 'en' => 'Submit']) → the current locale's text (fallback: first configured locale, then the
 * first value). A plain string is returned as is. {name} placeholders come from $vars and are NOT escaped.
 */
function t(array|string $s, array $vars = []): string
{
    if (is_array($s)) {
        $first = ((array) config('locales', ['zh', 'en']))[0] ?? '';
        $s = $s[locale()] ?? $s[$first] ?? ($s ? reset($s) : '');
    }
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = is_scalar($v) || $v instanceof Stringable ? (string) $v : '';
    return strtr((string) $s, $map);
}

/**
 * Current locale, one of config('locales'). Order: locale($set) > ?lang= > cookie 'lang' > Accept-Language > first.
 * locale($set) and ?lang= also store the 'lang' cookie (1 year). A $set that is not a configured locale string
 * (unknown value, array, …) is ignored. A language from the cookie, Accept-Language or the default varies by
 * visitor, so cache_public() is downgraded at finish.
 */
function locale(mixed $set = null): string
{
    static $current = null;
    $all = array_values((array) config('locales', ['zh', 'en']));
    if (is_string($set) && in_array($set, $all, true)) {
        ink_lang_cookie($set);
        return $current = $set;
    }
    if ($current !== null) return $current;
    $query = $_GET['lang'] ?? null;
    if (is_string($query) && in_array($query, $all, true)) {
        ink_lang_cookie($query);
        return $current = $query;
    }
    ink_set('locale_varies', true);
    $cookie = $_COOKIE['lang'] ?? null;
    if (is_string($cookie) && in_array($cookie, $all, true)) return $current = $cookie;
    return $current = ink_accept_language($all) ?? (string) ($all[0] ?? 'en');
}

function ink_lang_cookie(string $lang): void
{
    if (PHP_SAPI === 'cli' || ($_COOKIE['lang'] ?? null) === $lang) return;
    setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['lang'] = $lang;
}

/** Best configured locale for the Accept-Language header (exact tag first, then the primary subtag). */
function ink_accept_language(array $all): ?string
{
    $best = null;
    $bestQ = 0.0;
    foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $bits = explode(';', strtolower(trim($part)));
        $tag = trim($bits[0]);
        $q = isset($bits[1]) && preg_match('/^\s*q=([0-9.]+)/', $bits[1], $m) ? (float) $m[1] : 1.0;
        if ($tag === '' || $q <= $bestQ) continue;
        $match = null;
        foreach ($all as $l) if (strtolower($l) === $tag) $match ??= $l;
        foreach ($all as $l) if (explode('-', strtolower($l))[0] === explode('-', $tag)[0]) $match ??= $l;
        if ($match !== null) [$best, $bestQ] = [$match, $q];
    }
    return $best;
}

/** Pick the zh or en kernel message for the current locale. */
function ink_msg(string $zh, string $en): string { return str_starts_with(locale(), 'zh') ? $zh : $en; }

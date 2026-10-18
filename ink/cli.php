<?php
// bin/ink: serve, routes, check, test, migrate. See docs/SPEC.md §11.
// routes/check/test load only ink/route.php (the pure routing functions) and read pages with the tokenizer:
// they never run config.php or a page. migrate loads the whole kernel for config() and db().
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

const INK_CLI_DECL = ['params', 'allow', 'require_login', 'csrf_check', 'layout'];
define('INK_CLI_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));

function ink_cli(array $argv): int
{
    $args = $opts = [];
    foreach (array_slice($argv, 2) as $a) {
        if ($a === '-v') $opts['v'] = true;
        elseif (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
        else $args[] = $a;
    }
    $cmd = $argv[1] ?? 'help';
    if (in_array($cmd, ['routes', 'check', 'test'], true)) {
        require __DIR__ . '/ink.php'; // the whole kernel (config, locales, href…); in the CLI it sends nothing and buffers nothing
        set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) return false;
            throw new ErrorException($msg, 0, $no, $file, $line);
        });
    }
    return match ($cmd) {
        'serve' => ink_cli_serve((string) ($args[0] ?? '8000')),
        'routes' => ink_cli_routes(ink_cli_dir((string) ($opts['pages'] ?? 'pages'))),
        'check' => ink_cli_check(),
        'test' => ink_cli_test($args, $opts),
        'migrate' => ink_cli_migrate(),
        default => ink_cli_help($cmd),
    };
}

function ink_cli_help(string $cmd): int
{
    $ok = in_array($cmd, ['help', '-h', '--help'], true);
    if (!$ok) fwrite(STDERR, "unknown command: $cmd\n");
    echo "usage: bin/ink <command>\n"
        . "  serve [PORT]               dev server on http://127.0.0.1:PORT (default 8000), APP_ENV=dev\n"
        . "  routes [--pages=DIR]       URL table, read from each page's declarations\n"
        . "  check                      php -l + project rules; exit 1 on errors\n"
        . "  test [FILE...] [--http=BASE_URL] [--pages=DIR] [-v]\n"
        . "                             run route tables (default tests/*.txt) in-process, or over HTTP\n"
        . "  migrate                    apply new migrations/*.sql, one transaction each (run as the web user)\n";
    return $ok ? 0 : 1;
}

function ink_cli_serve(string $port): int
{
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        fwrite(STDERR, "serve: bad port '$port'\n");
        return 1;
    }
    $env = ['APP_ENV' => 'dev'] + getenv();
    $cmd = ['-S', "127.0.0.1:$port", '-t', INK_CLI_ROOT . '/public', INK_CLI_ROOT . '/public/index.php'];
    fwrite(STDERR, "http://127.0.0.1:$port  (APP_ENV=dev unless .env.php sets APP_ENV; Ctrl-C stops)\n");
    if (function_exists('pcntl_exec')) pcntl_exec(PHP_BINARY, $cmd, $env); // become the server: no orphan child
    $p = proc_open([PHP_BINARY, ...$cmd], [STDIN, STDOUT, STDERR], $pipes, INK_CLI_ROOT, $env);
    return is_resource($p) ? proc_close($p) : 1;
}

// ---- reading pages (tokenizer only, a page is never run) ------------------------------------------

/**
 * A page's declarations: 'decl' = its leading statements that are calls to params/allow/require_login/csrf_check/
 * layout, in order (args: literals, false = not a literal); 'uses' = every function it calls explicitly and the
 * superglobals it touches; 'names' = literal layout() names.
 */
function ink_cli_page(string $file): array
{
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    $t = [];
    $line = 1;
    foreach (token_get_all((string) file_get_contents($file)) as $tok) {
        $tok = is_array($tok) ? $tok : [0, $tok, $line];
        $line = $tok[2] + substr_count($tok[1], "\n");
        if (!in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $t[] = [$tok[0], $tok[1], $tok[2]];
    }
    $uses = $names = $decl = [];
    $allows = 0;
    $texts = $links = $redirects = []; // t([...]) keys, hard-coded local links in HTML, redirect('/literal') — by line
    foreach ($t as $i => [$type, $text]) {
        $prev = strtolower($t[$i - 1][1] ?? '');
        if (in_array($type, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ($t[$i + 1][1] ?? '') === '('
            && !in_array($prev, ['->', '?->', '::', 'function', 'new', 'const'], true)) {
            $fn = strtolower(ltrim($text, '\\'));
            $uses[$fn] = true;
            if ($fn === 'allow') $allows++;
            $arg = ($t[$i + 2][0] ?? 0) === T_CONSTANT_ENCAPSED_STRING ? ink_cli_literal([$t[$i + 2]]) : null;
            if ($fn === 'layout' && is_string($arg)) $names[] = [$fn, $arg];
            if ($fn === 'redirect' && is_string($arg) && str_starts_with($arg, '/')) $redirects[] = $t[$i][2];
            if ($fn === 't' && ($t[$i + 2][1] ?? '') === '[') { // the literal keys of t(['zh' => …, 'en' => …])
                $keys = [];
                for ($j = $i + 3, $depth = 0; isset($t[$j]); $j++) {
                    $x = $t[$j][1];
                    if ($x === '[' || $x === '(') $depth++;
                    elseif ($x === ']' || $x === ')') { if ($depth-- === 0) break; }
                    elseif ($depth === 0 && $t[$j][0] === T_CONSTANT_ENCAPSED_STRING && ($t[$j + 1][1] ?? '') === '=>') $keys[] = substr($x, 1, -1);
                }
                $texts[] = [$t[$i][2], $keys];
            }
        } elseif ($type === T_VARIABLE && in_array($text, ['$_POST', '$_FILES', '$_REQUEST', '$_GET', '$_SERVER'], true)) {
            $uses[$text] = true;
            $key = ($t[$i + 1][1] ?? '') === '[' ? ($t[$i + 2][1] ?? '') : '';
            if ($text === '$_GET' && preg_match('/^[\'"]key\d+[\'"]$/', $key)) $uses['keyN'] = true;
            if ($text === '$_SERVER' && preg_match('/^[\'"]REQUEST_METHOD[\'"]$/', $key)) $uses["\$_SERVER['REQUEST_METHOD']"] = true;
        } elseif ($type === T_CONSTANT_ENCAPSED_STRING && strtolower(substr($text, 1, -1)) === 'php://input') {
            $uses['php://input'] = true; // reading the request body by hand
        } elseif ($type === T_INLINE_HTML && preg_match_all('~\b(?:href|action|hx-(?:get|post|put|patch|delete))="/(?![/\\\\])~', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $off]) $links[] = $t[$i][2] + substr_count(substr($text, 0, $off), "\n");
        }
    }
    $stmt = [];
    $depth = 0;
    foreach ($t as [$type, $text]) { // leading statements, until the first one that is not a declaration
        if ($type === T_OPEN_TAG) continue;
        if ($type === T_INLINE_HTML) break;
        if ($depth === 0 && ($text === ';' || $type === T_CLOSE_TAG)) {
            if ($stmt === []) continue;
            $d = ink_cli_decl($stmt);
            if ($d === null) break;
            if ($d !== true) $decl[] = $d;
            $stmt = [];
            continue;
        }
        if (in_array($text, ['(', '[', '{', '${', '#['], true)) $depth++;
        elseif (in_array($text, [')', ']', '}'], true)) $depth--;
        $stmt[] = [$type, $text];
    }
    return $cache[$file] = ['decl' => $decl, 'uses' => $uses, 'names' => $names, 'allows' => $allows,
        'texts' => $texts, 'links' => $links, 'redirects' => $redirects];
}

/** One statement → ['fn', 'args', 'vars' (destructured names)] if it is a declaration call; true for declare/use. */
function ink_cli_decl(array $s): array|bool|null
{
    if (in_array($s[0][0], [T_DECLARE, T_USE], true)) return true;
    $vars = [];
    $eq = array_search('=', array_column($s, 1), true);
    if ($eq !== false) { // [$id, $tab] = params(1, 2);  $uid = require_login();
        $pos = 0;
        foreach (array_slice($s, 0, $eq) as [$type, $text]) {
            if ($text === ',') $pos++;
            elseif ($type === T_VARIABLE) $vars[$pos] = substr($text, 1);
            elseif (!in_array($text, ['[', ']', '(', ')'], true) && $type !== T_LIST) return null;
        }
        if ($eq === 1) $vars = [];
        $s = array_slice($s, $eq + 1);
    }
    $fn = strtolower(ltrim($s[0][1] ?? '', '\\'));
    if (!in_array($s[0][0] ?? 0, [T_STRING, T_NAME_FULLY_QUALIFIED], true) || !in_array($fn, INK_CLI_DECL, true)
        || ($s[1][1] ?? '') !== '(' || end($s)[1] !== ')') return null;
    $args = $cur = [];
    $depth = 0;
    foreach (array_slice($s, 2, -1) as $tok) {
        if ($depth === 0 && $tok[1] === ',') {
            $args[] = ink_cli_literal($cur);
            $cur = [];
            continue;
        }
        if (in_array($tok[1], ['(', '[', '{', '${', '#['], true)) $depth++;
        elseif (in_array($tok[1], [')', ']', '}'], true) && --$depth < 0) return null; // the call ended early
        $cur[] = $tok;
    }
    if ($cur !== []) $args[] = ink_cli_literal($cur);
    return ['fn' => $fn, 'args' => $args, 'vars' => $vars];
}

/** A literal int/string/null argument, or false when it is anything else. */
function ink_cli_literal(array $t): mixed
{
    if (count($t) !== 1) return false;
    [$type, $text] = $t[0];
    if ($type === T_LNUMBER && ctype_digit($text)) return (int) $text;
    if ($type === T_STRING && strtolower($text) === 'null') return null;
    if ($type !== T_CONSTANT_ENCAPSED_STRING) return false;
    $body = substr($text, 1, -1);
    return $text[0] === "'" ? strtr($body, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($body);
}

/** The first leading declaration of each kind. */
function ink_cli_lead(array $page): array
{
    $lead = [];
    foreach ($page['decl'] as $d) $lead[$d['fn']] ??= $d;
    return $lead;
}

/** [min, max] of a literal params() call, else null. */
function ink_cli_params(array $d): ?array
{
    [$min, $max] = $d['args'] + [0, null];
    $max ??= $min;
    return count($d['args']) <= 2 && is_int($min) && is_int($max) ? [$min, $max] : null;
}

/** Upper-case methods of a literal allow() call (+HEAD when GET is there), else null. */
function ink_cli_methods(array $d): ?array
{
    foreach ($d['args'] as $m) if (!is_string($m)) return null;
    $m = array_map('strtoupper', $d['args']);
    return array_values(array_unique(in_array('GET', $m, true) ? [...$m, 'HEAD'] : $m));
}

// ---- files ----------------------------------------------------------------------------------------

/** Every file below $dir as a sorted relative path; $skip lists relative directories not to enter. */
function ink_cli_files(string $dir, array $skip = [], string $rel = ''): array
{
    $out = [];
    $here = $rel === '' ? $dir : "$dir/$rel";
    if (!is_dir($here)) return [];
    foreach (scandir($here) ?: [] as $name) {
        $path = $rel === '' ? $name : "$rel/$name";
        if ($name === '.' || $name === '..' || in_array($path, $skip, true)) continue;
        if (is_dir("$dir/$path")) array_push($out, ...ink_cli_files($dir, $skip, $path));
        else $out[] = $path;
    }
    return $out;
}

/** true when pages/<$rel> is reachable by a URL; '' when it is private by design (_name, .name); else why not. */
function ink_cli_routable(string $rel): bool|string
{
    if ($rel === 'index.php') return true;
    $segs = explode('/', $rel);
    $last = array_pop($segs);
    if (!str_ends_with($last, '.php')) return $last[0] === '.' ? '' : 'not a .php file (only pages belong in pages/)';
    foreach ([...$segs, substr($last, 0, -4)] as $seg) {
        if ($seg !== '.well-known' && ($seg[0] === '_' || $seg[0] === '.')) return '';
        if ($seg === 'index') return 'never routable: only pages/index.php is an index (/x is served by pages/x.php)';
        if (!ink_name($seg)) return "never routable: '$seg' is not a route name (lowercase a-z 0-9 . _ -)";
    }
    return str_ends_with(substr($last, 0, -4), '.php') ? 'never routable: the canonical redirect strips .php from the URL' : true;
}

function ink_cli_dir(string $dir): string
{
    $abs = realpath(str_starts_with($dir, '/') ? $dir : INK_CLI_ROOT . "/$dir") ?: realpath($dir);
    if ($abs === false || !is_dir($abs)) {
        fwrite(STDERR, "no such directory: $dir\n");
        exit(1);
    }
    return $abs;
}

function ink_cli_rel(string $path): string
{
    return str_starts_with($path, INK_CLI_ROOT . '/') ? substr($path, strlen(INK_CLI_ROOT) + 1) : $path;
}

// ---- routes -----------------------------------------------------------------------------------------

function ink_cli_routes(string $pages): int
{
    $rows = [];
    $hidden = 0;
    foreach (ink_cli_files($pages) as $rel) {
        if (ink_cli_routable($rel) !== true) {
            $hidden++;
            continue;
        }
        $p = ink_cli_page("$pages/$rel");
        $lead = ink_cli_lead($p);
        $range = isset($lead['params']) ? ink_cli_params($lead['params']) : (isset($p['uses']['params']) ? null : [0, 0]);
        $url = $static = $rel === 'index.php' ? '' : '/' . substr($rel, 0, -4);
        if ($range === null) $url .= '/...';
        for ($k = 0; $k < ($range[1] ?? 0); $k++) {
            $url .= '/{' . ($lead['params']['vars'][$k] ?? $k + 1) . ($k < $range[0] ? '}' : '?}');
        }
        $methods = isset($lead['allow']) ? ink_cli_methods($lead['allow']) : ['GET', 'HEAD'];
        $notes = array_keys(array_filter(['login' => isset($p['uses']['require_login']), 'csrf' => isset($p['uses']['csrf_check'])]));
        foreach ($p['names'] as [$kind, $name]) if ($kind === 'layout') $notes[] = "layout:$name";
        if (!isset($lead['params'])) $notes[] = isset($p['uses']['params']) ? 'params()-not-first' : 'no-params()';
        if (ink_locale_mode() !== false && ink_locale_exempt($static === '' ? '/' : $static)) $notes[] = 'no-lang';
        $rows[$static . "\0" . $rel] = [$url === '' ? '/' : $url, ink_cli_rel("$pages/$rel"),
            $range === null ? '?' : ($range[0] === $range[1] ? "$range[0]" : "$range[0]-$range[1]"),
            $methods === null ? '?' : implode(' ', array_diff($methods, ['HEAD']) ?: ['HEAD']), implode(' ', array_unique($notes))];
    }
    ksort($rows, SORT_STRING);
    $rows = [['URL', 'FILE', 'PARAMS', 'METHODS', 'NOTES'], ...array_values($rows)];
    $w = [];
    foreach ($rows as $r) foreach ($r as $k => $c) $w[$k] = max($w[$k] ?? 0, strlen($c));
    foreach ($rows as $r) {
        echo rtrim(implode('  ', array_map(fn (string $c, int $k): string => str_pad($c, $w[$k]), $r, array_keys($r)))), "\n";
    }
    if ($hidden) echo "($hidden file(s) in " . ink_cli_rel($pages) . " are not routable: _private, .hidden or see bin/ink check)\n";
    $all = array_values((array) config('locales', ['zh', 'en']));
    $def = (string) ($all[0] ?? 'en');
    $prefixed = (bool) config('locale_default_prefixed', false);
    $others = array_values(array_filter($all, fn (string $l): bool => $l !== $def || $prefixed));
    if (ink_locale_mode() === 'prefix') {
        echo 'languages: every URL above also exists as /' . implode('/…, /', $others) . '/…'
            . ($prefixed ? " (bare URLs redirect by Accept-Language)\n" : " ($def = no prefix)\n");
    } elseif (ink_locale_mode() === 'query') {
        echo 'languages: ?lang=' . implode(', ?lang=', array_diff($all, [$def])) . " ($def = no ?lang)\n";
    }
    return 0;
}

// ---- check ------------------------------------------------------------------------------------------

function ink_cli_check(): int
{
    $root = INK_CLI_ROOT;
    $issues = [];
    $linted = 0;
    foreach (ink_cli_files($root, ['.git', 'storage', 'node_modules', 'vendor']) as $rel) {
        if (!str_ends_with($rel, '.php') && $rel !== 'bin/ink') continue;
        $linted++;
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $code); // -n: no ini, fast
        $msg = preg_grep('/error:/i', $out) ?: $out;
        if ($code !== 0) $issues[] = ['error', $rel, str_replace(" in $root/$rel", '', trim((string) reset($msg)))];
    }
    $locales = array_values((array) config('locales', ['zh', 'en']));
    if (ink_locale_mode() === 'prefix') {
        foreach ($locales as $l) {
            if (is_file("$root/pages/$l.php") || is_dir("$root/pages/$l")) {
                $issues[] = ['error', "pages/$l", "'/$l' is a language prefix (config locale_url = prefix): this page can never be reached; rename it"];
            }
        }
    }
    foreach ((array) config('locale_exempt', []) as $x) {
        if (!is_string($x) || !preg_match('~^/[a-z0-9._/-]*$~D', $x) || $x === '/') {
            $issues[] = ['error', 'config.php', "locale_exempt entry '" . (is_string($x) ? $x : gettype($x)) . "' must be a path prefix like '/admin' (not '/')"];
        }
    }
    if (is_dir("$root/partials")) {
        $issues[] = ['error', 'partials/', 'shared fragments were removed in 1.0: move the HTML into each page that uses it (in-page closure, see AGENTS.md 页面内片段), then delete partials/'];
    }
    foreach (['pages', 'layouts'] as $dir) {
        foreach (ink_cli_files("$root/$dir") as $rel) {
            $file = "$dir/$rel";
            $why = $dir === 'pages' ? ink_cli_routable($rel) : str_ends_with($rel, '.php');
            if (is_string($why) && $why !== '') $issues[] = [str_starts_with($why, 'not a') ? 'warn' : 'error', $file, $why];
            if (!str_ends_with($rel, '.php')) continue;
            $p = ink_cli_page("$root/$file");
            if (isset($p['uses']['keyN'])) $issues[] = ['error', $file, "\$_GET['keyN'] is 0.x: path params come from params()"];
            if (isset($p['uses']['partial'])) $issues[] = ['error', $file, 'partial() was removed in 1.0: put the fragment in this page (in-page closure, see AGENTS.md 页面内片段)'];
            foreach ($p['texts'] as [$at, $keys]) {
                $missing = $keys ? array_diff($locales, $keys) : [];
                if ($missing) $issues[] = ['warn', "$file:$at", "t([...]) has no '" . implode("', '", $missing) . "' text"];
            }
            if (ink_locale_mode() !== false) { // links and redirects that would drop the visitor's language
                foreach ($p['links'] as $at) $issues[] = ['warn', "$file:$at", 'hard-coded local link: write href="<?= e(href(\'/…\')) ?>" to keep the language'];
                foreach ($p['redirects'] as $at) $issues[] = ['warn', "$file:$at", "redirect('/…') drops the language: redirect(href('/…'))"];
            }
            foreach ($p['names'] as [$kind, $name]) {
                if (!preg_match('~^[a-z0-9_/-]+$~D', $name) || !is_file("$root/{$kind}s/$name.php")) {
                    $issues[] = ['error', $file, "$kind('$name'): {$kind}s/$name.php does not exist"];
                }
            }
            if ($why === true && $dir === 'pages') array_push($issues, ...ink_cli_check_page($file, $p));
        }
    }
    foreach (ink_cli_files("$root/public") as $rel) {
        if ($rel !== 'index.php' && preg_match('/\.(php\d?|phtml|phar)$/i', $rel)) {
            $issues[] = ['error', "public/$rel", 'only public/index.php may be PHP in the docroot'];
        }
    }
    foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/ratelimit', 'public/uploads'] as $d) {
        if (!is_writable(is_dir("$root/$d") ? "$root/$d" : dirname("$root/$d"))) {
            $issues[] = $d === 'public/uploads' ? ['warn', $d, 'not writable: store_upload() will fail'] : ['error', $d, 'not writable'];
        }
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $issues[] = ['warn', 'bin/ink', 'running as root: run it as the web user (sudo -u www-data bin/ink ...) so storage/ stays writable for PHP'];
    }
    if (ink_cli_git_tracks("$root/.git/index", '.env.php')) {
        $issues[] = ['error', '.env.php', 'is committed: git rm --cached .env.php, then rotate its secrets'];
    }
    foreach ($issues as [$level, $file, $msg]) echo str_pad($level, 6) . "$file: $msg\n";
    $errors = count(array_filter($issues, fn (array $i): bool => $i[0] === 'error'));
    echo "check: $linted PHP files, $errors error(s), " . (count($issues) - $errors) . " warning(s)\n";
    return $errors ? 1 : 0;
}

/**
 * Is $path in the git index file? Read directly (index versions 2-4) instead of running git: no repository config
 * (core.fsmonitor, hooks …) is ever executed, and it works whoever owns the repository. false when there is no index.
 */
function ink_cli_git_tracks(string $index, string $path): bool
{
    $ix = is_file($index) ? (string) file_get_contents($index) : '';
    if (strlen($ix) < 12 || !str_starts_with($ix, 'DIRC')) return false;
    ['v' => $v, 'n' => $n] = unpack('Nv/Nn', $ix, 4);
    $at = 12;
    $name = '';
    for ($i = 0; $i < $n && $at + 62 < strlen($ix); $i++) {
        $start = $at;
        $at += 62 + ($v >= 3 && (unpack('n', $ix, $at + 60)[1] & 0x4000) ? 2 : 0);
        if ($v >= 4) { // the path is stored as: how many bytes to drop from the previous path (varint), new suffix
            $b = ord($ix[$at++]);
            for ($drop = $b & 0x7f; $b & 0x80; $drop = (($drop + 1) << 7) | ($b & 0x7f)) $b = ord($ix[$at++]);
            $name = substr($name, 0, max(0, strlen($name) - $drop));
        }
        $end = strpos($ix, "\0", $at);
        if ($end === false) return false;
        $name = ($v >= 4 ? $name : '') . substr($ix, $at, $end - $at);
        if ($name === $path) return true;
        $at = $v >= 4 ? $end + 1 : $start + (($end - $start + 8) & ~7); // v2/v3: NUL-padded to a multiple of 8
    }
    return false;
}

/** The rules for one routable page (SPEC §4.3, §4.4, §8). */
function ink_cli_check_page(string $file, array $p): array
{
    $issues = [];
    $lead = ink_cli_lead($p);
    $admin = $file === 'pages/admin.php' || str_starts_with($file, 'pages/admin/');
    if (($p['decl'][0]['fn'] ?? null) !== 'params') {
        $issues[] = ['error', $file, 'the first statement must be params(...); or [$a, $b] = params(...);'];
    } elseif (($range = ink_cli_params($lead['params'])) === null) {
        $issues[] = ['warn', $file, 'params() arguments are not literal ints'];
    } elseif ($range[1] > 12 || $range[1] < $range[0]) {
        $issues[] = ['error', $file, 'params(min, max) needs min <= max <= 12 (a URL has at most 12 segments)'];
    }
    $posts = array_keys(array_intersect_key($p['uses'], array_flip(['input', 'is_post', 'store_upload', '$_POST', '$_FILES',
        '$_REQUEST', "\$_SERVER['REQUEST_METHOD']", 'php://input'])));
    if (isset($p['uses']['allow']) && ($p['decl'][1]['fn'] ?? null) !== 'allow') {
        $issues[] = ['error', $file, 'allow() must be the second statement, right after params() (before require_login() and csrf_check())'];
    } elseif (!isset($lead['allow']) && $posts) {
        $issues[] = ['error', $file, 'uses ' . implode('/', $posts) . ' but never calls allow(): it only answers GET/HEAD'];
    }
    $methods = isset($lead['allow']) ? ink_cli_methods($lead['allow']) : ['GET', 'HEAD'];
    if ($methods === null) {
        $issues[] = ['warn', $file, 'allow() arguments are not literal strings'];
    } elseif (array_diff($methods, ['GET', 'HEAD', 'OPTIONS']) && !isset($p['uses']['csrf_check'])) {
        $issues[] = [$admin ? 'error' : 'warn', $file, 'allows ' . implode('/', array_diff($methods, ['GET', 'HEAD', 'OPTIONS'])) . ' but never calls csrf_check()'];
    }
    $writes = array_diff($methods ?? [], ['GET', 'HEAD', 'OPTIONS']);
    if ($writes && isset($range) && $range[0] < $range[1] && $p['allows'] < 2) {
        // /x and /x/<id> both reach this page: a POST meant for /x would also run for /x/5 unless the page says otherwise
        $issues[] = ['error', $file, 'accepts ' . ($range[0] ? "$range[0]..$range[1]" : "0..$range[1]") . ' path params and ' . implode('/', $writes)
            . ': say which methods the URL with the param takes, e.g. if ($id !== null) allow(\'GET\');'];
    }
    if (isset($lead['csrf_check']) && in_array('GET', $methods ?? [], true)) {
        $issues[] = ['warn', $file, 'a leading csrf_check() answers 403 to every GET: call it inside if (is_post())'];
    }
    if ($admin && !isset($lead['require_login'])) $issues[] = ['error', $file, 'admin page: require_login() must be a leading statement'];
    return $issues;
}

// ---- test -------------------------------------------------------------------------------------------

/**
 * What the kernel answers to $method $raw for the pages in $pages, without running any page: canonical redirect,
 * resolver, the page's leading declarations for an anonymous visitor without a CSRF token, then ink_finish()'s
 * params()/allow() defaults. [200, file, params] means the page itself runs.
 */
function ink_cli_expect(string $pages, string $method, string $raw): array
{
    $q = strpos($raw, '?');
    $path = $q === false ? $raw : substr($raw, 0, $q);
    $canon = ink_canonical($path);
    if ($canon === null) return [400, null];
    $safe = in_array($method, ['GET', 'HEAD'], true);
    if ($canon !== $path) { // the Location exactly as ink_route() builds it
        return [$safe ? 301 : 308, ink_location($canon, $q === false ? '' : substr($raw, $q))];
    }
    // the language step exactly as ink_route() does it (a test request sends no Accept-Language)
    [$locale, $routed, $redirect] = ink_locale_route($canon, $q === false ? '' : substr($raw, $q));
    if ($redirect !== null) return [$redirect[1] === 302 ? ($safe ? 302 : 307) : ($safe ? 301 : 308), $redirect[0]];
    [$file, $params] = ink_resolve($pages, $routed) ?? [null, []];
    if ($file === null) return [404, null];
    $p = ink_cli_page($file);
    $called = [];
    $exit = null;
    foreach ($p['decl'] as $d) {
        if ($d['fn'] === 'layout') continue; // never changes the status
        if (in_array(false, $d['args'], true)) break; // not a literal: only running the page would tell
        $called[$d['fn']] = true;
        if ($d['fn'] === 'params') {
            if (($range = ink_cli_params($d)) === null) break;
            if (count($params) < $range[0] || count($params) > $range[1]) return [404, null];
        } elseif ($d['fn'] === 'allow') {
            if (($methods = ink_cli_methods($d)) === null) break;
            if (!in_array($method, $methods, true)) return [405, null];
        } elseif ($d['fn'] === 'csrf_check') {
            return [403, null];
        } elseif ($d['fn'] === 'require_login') {
            if (preg_match('~^/api(/|$)~', $routed)) return [401, null];
            $login = (string) ($d['args'][0] ?? ($locale === null ? '/login' : href('/login', $locale)));
            $to = $login . (str_contains($login, '?') ? '&' : '?') . 'next=' . rawurlencode($raw);
            $exit = [302, preg_match('~^/(?![/\\\\])~', $to) && !preg_match('/[\x00-\x1f\x7f]/', $to) ? $to : '/'];
            break; // a redirect still gets ink_finish()'s checks, with what was called so far
        }
    }
    $called = $exit ? $called : $p['uses'];
    if ($params && !isset($called['params'])) return [404, null];
    if (!in_array($method, ['GET', 'HEAD'], true) && !isset($called['allow'])) return [405, null];
    return $exit ?? [200, 'pages/' . substr($file, strlen($pages) + 1), $params];
}

/** [status, Location] of one request to $base. Non-ASCII bytes are percent-encoded (as browsers do); no redirects followed. */
function ink_cli_http(string $base, string $method, string $path): array
{
    $path = preg_replace_callback('/[^\x21-\x7e]/', fn (array $m): string => rawurlencode($m[0]), $path);
    $u = parse_url($base);
    if (!is_array($u) || !isset($u['host']) || !in_array($u['scheme'] ?? '', ['http', 'https'], true)) return [0, "bad URL $base"];
    $tls = $u['scheme'] === 'https';
    $port = $u['port'] ?? ($tls ? 443 : 80);
    $ctx = stream_context_create(['ssl' => ['peer_name' => trim($u['host'], '[]')]]);
    $fp = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . "{$u['host']}:$port", $no, $error, 10, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) return [0, "cannot connect: $error"];
    stream_set_timeout($fp, 10);
    fwrite($fp, "$method " . rtrim($u['path'] ?? '', '/') . "$path HTTP/1.1\r\nHost: {$u['host']}" . (isset($u['port']) ? ":$port" : '')
        . "\r\nUser-Agent: inkphp-test\r\nAccept: */*\r\nConnection: close\r\n"
        . (in_array($method, ['GET', 'HEAD'], true) ? '' : "Content-Length: 0\r\n") . "\r\n");
    $status = 0;
    $location = null;
    while (($h = fgets($fp)) !== false && ($h = rtrim($h, "\r\n")) !== '') {
        if ($status === 0) $status = preg_match('~^HTTP/\S+ (\d{3})~', $h, $m) ? (int) $m[1] : -1;
        elseif (stripos($h, 'location:') === 0) $location = trim(substr($h, 9));
    }
    fclose($fp);
    return [$status, $location];
}

function ink_cli_test(array $files, array $opts): int
{
    $http = is_string($opts['http'] ?? null) ? rtrim($opts['http'], '/') : null;
    $named = $files !== [];
    $files = $named ? $files : (glob(INK_CLI_ROOT . '/tests/*.txt') ?: []);
    if ($files === []) {
        fwrite(STDERR, "test: no route tables in tests/*.txt\n");
        return 1;
    }
    $failed = $ran = 0;
    foreach ($files as $table) {
        $path = is_file($table) ? $table : INK_CLI_ROOT . "/$table";
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            fwrite(STDERR, "test: cannot read $table\n");
            return 1;
        }
        $name = ink_cli_rel((string) realpath($path));
        $at = preg_grep('/^\s*@pages\s/', $lines);
        $dir = $at ? trim(substr(trim((string) reset($at)), 6)) : null;
        if ($http !== null && $dir !== null && !$named) {
            echo "$name: skipped over HTTP (it describes the pages in $dir)\n";
            continue;
        }
        $pages = $http === null ? ink_cli_dir((string) ($opts['pages'] ?? $dir ?? 'pages')) : '';
        // "@config key=value …" overrides config.php for this table, in-process only (over --http the server decides)
        $state = &ink_state();
        $savedConfig = $state['config'];
        if ($http === null) {
            foreach (preg_grep('/^\s*@config\s/', $lines) as $c) {
                foreach (preg_split('/\s+/', trim(substr(trim($c), 7))) as $kv) {
                    [$k, $v] = explode('=', $kv, 2) + [1 => ''];
                    $state['config'][$k] = $k === 'locales' || str_contains($v, ',') ? explode(',', $v)
                        : (in_array($v, ['false', 'off', '0'], true) ? false : (in_array($v, ['true', 'on', '1'], true) ? true : $v));
                }
            }
        }
        $ran++;
        $n = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '@') continue;
            $where = "$name:" . ($i + 1);
            $ok = preg_match('~^([A-Z]+)\s+(/\S*)\s+->\s+((?:http:)?\d{3})(?:\s+([^\s\[#]\S*))?(?:\s+(\[.*?\]))?\s*(?:#.*)?$~', $line, $m);
            $want = isset($m[5]) ? json_decode($m[5], true) : null;
            if (!$ok || (isset($m[5]) && !is_array($want))) {
                echo "FAIL  $where  cannot read: $line\n";
                $n['failed']++;
                continue;
            }
            [, $method, $raw, $status] = $m;
            $target = $m[4] ?? '';
            $httpOnly = str_starts_with($status, 'http:'); // -> http:404: the page decides, e.g. a missing record
            $status = $httpOnly ? substr($status, 5) : $status;
            $got = $http === null ? ($httpOnly ? [0, null, []] : ink_cli_expect($pages, $method, $raw)) : ink_cli_http($http, $method, $raw);
            if (isset($got[2]) && ($httpOnly || ($status !== '200' && !in_array($status, ['301', '308', '400', '404', '405'], true)))) {
                $n['skipped']++; // the page runs, so its own code decides the status: only --http can tell
                if (isset($opts['v'])) echo "skip  $where  $line\n";
                continue;
            }
            $pass = (string) $got[0] === $status && ($target === '' || $got[1] === $target || ($http !== null && $status[0] !== '3'))
                && ($want === null || $http !== null || ($got[2] ?? null) === $want);
            $n[$pass ? 'passed' : 'failed']++;
            if (!$pass || isset($opts['v'])) {
                $show = trim($got[0] . ' ' . ($got[1] ?? '') . (isset($got[2]) ? ' ' . json_encode($got[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));
                echo ($pass ? 'ok    ' : 'FAIL  ') . "$where  $line" . ($pass ? '' : "\n      got: $show") . "\n";
            }
        }
        $state['config'] = $savedConfig;
        unset($state);
        $failed += $n['failed'];
        echo "$name: {$n['passed']} passed, {$n['failed']} failed" . ($n['skipped'] ? ", {$n['skipped']} skipped (the page decides: use --http)" : '')
            . ($http === null ? '  [in-process, ' . ink_cli_rel($pages) . ']' : "  [$http]") . "\n";
    }
    if ($ran === 0) fwrite(STDERR, "test: no route table to run over HTTP\n");
    return $failed || $ran === 0 ? 1 : 0;
}

// ---- migrate ----------------------------------------------------------------------------------------

function ink_cli_migrate(): int
{
    require __DIR__ . '/ink.php';
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        fwrite(STDERR, "warning: running as root: a new storage/db.sqlite or log file will belong to root; run bin/ink as the web user\n");
    }
    try {
        $db = db();
    } catch (InkUnavailable) {
        fwrite(STDERR, 'migrate: cannot connect to the database (reason: storage/logs/' . gmdate('Y-m-d') . ".log)\n");
        return 1;
    }
    $db->exec('CREATE TABLE IF NOT EXISTS ink_migrations (name VARCHAR(190) PRIMARY KEY, applied_at VARCHAR(20) NOT NULL)');
    $done = array_map('strval', $db->query('SELECT name FROM ink_migrations')->fetchAll(PDO::FETCH_COLUMN));
    $files = glob(INK_ROOT . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    $applied = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $done, true)) continue;
        $db->beginTransaction();
        try {
            $sql = (string) file_get_contents($file);
            if (trim($sql) !== '') $db->exec($sql);
            $db->prepare('INSERT INTO ink_migrations (name, applied_at) VALUES (?, ?)')->execute([$name, gmdate('Y-m-d\TH:i:s\Z')]);
            if ($db->inTransaction()) $db->commit(); // MySQL has already committed if the file had DDL
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            fwrite(STDERR, "migrate: $name failed and was rolled back: {$e->getMessage()}\n");
            return 1;
        }
        echo "applied $name\n";
        $applied++;
    }
    echo $applied ? "migrate: $applied applied\n" : 'migrate: nothing to apply (' . count($done) . " already applied)\n";
    return 0;
}

exit(ink_cli($_SERVER['argv'] ?? []));

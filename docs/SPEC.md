# inkphp 1.0 — design spec (the contract)

Status: authoritative for the 1.0 rework. Code, AGENTS.md, README.md and examples must agree with this file.
If something here turns out to be wrong while implementing, change this file in the same commit.

## 1. Goal and principles

inkphp is a PHP framework designed to be easy for AI agents (and people) to read and change.

1. **One URL = one file = one feature.** A page file contains everything the feature needs: accepted params, allowed
   methods, auth, CSRF, validation, SQL, HTML, and its POST/htmx handling.
2. **Nothing runs that the page didn't ask for.** No guard files, no middleware, no auto-included files, no source
   scanning, no magic globals. Every dependency of a page is an explicit call in that page.
   The only always-on behaviour is the kernel itself — routing, the finish checks (undeclared path params → 404,
   undeclared methods → 405, the layout, the cache_public() downgrade), error handling and security headers — and it
   is fully described in AGENTS.md.
3. **Safe by default.** The shortest way to write something is the safe way: escaped output helper, parameterized SQL
   only, CSRF helper, local-only redirects, page files outside the docroot, uploads can never execute.
4. **Small enough to fit in an AI's context.** Web kernel (ink/*.php except cli.php) ≤ ~1050 physical lines
   (≈ 850 non-blank, non-comment lines), ~45 public functions plus internal `ink_*` helpers; CLI (ink/cli.php, never
   loaded by a web request) ≤ ~600 lines. Docs quote these measured sizes, not rounder ones.
5. **Zero dependencies, zero build.** No Composer, no npm. PHP ≥ 8.2 (target 8.4). Deploy = copy files / git pull.

Non-goals: MVC, ORM/query builder (no Medoo), plugins, themes, middleware, DI container, events, template engine.

## 2. Repository layout (the repo is itself the starter app)

```
inkphp/
├── public/                 # the ONLY web docroot
│   ├── index.php           # the ONLY .php file in public/ (front controller + php -S router script)
│   ├── .htaccess           # Apache / shared hosting: send non-files to index.php
│   ├── assets/             # css/js/images; assets/htmx.min.js vendored (htmx 2.x, pinned; version, source and
│   │                       #   sha256 in assets/VENDOR.txt, repeated in AGENTS.md)
│   └── uploads/            # store_upload() target (gitignored except .gitkeep)
├── pages/                  # URL = file. Never reachable directly (outside docroot).
├── layouts/                # page frames, used only via an explicit layout('name') call
├── migrations/             # NNN_name.sql, applied in order by `bin/ink migrate`
├── ink/                    # the kernel (projects vendor a copy; INK_VERSION constant)
│   ├── ink.php             # loads the other kernel files in order; nothing else
│   ├── boot.php            # constants, config/env, ob, error/exception/shutdown handlers, headers, logging
│   ├── route.php           # canonical redirect, resolver, params(), allow(), page runner, finish
│   ├── http.php            # input/json/redirect/abort/is_post/is_htmx/wants_json/host/is_https/url/client_ip/
│   │                       #   ip_key/cache_public/store_upload/upload_error/asset
│   ├── view.php            # e/layout/t/locale
│   ├── data.php            # db/q/one/all/val/tx/validate
│   ├── auth.php            # session/flash/csrf_*/login/logout/user/require_login/rate_limit
│   └── cli.php             # bin/ink implementation
├── bin/ink                 # #!/usr/bin/env php → requires ink/cli.php
├── storage/                # logs/, sessions/, ratelimit/, db.sqlite (gitignored, auto-created)
├── tests/routes.txt        # the kernel's routing table for `bin/ink test` (§4, against tests/fixtures/pages/)
├── tests/app.txt           # the app's own route table (in-process, and --http after a deploy)
├── tests/fixtures/pages/   # fixture pages for tests/routes.txt (never served by the app)
├── deploy/                 # nginx.conf (server block), baota.conf (宝塔伪静态), README.md (deployment guide, Chinese)
├── config.php              # returns array; committed; NO secrets
├── .env.php.example        # returns array of secrets; copy to .env.php (gitignored)
├── AGENTS.md               # the complete contract for AI agents (and humans): every function, every rule, examples
├── README.md               # human quick start (Chinese), links AGENTS.md
├── CHANGELOG.md
└── docs/SPEC.md            # this file
```

## 3. Request lifecycle

```
web server: if the path is an existing file under public/ (not *.php) → serve it statically
            else → public/index.php
public/index.php:
    (php -S only) if the URI maps to an existing non-.php file under public/ and has no hidden segment → return false
    require __DIR__ . '/../ink/ink.php';
    require ink_route();      // page runs in GLOBAL scope
    ink_finish();
boot (ink.php loads route/http/view/data/auth, then boot.php LAST so every helper exists when it runs):
    define INK_VERSION, INK_ROOT (= realpath(__DIR__.'/..') of ink/), INK_START (hrtime)
    set_error_handler / set_exception_handler / register_shutdown_function (§9)
    (web only, not CLI) ob_start(); header_remove('X-Powered-By'); default headers (§10)
    load .env.php then config.php (config.php may call env()) → config()/env();
    APP_ENV = env('APP_ENV', config('env', 'prod')) ∈ {dev, prod, test} (any other value → prod)
    date_default_timezone_set(config('timezone', 'UTC'))
ink_route():
    1. canonical redirect (§4.1) → 301 (GET/HEAD) or 308 (others), Location is a RELATIVE path + original query
    2. resolve (§4.2) → [file, params]; none → abort(404)
    3. store params for params(); return the absolute file path
ink_finish():  (also reached via exit in json()/redirect()/abort() or the page's own exit, through the shutdown path, §9)
    skipped for error responses (abort()/ink_fail()) and when no page ran (canonical redirect, 404)
    if the page received ≥1 path param and never called params() → discard buffer, abort(404)
    if the page never called allow() and the method is not GET/HEAD → discard buffer, abort(405)
      (these two checks also replace a json()/redirect() response)
    if layout() was called and the response is not json()/redirect() → render it around the buffered page output
    if Cache-Control is public but a session is active, a Set-Cookie is pending, or locale() was resolved from the
      lang cookie / Accept-Language / the default → downgrade (§6 cache_public)
    flush
```

## 4. Routing

### 4.1 Canonical form (one redirect, never a chain)
Work on the raw path of REQUEST_URI (before decoding), query string kept as-is.
- percent-encoding is normalized first (RFC 3986 §6.2.2): `%XX` of an unreserved character `[A-Za-z0-9._~-]` is
  decoded (`/%61bout` → `/about`, `%2E` → `.` so `/x/%2e%2e` is a `..` segment → 400), every other `%XX` gets
  upper-case hex digits (`%e4` → `%E4`, `%2f` → `%2F`). So every page has exactly one URL.
- collapse repeated `/`, drop a trailing `/` (except the root `/`)
- drop a trailing `.php` from the LAST segment (`/about.php` → `/about`, `/robots.txt.php` → `/robots.txt`)
- a first segment `index` is removed (`/index` → `/`, `/index/5` → `/5`)
- segments `.` are removed; any `..` segment → 400
The rules are applied until the result is stable (`/x.php.php` → `/x`, `/index/index` → `/`), so there is never a
second hop. If the result differs from the raw path → redirect. The Location never contains a scheme/host and must
start with exactly one `/` (guard against `//evil.com`, `/\evil.com`): `\`, spaces, control and non-ASCII bytes in it
are percent-encoded.

### 4.2 Resolution (right-to-left, deepest wins)
- Split the canonical path into segments; decode each segment ONCE with rawurldecode.
- A decoded segment that is `.` or `..` (a guard: §4.1 already turned `%2E` into `.`), is not valid UTF-8, or contains
  `/`, `\`, NUL or other control chars → 404.
  More than 12 segments → 404.
- A segment can be part of a file path only if it is a valid route name:
  `^[a-z0-9][a-z0-9._-]*$` (lowercase ASCII) and not equal to `index`, OR it is exactly `.well-known`.
  Names starting with `_` or `.` (other than `.well-known`) are never routable at any depth.
- For i = n … 1: if segments[0..i) are all valid names and `pages/<seg0>/…/<seg i-1>.php` is a file → that file,
  params = segments[i..n) (decoded strings). Only descend into directories that exist (stat pruning).
- Otherwise `pages/index.php` with params = all segments. (`/` → index.php with no params.)
- `x.php` and directory `x/` may coexist: `/x` → x.php, `/x/y` → x/y.php if it exists, else x.php with params [y].
- `index.php` is used only at the root. `pages/x/index.php` is never routed (`index` is not a valid name): the page
  for `/x` is `pages/x.php`, next to an optional `pages/x/` directory for `/x/…` pages.
- Dynamic "twins" of static-looking URLs just work: `pages/robots.txt.php` serves `/robots.txt`
  (a real file public/robots.txt would win because the web server serves files first).
- Path params are NOT copied into $_GET. Query string stays in $_GET untouched.
- Both steps are pure functions (apart from stat calls) that `bin/ink test` reuses in-process:
  `ink_canonical(string $rawPath): ?string` (null → 400) and
  `ink_resolve(string $pagesDir, string $canonicalPath): ?array` (`[absolute file, params]`, null → 404).

### 4.3 params(int $min = 0, ?int $max = null): array
- `$max` defaults to `$min`. If the number of path params is outside [min, max] → abort(404).
- Returns the params array padded with `null` up to `$max` (so `[$id, $tab] = params(1, 2);` works).
- `'0'` is a valid param. Values are strings. Unless `0 ≤ $min ≤ $max ≤ 12` → LogicException (no URL has more than
  12 segments).
- The accepted range applies to every method the page allows: a page serving `/x` and `/x/{id}` with
  `allow('GET', 'POST')` must decide itself what a POST to `/x/{id}` means (usually `abort(404)`).
- Convention (enforced by `bin/ink check`): `params()` is the first statement of every page.
- A page that never calls params() accepts zero path params (enforced in ink_finish, buffer discarded).

### 4.4 allow(string ...$methods): void
- Called as the 2nd statement (right after params(), before require_login()/csrf_check(); `bin/ink check` enforces
  it). If the request method is not in the list → abort(405) with an `Allow:` header, immediately, before the page
  runs anything else. GET implies HEAD. OPTIONS is not special.
- A page that never calls allow() accepts only GET/HEAD. Any other method reaching such a page is answered 405 in
  ink_finish (buffered output discarded), after the page has run. This is safe because a page without allow() has no
  code meant to handle other methods; `bin/ink check` turns it into an error if the page looks at the method or the
  body without allow(): input(), is_post(), store_upload(), $_POST, $_FILES, $_REQUEST,
  `$_SERVER['REQUEST_METHOD']` or `'php://input'`.
- allow() may be called again later to narrow one URL shape; each call is checked on its own:
  `[$raw] = params(0, 1); allow('GET', 'POST'); $id = as_id($raw); if ($id !== null) allow('GET');`
  A page whose params are optional (min < max) and that allows any method besides GET/HEAD/OPTIONS must call allow()
  at least twice, otherwise `bin/ink check` errors (POST meant for /x would also run for /x/5).

### 4.6 Language in the URL (config locale_url)
- 'prefix': ink_route() strips a leading segment equal to one of config('locales') before resolving (it is not a param).
  The default (first) locale has no prefix (/zh/x → 301 /x) unless locale_default_prefixed, then a bare /x → 302 (307 for
  non-GET) to /<Accept-Language best>/x with Vary: Accept-Language. pages/<locale>(.php) is unreachable (check error).
- 'query': only ?lang= decides; missing → default; ?lang=<default> or an unknown value → 301 without it.
- false: locale() from ?lang= (stores a cookie) > cookie > Accept-Language > default (one URL for all languages).
- In 'prefix'/'query' the URL alone decides (no cookie, no Accept-Language), so cache_public() is not downgraded for language.
- `href(string $path, ?string $locale = null): string` localizes a local path; `locale_urls(): array` = this page in every
  locale + 'x-default' (relative). require_login() defaults to href('/login'). wants_json() looks at the routed path.
- `locale_exempt` (path prefixes, e.g. ['/admin', '/login', '/api']): outside the language system. One URL, locale
  `locale_exempt_locale` (default: first locale): no prefix, no Accept-Language redirect; a prefixed form 301s to the bare
  one, ?lang= is stripped; href() never localizes them; locale_urls() gives the same URL for every locale. '/admin'
  matches /admin and /admin/…, not /administrator. `bin/ink routes` marks them no-lang; `check` rejects bad entries.
- `bin/ink check`: warns on hard-coded href/action/hx-* "/…" in HTML and redirect('/…') when locale_url is on, and on
  t([...]) arrays missing a configured locale. Test tables may set `@config key=value …` (in-process only).

### 4.5 as_id(?string $v): ?int
- Converts a path param to a record id: null → null; a canonical positive integer (`^[1-9][0-9]{0,17}$`) → int;
  anything else ('01', '0', '-1', '1e3', 'abc', 19+ digits) → abort(404). One record has exactly one URL.
- abort(405) from page code cannot carry an `Allow:` header (error responses drop page headers, §9): when a method
  is valid only on some URLs of a page, answer the others with abort(404).

## 5. Views

- `e(mixed $v): string` — htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, UTF-8); null/arrays/objects (non-Stringable) → ''.
- `layout(?string $name, array $vars = []): void` — after the page finishes, its buffered output becomes `$content`
  and `layouts/<name>.php` is rendered with `$vars` + `$content` extracted in an isolated function scope
  (`$content` always wins over a `$vars['content']`). Not applied when the response is JSON/redirect/abort, or when
  the page later calls `layout(null)` (e.g. htmx fragment responses). Calling layout() is optional.
  An invalid name or a missing file → LogicException at the call.
- **No shared fragments.** There is no partial() and no partials/ directory: a fragment shared between pages couples
  them (changing it for one page silently breaks another — found in the 1.0 AI-usability review). HTML a page needs lives
  in that page; similar HTML on two pages is written twice. HTML printed twice by the SAME page (full page + htmx
  response) is an in-page closure (`$list = function (array $rows): void { ?>…<?php };`). layouts/ is the only shared
  template and only reads variables passed explicitly via layout(), each with a default. `bin/ink check` errors on any
  partial() call or a partials/ directory.
- `asset(string $path): string` — `/assets/<path>?v=<filemtime>`; missing file → still returns path (logs warning in dev).
- `t(array|string $s, array $vars = []): string` — inline translations first:
  `t(['zh' => '提交', 'en' => 'Submit'])` picks locale(), then config('locales')[0], then the first value.
  A string returns itself (so plain text works). `{name}` placeholders replaced from $vars (not escaped; wrap in e()).
- `locale(mixed $set = null): string` — config('locales', ['zh','en']). Resolution: explicit set (stores cookie
  `lang`, 1 year, Lax, HttpOnly) > `?lang=` (also stores cookie) > cookie > Accept-Language (q-values; exact tag, then
  primary subtag: `zh-CN` → `zh`) > first configured. A `$set` that is not a string in config('locales') (unknown
  value, array, null) is ignored, so `locale($_GET['l'] ?? null)` is safe. The cookie is only sent when it changes.
  A result taken from the cookie, Accept-Language or the default differs per visitor: ink_finish then downgrades a
  cache_public() response (§6).

## 6. HTTP helpers

- `is_post(): bool`, `is_htmx(): bool` (HX-Request: true), `wants_json(): bool` (Accept contains application/json,
  or the path is /api or starts with /api/).
- `input(?string $key = null, mixed $default = null): mixed` — POST form fields, or a JSON body when Content-Type is
  application/json or `*+json` (invalid JSON, or JSON that is not an object/array → abort(400); empty body → []).
  With $key returns that field. Values are returned raw (not trimmed);
  validate() is where trimming happens (it trims strings before applying rules and stores trimmed values in $clean).
- `json(mixed $data, int $code = 200): never` — Content-Type application/json; JSON_UNESCAPED_UNICODE|
  JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR, pretty only in dev; Cache-Control no-store.
- `redirect(string $to, int $code = 302): never` — only local paths (`/` + not `//` or `/\`, no control chars);
  otherwise logs and redirects to `/`. $code must be 301, 302, 303, 307 or 308 (else LogicException). For external
  URLs pages call header() themselves (deliberately explicit).
- `abort(int $code, string $message = ''): never` — discards the buffer; JSON `{"error":code,"message":...}` when
  wants_json(), else `layouts/error.php` with $code/$message if it exists, else a built-in minimal page. 503 adds
  Retry-After: 30.
- `host(): string` — lowercased Host without port and trailing dot; must be a DNS name (labels `[a-z0-9-]`, ≤ 253
  chars) or a bracketed IPv6 literal; invalid → abort(400).
- `url(string $path = '/'): string` — absolute URL: config('base_url') if set, else scheme://host() where scheme is
  https if is_https(), plus the Host header's port when it is not the scheme's default (dev servers). `is_https()`: $_SERVER['HTTPS'] on, or SERVER_PORT 443, or (only when config('trust_proxy')
  is true) X-Forwarded-Proto/CF-Visitor says https.
- `client_ip(): string` — REMOTE_ADDR (deployment sets real IP, e.g. nginx real_ip for Cloudflare). Never reads
  X-Forwarded-For.
- `ip_key(): string` — client_ip() as a rate_limit() key: IPv4 unchanged, IPv6 cut to its /64 (`2001:db8:1:2::/64`;
  one host usually owns a whole /64), an IPv4-mapped IPv6 address → the IPv4 address.
- `cache_public(int $seconds): void` — `Cache-Control: public, max-age=min(60,$seconds), s-maxage=$seconds`; if at
  finish time a session was started, a Set-Cookie header is pending, or locale() was resolved from the lang cookie,
  Accept-Language or the default (§5), downgrade to `private, no-cache` and log a warning. (Not Vary: Cloudflare
  ignores it.) The page must not put other request-dependent data in such a response either: url() uses the Host
  header unless config('base_url') is set.
- `store_upload(string $field, array $types = ['jpg','jpeg','png','gif','webp','pdf'], int $maxBytes = 5_000_000): ?string`
  — validates is_uploaded_file, size, finfo MIME ↔ extension map; saves to public/uploads/<random32hex>.<ext>,
  returns the public URL path, or null (sets a reason retrievable via `upload_error(): ?string`, a short message in
  the current locale, zh/en). The map knows jpg jpeg png gif webp avif pdf txt csv mp3 mp4 zip; any other entry in
  $types (php, svg, html, …) is a bug → LogicException.

## 7. Data (PDO only)

- config('db') = ['dsn' => 'sqlite:' . INK_ROOT . '/storage/db.sqlite' | 'pgsql:…' | 'mysql:…;charset=utf8mb4',
  'user' => env('DB_USER'), 'pass' => env('DB_PASS')].
- `db(): PDO` — lazy, cached; ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES false (except sqlite), timeout 3s.
  Connection failure → 503 (logged), never leaks DSN/password.
- `q(string $sql, array $params = []): PDOStatement` · `one(): ?array` · `all(): array` · `val(): mixed`
  (first column of first row, null if none) — all take ($sql, $params).
- `tx(callable $fn): mixed` — calls `$fn(db())` between begin/commit, rollback + rethrow on exception. A tx() inside
  a tx() joins the outer transaction.
- `validate(array $data, array $rules): array` → `[$clean, $errors]`.
  Rules (pipe-separated): required, email, int, numeric, url (http/https only), bool, array, min:N, max:N (string
  length in characters for strings — also for a JSON number unless int/numeric is there; value for int/numeric; item
  count for array), in:a,b,c, regex:/…/ (must be last rule; may contain `|`). Empty = null, '' (after trim) or [].
  Non-required empty fields skip other rules and are set to null in $clean. `'0'` counts as present. Non-scalar values
  fail (except when the rule says `array`); invalid UTF-8 fails. int: a PHP int or a string of digits with an
  optional sign, within PHP's int range (`'05'` → 5; bools, floats, `'1.5'` fail). numeric: is_numeric and finite
  (`'1e999'` fails), never a bool. int/numeric/bool store the converted value in $clean (`'18'` → 18, `'yes'` → true);
  in stores the value as a string (JSON 5 → `'5'`).
  $clean contains exactly the fields that have rules (an invalid field keeps its trimmed input, for re-filling forms).
  $errors[field] = one human message in the current locale (zh/en).
  Unknown rule, or min/max without a number → LogicException (a bug, not user error).

## 8. Session, auth, CSRF, rate limiting

- Sessions are lazy: nothing starts a session except these functions. Storage: storage/sessions (files).
  Cookie: name `__Host-ink` on https else `ink`; HttpOnly; Secure on https; SameSite=Lax; path /; strict mode;
  use_only_cookies; cache_limiter ''. Idle lifetime config('session_lifetime', 86400) seconds, enforced by session()
  on every start: it keeps the last-seen time in `$_SESSION['_seen']` (refreshed at most once a minute, so lazy writes
  stay cheap) and a session idle for longer is emptied and gets a new id. GC (1% of session starts; Debian disables
  PHP's session GC by default, and our files are not in /var/lib/php/sessions) only deletes old files.
- Reading never starts a session: flash() read, user(), logout() and csrf_check() only open one when the browser
  sent our cookie with a well-formed id (`[A-Za-z0-9,-]{22,256}`) whose file storage/sessions/sess_<id> exists; a
  missing, bogus or stale cookie counts as "no session" (no file is created, no Set-Cookie is sent).
- `session(): void` start if needed. `flash(string $key, ?string $msg = null): ?string` set/get-and-clear.
- `csrf_token(): string` (session, 32 random bytes hex) · `csrf_field(): string` (`<input type=hidden name=_csrf>`)
  · `csrf_check(): void` — reads POST `_csrf` or header `X-CSRF-Token` (strings only), hash_equals, else abort(403).
  The token is never read from the query string, and the check runs whatever the method — on a GET+POST page call it
  inside `if (is_post())`.
- `login(int|string $id): void` (regenerates the session id and the CSRF token) · `logout(): void` (clears the session
  data and regenerates the id, so a flash() after it still works) · `user(): int|string|null` (no session is started
  when the cookie is absent) · `require_login(string $loginPath = '/login'): int|string` — not logged in:
  wants_json() → 401 JSON; else redirect to $loginPath?next=<rawurlencoded current local path + query>.
- Password helpers are plain PHP: password_hash / password_verify (documented in AGENTS.md, no wrapper).
- `rate_limit(string $key, int $max, int $windowSeconds): void` — fixed window counter in storage/ratelimit
  (flock, file per sha1(key); the file's mtime is set to the end of its window); exceeded → 429 with Retry-After.
  On 1% of calls, counter files whose window ended are deleted. Keys for visitors use ip_key(), not client_ip()
  (IPv6 rotation); logins also limit per account (`'login-user:' . strtolower($name)`), before password_verify().

## 9. Errors and logging

- Error handler: respects `@` (error_reporting() check). dev/test: every warning/notice → ErrorException (deprecations
  only logged). prod: log and continue.
- Exceptions/fatal errors → ink_fail(): log with request id, discard all output buffers, respond 500 (503 only when a
  service is unavailable: InkUnavailable, or a transient PDOException — SQLSTATE class 08, 40001, 40P01, 55P03, 57P01,
  57P03; MySQL driver codes 1205/1213/2002/2006/2013; SQLite driver codes 5/6 BUSY/LOCKED. SQL errors and constraint
  violations are bugs → 500; pages catch the 23xxx they expect) as JSON `{"error":500,"message":"Internal Server Error","rid":"…"}` or HTML
  (layouts/error.php if present, with `$code`, `$message`, `$rid`; if it is missing or itself fails, a built-in page).
  In dev (only dev, not test) the error page shows the message, file:line and trace (escaped; HTML: inserted before
  `</body>`; JSON: a `debug` field). prod never shows details. CLI: print to STDERR, exit 1 (abort() too).
- Every error response (abort/allow/rate_limit/finish/ink_fail) drops the page's pending headers except Set-Cookie,
  then re-sends the §10 defaults.
- When an exception is thrown inside ink/ (e.g. a LogicException from layout(), a PDOException from q()), the logged
  `at` is the calling page line, followed by the kernel location.
- `log_info(string $msg, array $ctx = [])`, `log_error(...)` — one JSON line {t (UTC ISO8601), lvl, msg, rid, req,
  ...ctx} appended (LOCK_EX) to storage/logs/YYYY-MM-DD.log (UTC date); ctx cannot override the base keys; if the file
  cannot be written the line goes to PHP's error_log(). rid = the CF-Ray header (`[A-Za-z0-9-]{1,64}`) only when
  config('trust_proxy') is true (anyone can send the header), else random 8 hex. Warnings are logged with lvl `warn`.
- `config(string $key, mixed $default = null)` dot notation · `env(string $key, mixed $default = null)` from .env.php,
  then getenv().

## 10. Security headers (always, kernel)

X-Content-Type-Options: nosniff · Referrer-Policy: strict-origin-when-cross-origin · X-Frame-Options: SAMEORIGIN ·
Cache-Control: private, no-cache (unless cache_public / json no-store). No X-Powered-By.
HSTS and Content-Security-Policy are deployment choices (deploy/nginx.conf has a commented HSTS line; deploy/README.md
recommends a CSP), not kernel defaults. The example layout prints the version and render time only in dev.

## 11. CLI (`bin/ink`)

ink/cli.php is never loaded by a web request. routes/check/test load only ink/route.php and read pages with
token_get_all: no page, config.php or .env.php runs. migrate loads the whole kernel. Run bin/ink as the web user
(`sudo -u www-data bin/ink migrate`) so files it creates in storage/ stay writable for PHP; check and migrate warn
when run as root.

- `serve [port=8000]` → `php -S 127.0.0.1:<port> -t public public/index.php` with APP_ENV=dev in the environment (an
  APP_ENV in .env.php still wins, §3). The CLI process becomes the server (pcntl_exec when available).
- **Declarations.** A page's leading statements are the statements before the first one that is not a declaration.
  A declaration is a call to params/allow/require_login/csrf_check/layout written as `fn(…);`, optionally assigned
  (`$x = fn(…);`, `[$a, $b] = fn(…);`, `list(…) = fn(…);`). `declare(…);` and `use …;` may come first; inline HTML
  ends the leading statements. Arguments are read only when they are literals (int, string, null).
- `routes [--pages=DIR]` → table: URL pattern (names from `[$id, $tab] = params(1, 2)` → `/x/{id}/{tab?}`) · file ·
  params min-max (`?` when not literal) · methods (from a leading allow(), default GET; HEAD is implied) · notes:
  `login`/`csrf` when the page calls require_login()/csrf_check() anywhere (e.g. inside `if (is_post())`),
  `layout:<name>` for a literal name. This is reading declarations, not guessing behaviour.
- `check` → exit 1 on errors:
  - `php -n -l` every PHP file (not in .git/, storage/, vendor/, node_modules/) and bin/ink;
  - page file names: every segment is a route name (§4.2); index.php only directly in pages/; a name ending in .php
    can never be reached (the canonical redirect strips it); non-.php files in pages/ (warning); `_name`/`.name`
    files are private and skipped;
  - the first statement of every page is params() (in one of the forms above), with literal 0 ≤ min ≤ max ≤ 12;
    allow(), when called, is the second statement; pages that use input()/is_post()/store_upload()/$_POST/$_FILES/
    $_REQUEST/`$_SERVER['REQUEST_METHOD']`/`'php://input'` call allow(); a page with optional params (min < max)
    that allows a method besides GET/HEAD/OPTIONS calls allow() at least twice (§4.4);
  - pages that allow any method other than GET/HEAD/OPTIONS call csrf_check() (warning, error in the admin area); a
    leading csrf_check() on a page that allows GET (warning: every GET gets 403);
  - pages in the admin area — `pages/admin.php` and everything under `pages/admin/` — have require_login() as a
    leading statement (error);
  - no `$_GET['keyN']` in pages/layouts; literal layout('x') names exist; no partial() calls and no partials/ dir;
  - no PHP file (`.php`, `.php5`/`.php7`…, `.phtml`, `.phar`) in public/ except index.php; storage/ and its logs/,
    sessions/, ratelimit/ writable; public/uploads writable (warning); .env.php not tracked by git — found by reading
    .git/index (versions 2–4) directly, never by running git, so no repository config (core.fsmonitor, hooks) runs
    and git's safe.directory protection is never switched off.
- `test [FILE…] [--http=BASE_URL] [--pages=DIR] [-v]` → runs route tables: the FILEs, else every tests/*.txt.
  Line format: `METHOD /path -> STATUS [target] [params]`, optional `# comment`. STATUS may be written `http:404`:
  the status is decided by page code (a missing record, a bad id), so the line is skipped in-process and checked only
  with --http. target is a page file (200) written
  as `pages/<file>` relative to the pages directory in use, a Location (3xx, compared exactly) or omitted; params is
  a JSON array of the path params: `GET /zh/popular/0 -> 200 pages/zh/popular.php ["0"]`. A line `@pages DIR` says
  the table describes another page tree (tests/routes.txt → tests/fixtures/pages); `--pages` overrides it.
  - In-process (default): no server, no page runs. ink_canonical → 400 / 301 (GET, HEAD) / 308 + Location exactly as
    ink_route() builds it; ink_resolve → 404; then the page's leading declarations in order, for an anonymous visitor
    without a CSRF token: params → 404, allow → 405, csrf_check → 403, require_login → 401 under /api, else 302 to
    its login path; layout is skipped; a non-literal argument stops the evaluation. Then ink_finish's defaults: path
    params and no params() call → 404, a method other than GET/HEAD and no allow() call → 405 (also after the
    require_login redirect, counting only the calls made before it). Otherwise the page runs: 200 + file + params.
    For a page that runs, a line expecting a status that only page code produces (anything but 200, 301, 308, 400,
    404, 405) is reported as skipped. Static files in public/ are not modelled.
  - `--http=BASE_URL`: one raw HTTP/1.1 request per line, no cookies, redirects not followed; bytes outside
    printable ASCII are percent-encoded first, as browsers do. Compares the status and, for 3xx, the Location.
    Tables with `@pages` are skipped unless named. No table run → exit 1.
  - tests/routes.txt covers every rule of §4 and the 0.x routing bugs; it passes in-process and over HTTP against a
    server whose pages/ is a copy of tests/fixtures/pages. tests/app.txt is the app's own table.
- `migrate` → applies migrations/*.sql not yet recorded in table ink_migrations (name, applied_at) in name order, each
  file and its ink_migrations row in one transaction; a failing file is rolled back and stops the run (exit 1).
  Files hold plain SQL statements without BEGIN/COMMIT. MySQL commits DDL implicitly, so there a failed file can be
  partly applied. A connection failure prints where the reason was logged (never the DSN or password).

## 12. Deployment files

- deploy/nginx.conf: one server block: root …/public; `location ~ /\.(?!well-known/) { return 404; }`;
  `location ~* \.(php\d?|phtml|phar)$ { try_files /__never__ @ink; }` (the same extensions as public/index.php,
  .htaccess and check); `location /assets/` and `location /uploads/`
  (`try_files $uri @ink;` + `X-Content-Type-Options: nosniff`; assets also `expires 7d`, safe because asset() adds
  `?v=<mtime>`; uploads also `Content-Security-Policy: sandbox; default-src 'none'`, because visitors' files are
  served from the site's origin); `location / { try_files $uri @ink; }`; a commented HSTS line in the TLS block;
  `location @ink { include fastcgi_params;` (first, so the lines after it win) `fastcgi_param SCRIPT_FILENAME
  $realpath_root/index.php; fastcgi_param SCRIPT_NAME /index.php; fastcgi_param DOCUMENT_ROOT $realpath_root;
  fastcgi_param HTTP_PROXY ""; fastcgi_pass …; }`. The two regex locations are checked before the prefix ones, so
  `/uploads/x.php` also goes to the router and is never served or executed.
  Shared FPM pools: never use fastcgi_param PHP_VALUE/PHP_ADMIN_VALUE: the value stays in the worker and leaks into
  other sites' requests (reproduced in the lab: another site's `open_basedir` made every inkphp request fail with
  "No input file specified"). Give the app its own pool, with open_basedir = the project root (not public/).
- public/.htaccess and deploy/baota.conf: rewrite non-files to /index.php (宝塔: `try_files $uri /index.php$is_args$args`).
  宝塔's stock `enable-php-XX.conf` location runs before the 伪静态 rules and executes any .php file under public/,
  so baota.conf adds `location ^~ /uploads/` with the nosniff + CSP sandbox headers and nested 404 rules for PHP
  files and dotfiles (only a `^~` prefix location wins over that regex), and deploy/README.md recommends replacing the
  stock PHP include with the @ink location. public/.htaccess sends the same two headers for /uploads/ (mod_headers).
- deploy/README.md (Chinese): steps, permissions (storage/ and public/uploads/ writable by PHP; run bin/ink as the
  web user), nginx, own FPM pool, Cloudflare (real_ip snippet, SSL mode, trust_proxy, origin firewall, cache rules),
  宝塔, Apache (`CGIPassAuth On` for Authorization headers), releases, cron (logs; optional session/ratelimit
  cleanup for low-traffic sites), HSTS and CSP, troubleshooting.

## 13. Removed from 0.x

Routing in nginx.conf, `$_GET['keyN']`, static source awareness, unconditional session/DB, Medoo, `_config.php`,
`_core/`, `_errors/`, logger.php, debug/raw/test pages, `upload/`, the four overlapping docs.

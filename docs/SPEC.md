# inkphp 1.0 — design spec (the contract)

Status: authoritative for the 1.0 rework. Code, AGENTS.md, README.md and examples must agree with this file.
If something here turns out to be wrong while implementing, change this file in the same commit.

## 1. Goal and principles

inkphp is a PHP framework designed to be easy for AI agents (and people) to read and change.

1. **One URL = one file = one feature.** A page file contains everything the feature needs: accepted params, allowed
   methods, auth, CSRF, validation, SQL, HTML, and its POST/htmx handling.
2. **Nothing runs that the page didn't ask for.** No guard files, no middleware, no auto-included files, no source
   scanning, no magic globals. Every dependency of a page is an explicit call in that page.
   The only always-on behaviour is the kernel itself (error handling, security headers, routing) and it is fully
   described in AGENTS.md.
3. **Safe by default.** The shortest way to write something is the safe way: escaped output helper, parameterized SQL
   only, CSRF helper, local-only redirects, page files outside the docroot, uploads can never execute.
4. **Small enough to fit in an AI's context.** Kernel ≤ ~800 lines of web code + ≤ ~250 lines CLI, ~40 functions.
5. **Zero dependencies, zero build.** No Composer, no npm. PHP ≥ 8.2 (target 8.4). Deploy = copy files / git pull.

Non-goals: MVC, ORM/query builder (no Medoo), plugins, themes, middleware, DI container, events, template engine.

## 2. Repository layout (the repo is itself the starter app)

```
inkphp/
├── public/                 # the ONLY web docroot
│   ├── index.php           # the ONLY .php file in public/ (front controller + php -S router script)
│   ├── .htaccess           # Apache / shared hosting: send non-files to index.php
│   ├── assets/             # css/js/images; assets/htmx.min.js vendored (htmx 2.x, pinned, sha256 in AGENTS.md)
│   └── uploads/            # store_upload() target (gitignored except .gitkeep)
├── pages/                  # URL = file. Never reachable directly (outside docroot).
├── layouts/                # page frames, used only via an explicit layout('name') call
├── partials/               # reusable fragments, used only via an explicit partial('name') call
├── migrations/             # NNN_name.sql, applied in order by `bin/ink migrate`
├── ink/                    # the kernel (projects vendor a copy; INK_VERSION constant)
│   ├── ink.php             # loads the other kernel files in order; nothing else
│   ├── boot.php            # constants, config/env, ob, error/exception/shutdown handlers, headers, logging
│   ├── route.php           # canonical redirect, resolver, params(), allow(), page runner, finish
│   ├── http.php            # input/json/redirect/abort/is_post/is_htmx/host/url/client_ip/cache_public/store_upload/asset
│   ├── view.php            # e/layout/partial/t/locale
│   ├── data.php            # db/q/one/all/val/tx/validate
│   ├── auth.php            # session/flash/csrf_*/login/logout/user/require_login/rate_limit
│   └── cli.php             # bin/ink implementation
├── bin/ink                 # #!/usr/bin/env php → requires ink/cli.php
├── storage/                # logs/, sessions/, ratelimit/, db.sqlite (gitignored, auto-created)
├── tests/routes.txt        # route table used by `bin/ink test`
├── deploy/                 # nginx.conf (server block), baota.conf (宝塔伪静态), README section on Cloudflare/real IP
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
boot (ink.php → boot.php …):
    define INK_VERSION, INK_ROOT (= realpath(__DIR__.'/..') of ink/), INK_START (hrtime)
    load config.php + .env.php → config()/env(); APP_ENV = env('APP_ENV', config('env', 'prod')) ∈ {dev, prod, test}
    date_default_timezone_set(config('timezone', 'UTC'))
    ob_start(); set_error_handler / set_exception_handler / register_shutdown_function (§9)
    header_remove('X-Powered-By'); default headers (§10)
ink_route():
    1. canonical redirect (§4.1) → 301 (GET/HEAD) or 308 (others), Location is a RELATIVE path + original query
    2. resolve (§4.2) → [file, params]; none → abort(404)
    3. store params for params(); return the absolute file path
ink_finish():  (also reached via exit in json()/redirect()/abort() through the shutdown path, see §9)
    if the page received ≥1 path param and never called params() → discard buffer, abort(404)
    if layout() was called → render it around the buffered page output
    flush
```

## 4. Routing

### 4.1 Canonical form (one redirect, never a chain)
Work on the raw path of REQUEST_URI (before decoding), query string kept as-is.
- collapse repeated `/`, drop a trailing `/` (except the root `/`)
- drop a trailing `.php` from the LAST segment (`/about.php` → `/about`, `/robots.txt.php` → `/robots.txt`)
- a first segment `index` is removed (`/index` → `/`, `/index/5` → `/5`)
- segments `.` are removed; any `..` segment → 400
If the result differs from the raw path → redirect. The Location never contains a scheme/host and must start with
exactly one `/` (guard against `//evil.com`, `/\evil.com`).

### 4.2 Resolution (right-to-left, deepest wins)
- Split the canonical path into segments; decode each segment ONCE with rawurldecode.
- A decoded segment containing `/`, `\`, NUL or other control chars → 404. More than 12 segments → 404.
- A segment can be part of a file path only if it is a valid route name:
  `^[a-z0-9][a-z0-9._-]*$` (lowercase ASCII) and not equal to `index`, OR it is exactly `.well-known`.
  Names starting with `_` or `.` (other than `.well-known`) are never routable at any depth.
- For i = n … 1: if segments[0..i) are all valid names and `pages/<seg0>/…/<seg i-1>.php` is a file → that file,
  params = segments[i..n) (decoded strings). Only descend into directories that exist (stat pruning).
- Otherwise `pages/index.php` with params = all segments. (`/` → index.php with no params.)
- `x.php` and directory `x/` may coexist: `/x` → x.php, `/x/y` → x/y.php if it exists, else x.php with params [y].
- Dynamic "twins" of static-looking URLs just work: `pages/robots.txt.php` serves `/robots.txt`
  (a real file public/robots.txt would win because the web server serves files first).
- Path params are NOT copied into $_GET. Query string stays in $_GET untouched.

### 4.3 params(int $min = 0, ?int $max = null): array
- `$max` defaults to `$min`. If the number of path params is outside [min, max] → abort(404).
- Returns the params array padded with `null` up to `$max` (so `[$id, $tab] = params(1, 2);` works).
- `'0'` is a valid param. Values are strings.
- Convention (enforced by `bin/ink check`): `params()` is the first statement of every page.
- A page that never calls params() accepts zero path params (enforced in ink_finish, buffer discarded).

### 4.4 allow(string ...$methods): void
- Called as the 2nd statement (right after params()). If the request method is not in the list → abort(405) with an
  `Allow:` header, immediately, before the page runs anything else. GET implies HEAD. OPTIONS is not special.
- A page that never calls allow() accepts only GET/HEAD. Any other method reaching such a page is answered 405 in
  ink_finish (buffered output discarded). This is safe because a page without allow() has no code meant to handle
  other methods; `bin/ink check` turns it into an error if the page uses input()/is_post()/$_POST without allow().

## 5. Views

- `e(mixed $v): string` — htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, UTF-8); null/arrays/objects (non-Stringable) → ''.
- `layout(string $name, array $vars = []): void` — after the page finishes, its buffered output becomes `$content`
  and `layouts/<name>.php` is rendered with `$vars` + `$content` extracted in an isolated function scope.
  Not applied when the response is JSON/redirect/abort, or when the page later calls `layout(null)`
  (e.g. htmx fragment responses). Calling layout() is optional.
- `partial(string $name, array $vars = []): string` — renders `partials/<name>.php` in an isolated scope, returns HTML.
  Name must match `^[a-z0-9_/-]+$`, no `..`.
- `asset(string $path): string` — `/assets/<path>?v=<filemtime>`; missing file → still returns path (logs warning in dev).
- `t(array|string $s, array $vars = []): string` — inline translations first:
  `t(['zh' => '提交', 'en' => 'Submit'])` picks locale(), then config('locales')[0], then the first value.
  A string returns itself (so plain text works). `{name}` placeholders replaced from $vars (not escaped; wrap in e()).
- `locale(?string $set = null): string` — config('locales', ['zh','en']). Resolution: explicit set (stores cookie
  `lang`, 1 year, Lax) > `?lang=` (also stores cookie) > cookie > Accept-Language > first configured.

## 6. HTTP helpers

- `is_post(): bool`, `is_htmx(): bool` (HX-Request: true), `wants_json(): bool` (Accept contains application/json,
  or the path starts with /api/).
- `input(?string $key = null, mixed $default = null): mixed` — POST form fields, or a JSON body when Content-Type is
  application/json (invalid JSON → abort(400)). With $key returns that field. Values are returned raw (not trimmed);
  validate() is where trimming happens (it trims strings before applying rules and stores trimmed values in $clean).
- `json(mixed $data, int $code = 200): never` — Content-Type application/json; JSON_UNESCAPED_UNICODE|
  JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR, pretty only in dev; Cache-Control no-store.
- `redirect(string $to, int $code = 302): never` — only local paths (`/` + not `//` or `/\`, no control chars);
  otherwise logs and redirects to `/`. For external URLs pages call header() themselves (deliberately explicit).
- `abort(int $code, string $message = ''): never` — discards the buffer; JSON `{"error":code,"message":...}` when
  wants_json(), else `layouts/error.php` with $code/$message if it exists, else a built-in minimal page. 503 adds
  Retry-After: 30.
- `host(): string` — lowercased Host without port and trailing dot; invalid → abort(400).
- `url(string $path = '/'): string` — absolute URL: config('base_url') if set, else scheme://host() where scheme is
  https if is_https(). `is_https()`: $_SERVER['HTTPS'] on, or SERVER_PORT 443, or (only when config('trust_proxy')
  is true) X-Forwarded-Proto/CF-Visitor says https.
- `client_ip(): string` — REMOTE_ADDR (deployment sets real IP, e.g. nginx real_ip for Cloudflare). Never reads
  X-Forwarded-For.
- `cache_public(int $seconds): void` — `Cache-Control: public, max-age=60, s-maxage=$seconds`; if a session was started
  or a Set-Cookie header is pending at finish time, downgrade to `private, no-cache` and log a warning.
- `store_upload(string $field, array $types = ['jpg','jpeg','png','gif','webp','pdf'], int $maxBytes = 5_000_000): ?string`
  — validates is_uploaded_file, size, finfo MIME ↔ extension map; saves to public/uploads/<random32hex>.<ext>,
  returns the public URL path, or null (sets a reason retrievable via `upload_error()`).

## 7. Data (PDO only)

- config('db') = ['dsn' => 'sqlite:' . INK_ROOT . '/storage/db.sqlite' | 'pgsql:…' | 'mysql:…;charset=utf8mb4',
  'user' => env('DB_USER'), 'pass' => env('DB_PASS')].
- `db(): PDO` — lazy, cached; ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES false (except sqlite), timeout 3s.
  Connection failure → 503 (logged), never leaks DSN/password.
- `q(string $sql, array $params = []): PDOStatement` · `one(): ?array` · `all(): array` · `val(): mixed`
  (first column of first row, null if none) — all take ($sql, $params).
- `tx(callable $fn): mixed` — begin/commit, rollback + rethrow on exception.
- `validate(array $data, array $rules): array` → `[$clean, $errors]`.
  Rules (pipe-separated): required, email, int, numeric, url, bool, min:N, max:N (string length in characters for
  strings; value for int/numeric), in:a,b,c, regex:/…/ (must be last rule). Non-required empty fields skip other rules
  and are set to null in $clean. `'0'` counts as present. Non-scalar values fail (except when the rule says `array`).
  $clean contains only fields that have rules. $errors[field] = one human message in the current locale (zh/en).
  Unknown rule → LogicException (a bug, not user error).

## 8. Session, auth, CSRF, rate limiting

- Sessions are lazy: nothing starts a session except these functions. Storage: storage/sessions (files).
  Cookie: name `__Host-ink` on https else `ink`; HttpOnly; Secure on https; SameSite=Lax; path /; strict mode;
  use_only_cookies; cache_limiter ''.
- `session(): void` start if needed. `flash(string $key, ?string $msg = null): ?string` set/get-and-clear.
- `csrf_token(): string` (session, 32 random bytes hex) · `csrf_field(): string` (`<input type=hidden name=_csrf>`)
  · `csrf_check(): void` — reads POST `_csrf` or header `X-CSRF-Token` (strings only), hash_equals, else abort(403).
  GET is never accepted.
- `login(int|string $id): void` (regenerates id) · `logout(): void` · `user(): int|string|null` (no session is started
  when the cookie is absent) · `require_login(string $loginPath = '/login'): int|string` — not logged in:
  wants_json() → 401 JSON; else redirect to $loginPath?next=<current local path>.
- Password helpers are plain PHP: password_hash / password_verify (documented in AGENTS.md, no wrapper).
- `rate_limit(string $key, int $max, int $windowSeconds): void` — fixed window counter in storage/ratelimit
  (flock, file per sha1(key)); exceeded → 429 with Retry-After.

## 9. Errors and logging

- Error handler: respects `@` (error_reporting() check). dev/test: every warning/notice → ErrorException (deprecations
  only logged). prod: log and continue.
- Exceptions/fatal errors → ink_fail(): log with request id, discard all output buffers, respond 500 (503 for
  PDOException / InkUnavailable) as JSON or HTML (layouts/error.php if present). In dev the HTML error page shows the
  message, file:line and trace (escaped). prod never shows details. CLI: print to STDERR, exit 1.
- `log_info(string $msg, array $ctx = [])`, `log_error(...)` — one JSON line {t (UTC ISO8601), lvl, msg, rid, req,
  ...ctx} appended (LOCK_EX) to storage/logs/YYYY-MM-DD.log. rid = CF-Ray header if present else random 8 hex.
- `config(string $key, mixed $default = null)` dot notation · `env(string $key, mixed $default = null)` from .env.php,
  then getenv().

## 10. Security headers (always, kernel)

X-Content-Type-Options: nosniff · Referrer-Policy: strict-origin-when-cross-origin · X-Frame-Options: SAMEORIGIN ·
Cache-Control: private, no-cache (unless cache_public / json no-store). No X-Powered-By.

## 11. CLI (`bin/ink`)

- `serve [port=8000]` → `php -S 127.0.0.1:<port> -t public public/index.php` with APP_ENV=dev.
- `routes` → table: URL pattern · file · params(min,max) · methods · notes (login, csrf, layout) — obtained by
  tokenizing each page and reading its EXPLICIT leading calls (params/allow/require_login/csrf_check/layout).
  This is reading declarations, not guessing behaviour.
- `check` → exit 1 on errors: php -l every PHP file; page file names valid; params() is the first statement; pages
  that use input()/is_post()/$_POST call allow(); pages that allow POST call csrf_check() (warning, error under
  pages/admin/); pages under pages/admin/ call require_login() (error); no .php in public/ except index.php;
  no `$_GET['key` usage; storage writable; .env.php not committed.
- `test [--http=BASE_URL]` → runs tests/routes.txt in-process (resolver + canonicalizer only, no page execution) or over
  HTTP against a running server (full behaviour). Line format:
  `METHOD /path -> STATUS [target]` where target is a page file (200, in-process), a Location (3xx) or omitted.
  Optional `# comment`. Params can be asserted: `GET /zh/popular/0 -> 200 pages/zh/popular.php ["0"]`.
- `migrate` → applies migrations/*.sql in name order inside a transaction each, tracked in table ink_migrations.

## 12. Deployment files

- deploy/nginx.conf: one server block: root …/public; `location / { try_files $uri @ink; }`;
  `location ~* \.(php|phtml|phar)$ { try_files /__never__ @ink; }`; `location ~ /\.(?!well-known/) { return 404; }`;
  `location @ink { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
  fastcgi_param SCRIPT_NAME /index.php; fastcgi_param DOCUMENT_ROOT $realpath_root; fastcgi_pass …; }`.
  Note on shared FPM pools: never use fastcgi_param PHP_VALUE/PHP_ADMIN_VALUE (it leaks into other sites' requests);
  give the app its own pool if you need open_basedir.
- public/.htaccess and deploy/baota.conf: rewrite non-files to /index.php.

## 13. Removed from 0.x

Routing in nginx.conf, `$_GET['keyN']`, static source awareness, unconditional session/DB, Medoo, `_config.php`,
`_core/`, `_errors/`, logger.php, debug/raw/test pages, `upload/`, the four overlapping docs.

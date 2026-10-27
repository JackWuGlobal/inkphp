# inkphp 速查（AGENTS-QUICK.md）

AGENTS.md 的浓缩版：**日常写页面读这一份就够**。内容与 AGENTS.md 一致，冲突时以 AGENTS.md（再上一级是 docs/SPEC.md）为准。
遇到下面这些情况再读完整版对应章节：上传（§6.3 `store_upload`）、登录 / 隐藏后台（§6.6）、`e()` 管不到的输出上下文（§9.3）、
错误与 503 细节（§10）、部署（§15 + deploy/README.md）、完整示例（§17）。

## 规则（必须遵守）

- **一个网址 = 一个文件 = 一个功能。** `/signup` → `pages/signup.php`：参数、方法、登录、CSRF、校验、SQL、HTML 都在这一个文件里。
- **没有隐式执行。** 不加中间件、守卫文件、`_bootstrap.php`、`auto_prepend_file`、自动加载、运行时扫描源码。页面需要什么就自己调用。
- **页面骨架顺序固定：** `params()` → `allow()` → `require_login()` → `csrf_check()` → 业务逻辑 → `layout()` → HTML。
- **输出一律 `e()`；SQL 一律占位符；写操作一律 `csrf_check()`；GET 绝不改数据。**
- **不共享 HTML 片段：** 没有 `partial()`、没有 `partials/`、不 `include` 别的页面。同页复用用页面内闭包；别的页面要类似的 HTML 就各写一份。唯一共享模板是 `layouts/`。
- 多页共用的**业务函数**放 `lib/x.php`：只定义函数和常量（require 时不输出、不查库），函数名带前缀（`app_xxx()`），页面在声明之后显式 `require_once INK_ROOT . '/lib/x.php';`。
- 零依赖零构建：不引入 Composer、npm、ORM、Medoo、模板引擎。页面里不要 `require` 内核，不要定义 `e()`/`db()` 等同名函数（小工具用闭包）。
- 改完运行 `php bin/ink check`；新网址在 `tests/app.txt` 加几行并 `php bin/ink test`。

## 目录

```
public/        唯一的网站根目录：index.php（唯一的 .php）、assets/（htmx.min.js 等）、uploads/
pages/         网址 = 文件，在根目录之外
layouts/       site.php（外框）、error.php（错误页，变量 $code $message $rid）
lib/           多页共用的业务函数（可选）
migrations/    NNN_name.sql
ink/           内核（不要改）    bin/ink   命令行
storage/       logs/ sessions/ ratelimit/（不进 git）
config.php     配置（进 git，无机密）    .env.php  机密（不进 git，模板 .env.php.example）
tests/app.txt  应用的路由表
```

## 路由

- **规范化（一次 301，非 GET/HEAD 用 308）：** `/about.php`→`/about`，`/about/`、`//about`→`/about`，`/index`、`/index.php`→`/`，`/index/5`→`/5`；`..` / `%2e%2e` → 400。查询串原样保留。
- **解析：** 按 `/` 切段、每段 `rawurldecode` 一次；从最长前缀找文件：`pages/a/b/c.php` → `pages/a/b.php` → `pages/a.php`，**剩下的段是路径参数**；都没有 → `pages/index.php`（全部段都是它的参数）。最多 12 段。
- 路由名必须 `^[a-z0-9][a-z0-9._-]*$`（小写），不能是 `index`；`_x`、`.x` 永不路由；**子目录里的 `index.php` 不路由**（`/blog` 是 `pages/blog.php`）。
- 路径参数**不进 `$_GET`**，内容任意（可含大写、中文），必须自己校验。
- 动态"静态文件"：`pages/robots.txt.php` → `/robots.txt`（自己发 `Content-Type`）。不要建 `pages/assets.php`、`pages/uploads.php`。
- `x.php` 与目录 `x/` 可并存：`/x/y` 优先 `x/y.php`，否则 `x.php` 收到参数 `['y']`。

## 页面骨架

```php
<?php
// pages/things.php → /things、/things/{id}   一句话说明
[$raw] = params(0, 1);                  // 第一条语句。个数不在 [min,max] → 404；不足 max 用 null 补齐
allow('GET', 'POST');                   // 第二条语句（只读页面可省略，默认 GET/HEAD）
$id = as_id($raw);                      // 路径里的 id 一律 as_id()：非规范正整数 → 404，null → null
if ($id !== null) allow('GET');         // 参数可选 + 允许写方法时，必须再收窄带参数的网址（check 会报错）
$uid = require_login();                 // 需要登录时

if (is_post()) {
    csrf_check();                       // 放在 is_post() 里面，否则每个 GET 都 403
    [$in, $errors] = validate(input(), ['title' => 'required|max:100']);
    if (!$errors) {
        q('INSERT INTO things (user_id, title) VALUES (?, ?)', [$uid, $in['title']]);
        redirect('/things', 303);       // PRG
    }
    http_response_code(422);
}

$rows = all('SELECT id, title FROM things WHERE user_id = ? ORDER BY id DESC', [$uid]);
layout('site', ['title' => 'Things']);  // 可选；必须在 allow() 之后
?>
<ul><?php foreach ($rows as $r): ?><li><?= e($r['title']) ?></li><?php endforeach ?></ul>
```

- 不调用 `params()` 的页面只接受 0 个参数；不调用 `allow()` 的页面只接受 GET/HEAD（都在收尾时检查，**页面代码已经执行过了**，所以处理 POST 的页面必须第二行 `allow()`）。
- `params()` / `allow()` 的参数用字面量，`bin/ink routes` 才读得出。
- 页面在全局作用域运行；布局只看得到 `layout()` 传入的 `$vars` 和 `$content`。随时可以 `exit` / `return` / `json()` / `redirect()` / `abort()`。

## 函数速查（全部公开函数；`ink_` 开头的是内核内部函数，页面不要调用）

**路由**
- `params(int $min = 0, ?int $max = null): array` — 声明并取得路径参数（`$max` 默认 = `$min`）。
- `allow(string ...$methods): void` — 方法不在列表 → 405 + `Allow`；GET 含 HEAD；可再调一次收窄。
- `as_id(?string $v): ?int` — 规范正整数（`'42'`）→ int；`'01' '0' '-1' 'abc'`、超过 18 位 → 404。

**请求**
- `is_post(): bool` · `is_htmx(): bool`（`HX-Request: true`）· `wants_json(): bool`（Accept 含 json，或路径是 `/api…`）
- `input(?string $key = null, mixed $default = null): mixed` — JSON 请求体（坏 JSON → 400）或 `$_POST`；**原样，不 trim 不校验**。PUT/PATCH 表单数据读不到，写操作用 POST。
- `host(): string`（小写，非法 → 400）· `is_https(): bool` · `url(string $path = '/'): string`（绝对网址，`config('base_url')` 优先）
- `client_ip(): string`（只读 `REMOTE_ADDR`，真实 IP 靠 nginx `real_ip`）· `ip_key(): string`（限流用：IPv6 取 /64）

**响应**
- `json(mixed $data, int $code = 200): never` — 丢弃输出，`no-store`，结束。
- `redirect(string $to, int $code = 302): never` — 只接受本站路径（以 `/` 开头），否则记 warn 跳 `/`；`$code` 只能 301/302/303/307/308。跳外站自己 `header('Location: …'); exit;`。
- `abort(int $code, string $message = ''): never` — 错误响应（JSON 或 `layouts/error.php`）；`$message` 生产环境也显示，不放内部信息；之前设的响应头（除 Set-Cookie）被丢弃。
- `cache_public(int $seconds): void` — 允许 CDN 缓存；开过 session、有 Set-Cookie、语言来自 cookie/Accept-Language/默认值时自动降级为 `private, no-cache`。
- `store_upload(string $field, array $types = [...], int $maxBytes = 5_000_000): ?string` · `upload_error(): ?string` — 见 AGENTS §6.3。

**视图**
- `e(mixed $v): string` — HTML 转义（文本和**带引号的**属性）。
- `layout(?string $name, array $vars = []): void` — 页面结束时把输出作为 `$content` 套 `layouts/<name>.php`；最后一次生效；`layout(null)` 取消。
- `asset(string $path): string` — `/assets/<path>?v=<mtime>`。
- `t(array|string $s, array $vars = []): string` — 就地翻译 `t(['zh' => '已有 {n} 人', 'en' => '{n} people'], ['n' => $c])`；**结果不转义**，输出要 `e()`。
- `locale(mixed $set = null, bool $remember = true): string` — 优先级：`locale('x')` > `?lang=` > cookie `lang` > `Accept-Language` > 第一个配置语言；非配置值被忽略；`locale('zh', false)` 只管本次请求、不写 cookie。

**数据（PDO）**
- `db(): PDO` — 懒连接；连不上 → 503，不泄露 DSN。
- `q(string $sql, array $params = []): PDOStatement` · `one(...): ?array` · `all(...): array` · `val(...): mixed` — 占位符 `?` 或 `:name`（**同一条 SQL 不要混用**）。
- `tx(callable $fn): mixed` — 事务，异常回滚并重抛，可嵌套。
- `validate(array $data, array $rules): array` → `[$clean, $errors]`（规则见下）。

**会话 / 登录 / CSRF / 限流**（session 惰性开启：普通页面不发 cookie）
- `session(): void`（别动 `_uid _csrf _flash _seen` 键）· `flash(string $key, ?string $msg = null): ?string`
- `csrf_token(): string` · `csrf_field(): string`（放进每个 POST 表单）· `csrf_check(): void`（POST 字段 `_csrf` 或头 `X-CSRF-Token`；从不读查询串）
- `csrf_exempt(string $reason): void` — 声明有意不用 CSRF（sendBeacon、API token）；后台不能豁免。
- `login(int|string $id): void` · `logout(): void`（用 POST + CSRF）· `user(): int|string|null`
- `require_login(string $loginPath = '/login', bool $hidden = false): int|string` — 未登录：JSON → 401，否则跳 `/login?next=…`；`hidden: true` → 与不存在的网址一样的 404。**只管是否登录，权限自己查。**
- `rate_limit(string $key, int $max, int $windowSeconds): void` — 超过 → 429。按 IP 用 `ip_key()`；登录再按账号限流（放在 `password_verify()` 之前）。密码用原生 `password_hash/verify`。

**配置 / 日志 / 常量**
- `config(string $key, mixed $default = null)`（点号路径）· `env(string $key, mixed $default = null)`（先 `.env.php` 再 `getenv`）
- `log_info(string $msg, array $ctx = [])` · `log_error(...)` → `storage/logs/YYYY-MM-DD.log`（JSON 行，带 `rid`）
- `INK_VERSION` `INK_ROOT` `INK_START` `APP_ENV`（dev/prod/test）· `throw new InkUnavailable('…')` → 503

## `validate()` 规则

`required` `email` `int` `numeric` `url`（只 http/https）`bool` `array` `min:N` `max:N` `in:a,b` `regex:/…/`（必须最后一条）

- 字符串先 trim，`$clean` 只含写了规则的字段；非 required 的空字段 → `null`（**没勾选的复选框是 null**，写库用 `$x ? 1 : 0`）。
- `min/max`：字符串按字符数，配 `int/numeric` 按数值，数组按个数。`int` 接受 `'05'`，**不要用它校验网址里的 id**（用 `as_id()`）。
- **密码不要过 `validate()`**，用 `input('password')`。查询串也能校验：`validate($_GET, ['page' => 'int|min:1'])`。

## 常用写法

```php
// htmx：同一网址返回整页或片段（页面内闭包，参数显式传入，不用 use/global）
$list = function (array $rows): void { ?><ul><?php foreach ($rows as $r): ?><li><?= e($r['name']) ?></li><?php endforeach ?></ul><?php };
header('Vary: HX-Request');
if (is_htmx()) { $list($rows); exit; }

// JSON API（pages/api/…，错误自动是 JSON）
if ($errors) json(['errors' => $errors], 422);
json(['id' => (int) db()->lastInsertId()], 201);

// 唯一约束冲突自己接（否则 500）
try { q('INSERT …', […]); } catch (PDOException $e) { if (!str_starts_with((string) $e->getCode(), '23')) throw $e; $errors['email'] = '…'; }

// LIKE：转义通配符；IN：只拼占位符；排序：白名单
$like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';   // … LIKE ? ESCAPE '!'
$in = implode(',', array_fill(0, count($ids), '?'));                       // … IN ($in)，值放 $params
$order = match ($_GET['sort'] ?? '') { 'name' => 'name ASC', default => 'id DESC' };
```

- 链接里的用户网址先 `validate(…, ['u' => 'url'])` 再 `href="<?= e($u) ?>"`；网址的一段用 `rawurlencode`；`<script>` 里的数据用 `json_encode(…, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`；属性必须加引号。
- 内核不管网址里的语言：要每种语言独立网址，就用 `?lang=`、一种语言一个目录，或写死的网址（页面自己选）。

## 配置与数据库

- `config.php`：`env`、`timezone`（默认 UTC，库里存 UTC）、`base_url`、`trust_proxy`（只有源站只接受自己代理时才 true）、`locales`（第一个是默认）、`session_lifetime`、`db.dsn/user/pass`。
- `APP_ENV`：`.env.php` > 环境变量 > `config('env')` > prod。dev 下 warning 直接 500 带调用栈。
- 迁移：`migrations/NNN_描述.sql`，第一行注释写用到这张表的页面；**跑过的文件不改**，加字段就新建文件；`sudo -u www-data php bin/ink migrate`。

## 命令行与测试

| 命令 | 作用 |
|---|---|
| `php bin/ink serve [PORT]` | 开发服务器（APP_ENV=dev） |
| `php bin/ink routes` | 从页面开头的声明读出路由表 |
| `php bin/ink check` | 语法 + 项目规则，有 error 退出码 1 |
| `php bin/ink test [FILE] [--http=URL]` | 跑 `tests/*.txt` 路由表；不加 `--http` 时不运行页面，只推断 |
| `php bin/ink migrate` | 执行新迁移 |

路由表一行一个请求：`METHOD /path -> STATUS [页面文件 | Location] [参数 JSON]`

```
GET  /blog/hello   -> 200 pages/blog.php ["hello"]   # 参数 JSON 只写实际的段，不补 null
GET  /about.php    -> 301 /about
PUT  /signup       -> 405
GET  /blog/0       -> http:404                       # 页面自己决定的结果：前缀 http:，只在 --http 时检查
```

`check` 的主要错误：`params()` 不是第一条语句；`allow()` 不是第二条；用了 `input()/is_post()/$_POST…` 却没 `allow()`；参数可选又允许写方法但只 `allow()` 一次；后台（`pages/admin…`）没 `require_login()` 或写操作没 `csrf_check()`；`layout()` 指向不存在的文件；用了 `partial()`/`partials/`/`$_GET['key1']`；`public/` 里有别的 PHP；`.env.php` 进了 git；`storage/` 不可写。

## 常见陷阱

- 首页写 `params(0, 1)` 会吞掉所有单段网址：首页用 `params();`。
- GET+POST 页面在开头直接写 `csrf_check()` → 每个 GET 都 403。
- 布局里用了页面变量：布局只有 `$vars`，可选变量写 `$title ?? ''`。
- `cache_public()` + `t()`/语言 cookie/`url()`（无 `base_url`）→ 会被降级或缓存了错误内容：多语言可缓存页面要用网址区分语言并固定 `locale()`。
- 给已有 POST 页面加路径参数后，记得 `if ($id !== null) allow('GET');`。
- 用 root 跑 `bin/ink migrate` / 脚本 → `storage/` 里出现 root 文件；用 `sudo -u www-data`。
- 慢操作前若不再写 session，先 `session_write_close()`（同一用户的请求会排队）。
- 命令行脚本放 `bin/`：开头 `require __DIR__ . '/../ink/ink.php';`，然后直接用 `config()/q()/tx()/log_info()`；不要用 `params()/layout()/redirect()`/session。

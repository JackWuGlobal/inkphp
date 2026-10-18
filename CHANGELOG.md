# 更新日志

## 1.0.0-dev（未发布）

1.0 是一次重写，**与 0.x 不兼容**。设计规格见 `docs/SPEC.md`，开发契约见 `AGENTS.md`，部署见 `deploy/README.md`。

### 为什么重写

对 0.x 的审查共核实了 85 个问题，根子集中在几处设计上：

- **路由写在 nginx 里。** 路由靠 `nginx.conf` 中一长串 `if` 和正则捕获实现。nginx 会对捕获组再转义一次，正则 location 的先后顺序会让私有文件规则漏网，还会产生指向外站的跳转。
  这些行为只能在某个 nginx 版本上实测，无法单元测试。`php -S`、Apache、宝塔下的行为也各不相同。
- **页面源码就在网站根目录里。** 任何 `.php` 都能被直接访问和执行，日志、配置备份、`nginx.conf` 都能被下载。
- **隐式行为太多。**
  - "静态源码感知"在运行时扫描页面源码里的 `$_GET['keyN']`，用来推断参数是必填还是可选。
  - 每个请求都无条件开启 session、连接数据库。
  - 未捕获的异常没有统一处理：返回空白 500，或者在 debug 下把调用栈返回给访客。
- **安全默认值不够。**
  - `get_client_ip()` 相信可伪造的 `X-Forwarded-For`。
  - CSRF token 可以从查询串读取。
  - `redirect()` 可以跳到任何地址。
  - 没有安全响应头。
- **文档与代码对不上。** 四份文档互相重复、互相矛盾，引用了不存在的文件（`NGINX.md`、`_core/auth.php`）。

### 主要变化

**路由**
- 路由移到 PHP：`public/index.php` 是唯一入口。规范化和解析是两个纯函数（`ink_canonical()` / `ink_resolve()`），`bin/ink test` 直接调用它们。nginx 只做"有静态文件就返回，否则交给 index.php"。
- 规则不变（"网址 = 文件，从右到左，最深者胜"），但更严格：
  - 只有小写 ASCII 文件名能被路由。
  - 以 `_` / `.` 开头的路径永远不会被路由。
  - 每段只解码一次，`%2F`、`..` 不能用来穿越。
  - 最多 12 段。
- 规范化跳转只跳一次：GET/HEAD 用 301，其他方法用 308（0.x 用 301，POST 的表单数据会丢失）。`Location` 永远是本站相对路径。

**页面**
- 页面移到 `pages/`，在网站根目录之外。`public/` 里只允许 `index.php` 一个 PHP 文件（`bin/ink check` 检查）。
- 页面必须显式声明：`params(min, max)` 声明接受几个路径参数，`allow(...)` 声明接受哪些请求方法。没声明的参数 → 404，没声明的方法 → 405。
- 布局只在页面调用 `layout()` 时使用，并且在隔离的作用域里渲染。**没有跨页面共享的 HTML 片段**（没有 `partial()`、`partials/`）：一个页面要用的 HTML 都写在这个页面里，同一页面内复用写成页面内闭包。
- 输出全部缓冲：页面任何时候都能设置响应头。

**内核（`ink/`，替代 `_core/`）**
- 一个只有 46 个公开函数的内核，零依赖：`params allow as_id` · `input json redirect abort is_post is_htmx wants_json host is_https url client_ip ip_key cache_public store_upload upload_error asset` ·
  `e layout t locale href locale_urls` · `db q one all val tx validate` · `session flash csrf_* login logout user require_login rate_limit` · `config env log_info log_error`。
- **多语言网址**：`config('locale_url')` 选 `'prefix'`（`/en/about`）、`'query'`（`?lang=en`）或 `false`；同一个页面文件服务所有语言，`href()` 生成带语言的链接，`locale_urls()` 输出语言切换和 hreflang；`locale_exempt` 列出不参与多语言的网址（后台、登录、API）；`bin/ink check` 提示丢语言的链接和缺语言的 `t()`。
- **去掉 Medoo**，改为 PDO 辅助函数 `q/one/all/val/tx`。只写参数化的原生 SQL，PostgreSQL 的 `RETURNING`、`::jsonb` 等都能直接用。
- 数据库和 session 都是惰性的：用到才连接、才开启。普通页面不发 cookie，可以被 CDN 缓存（`cache_public()`）。

**安全**
- 默认安全响应头（`nosniff`、`Referrer-Policy`、`X-Frame-Options`、`Cache-Control: private, no-cache`），去掉 `X-Powered-By`。
- `csrf_check()` 只从 POST 字段 `_csrf` 或 `X-CSRF-Token` 头读 token，失败直接 403。`login()` 会更换 session id 和 CSRF token。
- session cookie：HttpOnly、SameSite=Lax、https 下 `__Host-` 前缀 + Secure。
- `redirect()` 只跳本站。
- `client_ip()` 只读 `REMOTE_ADDR`，真实 IP 由 nginx `real_ip` 提供。
- `host()` 校验 Host 头。
- `store_upload()`：白名单类型 + 真实 MIME + 随机文件名。
- 新增 `rate_limit()` 和 `ip_key()`（限流 key：IPv6 按 /64 算）。示例登录页同时按 IP 和按账号限流。
- 网址规范化也统一 `%XX`：不需要编码的字符被解码（`/%61bout` → `/about`），其他转义统一大写，每个页面只有一个网址。
- session 闲置超时在每次开启时检查（不再依赖 1% 概率的 GC）；乱写或过期的 session cookie 不会建 session 文件。
- `bin/ink check` 直接读 `.git/index` 判断 `.env.php` 是否被提交，不运行 git（不会执行仓库配置里的命令）。
- `deploy/` 给 `/uploads/` 加 `nosniff` 和 `Content-Security-Policy: sandbox`。

**错误与日志**
- 统一的错误/异常/致命错误处理：生产环境不显示细节，返回 500，数据库暂时不可用（连接断开、死锁、锁超时）返回 503；`dev` 下显示调用栈；`/api` 下返回 JSON。
- 日志是每行一个 JSON，时间用 UTC，带请求 id（设了 `trust_proxy` 时用 Cloudflare 的 `CF-Ray`），文件在 `storage/logs/YYYY-MM-DD.log`，目录会自动创建。

**配置**
- `_config.php` 拆成 `config.php`（进 git，不放机密）和 `.env.php`（机密，不进 git）。
- `debug` 改为 `APP_ENV`（`dev`/`prod`/`test`，默认 `prod`）。
- 时区默认 UTC。

**多语言**
- `zh.php` / `en.php` / `zh-hant/` / `all_language/` 加 `$lang` 的写法，改为页面里就地写的 `t(['zh' => …, 'en' => …])` 加 `locale()`（`?lang=` → cookie → Accept-Language）。

**命令行（新增）**
- `bin/ink serve | routes | check | test | migrate`。
  - `check` 会检查页面规则，例如 `params()` 必须是第一句、POST 页面必须调用 `allow()` 和 `csrf_check()`、后台页面必须调用 `require_login()`、不能用 `$_GET['keyN']`、`public/` 里不能有 `.php`。
  - `test` 运行 `tests/*.txt` 路由表，可以在进程内运行，也可以对运行中的服务器发 HTTP 请求。

**部署**
- 222 行的 `nginx.conf`（完整的 nginx 主配置，带开发者本机路径）换成 `deploy/nginx.conf`：只有一个 `server {}` 块，路由规则一条都没有。
- 新增宝塔伪静态规则 `deploy/baota.conf`、Apache 的 `public/.htaccess`。
- 新增部署指南 `deploy/README.md`，覆盖 Cloudflare 真实 IP、独立 FPM 池、目录权限。

**文档**
- `inkphp.md`、`inkphp-ARCHITECTURE.md`、`inkphp-DEVELOPMENT.md` 和旧 `README.md` 合并为：`AGENTS.md`（完整契约）、`README.md`（快速上手）、`docs/SPEC.md`（规格）、`deploy/README.md`（部署）。

**删除**
- `_core/`（`init.php`、`logger.php`）、`_errors/`、`nginx.conf`、Medoo。
- 0.x 根目录下的调试和示例页面：`debug.php`、`debug_route.php`、`raw.php`、`test.php`、`p.php`、`search.php`、`blueblueadmin.php`、`testdir/`、`1.txt`、`1.jpg`、`index.html`。
- `upload/`、`assets/css/tailwind.css`、旧的多语言目录。
- 静态源码感知、`$_GET['keyN']`、无条件的 session 和数据库连接。

### 从 0.x 迁移

1. **搬文件。**
   - 页面移到 `pages/`，路径不变：`about.php` → `pages/about.php`，`admin/user/add.php` → `pages/admin/user/add.php`。
   - 静态文件移到 `public/assets/`，上传文件从 `upload/` 移到 `public/uploads/`，错误页 `_errors/*.html` 合并为 `layouts/error.php`（变量 `$code`、`$message`、`$rid`）。
   - 文件名必须小写。
   - 目录里的 `index.php` 不会被路由：`pages/x/index.php` 要改名为 `pages/x.php`。
2. **删掉每个页面开头的 `require_once '…/_core/init.php';`**。内核由 `public/index.php` 加载，页面直接调用函数。
3. **在页面开头加声明**：第一行 `params(…)`，处理 POST 的页面第二行加 `allow('GET', 'POST')`。
4. **把下表左边的写法换成右边的**，然后运行 `php bin/ink check`，它会指出遗漏的 `$_GET['keyN']`、缺失的 `allow()` / `csrf_check()` 等。
5. **配置**：把 `_config.php` 里的数据库连接写进 `config.php` 的 `db`，用户名和密码放进 `.env.php`。`'debug' => true` 改为 `.env.php` 里的 `'APP_ENV' => 'dev'`（仅限本机）。
6. **部署**：网站根目录改为 `public/`，nginx 换成 `deploy/nginx.conf`（见 `deploy/README.md`）。原来 nginx 里的路由规则全部删掉。

| 0.x | 1.0 |
|---|---|
| `require_once '_core/init.php';` | 删除 |
| `$id = $_GET['key1'];`（必填） | `[$id] = params(1);` |
| `$tab = $_GET['key2'] ?? 'info';`（可选） | `[$id, $tab] = params(1, 2); $tab ??= 'info';` |
| 靠"静态源码感知"推断参数个数 | `params(min, max)` 显式声明；不声明 = 不接受参数 |
| 路径参数 `'0'` 被当作空值 | `'0'` 是合法参数 |
| `$db->select('t', '*', ['status' => 'on'])` | `all('SELECT * FROM t WHERE status = ?', ['on'])` |
| `$db->get('t', '*', ['id' => $id])` | `one('SELECT * FROM t WHERE id = ?', [$id])` |
| `$db->count('t', ['status' => 'on'])` | `val('SELECT COUNT(*) FROM t WHERE status = ?', ['on'])` |
| `$db->insert('t', ['a' => 1]); $db->id()` | `q('INSERT INTO t (a) VALUES (?)', [1]); db()->lastInsertId()` |
| `$db->update('t', ['a' => 1], ['id' => $id])` | `q('UPDATE t SET a = ? WHERE id = ?', [1, $id])` |
| `$db->delete('t', ['id' => $id])` | `q('DELETE FROM t WHERE id = ?', [$id])` |
| `$db->action(function () { … })` | `tx(function (PDO $db) { … })` |
| `json_response($data, $code)` | `json($data, $code)` |
| `success($data, $msg)` / `error($msg, $code)` | `json(['data' => $data])` / `json(['error' => $msg], $code)`，或者 `abort($code, $msg)` |
| `is_method('POST')` / `request_method()` | `is_post()` / `$_SERVER['REQUEST_METHOD']`，并用 `allow()` 声明 |
| `is_ajax()` | `is_htmx()` / `wants_json()` |
| `current_url()` | `url($path)`（Host 已校验） |
| `csrf_field()` 生成字段 `csrf_token` | `csrf_field()` 生成字段 `_csrf` |
| `if (!csrf_verify()) die(…);` | `csrf_check();`（失败自动 403；不再接受查询串里的 token） |
| `$errors = validate($data, $rules);` | `[$clean, $errors] = validate($data, $rules);`（使用 `$clean`，它已 trim 并转换类型） |
| `flash()` 默认键 `'message'` | `flash('ok', $msg)` / `flash('ok')`，键必须写 |
| `abort(404)` 读 `_errors/404.html` | `abort(404, '可选的提示')`，渲染 `layouts/error.php` |
| `redirect($anyUrl)` | `redirect('/local/path')` 只跳本站；外站：`header('Location: …'); exit;` |
| `e($str)` | `e($v)`（多了 `ENT_SUBSTITUTE`，数组和 `null` 返回 `''`） |
| `dd($var)` | 删除；dev 下用 `var_dump()`，或 `log_info('…', ['v' => $var])` |
| `get_client_ip()` | `client_ip()`（只读 `REMOTE_ADDR`；Cloudflare 后面要配 nginx `real_ip`） |
| `log_visitor()` 等 logger.php 函数 | 删除；需要时用 `log_info()` |
| `DEBUG` 常量 | `APP_ENV === 'dev'` |
| 写死的 `Asia/Shanghai` 时区 | `config('timezone')`，默认 UTC |
| `$lang` + `zh.php` / `en.php` + `include all_language/x.php` | 一个页面，文字写成 `t(['zh' => …, 'en' => …])`，语言由 `locale()` 决定（`?lang=en`）。需要 `/en/…` 这类网址时：`pages/en.php` 用 `params(0, N)` 接住后面的段并调用 `locale('en')`，或者在 `pages/en/*.php` 里各自调用 `locale('en')`。注意 `locale($x)` 会写 `lang` cookie，之后访问不带前缀的页面也会是这种语言，而且这些响应不能被 `cache_public()` 缓存 |
| `require_login()` / `require_role()`（0.x 引用了但仓库里没有 `auth.php`） | `require_login()` 返回用户 id；角色自己查，`abort(403)` |
| 每个请求都 `session_start()` | 只有 `session()`、`flash()`、`csrf_*`、`login()`、`user()` 等用到时才开启 |
| `_` 开头的私有文件靠 nginx 返回 403 | 私有文件放在 `pages/` 以外（如 `layouts/`、`lib/`），或以 `_` 开头（永远不会被路由，返回 404） |

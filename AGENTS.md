# AGENTS.md — inkphp 1.0 开发契约

给 AI 编程助手（和人）的完整说明：**读完本文件就能正确地写功能，不需要读内核源码。**
本文件与 `docs/SPEC.md`（设计规格）一致；两者有冲突时以 SPEC 为准，并且应当修正其中一方。

---

## 0. 一分钟版本

- **一个网址 = 一个文件 = 一个功能。** `/signup` 就是 `pages/signup.php`。参数、请求方法、登录、CSRF、校验、SQL、HTML、POST/htmx 处理，全部写在这一个文件里。
- **不调用就不运行。** 没有中间件、守卫文件、自动加载、全局钩子。页面需要什么就自己调用什么。内核只自动做四件事：路由、收尾检查（未声明的路径参数 → 404、未声明的方法 → 405、套布局、`cache_public()` 降级）、错误处理、默认安全响应头。
- **页面固定骨架：** `params()` → `allow()` → `require_login()` → `csrf_check()` → 业务逻辑 → `layout()` → HTML。
- **输出一律 `e()`，SQL 一律 `?` 占位符，写操作一律 `csrf_check()`。**
- 零依赖、零构建：没有 Composer、npm，也没有 ORM 和模板引擎。数据库只用 PDO 辅助函数（`q/one/all/val/tx`）。
- 改完运行 `php bin/ink check`，它会检查本文件里能自动检查的规则。

```php
<?php
// pages/hello.php → /hello/<name>
[$name] = params(1);                       // 恰好 1 个路径参数，否则 404
layout('site', ['title' => 'Hello']);
?>
<h1><?= e(t(['zh' => '你好，{n}', 'en' => 'Hello, {n}'], ['n' => $name])) ?></h1>
```

---

## 1. 原则

1. **一个网址 = 一个文件 = 一个功能。** 读一个页面文件就能知道这个网址的全部行为。不要把一个功能拆到多个文件里，也不要让一个文件服务彼此无关的功能。
2. **没有隐式执行。** 页面的每个依赖都是页面里一次显式的调用。不准添加守卫文件、中间件、`auto_prepend_file`、"每个请求都会 include 的文件"或运行时扫描源码。
3. **默认安全。** 最短的写法就是安全的写法：`e()` 转义，参数化 SQL，`csrf_field()`/`csrf_check()`，只跳本站的 `redirect()`，页面源码不在网站根目录，上传文件永远不会被执行。
4. **小。** Web 内核（`ink/` 下除 `cli.php` 外的文件）约 1000 行（不算空行和注释约 830 行）、44 个公开函数；命令行 `ink/cli.php` 约 570 行，网页请求从不加载它。整个放得进 AI 的上下文。
5. **零依赖，零构建。** PHP ≥ 8.2（目标 8.4）。部署 = 复制文件 / `git pull`。

**不做**：MVC、ORM/查询构造器（没有 Medoo）、插件、主题、中间件、依赖注入容器、事件、模板引擎。不要把它们加回来。

---

## 2. 目录结构

```
inkphp/
├── public/                 # 唯一的网站根目录（docroot）
│   ├── index.php           # public/ 里唯一的 .php：前端控制器，也是 php -S 的路由脚本
│   ├── .htaccess           # Apache / 虚拟主机用
│   ├── assets/             # css/js/图片；htmx.min.js（htmx 2.0.11，见 assets/VENDOR.txt）
│   └── uploads/            # store_upload() 保存的文件（不进 git）
├── pages/                  # 网址 = 文件。在网站根目录之外，不能被直接访问
├── layouts/                # 页面外框，只在页面调用 layout('name') 时使用；error.php = 错误页
├── migrations/             # NNN_name.sql，php bin/ink migrate 按文件名顺序执行
├── ink/                    # 内核（ink.php 按顺序加载其余文件；cli.php = bin/ink）
├── bin/ink                 # 命令行：serve / routes / check / test / migrate
├── storage/                # logs/ sessions/ ratelimit/ db.sqlite（运行时数据，不进 git）
├── tests/                  # 路由表 *.txt，php bin/ink test 执行
├── deploy/                 # nginx.conf、baota.conf、README.md（部署指南）
├── config.php              # 配置（进 git，不放机密）
├── .env.php.example        # 机密模板；复制为 .env.php（不进 git）
├── AGENTS.md               # 本文件
├── README.md               # 给人看的快速上手
├── CHANGELOG.md
└── docs/SPEC.md            # 设计规格
```

新增功能 = 在 `pages/` 下新建一个文件（必要时加一个 `migrations/NNN_*.sql`）。几乎不需要碰其他地方。
**修改**已有的功能 = 改它那一个页面文件。只有改表结构时要多动几个文件（新的迁移 + 每个读写这张表的页面，见 §12）。

**不共享 HTML 片段**：inkphp 没有 `partial()`，也没有 `partials/` 目录。一个页面需要的 HTML 全部写在这个页面里；
两个页面显示类似的东西（例如都列出留言），就**各写一份**。少量重复换来的是：改一个页面永远不会弄坏另一个页面。
同一个页面里要输出两次的片段（例如整页和 htmx 刷新），写成**页面内片段**（§6.4）。
唯一共享的模板是 `layouts/`（页面外框），它只通过 `layout()` 拿到明确传入的变量。
确实要共享的 PHP 函数（很少需要，例如金额格式化），放在 `pages/` 以外的目录（例如 `lib/money.php`），
由用到它的页面在骨架声明之后显式写 `require_once INK_ROOT . '/lib/money.php';`。不要做任何自动加载。

---

## 3. 一个请求的经过（内核自动做的事）

```
nginx/Apache：public/ 里存在这个文件（且不是 .php）→ 直接返回；否则 → public/index.php
public/index.php → require ink/ink.php（启动）→ require ink_route()（运行页面）→ ink_finish()
```

1. **启动**：注册错误/异常处理；开启输出缓冲；发送默认安全头；读取 `.env.php` 和 `config.php`；确定 `APP_ENV`；设置时区。
2. **规范化跳转**：网址不规范就 301（GET/HEAD）或 308（其他方法）到规范形式，只跳一次（§4.1）。
3. **解析**：从右到左找最深的页面文件，剩余的段作为路径参数（§4.2）。找不到 → 404。
4. **运行页面**：在**全局作用域**里 `require` 页面文件。页面的所有输出都先进缓冲区，所以页面任何时候都可以调用 `header()`、`setcookie()`、`http_response_code()`，不会出现 "headers already sent"。
5. **收尾**（`ink_finish()`，页面正常结束、`exit`、`json()`、`redirect()` 都会走到这里）：
   - 有路径参数但页面从没调用 `params()` → 丢弃输出，404。
   - 不是 GET/HEAD 但页面从没调用 `allow()` → 丢弃输出，405。
   - 调用过 `layout()` 且响应不是 `json()`/`redirect()` → 把页面输出作为 `$content` 套进布局。
   - 设了 `cache_public()`，但本次请求开过 session、有待发送的 Set-Cookie，或者 `locale()` 的结果来自 cookie / `Accept-Language` / 默认语言 → 降级为 `private, no-cache` 并记 `warn` 日志。
6. **错误**：未捕获的异常或致命错误会丢弃全部输出，记一行日志（带请求 id），返回 500（数据库暂时不可用时返回 503，§10）。生产环境不显示任何细节（§10）。

注意：第 5 步的 404/405 只是兜底，**页面代码已经执行过了**。所以凡是处理 POST 的页面，都必须在第二行调用 `allow()`，这样别的方法在执行任何逻辑之前就会被拒绝。

---

## 4. 路由

### 4.1 规范化（一次 301/308，绝不连跳）

| 请求 | 结果 |
|---|---|
| `/about.php` | 301 → `/about`（只去掉**最后一段**末尾的 `.php`） |
| `/about/`、`//about`、`/./about` | 301 → `/about` |
| `/index`、`/index.php` | 301 → `/`（只去掉**第一段**的 `index`） |
| `/index/5` | 301 → `/5` |
| `/about.php?x=1` | 301 → `/about?x=1`（查询串原样保留） |
| `POST /about/` | 308 → `/about`（308 保留方法和请求体） |
| `/a/../b`、`/a/%2e%2e/b` | 400 |
| `/%61bout`、`/abou%74` | 301 → `/about`（不需要编码的字符 `A-Z a-z 0-9 . _ ~ -` 的 `%XX` 会被解码） |
| `/search/%e4%b8%ad` | 301 → `/search/%E4%B8%AD`（其他 `%XX` 统一成大写） |

所以每个页面只有一个网址。`Location` 永远是本站相对路径，不会出现 `//evil.com` 或 `/\evil.com`。

### 4.2 解析（从右到左，最深者胜）

- 规范路径按 `/` 切段，每段 `rawurldecode` **一次**。
- 段解码后是 `.`、`..`、含 `/` `\` NUL 或控制字符、或不是合法 UTF-8 → 404。超过 12 段 → 404。
- 能当作文件路径一部分的段（"路由名"）必须匹配 `^[a-z0-9][a-z0-9._-]*$`（小写 ASCII），并且不等于 `index`；另外 `.well-known` 也算合法的路由名。
  以 `_` 或 `.` 开头的文件和目录**永远不会被路由**。
- 从最长的前缀开始试：`pages/a/b/c.php` → `pages/a/b.php` → `pages/a.php`，第一个存在的文件胜出，**剩下的段就是路径参数**（字符串）。
- 都不存在 → `pages/index.php`，所有段都是它的参数。`/` → `pages/index.php`，没有参数。
- **路径参数不会放进 `$_GET`。** `$_GET` 只有查询串。

假设有这些页面：`index.php`（`params()`）、`about.php`、`blog.php`（`params(0, 2)`）、`blog/new.php`、`admin/users.php`（`params(0, 1)`）、`robots.txt.php`：

| URL | 文件 | `params()` 收到的参数 |
|---|---|---|
| `/` | `pages/index.php` | `[]` |
| `/about` | `pages/about.php` | `[]` |
| `/blog` | `pages/blog.php` | `[]` |
| `/blog/hello-world` | `pages/blog.php` | `['hello-world']`（没有 `blog/hello-world.php`） |
| `/blog/new` | `pages/blog/new.php` | `[]`（更深的文件胜出） |
| `/blog/2026/10` | `pages/blog.php` | `['2026', '10']` |
| `/blog/a/b/c` | `pages/blog.php` | 3 个参数 > max 2 → **404** |
| `/admin/users/7` | `pages/admin/users.php` | `['7']` |
| `/admin` | 没有 `pages/admin.php` → `pages/index.php` | `['admin']` → `params()` 只收 0 个 → **404** |
| `/robots.txt` | `pages/robots.txt.php` | `[]`（`public/robots.txt` 若存在则由 web 服务器直接返回，优先） |
| `/Blog` | 大写不是路由名 → `pages/index.php` | `['Blog']` → 404 |
| `/%E4%B8%AD%E6%96%87` | `pages/index.php` | `['中文']` → `params()` 只收 0 个 → **404** |
| `/blog/%E4%B8%AD%E6%96%87` | `pages/blog.php` | `['中文']`（每段只解码一次） |
| `/blog/a%2Fb` | — | 404（解码后含 `/`） |

要点：

- `x.php` 和目录 `x/` 可以并存：`/x` → `x.php`，`/x/y` → `x/y.php`（存在时），否则 `x.php` 收到参数 `['y']`。
- **目录里的 `index.php` 不会被路由**（只有根目录的 `pages/index.php` 是首页）。`/blog` 对应 `pages/blog.php`，不是 `pages/blog/index.php`。
- `pages/index.php` 是所有"找不到的单段网址"的兜底。它要是写成 `params(0, 1)`，`/任何东西` 都会返回 200，所以首页通常写 `params();`。
- 文件名一律小写：`pages/user-profile.php` → `/user-profile`。
- 动态"静态文件"：`pages/robots.txt.php` → `/robots.txt`，`pages/sitemap.xml.php` → `/sitemap.xml`，`pages/.well-known/security.txt.php` → `/.well-known/security.txt`。页面自己 `header('Content-Type: …')`。
- `/assets/…`、`/uploads/…` 留给静态文件，不要创建 `pages/assets.php` 或 `pages/uploads.php`。

### 4.3 `params()` 和 `allow()` 是页面的"声明"

```php
[$id] = params(1);            // 恰好 1 个参数
[$id, $tab] = params(1, 2);   // 1~2 个；不足 max 时用 null 补齐：/post/5 → ['5', null]
params();                     // 不接受任何路径参数（最常见）
$all = params(0, 12);         // 0~12 个，全拿
allow('GET', 'POST');         // 只接受这些方法；GET 自动包含 HEAD；其他方法 → 405 + Allow 头
```

- 参数个数不在 `[min, max]` 内 → 404。`'0'` 是合法参数。参数永远是字符串（或补齐的 `null`），**内容任意，必须自己校验**。
  数据库 id 一律用 `as_id()` 转换（只接受规范的正整数，`/x/01`、`/x/0`、`/x/+1`、`/x/abc` 都是 404，一条记录只有一个网址）：
  ```php
  [$raw] = params(1);
  $id = as_id($raw);                 // int；不合法 → 404。可选参数传 null 进去会得到 null
  ```
  不要自己写 `ctype_digit($id)` 或 `(int) $id`（`'01'`、`'1abc'` 都会混过去）。
- 必须 `0 ≤ min ≤ max ≤ 12`（网址最多 12 段），否则 `LogicException`。
- 不调用 `params()` 的页面只接受 0 个参数（在收尾时检查）。
- 不调用 `allow()` 的页面只接受 GET/HEAD（在收尾时检查）。
- **参数可选的页面，要分别声明两种网址接受哪些方法。** `params(0, 1)` 加上 `allow('GET', 'POST')`，意味着 `/x` 和 `/x/5` 都接受 POST，
  原本写给 `/x` 的写操作在 `POST /x/5` 时也会执行。所以再调用一次 `allow()`，收窄带参数的网址：
  ```php
  [$raw] = params(0, 1);             // /notes 或 /notes/<id>
  allow('GET', 'POST');              // /notes：列表 + 新建
  $id = as_id($raw);
  if ($id !== null) allow('GET');    // /notes/<id>：只读；POST → 405（带正确的 Allow 头）
  ```
  带参数的网址确实也要接受写入时，照样明确写出来：`if ($id !== null) allow('GET', 'POST');`。
  `bin/ink check` 会报错：参数可选（min < max）、又允许了 GET 以外的方法，但整个页面只调用了一次 `allow()`。
- `bin/ink check` 要求：`params()` 是**第一条语句**；用了 `allow()` 时它必须是**第二条语句**（紧跟 `params()`，在 `require_login()`、`csrf_check()`、`layout()` 之前）；参数用字面量（`params(1, 2)`、`allow('GET', 'POST')`），这样 `bin/ink routes` 才能读出路由表。
  顺序会影响结果：`allow()` 写在 `require_login()` 后面时，未登录的 POST 得到的是收尾时的 405，而不是跳转登录。

---

## 5. 页面骨架

```php
<?php
// pages/things.php → /things、/things/{id}   一句话说明这个页面做什么
[$id] = params(0, 1);                       // 1. 第一条语句：路径参数
allow('GET', 'POST');                       // 2. 允许的方法（只读页面可以不写，默认 GET/HEAD）
$uid = require_login();                     // 3. 需要登录时（未登录：网页跳 /login，/api 返回 401 JSON）

if (is_post()) {
    csrf_check();                           // 4. 每个写操作先校验 CSRF（放在 is_post() 里面，否则 GET 也会 403）
    [$in, $errors] = validate(input(), ['title' => 'required|max:100']);
    if (!$errors) {
        q('INSERT INTO things (user_id, title) VALUES (?, ?)', [$uid, $in['title']]);
        redirect('/things', 303);           // 5. 写成功后跳转（PRG），刷新不会重复提交
    }
}

$rows = all('SELECT id, title FROM things WHERE user_id = ? ORDER BY id DESC', [$uid]);
layout('site', ['title' => 'Things']);      // 6. 套布局（可选）
?>
<!-- 7. HTML：每个变量都 e() -->
<ul>
<?php foreach ($rows as $r): ?>
    <li><?= e($r['title']) ?></li>
<?php endforeach ?>
</ul>
```

规则：

- 声明的顺序固定：`params` → `allow` → `require_login` → `csrf_check`（只允许 POST 的页面可以直接写在开头；GET+POST 页面写在 `if (is_post())` 里）。`layout()` 可以写在 `allow()` 之后的任何地方（开头的声明里，或者 HTML 之前），但不能写在 `allow()` 前面。
- 只读页面：`params(); layout('site');` 然后就是 HTML。
- 页面可以随时 `exit`、`return`，也可以调用 `json()`、`redirect()`、`abort()`，内核都会正确收尾。
- 页面在全局作用域运行，但**布局和局部模板看不到页面的变量**，只能看到传进去的 `$vars`。
- 不要在页面里定义同名函数（`function e()`、`function db()`）。页面里的小工具用闭包：`$money = fn (int $c): string => number_format($c / 100, 2);`。

---

## 6. 函数参考

下面列出全部公开函数，签名与 `ink/*.php` 完全一致。以 `ink_` 开头的函数都是内核内部函数，页面不要调用。

### 6.1 路由

**`params(int $min = 0, ?int $max = null): array`**
声明并取得路径参数。`$max` 默认等于 `$min`。个数不在范围内 → 404。返回值用 `null` 补齐到 `$max` 个。`$max < $min` → `LogicException`。
```php
[$slug] = params(1);                 // /blog/hello → 'hello'
[$year, $month] = params(0, 2);      // /archive → [null, null]；/archive/2026 → ['2026', null]
```

**`allow(string ...$methods): void`**
请求方法不在列表里 → 立即 405，并带 `Allow:` 头。`GET` 自动包含 `HEAD`；方法名不区分大小写。
可以再调用一次来收窄某种网址（§4.3）；每次调用都单独检查。
```php
allow('GET', 'POST');
allow('POST');                       // 只处理表单提交 / API 写入的页面
if ($id !== null) allow('GET');      // 带参数的网址只读
```

**`as_id(?string $v): ?int`**
把路径参数转成记录 id：`null` → `null`；规范的正整数（`'42'`）→ `42`；其他（`'01'`、`'0'`、`'-1'`、`'1e3'`、`'abc'`、超过 18 位）→ 404。
```php
[$raw] = params(1);
$row = one('SELECT * FROM notes WHERE id = ?', [as_id($raw)]) ?? abort(404);
```

### 6.2 请求

**`is_post(): bool`** — 请求方法是否为 POST。
```php
if (is_post()) { csrf_check(); /* … */ }
```

**`is_htmx(): bool`** — 是否 htmx 请求（`HX-Request: true`）。
```php
if (is_htmx()) { $list($rows); exit; }   // 只回片段（$list 是本页的页面内片段，§6.4）
```

**`wants_json(): bool`** — `Accept` 含 `application/json`，或路径是 `/api` / 以 `/api/` 开头。`abort()`、错误页、`require_login()` 用它决定回 JSON 还是 HTML。
```php
if (wants_json()) json($data);
```

**`input(?string $key = null, mixed $default = null): mixed`**
请求体：`Content-Type` 为 `application/json` 或 `*+json` 时是解码后的 JSON（坏 JSON、或 JSON 不是对象/数组 → 400；空请求体 → `[]`），否则是 `$_POST`。
`$key` 给出时返回该字段（不存在或为 `null` 时返回 `$default`）。**值是原样的，没有 trim，也没有校验**，请交给 `validate()`。
```php
[$in, $errors] = validate(input(), ['email' => 'required|email']);
$password = input('password');       // 密码：直接取原值，不要 trim
```
注意：PHP 只解析 POST 的表单请求体。PUT/PATCH/DELETE 发表单编码数据时 `input()` 是 `[]`，所以写操作请用 POST（htmx 用 `hx-post`），或者发 JSON。

**`host(): string`** — 小写的 Host（去掉端口和末尾的点）。不是合法域名或 `[IPv6]` → 400。
```php
if (host() === 'admin.example.com') { /* … */ }
```

**`is_https(): bool`** — `$_SERVER['HTTPS']` 为 on、或端口 443；`config('trust_proxy') === true` 时也相信 `X-Forwarded-Proto` / `CF-Visitor`。
```php
$scheme = is_https() ? 'https' : 'http';
```

**`url(string $path = '/'): string`** — 绝对网址。设了 `config('base_url')` 就用它，否则用 `scheme://host()`（端口不是默认端口时带上端口）。用在邮件、canonical、OG 标签这类需要完整网址的地方；站内链接直接写 `/path`。
```php
<link rel="canonical" href="<?= e(url('/blog/' . rawurlencode($slug))) ?>">
```

**`client_ip(): string`** — 只返回 `REMOTE_ADDR`，**从不读 `X-Forwarded-For`**。在 Cloudflare/反向代理后面，要由 web 服务器（nginx `real_ip`）设置真实 IP，见 `deploy/README.md`。
```php
log_info('signup', ['ip' => client_ip()]);
```

**`ip_key(): string`** — 给 `rate_limit()` 当 key 用的访客 IP：IPv4 原样返回；IPv6 只取前 64 位（`2001:db8:1:2::/64`），因为一台机器通常拥有整个 /64，可以无限换地址；`::ffff:1.2.3.4` 返回 `1.2.3.4`。**按 IP 限流一律用 `ip_key()`，不要用 `client_ip()`。**
```php
rate_limit('login:' . ip_key(), 5, 300);
```

### 6.3 响应

**`json(mixed $data, int $code = 200): never`**
丢弃页面已有输出，不套布局，发送 `Content-Type: application/json; charset=utf-8` 和 `Cache-Control: no-store`，然后结束。中文不转义，非法 UTF-8 替换为 U+FFFD，`dev` 环境下缩进排版。
```php
json(['id' => 42], 201);
json(['errors' => $errors], 422);
```

**`redirect(string $to, int $code = 302): never`**
跳转并结束。`$code` 只能是 301、302、303、307、308（其他值 → `LogicException`）。**只接受本站路径**（以 `/` 开头，不能是 `//` 或 `/\`，不能含控制字符）。其他目标（`https://…`、`//evil.com`、相对路径）会记一条 `warn` 日志，然后跳到 `/`。
所以把用户给的 `?next=` 交给它是安全的。表单 POST 成功后用 303。确实要跳到外站时，自己写 `header('Location: https://…', true, 302); exit;`（刻意不封装）。
```php
redirect('/things', 303);
$next = $_GET['next'] ?? '/';
redirect(is_string($next) ? $next : '/');
```

**`abort(int $code, string $message = ''): never`**
丢弃输出，返回错误响应并结束。`wants_json()` 时返回 `{"error":404,"message":"Not Found"}`，否则渲染 `layouts/error.php`（变量 `$code`、`$message`、`$rid`），没有这个文件就用内置页面。
`$message` 为空时使用英文状态文本（Not Found 等）。**`$message` 在生产环境也会显示给用户**（已转义），不要放内部信息。503 会自动加 `Retry-After: 30`。
页面在调用前设置的响应头（除 Set-Cookie 外）会被丢弃，然后重新加上默认安全头。所以页面自己 `abort(405)` 发不出 `Allow:` 头：方法只在部分网址上有效时用 `abort(404)`（§4.3）。
```php
$post = one('SELECT * FROM posts WHERE id = ?', [$id]) ?? abort(404);
if ($post['status'] !== 'published') abort(403, t(['zh' => '文章还没有发布', 'en' => 'Not published yet']));
```

**`cache_public(int $seconds): void`**
允许浏览器和 CDN 缓存这个响应：`Cache-Control: public, max-age=min(60,$seconds), s-maxage=$seconds`。默认所有响应都是 `private, no-cache`。
收尾时自动降级为 `private, no-cache` 并记日志的情况：本次请求开过 session；要发 Set-Cookie（包括 `locale()` 写 `lang` cookie）；`locale()`（包括 `t()`、套了 `layouts/site.php`）的结果来自 cookie、`Accept-Language` 或默认语言——这些都因访客而异。
只用在**对所有访客都一样**的页面上：不用 `t()`（或者先用 `locale('en')` 固定语言），不输出 `url()`（它用请求的 Host 头，除非设了 `config('base_url')`），不输出任何来自请求头的内容。
```php
params();
cache_public(300);                   // 公开页面：CDN 缓存 5 分钟
```

**`store_upload(string $field, array $types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], int $maxBytes = 5_000_000): ?string`**
保存 `$_FILES[$field]` 到 `public/uploads/<32 位随机十六进制>.<扩展名>`，返回网址路径（`/uploads/…`）。失败返回 `null`，原因用 `upload_error()` 取。
检查项：`is_uploaded_file`、大小、原文件扩展名在 `$types` 里，**并且** finfo 检测出的真实 MIME 与该扩展名匹配。
`$types` 只能取：`jpg jpeg png gif webp avif pdf txt csv mp3 mp4 zip`。写了别的（`php`、`svg`、`html` …）属于程序错误，会抛 `LogicException`。
每个字段只能有一个文件（`name="photo"`，不支持 `name="photos[]"`）。表单要写 `enctype="multipart/form-data"`。
上传的文件和网站同源。`deploy/` 里的配置给 `/uploads/` 加了 `nosniff` 和 `Content-Security-Policy: sandbox`，但 finfo 只看文件头，构造过的文件（例如 PDF 头 + HTML）仍然能通过。访客上传的文件最好用单独的域名提供；不需要在浏览器里打开的类型（pdf、zip、csv）让用户下载（经页面发 `Content-Disposition: attachment`）。
```php
$path = store_upload('avatar', ['jpg', 'png', 'webp'], 2_000_000);
if ($path === null) $errors['avatar'] = upload_error();
else q('UPDATE users SET avatar = ? WHERE id = ?', [$path, $uid]);
```

**`upload_error(): ?string`** — 上一次 `store_upload()` 失败的原因，是当前语言（zh/en）的一句话，例如"文件太大""不支持的文件类型"。没有失败时为 `null`。

### 6.4 视图

**`e(mixed $v): string`**
HTML 转义（`htmlspecialchars`，`ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`，UTF-8），文本和属性值都能用。`null`、数组和不能转成字符串的对象返回 `''`；`true` 返回 `'1'`，`false` 返回 `''`。
```php
<a href="/u/<?= e(rawurlencode($name)) ?>" title="<?= e($title) ?>"><?= e($name) ?></a>
```
`e()` 只管 HTML 文本和**带引号的**属性。其他上下文另行处理，见 §9.3。

**`layout(?string $name, array $vars = []): void`**
页面结束时，把页面输出作为 `$content`，渲染 `layouts/<name>.php`。布局只能看到 `$vars` 和 `$content`（`$content` 总是页面输出，`$vars['content']` 无效）。
最后一次调用生效；`layout(null)` 取消（例如 htmx 片段）。响应是 `json()`/`redirect()`/错误时不套布局。名字不合法或文件不存在 → `LogicException`（500）。
```php
layout('site', ['title' => t(['zh' => '报名', 'en' => 'Sign up'])]);
if (is_htmx()) layout(null);         // htmx 请求只要片段
```

**页面内片段**（没有 `partial()`）
同一个页面里要输出两次的 HTML（典型是：整页里显示一次，htmx 请求时只回这一段），在页面里写成一个闭包，调用两次：
```php
$rows = all('SELECT id, name, message FROM guestbook ORDER BY id DESC LIMIT 50');

// 页面内片段：只读上面 SELECT 出来的列
$list = function (array $rows): void { ?>
    <ul>
        <?php foreach ($rows as $r): ?>
            <li><strong><?= e($r['name']) ?></strong> <?= e($r['message']) ?></li>
        <?php endforeach ?>
    </ul>
<?php };

header('Vary: HX-Request');
if (is_htmx()) { $list($rows); exit; }   // htmx：只回片段，不套布局

layout('site', ['title' => '留言']);
?>
<div id="list" hx-get="/guestbook" hx-trigger="every 30s"><?php $list($rows) ?></div>
```
规则：
- 闭包只在**本页**用，写在用到它之前；参数明确传进去，不用 `use`/`global` 偷偷读外面的变量（`t()` 文本也作为参数传入）。
- 闭包里照样每个变量都 `e()`。
- 另一个页面需要类似的列表 → 在那个页面里**再写一份**，不要跨页面共享。改其中一个，另一个不受影响。
- 布局（`layouts/*.php`）是唯一共享的模板：只读 `layout()` 传进来的变量和 `$content`，每个变量都要有默认值（`$title ?? ''`），
  这样哪个页面少传一个变量都不会出错。给布局加新变量时，用默认值兼容所有现有页面。

**`asset(string $path): string`** — `/assets/<path>?v=<修改时间>`，文件一改网址就变（缓存失效）。文件不存在时返回不带 `?v=` 的路径（`dev` 下记 `warn`）。
```php
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
```

**`t(array|string $s, array $vars = []): string`**
就地翻译：传数组时按 `locale()` 取值，取不到用 `config('locales')[0]` 的值，再取不到用第一个值。传字符串就原样返回。
`{name}` 占位符用 `$vars` 替换，**结果不转义**，输出时要包一层 `e()`。
```php
<?= e(t(['zh' => '已有 {n} 人报名', 'en' => '{n} signed up'], ['n' => $count])) ?>
```
长段落的双语内容用 `<?php if (locale() === 'zh'): ?> … <?php else: ?> … <?php endif ?>`。

**`locale(mixed $set = null): string`**
当前语言，取值来自 `config('locales')`（默认 `['zh', 'en']`）。优先级：`locale('en')` > 查询串 `?lang=en` > cookie `lang` > `Accept-Language`（按 q 值；先精确匹配，再匹配主标签：`zh-CN` → `zh`）> 第一个配置的语言。
`locale($x)` 和 `?lang=` 会写 `lang` cookie（1 年、Lax、HttpOnly，值变了才发）。**不是配置里的语言字符串的值（未知的值、数组、`null`）会被忽略**，所以 `locale($_GET['l'] ?? null)` 这种写法是安全的（`?l[]=en` 也不会出错）。
结果来自 cookie、`Accept-Language` 或默认语言时，这个响应因访客而异，`cache_public()` 会在收尾时被降级。
```php
<html lang="<?= e(locale()) ?>">
<a href="?lang=en">English</a>
```

### 6.5 数据（只有 PDO）

配置：`config('db')` = `['dsn' => …, 'user' => env('DB_USER'), 'pass' => env('DB_PASS')]`。默认 `sqlite:storage/db.sqlite`，也可以用 `pgsql:…` 或 `mysql:…;charset=utf8mb4`。

**`db(): PDO`**
共享连接，第一次调用时才连。设置：`ERRMODE_EXCEPTION`、`FETCH_ASSOC`、超时 3 秒；非 SQLite 关闭模拟预处理；SQLite 开启 `foreign_keys`。
连接失败会记日志，并返回 503；DSN 和密码不会出现在页面或异常里。
```php
$id = (int) db()->lastInsertId();
```

**`q(string $sql, array $params = []): PDOStatement`** — 预处理并执行，占位符用 `?` 或 `:name`。用于 INSERT/UPDATE/DELETE。
```php
q('UPDATE posts SET title = ? WHERE id = ? AND user_id = ?', [$in['title'], $id, $uid]);
$n = q('DELETE FROM sessions_log WHERE created_at < ?', [$cutoff])->rowCount();
```

**`one(string $sql, array $params = []): ?array`** — 第一行（关联数组），没有则 `null`。
```php
$user = one('SELECT id, email FROM users WHERE email = ?', [$email]);
```

**`all(string $sql, array $params = []): array`** — 所有行。
```php
$rows = all('SELECT id, title FROM posts ORDER BY id DESC LIMIT ?', [20]);
```

**`val(string $sql, array $params = []): mixed`** — 第一行第一列，没有行时返回 `null`（值为 0 时就是 0）。
```php
$count = (int) val('SELECT COUNT(*) FROM posts');
$newId = val('INSERT INTO notes (text) VALUES (?) RETURNING id', [$text]);   // SQLite ≥ 3.35 / PostgreSQL
```

**`tx(callable $fn): mixed`**
在事务里执行 `$fn(db())`，成功提交并返回 `$fn` 的返回值；抛异常就回滚并重新抛出。`tx()` 里再调用 `tx()` 会并入外层事务。
```php
$orderId = tx(function (PDO $db) use ($uid, $cart): int {
    q('INSERT INTO orders (user_id) VALUES (?)', [$uid]);
    $id = (int) $db->lastInsertId();
    foreach ($cart as $sku => $qty) q('INSERT INTO order_items (order_id, sku, qty) VALUES (?, ?, ?)', [$id, $sku, $qty]);
    return $id;
});
```

**`validate(array $data, array $rules): array`** → `[$clean, $errors]`，规则见 §7。
```php
[$in, $errors] = validate(input(), ['email' => 'required|email', 'age' => 'int|min:18']);
if ($errors) json(['errors' => $errors], 422);
```

### 6.6 会话、登录、CSRF、限流

Session 是**惰性**的：只有下面这些函数会开启 session，普通页面不会发 cookie。
Cookie 名：https 下是 `__Host-ink`，否则是 `ink`。属性：HttpOnly，https 下 Secure，SameSite=Lax，path=/，strict mode。
Cookie 在浏览器关闭时失效；服务器端存放在 `storage/sessions/`。闲置超过 `config('session_lifetime', 86400)` 秒的 session **每次开启时都会检查**：过期就清空数据、换新 id（等于退出登录）。
另外每次开启 session 有 1% 的概率删除旧的 session 文件（只是清理磁盘）。
只读的函数（`flash()` 读取、`user()`、`logout()`、`csrf_check()`）只在浏览器带来的 cookie 格式正确、而且对应的 session 文件存在时才开启 session；没有 cookie、乱写的 cookie、过期的 cookie 都当作"没有 session"，不建文件，也不发 Set-Cookie。

**`session(): void`** — 需要时开启 session。之后可以直接读写 `$_SESSION` 存自己的数据，但不要动内核用的键：`_uid`、`_csrf`、`_flash`、`_seen`。
```php
session();
$_SESSION['cart'][$sku] = ($_SESSION['cart'][$sku] ?? 0) + 1;
```

**`flash(string $key, ?string $msg = null): ?string`** — 带 `$msg` 时存一条一次性消息；不带时取出并删除（没有则 `null`）。读取不会为没有（有效）session 的访客开启 session。`layouts/site.php` 会显示 `flash('ok')`。
```php
flash('ok', t(['zh' => '已保存', 'en' => 'Saved']));
redirect('/settings', 303);
```

**`csrf_token(): string`** — 当前 session 的 CSRF token（64 个十六进制字符），没有就生成（会开启 session）。

**`csrf_field(): string`** — `<input type="hidden" name="_csrf" value="…">`，放进每个 POST 表单。
```php
<form method="post"><?= csrf_field() ?> … </form>
```

**`csrf_check(): void`**
从 POST 字段 `_csrf` 或请求头 `X-CSRF-Token` 读 token（必须是字符串），用 `hash_equals` 与 session 里的比较。不一致、缺失、没有 session → 403。
**从不读查询串；调用就检查，不管请求方法**，所以 GET+POST 页面要写在 `if (is_post())` 里。JSON 请求体里的 `_csrf` 不算，前端 JS 要用请求头：
```js
fetch('/api/notes', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token}, body: JSON.stringify(data)})
```

**`login(int|string $id): void`** — 登录：换新的 session id（防会话固定），换新的 CSRF token，记住用户 id。
```php
if ($u && is_string($pw) && password_verify($pw, $u['password_hash'])) { login($u['id']); redirect('/', 303); }
```

**`logout(): void`** — 清空 session 数据并换新 id。之后仍然可以 `flash()`。退出登录要用 POST + CSRF（见 `pages/logout.php`），不要用 GET 链接。

**`user(): int|string|null`** — 已登录用户的 id，未登录为 `null`。访客没有有效的 session cookie 时不会开启 session。只返回 id，要用户资料自己查：
```php
$me = user() === null ? null : one('SELECT id, name FROM users WHERE id = ?', [user()]);
```

**`require_login(string $loginPath = '/login'): int|string`**
已登录 → 返回用户 id。未登录：`wants_json()`（包括 `/api/…`）→ 401 JSON；否则跳到 `$loginPath?next=<当前路径和查询串，已编码>`。
它只管"是否登录"。**权限（角色、数据归属）要自己检查。**
```php
$uid = require_login();
$doc = one('SELECT * FROM docs WHERE id = ? AND owner_id = ?', [$id, $uid]) ?? abort(404);
```

**`rate_limit(string $key, int $max, int $windowSeconds): void`**
固定窗口计数：同一个 `$key` 在 `$windowSeconds` 秒内最多调用 `$max` 次，超过 → 429，带 `Retry-After`。计数存在 `storage/ratelimit/`（文件 + flock）；每次调用有 1% 的概率顺便删除窗口已经结束的计数文件。
每次调用都会计数，所以要放在真正要限制的操作前面。`$max < 0` 或 `$windowSeconds < 1` → `LogicException`。
按 IP 限流用 `ip_key()`（IPv6 按 /64 算）。登录这类能猜密码的地方**还要按账号限流**，放在 `password_verify()` 之前——换 IP 绕不过它（代价是：别人可以故意把某个账号锁住一个窗口）：
```php
rate_limit('login:' . ip_key(), 5, 300);                   // 每个 IP 5 分钟 5 次
rate_limit('login-user:' . strtolower($name), 20, 3600);   // 每个账号 1 小时 20 次：放在 validate() 之后、password_verify() 之前
rate_limit('reset:' . strtolower($in['email']), 3, 3600);  // 找回密码：按邮箱
```

密码：直接用 PHP 自带的 `password_hash($pw, PASSWORD_DEFAULT)`、`password_verify($pw, $hash)`、`password_needs_rehash()`，没有包装函数。

### 6.7 配置与日志

**`config(string $key, mixed $default = null): mixed`** — 读 `config.php`，支持点号路径。
```php
$dsn = config('db.dsn');
$langs = config('locales', ['zh', 'en']);
```

**`env(string $key, mixed $default = null): mixed`** — 读机密：先读 `.env.php`，再读进程环境变量（`getenv`）。
```php
$token = (string) env('API_TOKEN', '');   // .env.php.example 里有这个键（注释掉的），用到时复制到 .env.php
```

**`log_info(string $msg, array $ctx = []): void`**、**`log_error(string $msg, array $ctx = []): void`**
往 `storage/logs/YYYY-MM-DD.log`（UTC 日期）追加一行 JSON：`{"t":"…Z","lvl":"info","msg":…,"rid":…,"req":"GET host/path",…ctx}`。
`$ctx` 不能覆盖 `t/lvl/msg/rid/req`。文件写不进去时退回 PHP 的 `error_log()`。不要记录密码、token、完整的卡号等机密。
```php
log_info('order paid', ['order' => $id, 'by' => $uid]);
log_error('payment webhook rejected', ['reason' => $why]);
```

### 6.8 常量与类

| 名称 | 含义 |
|---|---|
| `INK_VERSION` | 内核版本，如 `'1.0.0-dev'`（发布前）、`'1.0.0'` |
| `INK_ROOT` | 项目根目录的绝对路径（`ink/` 的上一级） |
| `INK_START` | 请求开始的 `hrtime(true)`（纳秒） |
| `APP_ENV` | `'dev'` / `'prod'` / `'test'` |
| `InkUnavailable` | 异常类（继承 `RuntimeException`）。外部服务暂时不可用时 `throw new InkUnavailable('…')`，返回 503 |

---

## 7. `validate()` 规则

```php
[$clean, $errors] = validate($data, [
    'name'  => 'required|max:40',
    'email' => 'required|email|max:254',
    'age'   => 'int|min:18|max:120',
    'plan'  => 'required|in:basic,pro',
    'tags'  => 'array|max:5',
    'code'  => 'required|regex:/^[A-Z]{3}-\d{4}$/',
]);
```

| 规则 | 通过条件 | 写入 `$clean` 的值 |
|---|---|---|
| `required` | 非空。空 = `null`、`''`（trim 之后）、`[]`；**`'0'` 不算空** | — |
| `email` | `FILTER_VALIDATE_EMAIL` | 字符串 |
| `int` | 整数：PHP 的 int，或带可选正负号的数字串（`'18'`、`'-3'`、`'05'` → 5）；`'1.5'`、`'abc'`、`true`/`false`、浮点数、超出 int 范围 不通过 | `int` |
| `numeric` | `is_numeric` 且是有限值（`'1e999'` 不通过）；布尔值不通过 | `int` 或 `float` |
| `url` | 合法网址，且只能是 `http://` / `https://`（`javascript:` 不通过） | 字符串 |
| `bool` | `1 0 true false yes no on off` | `bool` |
| `array` | 值必须是数组（没有这条规则时，数组值一律不通过） | 数组 |
| `min:N` / `max:N` | 字符串：字符数（中文按字算）；有 `int`/`numeric` 时：数值；数组：元素个数 | — |
| `in:a,b,c` | 值（按字符串比较）是其中之一 | 字符串（JSON 的 `5` 存成 `'5'`） |
| `regex:/…/` | `preg_match` 匹配。**必须是最后一条规则** | 字符串 |

`regex` 必须是最后一条规则，正则本身可以包含竖线，例如 `'code' => 'required|regex:/^(a|b)$/'`。
JSON 请求体里的数字（`{"age": 5}`）配 `min`/`max` 时要写 `int` 或 `numeric`，否则按字符串长度比较（`5` 是 1 个字符）。

行为细节：

- 字符串先 **trim** 再校验，`$clean` 里存 trim 后的值。**密码不要经过 `validate()`**，用 `input('password')` 取原值。
- 非 `required` 的字段为空时跳过其他规则，`$clean[字段] = null`。**没勾选的复选框是 `null`，不是 `false`**：写库时用 `$clean['x'] ? 1 : 0`。
- `$clean` **只包含写了规则的字段**（多余的输入字段被丢掉，防止批量赋值）。不通过的字段保留 trim 后的原始输入，方便回填表单。
- 不是字符串/数字/布尔的值（例如没写 `array` 规则时传来的数组）、非法 UTF-8 → 不通过。
- `$errors[字段]` 是一句当前语言（zh/en）的提示，例如"必填""邮箱格式不正确""至少 3 个字符""不能大于 10""选项无效"。每个字段只报第一条不通过的规则。
- 未知规则、`min`/`max` 不带数字、`in` 不带选项、正则写错 → `LogicException`（这是程序错误，返回 500）。
- 查询串也可以校验：`[$q, $bad] = validate($_GET, ['page' => 'int|min:1']);`。
- `int` 接受 `'05'`、`'+5'`（都变成 `5`），所以**不要**用它校验网址里的 id（会出现多个网址），id 用 §4.3 的正则。

---

## 8. 表单、htmx、JSON 的常用写法

**表单（PRG：POST → 校验 → 写库 → 303 跳转；校验失败就回显）**
```php
$form = ['title' => ''];
$errors = [];
if (is_post()) {
    csrf_check();
    [$form, $errors] = validate(input(), ['title' => 'required|max:100']);
    if (!$errors) { q('INSERT INTO posts (title) VALUES (?)', [$form['title']]); redirect('/posts', 303); }
    http_response_code(422);
}
```
```php
<input name="title" value="<?= e($form['title']) ?>">
<?php if (isset($errors['title'])): ?><p class="error"><?= e($errors['title']) ?></p><?php endif ?>
```

**htmx**（`public/assets/htmx.min.js`，htmx 2.0.11，sha256 `d6fdc75f204e6bdefa99b69bf1e6d4ac69b8a364f77929f45c13476b4000f717`，来源和升级方法见 `public/assets/VENDOR.txt`；`layouts/site.php` 已经引入）：
- 同一个网址既返回整页也返回片段：用页面内片段（§6.4），`if (is_htmx()) { $list($rows); exit; }`（在调用 `layout()` 之前退出，或者调用 `layout(null)`）。同时发 `header('Vary: HX-Request');`。
- 写操作用 `hx-post`。把 `hx-post` 放在包含 `csrf_field()` 的 `<form>` 上，token 会随表单字段提交，而且不开 JS 时普通提交也能用。
  不在表单里的按钮，要在外层元素上带上请求头：`<div hx-headers="<?= e(json_encode(['X-CSRF-Token' => csrf_token()])) ?>">`。
- 删除一行：`hx-target="closest tr" hx-swap="outerHTML"`，页面返回空响应（`exit;`），这一行就会消失。
- htmx 默认不替换 4xx/5xx 响应。需要在片段里显示校验错误时，用 200 返回带错误的表单片段。
- 不要用 `hx-put` / `hx-patch` 发表单字段（PHP 不解析，`input()` 会是 `[]`）。

**JSON API**：放在 `pages/api/` 下（`/api/…` 的错误自动返回 JSON）。用 `input()` 读 JSON 请求体，校验失败返回 `json(['errors' => $errors], 422)`，创建成功返回 201。
- 认证方式一：cookie 登录（给本站 JS 用）。用 `require_login()`（返回 401 JSON）加 `csrf_check()`（请求头 `X-CSRF-Token`）。
- 认证方式二：token（给其他系统用，见示例 2）。用 `hash_equals()` 比较 `Authorization: Bearer …`，没有 cookie 就不需要 CSRF。

**下载/大文件**：页面输出默认全部缓冲在内存里。流式输出前先清掉缓冲：
```php
[$id] = params(1);
$uid = require_login();
$f = one('SELECT path, name FROM files WHERE id = ? AND owner_id = ?', [$id, $uid]) ?? abort(404);
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($f['name']));
while (ob_get_level() > 0) ob_end_clean();
readfile(INK_ROOT . '/storage/files/' . basename($f['path']));
exit;
```
（清掉缓冲之后，出错时内核就无法再换成错误页了，所以所有检查都要在这之前做完。）

---

## 9. 安全

### 9.1 内核自动做的（不用写）

- 页面源码在网站根目录之外；`public/` 里只有 `index.php` 一个 PHP 文件；`deploy/nginx.conf` 和 `public/.htaccess` 不会执行或下载 `public/` 里其他 PHP 文件（`.php`、`.php5`…、`.phtml`、`.phar`；宝塔的差异见 `deploy/README.md`）。
- `deploy/` 的配置给 `/uploads/` 加 `nosniff` 和 `Content-Security-Policy: sandbox`，浏览器不会把上传的文件当网页运行。
- 网址规范化（每个页面只有一个网址），路径穿越（`..`、`%2e%2e`）→ 400，非法段 → 404，`Location` 不会指向外站。
- 页面没有声明的路径参数 → 404，没有声明的请求方法 → 405（收尾时检查）。
- 每个响应都带 `X-Content-Type-Options: nosniff`、`Referrer-Policy: strict-origin-when-cross-origin`、`X-Frame-Options: SAMEORIGIN`，默认 `Cache-Control: private, no-cache`，没有 `X-Powered-By`。
- 错误：生产环境不显示异常信息、文件路径、SQL、DSN；日志里有完整信息和请求 id。
- Session cookie：HttpOnly、SameSite=Lax、https 下 Secure 且用 `__Host-` 前缀、strict mode、只在需要时开启；闲置超时每次都检查；乱写的 cookie 不会建 session；`login()` 换 session id 和 CSRF token。
- `redirect()` 只跳本站；`json()` 不缓存；`store_upload()` 用白名单类型 + 真实 MIME + 随机文件名；`db()` 抛异常、不泄露连接信息；`host()` 校验 Host 头。

### 9.2 页面必须自己做的

| 要做的事 | 怎么做 |
|---|---|
| 输出转义 | HTML 里的每个变量都 `<?= e($x) ?>`。例外只有两个：布局里的 `$content`、`csrf_field()`（它们本身就是 HTML） |
| SQL 注入 | 只用 `q/one/all/val` 加占位符。值永远放在 `$params` 里 |
| CSRF | 每个改数据的请求先 `csrf_check()`；每个 POST 表单放 `csrf_field()` |
| 方法 | 处理 POST 的页面第二行写 `allow('GET', 'POST')` 或 `allow('POST')`。**GET 请求绝不改数据** |
| 登录与权限 | `require_login()` 只证明"已登录"。数据归属和角色要在 SQL 或代码里检查（`WHERE owner_id = ?`、`abort(403)`） |
| 输入校验 | 请求体、查询串、**路径参数**都要校验：`validate()`、路径里的 id 用 `as_id()`、其余用白名单 |
| 限流 | 登录、注册、找回密码、公开表单、API：`rate_limit('…:' . ip_key(), …)`；登录再按账号限流 |
| 密码 | `password_hash` / `password_verify`。不 trim，不记日志，不回显 |
| 缓存 | `cache_public()` 只用于对所有人都一样的页面（没有用户数据，不依赖 cookie、语言、Host 头） |
| 机密 | 放 `.env.php`，用 `env()` 读。不要写进 `config.php`、页面、日志或错误消息 |

### 9.3 `e()` 管不到的地方

- **链接里的用户网址**：`e()` 拦不住 `javascript:`。先用 `validate(…, ['site' => 'url'])`（只接受 http/https），再 `href="<?= e($url) ?>"`。
- **网址的一段**：`/u/<?= e(rawurlencode($name)) ?>`；查询串用 `http_build_query()`。
- **`mailto:` 链接**：`email` 规则允许 `?`、`&`、`%`（`a?cc=x@evil.example` 是合法邮箱），直接拼进 `mailto:` 会给访客的邮件加上抄送或正文。写成 `href="mailto:<?= e(rawurlencode($email)) ?>"`。
  另外，公开页面上显示访客的邮箱会被爬虫收集（个人信息）：除非确实需要，只在后台页面显示。
- **`<script>` 里的数据**：`<script>const data = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>`。
  更推荐 `data-*` 属性：`<div data-user="<?= e(json_encode($u)) ?>">`。
- **属性必须加引号**：`value="<?= e($x) ?>"`，不要写 `value=<?= e($x) ?>`。
- 不要把用户输入放进 `style`、`on*` 事件属性、`<script>` 内联代码或 SQL 的标识符（表名、列名、`ORDER BY`）。动态排序用白名单：
  ```php
  $order = match ($_GET['sort'] ?? '') { 'name' => 'name ASC', 'new' => 'id DESC', default => 'id DESC' };
  $rows = all("SELECT id, name FROM users ORDER BY $order LIMIT ?", [50]);   // $order 只可能是上面三个字面量
  ```
- **`IN (…)` 列表**：只拼接占位符，值仍然走参数：
  ```php
  $ids = array_values(array_map('intval', $ids));     // 占位符按位置绑定：键必须是 0,1,2…
  $rows = $ids ? all('SELECT * FROM posts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) : [];
  ```
- **LIKE 搜索**：转义通配符（用 `!` 作转义符，SQLite/PostgreSQL/MySQL 通用）：
  ```php
  $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
  $rows = all("SELECT id, title FROM posts WHERE title LIKE ? ESCAPE '!'", [$like]);
  ```

---

## 10. 错误与日志

| 情况 | dev | test | prod |
|---|---|---|---|
| warning / notice | 抛 `ErrorException` → 500，显示信息 | 抛 `ErrorException` → 500，不显示细节 | 记 `warn` 日志，继续执行 |
| deprecated | 只记日志 | 只记日志 | 只记日志 |
| 未捕获异常 / 致命错误 | 500，错误页里显示信息、文件:行号、调用栈（已转义） | 500，不显示细节 | 500，不显示细节 |
| `InkUnavailable`、暂时性的数据库错误（连接断开、死锁、锁超时、SQLite 文件被锁） | 503（+ `Retry-After: 30`） | 503 | 503 |
| 其他 `PDOException`（SQL 写错、约束冲突、表不存在…） | 500，显示信息 | 500 | 500 |

- `@` 抑制的错误会被尊重（不抛也不记）。
- 错误响应：`wants_json()` 时是 `{"error":500,"message":"Internal Server Error","rid":"…"}`（dev 下多一个 `debug` 字段），否则渲染 `layouts/error.php`（变量 `$code`、`$message`、`$rid`）。这个文件不存在或本身出错时，用内置的最简页面。
- 只有"等一会儿再试可能就好了"的错误是 503：`InkUnavailable`、SQLSTATE `08…`（连接）、`40001`/`40P01`（死锁、串行化失败）、`55P03`/`57P01`/`57P03`（PostgreSQL 锁超时、正在关闭）、MySQL 错误码 1205/1213/2002/2006/2013、SQLite 的 BUSY/LOCKED（5/6）。
  其他 `PDOException` 都是程序或数据问题，返回 500，包括唯一约束冲突。预期内的冲突要自己捕获：
  ```php
  try {
      q('INSERT INTO signups (email) VALUES (?)', [$email]);
  } catch (PDOException $e) {
      if (!str_starts_with((string) $e->getCode(), '23')) throw $e;   // SQLSTATE 23xxx = 约束冲突（SQLite/MySQL 23000，PostgreSQL 23505）
      $errors['email'] = t(['zh' => '已经报过名了', 'en' => 'Already signed up']);
  }
  ```
- 日志：`storage/logs/YYYY-MM-DD.log`，每行一个 JSON：`t`（UTC，以 `Z` 结尾）、`lvl`（`info`/`warn`/`error`）、`msg`、`rid`、`req`，以及上下文字段。
  异常记录里有 `ex`（类名）和 `at`（文件:行号；异常从内核函数里抛出时，`at` 先写页面的那一行）。
- `rid`（请求 id）：`config('trust_proxy')` 为 `true`（源站只接受你的代理/Cloudflare 的连接）时用 `CF-Ray` 头，否则是 8 位随机十六进制（任何人都能伪造 `CF-Ray`）。错误页上会显示它，用它找日志：`grep '"rid":"3f9a0c1e"' storage/logs/*.log`。
- 看日志：`tail -f storage/logs/$(date -u +%F).log`。

---

## 11. 配置

**`config.php`**（进 git，不放机密）：

| 键 | 默认 | 说明 |
|---|---|---|
| `env` | `'prod'` | 环境（`.env.php` 或环境变量里的 `APP_ENV` 优先） |
| `timezone` | `'UTC'` | `date_default_timezone_set`。数据库里统一存 UTC |
| `base_url` | `''` | `url()` 用的固定前缀，例如 `'https://example.com'` |
| `trust_proxy` | `false` | 只有在源站只接受你自己的代理连接时才设 `true`（`is_https()` 才会相信 `X-Forwarded-Proto`，日志的 `rid` 才用 `CF-Ray`） |
| `locales` | `['zh', 'en']` | 支持的语言，第一个是默认语言 |
| `session_lifetime` | `86400` | session 闲置多少秒后失效（每次开启 session 时检查） |
| `db.dsn` / `db.user` / `db.pass` | SQLite | 见 §6.5 |

**`.env.php`**（不进 git；从 `.env.php.example` 复制）：返回一个数组，例如 `APP_ENV`、`DB_USER`、`DB_PASS`、`ADMIN_USER`、`ADMIN_PASSWORD_HASH`、`API_TOKEN`。用 `env('KEY')` 读。
新增机密时，同时在 `.env.php.example` 里加一个空值并写上说明。

**`APP_ENV`** 的确定顺序：`.env.php` 的 `APP_ENV` > 进程环境变量 `APP_ENV` > `config('env')` > `'prod'`；不认识的值当作 `prod`。
注意：`.env.php` 优先级最高。`php bin/ink serve` 会设置环境变量 `APP_ENV=dev`，但如果本机的 `.env.php` 写的是 `'prod'`，最终还是 `prod`。所以本机开发时，`.env.php` 里写 `'dev'` 或者删掉这一项。

---

## 12. 数据库与迁移

- 迁移文件：`migrations/NNN_描述.sql`（`001_guestbook.sql`、`002_notes.sql` …）。按文件名排序依次执行，每个文件一个事务，已执行的记录在 `ink_migrations` 表里。**已经执行过的文件不要改**，要改就新建一个文件。
- 执行：`php bin/ink migrate`。生产环境要以 PHP 的运行用户执行：`sudo -u www-data php bin/ink migrate`。
- MySQL 的 DDL 会自动提交，所以 MySQL 上的迁移不是原子的。一个文件里尽量只放一件事。
- **约定**：每个迁移文件第一行注释写明用到这张表的页面（例如 `-- Guestbook entries: used by pages/guestbook.php, pages/search.php and pages/admin.php.`），改表结构时照着它找。

**给已有的表加字段**（不改旧文件，新建一个迁移）：
1. 新建 `migrations/NNN_add_x.sql`：
   ```sql
   -- guestbook.email: used by pages/guestbook.php
   ALTER TABLE guestbook ADD COLUMN email VARCHAR(254) NULL;
   ```
   SQLite 不允许直接加 `NOT NULL` 且没有默认值的列：要么允许 `NULL`，要么写 `NOT NULL DEFAULT ''`。一条 `ALTER TABLE` 只加一列（SQLite 的限制）。
2. 找出所有读写这张表的地方，逐个更新（`SELECT` 列表、`INSERT` 的列、`validate()` 规则、表单）：
   ```bash
   grep -rn -E '(FROM|INTO|UPDATE|JOIN) +guestbook\b' pages layouts lib
   ```
   每个页面只读它自己 `SELECT` 出来的列，所以只有真正要显示新字段的页面才需要改。
3. `php bin/ink migrate`，然后在 dev 下把相关页面都点一遍。
- 可移植的写法：
  - 主键用 `INTEGER PRIMARY KEY`（SQLite），PostgreSQL 用 `BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY`。
  - 布尔值存 `INTEGER` 0/1；时间存 UTC（`CURRENT_TIMESTAMP` 或 `gmdate('Y-m-d H:i:s')`）。
  - INSERT 里不写 `id` 和带默认值的列。
- 取新 id：`db()->lastInsertId()`（三种数据库都能用），或者 `val('INSERT … RETURNING id', …)`（SQLite ≥ 3.35、PostgreSQL、MariaDB）。
- `LIMIT ?` / `OFFSET ?` 的参数传 `int`。
- 在命令行脚本或一次性操作里使用内核：
  ```bash
  sudo -u www-data php -r 'require "ink/ink.php"; var_dump(val("SELECT COUNT(*) FROM guestbook"));'
  ```
  命令行下：没有输出缓冲和响应头；异常和 `abort()` 打印到 STDERR，退出码为 1；`APP_ENV` 默认 `prod`。

---

## 13. 命令行 `bin/ink`

| 命令 | 作用 |
|---|---|
| `php bin/ink serve [PORT]` | 开发服务器 `http://127.0.0.1:PORT`（默认 8000），`APP_ENV=dev`。只用于开发 |
| `php bin/ink routes [--pages=DIR]` | 路由表：网址模式、文件、参数个数、方法、备注（login / csrf / layout）。从每个页面开头的声明里读出来，不运行页面 |
| `php bin/ink check` | 语法检查 + 项目规则（下表），有错误时退出码为 1 |
| `php bin/ink test [FILE…] [--http=BASE_URL] [--pages=DIR] [-v]` | 运行路由表（默认 `tests/*.txt`）：在进程内根据规范化、解析和页面开头的声明推断结果（不运行页面）；加 `--http` 时对运行中的服务器发真实请求 |
| `php bin/ink migrate` | 执行新的 `migrations/*.sql` |

**`bin/ink check` 检查的规则**

| 级别 | 规则 |
|---|---|
| error | 任何 `.php` 文件（以及 `bin/ink`）`php -l` 不通过 |
| error | `pages/` 里的文件名永远不会被路由：非根目录的 `index.php`、不合法的路由名（大写、空格等）、`x.php.php`。（`_x`、`.x` 是有意设为私有的，不报错） |
| warn | `pages/` 里有非 `.php` 文件 |
| error | 页面的第一条语句不是 `params()` |
| error | `params()` 的字面量参数不满足 `0 ≤ min ≤ max ≤ 12` |
| error | 页面调用了 `allow()`，但它不是第二条语句（紧跟 `params()`） |
| error | 页面使用了 `input()` / `is_post()` / `store_upload()` / `$_POST` / `$_FILES` / `$_REQUEST` / `$_SERVER['REQUEST_METHOD']` / `'php://input'`，却没有 `allow()` |
| warn / error | `allow()` 允许了 GET/HEAD/OPTIONS 以外的方法，却从没调用 `csrf_check()`。普通页面是 warn，`pages/admin.php` 和 `pages/admin/…` 是 error |
| warn | 开头的声明里直接写了 `csrf_check()`，但页面同时允许 GET（每个 GET 都会 403） |
| error | `pages/admin.php` 和 `pages/admin/…` 的开头声明里没有 `require_login()` |
| error | 参数可选（`params(0, 1)` 这类 min < max）、又允许了 GET 以外的方法，但整页只调用了一次 `allow()`：要写明带参数的网址接受哪些方法，例如 `if ($id !== null) allow('GET');`（§4.3） |
| warn | `params()` / `allow()` 的参数不是字面量 |
| error | `layout('x')` 指向的文件不存在 |
| error | 调用了 `partial()`，或者存在 `partials/` 目录（1.0 已移除共享片段：把 HTML 写进页面，见 §6.4） |
| error | 使用了 0.x 的 `$_GET['key1']` 写法 |
| error | `public/` 下除 `index.php` 外还有 PHP 文件（`.php`、`.php5`/`.php7`…、`.phtml`、`.phar`） |
| error / warn | `storage/`、`storage/logs`、`storage/sessions`、`storage/ratelimit` 不可写（error）；`public/uploads` 不可写（warn） |
| warn | 以 root 身份运行 |
| error | `.env.php` 被提交进了 git（直接读 `.git/index`，不运行 git，所以不会执行仓库配置里的命令） |

"开头的声明"指文件开头连续的 `params()`、`allow()`、`require_login()`、`csrf_check()`、`layout()` 调用，可以带赋值（如 `[$id] = params(1);`、`$uid = require_login();`）；`declare(...)` 不算打断。

---

## 14. 测试

1. **语法和规则**：`php bin/ink check`。
2. **路由表**：`tests/app.txt` 是应用自己的路由表（`tests/routes.txt` 是内核的，对应 `tests/fixtures/pages`，不要改）。新增网址时在 `tests/app.txt` 里加几行，一行一个请求：
   ```
   # 格式：METHOD /path -> STATUS [页面文件(200) | Location(3xx)] [参数 JSON]   # 注释
   GET  /                    -> 200 pages/index.php []
   GET  /signup              -> 200 pages/signup.php []
   GET  /signup.php          -> 301 /signup
   GET  /signup/x            -> 404
   PUT  /signup              -> 405
   POST /signup              -> 403                         # 没有 CSRF token
   GET  /admin/signups       -> 302 /login?next=%2Fadmin%2Fsignups
   GET  /api/signups         -> 401
   ```
   - `php bin/ink test`：运行 `tests/*.txt`，在进程内检查。它不运行页面，只根据规范化、解析和页面开头的声明来推断结果（301/308/400、路由和 `params()` 的 404、`allow()` 的 405、开头声明里的 `csrf_check()` 403 / `require_login()` 302 或 401）。
     页面会运行、而期望的状态码只有页面代码才给得出（上例中 `if (is_post())` 里的 403、422、429 …）的行会被标为 skipped。
   - **页面自己决定的 404/405/200**（记录不存在、id 不合法）在进程内看不出来——进程内只知道"页面会运行"。这类行的状态码前面写 `http:`，进程内跳过，只在 `--http` 时检查。用一个永远不存在的 id，结果才不依赖数据：
     ```
     GET  /blog/0              -> http:404                     # 页面自己 abort(404)：id 必须从 1 开始
     GET  /blog/abc            -> http:404                     # 不合法的 id
     ```
     不要为了让进程内通过而把这类行写成 `-> 200 pages/blog.php ["abc"]`：`--http` 时它会失败。
   - `php bin/ink serve 8000` 启动后运行 `php bin/ink test tests/app.txt --http=http://127.0.0.1:8000`：发真实请求（不带 cookie，不跟随跳转），检查所有行。部署后也可以对线上地址运行。
   - 文件开头写 `@pages DIR` 时，这个表针对的是另一个页面目录（例如内核自己的 `tests/routes.txt` 对应 `tests/fixtures/pages`）。
3. **手工检查**：`curl -i http://127.0.0.1:8000/signup`。需要 CSRF 的 POST 要先 GET 页面拿到 cookie 和 token：
   ```bash
   c=$(mktemp); t=$(curl -s -c $c http://127.0.0.1:8000/signup | grep -o 'name="_csrf" value="[0-9a-f]*"' | cut -d'"' -f4)
   curl -i -b $c -d "_csrf=$t&name=Ann&email=ann@example.com&seats=2&plan=basic" http://127.0.0.1:8000/signup
   ```
4. 在 `dev` 下点一遍页面：任何 warning 都会变成带调用栈的 500，问题不会被藏起来。

---

## 15. 部署（摘要）

详见 `deploy/README.md`。

- 网站根目录指向 **`public/`**，绝不能指向项目根目录。nginx 用 `deploy/nginx.conf`，宝塔用 `deploy/baota.conf`，Apache 用 `public/.htaccess`。
- `storage/` 和 `public/uploads/` 要让 PHP 可写；`bin/ink` 要以 PHP 的运行用户执行。
- `.env.php` 里的 `APP_ENV` 写 `prod`；机密只放在 `.env.php`。
- 每个应用用独立的 PHP-FPM 池；**不要**用 `fastcgi_param PHP_VALUE/PHP_ADMIN_VALUE`（在共享池里会串到别的网站）。
- 在 Cloudflare 后面：nginx 配 `real_ip`（否则 `client_ip()` 是 CF 节点的 IP）；SSL 用 Full (strict)；`trust_proxy` 保持 `false`（源站只放行 Cloudflare 时才可以设 `true`）。
- HSTS 和 Content-Security-Policy 不是内核默认的响应头，推荐的写法见 `deploy/README.md`。示例布局只在 `dev` 下显示版本号和耗时。

---

## 16. 要做 / 不要做

**要做**

- 一个功能一个页面文件；开头依次写 `params()`、`allow()`、`require_login()`。
- 每个 POST 表单放 `csrf_field()`，每个写操作先 `csrf_check()`。
- HTML 里的每个变量都 `e()`；SQL 只用占位符。
- 写成功后 `redirect(…, 303)`；校验失败回显表单并 `http_response_code(422)`。
- 路径参数和查询串当作不可信输入来校验。
- 登录、表单、API 加 `rate_limit()`（按 IP 用 `ip_key()`，登录再按账号）；数据归属在 SQL 里检查（`AND owner_id = ?`）。
- 界面文字用 `t(['zh' => …, 'en' => …])` 就地写两种语言。
- 新表写成 `migrations/NNN_*.sql`；新机密同时加进 `.env.php.example`。
- 改完运行 `php bin/ink check`；给新网址在 `tests/app.txt` 里加几行。

**不要做**

- 不要 `echo` 或 `<?=` 没转义的输入（`$_GET`、`$_POST`、`input()`、数据库内容、路径参数）。
- 不要拼接 SQL 字符串（`"… WHERE id = $id"`、`'…' . $_GET['x']`）。
- 不要添加会隐式运行的文件：守卫文件、中间件、`_bootstrap.php`、`auto_prepend_file`、`.user.ini` 里的 prepend，或者被"约定"自动 include 的文件。
- 不要跨页面共享 HTML 片段（不要建 `partials/`，不要 `include` 别的页面）。类似的 HTML 在每个页面里各写一份；同一页面内复用用页面内片段（§6.4）。
- 不要在 `public/` 里放 `.php` 文件（`index.php` 除外）；不要把页面、配置、日志放进 `public/`。
- 不要在页面里 `require` 内核或 `ink/ink.php`。内核已经加载好了，页面直接调用函数。
- 不要用 GET 改数据（包括退出登录）；不要从查询串读 CSRF token。
- 不要用 `$_GET['key1']`（0.x 写法）。路径参数用 `params()`。
- 不要读 `X-Forwarded-For` 来取 IP，用 `client_ip()`；限流的 key 用 `ip_key()`。
- 不要用 `redirect()` 以外的方式跳到用户提供的地址。
- 不要引入 Composer、npm、ORM、Medoo、模板引擎、构建步骤。
- 不要把机密写进 `config.php`、页面或日志；不要提交 `.env.php`。
- 不要在 `pages/` 下建 `xxx/index.php`（不会被路由）；文件名不要用大写。
- 不要依赖 PHP 的 `display_errors`；错误由内核处理，看 `storage/logs/`。

---

## 17. 完整示例

三个完整的单文件功能，共用一张表。它们都使用仓库里现成的 `layouts/site.php`（导航、语言切换，并显示 `flash('ok')`）和 `pages/login.php`（登录）。

```sql
-- migrations/003_signups.sql
CREATE TABLE signups (
    id         INTEGER PRIMARY KEY,
    name       VARCHAR(40)  NOT NULL,
    email      VARCHAR(254) NOT NULL UNIQUE,
    seats      INTEGER      NOT NULL,
    plan       VARCHAR(10)  NOT NULL,
    newsletter INTEGER      NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

### 示例 1：表单 + 数据库（`pages/signup.php` → `/signup`）

```php
<?php
// pages/signup.php → /signup   活动报名：GET 显示表单；POST 校验、写库、跳转（PRG）。表：migrations/003_signups.sql
params();
allow('GET', 'POST');

$form = ['name' => '', 'email' => '', 'seats' => 1, 'plan' => 'basic', 'newsletter' => false];
$errors = [];

if (is_post()) {
    csrf_check();                                        // 没有/错误的 _csrf → 403
    rate_limit('signup:' . ip_key(), 10, 3600);          // 每个 IP（IPv6 按 /64）每小时最多 10 次 → 否则 429
    [$form, $errors] = validate(input(), [
        'name'       => 'required|max:40',
        'email'      => 'required|email|max:254',
        'seats'      => 'required|int|min:1|max:10',
        'plan'       => 'required|in:basic,pro',
        'newsletter' => 'bool',                          // 没勾选 → null
    ]);
    if (!$errors) {
        try {
            q('INSERT INTO signups (name, email, seats, plan, newsletter) VALUES (?, ?, ?, ?, ?)',
                [$form['name'], $form['email'], $form['seats'], $form['plan'], $form['newsletter'] ? 1 : 0]);
            flash('ok', t(['zh' => '报名成功！', 'en' => 'You are signed up!']));
            redirect('/signup', 303);                    // 刷新下一页不会重复提交
        } catch (PDOException $e) {
            if (!str_starts_with((string) $e->getCode(), '23')) throw $e;   // 只处理唯一约束冲突，其他交给内核（500/503）
            $errors['email'] = t(['zh' => '这个邮箱已经报过名了', 'en' => 'This email is already signed up']);
        }
    }
    http_response_code(422);                             // 回显表单、错误和用户的输入
}

$total = (int) val('SELECT COALESCE(SUM(seats), 0) FROM signups');
$plans = ['basic' => t(['zh' => '基础', 'en' => 'Basic']), 'pro' => t(['zh' => '专业', 'en' => 'Pro'])];
$error = fn (string $field): string => isset($errors[$field]) ? '<p class="error">' . e($errors[$field]) . '</p>' : '';

layout('site', ['title' => t(['zh' => '活动报名', 'en' => 'Sign up'])]);
?>
<h1><?= e(t(['zh' => '活动报名', 'en' => 'Sign up'])) ?></h1>
<p class="muted"><?= e(t(['zh' => '已报名 {n} 个座位', 'en' => '{n} seats taken'], ['n' => $total])) ?></p>

<form class="box" method="post" action="/signup">
    <?= csrf_field() ?>

    <label for="name"><?= e(t(['zh' => '名字', 'en' => 'Name'])) ?></label>
    <input id="name" name="name" maxlength="40" required value="<?= e($form['name']) ?>">
    <?= $error('name') ?>

    <label for="email"><?= e(t(['zh' => '邮箱', 'en' => 'Email'])) ?></label>
    <input id="email" name="email" type="email" required value="<?= e($form['email']) ?>">
    <?= $error('email') ?>

    <label for="seats"><?= e(t(['zh' => '人数', 'en' => 'Seats'])) ?></label>
    <input id="seats" name="seats" type="number" min="1" max="10" required value="<?= e($form['seats']) ?>">
    <?= $error('seats') ?>

    <label for="plan"><?= e(t(['zh' => '套餐', 'en' => 'Plan'])) ?></label>
    <select id="plan" name="plan">
        <?php foreach ($plans as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $form['plan'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach ?>
    </select>
    <?= $error('plan') ?>

    <label><input type="checkbox" name="newsletter" value="1"<?= $form['newsletter'] ? ' checked' : '' ?>>
        <?= e(t(['zh' => '有新活动时通知我', 'en' => 'Tell me about new events'])) ?></label>

    <button><?= e(t(['zh' => '报名', 'en' => 'Sign up'])) ?></button>
</form>
```

### 示例 2：JSON API（`pages/api/signups.php` → `/api/signups`）

```php
<?php
// pages/api/signups.php — 给其他系统用的 JSON API，认证方式是 Authorization: Bearer <API_TOKEN>（.env.php）。
//   GET  /api/signups?plan=pro&limit=50  → 200 {"signups": [...]}
//   GET  /api/signups/{id}               → 200 {"signup": {...}} | 404
//   POST /api/signups  (JSON 请求体)      → 201 {"id": 7} | 422 {"errors": {...}} | 409
// 不用 cookie，所以不需要 CSRF（bin/ink check 会对此给出一条 warning，属预期）。/api 下的错误都是 JSON。
// API_TOKEN 放在 .env.php（.env.php.example 里有这个键）。
[$raw] = params(0, 1);
allow('GET', 'POST');                                    // /api/signups：列表 + 新建
$id = as_id($raw);                                       // 不是规范 id → 404
if ($id !== null) allow('GET');                          // /api/signups/{id}：只读（POST → 405 + Allow 头）

rate_limit('api:' . ip_key(), 120, 60);
$token = (string) env('API_TOKEN', '');
if ($token === '' || !hash_equals('Bearer ' . $token, (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) abort(401);

if (is_post()) {                                         // 只会是 /api/signups（上面已经收窄了带 id 的网址）
    [$in, $errors] = validate(input(), [                 // input()：JSON 请求体；坏 JSON → 400
        'name'  => 'required|max:40',
        'email' => 'required|email|max:254',
        'seats' => 'required|int|min:1|max:10',
        'plan'  => 'required|in:basic,pro',
    ]);
    if ($errors) json(['errors' => $errors], 422);
    try {
        q('INSERT INTO signups (name, email, seats, plan) VALUES (?, ?, ?, ?)', [$in['name'], $in['email'], $in['seats'], $in['plan']]);
    } catch (PDOException $e) {
        if (!str_starts_with((string) $e->getCode(), '23')) throw $e;
        json(['errors' => ['email' => 'already signed up']], 409);
    }
    json(['id' => (int) db()->lastInsertId()], 201);
}

if ($id !== null) {
    $row = one('SELECT id, name, email, seats, plan, newsletter, created_at FROM signups WHERE id = ?', [$id]) ?? abort(404);
    json(['signup' => $row]);
}

[$f, $errors] = validate($_GET, ['plan' => 'in:basic,pro', 'limit' => 'int|min:1|max:500']);
if ($errors) json(['errors' => $errors], 422);
$rows = $f['plan'] === null
    ? all('SELECT id, name, email, seats, plan, created_at FROM signups ORDER BY id DESC LIMIT ?', [$f['limit'] ?? 100])
    : all('SELECT id, name, email, seats, plan, created_at FROM signups WHERE plan = ? ORDER BY id DESC LIMIT ?', [$f['plan'], $f['limit'] ?? 100]);
json(['signups' => $rows]);
```

调用：
```bash
curl -s -H "Authorization: Bearer $API_TOKEN" http://127.0.0.1:8000/api/signups?plan=pro
curl -s -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
     -d '{"name":"Ann","email":"ann@example.com","seats":2,"plan":"pro"}' http://127.0.0.1:8000/api/signups
```
（Apache + PHP-FPM 需要 `CGIPassAuth On` 才能把 `Authorization` 头传给 PHP，见 `deploy/README.md`。）

### 示例 3：后台页面（`pages/admin/signups.php` → `/admin/signups`）

```php
<?php
// pages/admin/signups.php — 后台：报名列表（分页）+ 删除。删除用表单 POST；有 htmx 时原地删掉这一行。
//   GET  /admin/signups?page=2
//   POST /admin/signups/{id}    删除一条
[$raw] = params(0, 1);
allow('GET', 'POST');
$me = require_login();                                   // 未登录 → /login?next=%2Fadmin%2Fsignups
$id = as_id($raw);                                       // 不是规范 id → 404
if ($id !== null) allow('POST'); else allow('GET');      // /admin/signups 只看；/admin/signups/{id} 只删
// 本仓库只有一个管理员账号（pages/login.php）。有多种角色时，在这里检查权限，例如：
//   if (val('SELECT role FROM users WHERE id = ?', [$me]) !== 'admin') abort(403);

if ($id !== null) {                                      // POST /admin/signups/{id}：删除
    csrf_check();
    $row = one('SELECT id, email FROM signups WHERE id = ?', [$id]) ?? abort(404);
    q('DELETE FROM signups WHERE id = ?', [$row['id']]);
    log_info('signup deleted', ['by' => $me, 'id' => $row['id']]);
    if (is_htmx()) exit;                                 // 空响应 + hx-swap="outerHTML" → 这一行消失
    flash('ok', t(['zh' => '已删除', 'en' => 'Deleted']));
    redirect('/admin/signups', 303);
}

[$q] = validate($_GET, ['page' => 'int|min:1']);
$page = is_int($q['page']) ? $q['page'] : 1;             // 不合法的页码当作第 1 页
$per = 50;
$total = (int) val('SELECT COUNT(*) FROM signups');
$pages = max(1, (int) ceil($total / $per));
$rows = all('SELECT id, name, email, seats, plan, newsletter, created_at FROM signups ORDER BY id DESC LIMIT ? OFFSET ?',
    [$per, ($page - 1) * $per]);
$confirm = t(['zh' => '确定删除这条报名吗？', 'en' => 'Delete this sign-up?']);

layout('site', ['title' => t(['zh' => '报名管理', 'en' => 'Sign-ups'])]);
?>
<h1><?= e(t(['zh' => '报名管理', 'en' => 'Sign-ups'])) ?> <small class="muted">(<?= e($total) ?>)</small></h1>

<div class="scroll">
    <table>
        <thead>
            <tr><th>#</th><th><?= e(t(['zh' => '名字', 'en' => 'Name'])) ?></th><th><?= e(t(['zh' => '邮箱', 'en' => 'Email'])) ?></th>
                <th><?= e(t(['zh' => '人数', 'en' => 'Seats'])) ?></th><th><?= e(t(['zh' => '套餐', 'en' => 'Plan'])) ?></th>
                <th><?= e(t(['zh' => '时间 (UTC)', 'en' => 'Time (UTC)'])) ?></th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['id']) ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['email']) ?></td>
                    <td><?= e($r['seats']) ?></td>
                    <td><?= e($r['plan']) ?></td>
                    <td><?= e(substr((string) $r['created_at'], 0, 16)) ?></td>
                    <td>
                        <form method="post" action="/admin/signups/<?= e($r['id']) ?>"
                            hx-post="/admin/signups/<?= e($r['id']) ?>" hx-target="closest tr" hx-swap="outerHTML"
                            hx-confirm="<?= e($confirm) ?>">
                            <?= csrf_field() ?>
                            <button class="danger"><?= e(t(['zh' => '删除', 'en' => 'Delete'])) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>

<p>
    <?php if ($page > 1): ?><a href="?page=<?= e($page - 1) ?>">← <?= e(t(['zh' => '上一页', 'en' => 'Previous'])) ?></a><?php endif ?>
    <?= e($page) ?> / <?= e($pages) ?>
    <?php if ($page < $pages): ?><a href="?page=<?= e($page + 1) ?>"><?= e(t(['zh' => '下一页', 'en' => 'Next'])) ?> →</a><?php endif ?>
</p>
```

### 仓库里的其他示例

| 网址 | 文件 | 演示 |
|---|---|---|
| `/` | `pages/index.php` | `params()`、就地翻译 |
| `/about` | `pages/about.php` | 普通页面 + 布局，长段落的双语写法 |
| `/guestbook` | `pages/guestbook.php` | 表单、CSRF、校验、flash、页面内片段、htmx 局部刷新 |
| `/search` | `pages/search.php` | htmx 实时搜索、`params(0, 1)` |
| `/login`、`/logout` | `pages/login.php`、`pages/logout.php` | `password_verify`、限流、只接受本站的 `next=`、POST 退出 |
| `/admin` | `pages/admin.php` | `require_login()`、htmx 原地删除 |
| `/api/notes` | `pages/api/notes.php` | cookie 登录的 JSON API（`X-CSRF-Token`） |
| `/robots.txt` | `pages/robots.txt.php` | 动态"静态文件"、`cache_public()` |

---

## 18. 常见陷阱

- **首页吞掉所有网址**：`pages/index.php` 写成 `params(0, 1)` 时，`/任何单段网址` 都返回 200。首页通常写 `params();`。
- **POST 页面忘了 `allow()`**：收尾时才会 405，而这时页面代码（可能包括写库）已经执行过了。`bin/ink check` 会报错。
- **GET+POST 页面开头直接写 `csrf_check()`**：每个 GET 都会 403。要写在 `if (is_post())` 里。
- **布局里用了页面的变量**：布局只能看到 `layout()` 传进去的 `$vars`。布局里的可选变量写成 `$title ?? ''`，否则 dev 下会报 "Undefined variable"。
- **在 `abort()` 之前设的响应头被丢掉**：这是有意的（错误响应只保留 Set-Cookie）。
- **唯一约束冲突变成 500**：自己 `catch (PDOException $e)` 并检查 SQLSTATE `23…`，回 422/409（§10）。
- **`validate()` trim 了密码**：密码用 `input('password')`。
- **复选框是 `null` 不是 `false`**：`$clean['x'] ? 1 : 0`。
- **超过 `post_max_size` 的上传 → 403**：PHP 会丢掉整个 `$_POST`，CSRF 字段也没了。调大 PHP 和 nginx 的上限（`deploy/README.md`）。
- **用 root 跑 `bin/ink migrate`**：数据库文件归 root 所有，网站写入时报错（SQLite："attempt to write a readonly database"，500）。用 `sudo -u www-data`。
- **`cache_public()` 和语言**：靠 cookie / `Accept-Language` / 默认值决定语言的页面会被内核自动降级为不缓存（否则 CDN 会把一种语言发给所有人）。需要缓存的多语言页面，用网址区分语言并调用 `locale('en')`。
- **`cache_public()` 和 `url()`**：`url()` 默认用请求的 Host 头，CDN 会把别人伪造的 Host 缓存给所有人。可缓存的响应里要用 `url()`，先设 `config('base_url')`。
- **给已有的 POST 页面加路径参数**：`params()` 改成 `params(0, 1)` 后，`POST /x/5` 也会执行原来给 `/x` 写的写操作。紧接着加 `if ($id !== null) allow('GET');`（§4.3）；`bin/ink check` 会提醒。
- **id 有多种写法**：`ctype_digit()` 和 `validate()` 的 `int` 都接受 `'01'`，同一条记录会有多个网址。路径参数里的 id 一律用 `as_id()`（§4.3）。
- **`.env.php` 覆盖了 `bin/ink serve` 的 `APP_ENV=dev`**：见 §11。
- **session 锁**：同一用户的请求在 session 开启期间会排队。做慢操作（调用外部 API）之前，如果不再写 session，可以先 `session_write_close()`。
- **按 `client_ip()` 限流**：IPv6 的访客可以换无数个地址。用 `ip_key()`；登录再按账号限流。

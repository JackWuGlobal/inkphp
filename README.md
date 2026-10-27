# inkphp

> 一个网址 = 一个文件 = 一个功能。为 AI 编程助手（和人）设计的极小 PHP 框架。

inkphp 1.0 是一次重写，与 0.x 不兼容。迁移方法见 [CHANGELOG.md](CHANGELOG.md)。

## inkphp 是什么

- **文件即网址**：`/about` 就是 `pages/about.php`，`/blog/hello` 是 `pages/blog.php` 收到参数 `hello`。没有路由表要维护。
- **一个文件就是一个完整功能**：参数、允许的请求方法、登录、CSRF、校验、SQL、HTML、表单提交和 htmx 局部刷新，都写在同一个页面文件里。
- **不调用就不运行**：没有中间件、守卫文件、自动加载、事件钩子。页面需要什么，就在页面里调用什么。
- **默认安全**：`e()` 转义输出，SQL 只用参数，表单自带 CSRF，`redirect()` 只跳本站，页面源码不在网站根目录，上传文件永远不会被执行。
- **零依赖、零构建**：不需要 Composer，不需要 npm。PHP ≥ 8.2（推荐 8.4），数据库默认 SQLite，也支持 PostgreSQL / MySQL（只用 PDO，没有 ORM）。内置 htmx 2，界面文字用 `t()` 就地写中英文。
- **小**：Web 内核（`ink/` 下除 `cli.php` 外）约 1000 行、45 个公开函数，命令行 `ink/cli.php` 约 570 行，一次就能整个读完。

## 为什么是"一个网址 = 一个文件"

让 AI（或者新同事）改一个功能时，最怕两件事：要读的文件太多，以及有看不见的东西在悄悄运行。

- 打开 `pages/signup.php`，就能看到 `/signup` 的**全部**行为。不需要再去找控制器、路由表、中间件、模型、模板、配置。
- 文件里写着什么就执行什么。没有"约定好会自动执行的文件"，所以读到的就是运行的。
- 改一个功能只改一个文件，删一个功能就删一个文件，代码审查一眼就能看完。
- 规则少而固定（第一行 `params()`，第二行 `allow()` …），`php bin/ink check` 能自动检查，AI 不容易写偏。

## 5 分钟上手

需要：PHP ≥ 8.2，带 `pdo_sqlite`（大多数发行版默认就有）。

```bash
git clone https://github.com/JackWuGlobal/inkphp.git myapp && cd myapp
php bin/ink migrate        # 建示例用的数据表（SQLite：storage/db.sqlite）
php bin/ink serve          # 开发服务器：http://127.0.0.1:8000
```

打开 http://127.0.0.1:8000 ，首页列出了仓库自带的示例：留言板、实时搜索、登录、后台、JSON API。

**写第一个页面。** 新建 `pages/hello.php`：

```php
<?php
[$name] = params(0, 1);                    // /hello 或 /hello/<名字>
layout('site', ['title' => 'Hello']);
?>
<h1><?= e(t(['zh' => '你好，{n}！', 'en' => 'Hello, {n}!'], ['n' => $name ?? 'inkphp'])) ?></h1>
```

访问 `/hello` 和 `/hello/世界`。不用注册路由，也不用重启。

**加一个表单。** 处理 POST 的页面再多两行：`allow('GET', 'POST')` 和 `csrf_check()`。完整写法见 [AGENTS.md](AGENTS.md) 第 5 节和第 17 节。

**常用命令：**

```bash
php bin/ink routes         # 列出所有网址、对应文件、参数个数、允许的方法
php bin/ink check          # 语法 + 项目规则检查（提交前跑一次）
php bin/ink test           # 运行 tests/*.txt 路由表
php bin/ink migrate        # 执行 migrations/*.sql 里新的迁移
```

**后台登录**（示例 `/admin`）：复制 `.env.php.example` 为 `.env.php`，把 `APP_ENV` 改成 `'dev'`，按文件里的注释生成 `ADMIN_PASSWORD_HASH`。
本机开发时 `.env.php` 不是必需的；但只要有这个文件，它的 `APP_ENV` 就优先于 `bin/ink serve` 设置的 `dev`。

## 目录

```
public/      网站根目录：只有 index.php、静态文件（assets/）和上传文件（uploads/）
pages/       页面：网址 = 文件
layouts/     页面外框（layout('site')）和错误页（error.php）
migrations/  SQL 迁移
ink/         内核
bin/ink      命令行
storage/     日志、session、限流计数、SQLite 数据库（不进 git）
tests/       路由表
deploy/      nginx / 宝塔 配置和部署指南
```

## 和 AI 一起用

让你的 AI 编程助手先读 **[AGENTS-QUICK.md](AGENTS-QUICK.md)**（速查，约 190 行，日常写页面够用），需要细节时再读 **[AGENTS.md](AGENTS.md)**。那是完整的开发契约：每个函数的签名、行为和例子，路由规则，安全规则，`bin/ink check` 检查什么，以及三个完整的单文件示例。
读完它，不需要读内核源码就能正确地写功能。

## 文档

| 文档 | 内容 |
|---|---|
| [AGENTS-QUICK.md](AGENTS-QUICK.md) | 速查：规则、骨架、全部函数、常见陷阱（AI 先读这份） |
| [AGENTS.md](AGENTS.md) | 开发契约：函数参考、路由、安全、测试、示例（给 AI，也给人） |
| [deploy/README.md](deploy/README.md) | 部署：nginx、宝塔、Apache、Cloudflare、PHP-FPM 池、目录权限 |
| [docs/SPEC.md](docs/SPEC.md) | 设计规格（英文），代码和文档都以它为准 |
| [CHANGELOG.md](CHANGELOG.md) | 版本变化，以及从 0.x 迁移 |

## 不做什么

MVC、ORM / 查询构造器、插件、主题、中间件、依赖注入容器、事件、模板引擎、构建步骤。需要它们的项目，更适合用 Laravel / Symfony。
inkphp 适合官网、落地页、后台、中小型应用和 API，也适合"让 AI 帮你写、你来审"的项目。

## 许可证

MIT，见 [LICENSE](LICENSE)。

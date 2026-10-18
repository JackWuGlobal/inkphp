# inkphp 部署指南

部署 inkphp 只需要三件事：

1. **把代码复制到服务器**（`git clone` / `git pull` / 上传压缩包都行，没有构建步骤）。
2. **网站根目录指向 `public/`**，并把"不存在的文件"交给 `public/index.php`。路由在 PHP 里，不在 nginx 里。
3. **让 PHP 能写 `storage/` 和 `public/uploads/`**，代码本身对 PHP 只读。

本目录的文件：

| 文件 | 用途 |
|---|---|
| `nginx.conf` | 一个完整的 nginx `server {}`，复制后改 3 处（标了 `CHANGE`）；要 HTTPS 时再打开证书那几行 |
| `baota.conf` | 宝塔面板「伪静态」规则 |
| `../public/.htaccess` | Apache / 虚拟主机（已在 `public/` 里，无需复制） |

> 开发时不需要任何 web 服务器：`php bin/ink serve` 即可（只监听 127.0.0.1，`APP_ENV=dev`）。**不要**用 `php -S` 跑生产环境。

---

## 1. 服务器要求

- PHP ≥ 8.2（推荐 8.4），扩展：`pdo` + 你用的驱动（`pdo_sqlite` / `pdo_pgsql` / `pdo_mysql`）、`fileinfo`（`store_upload()` 需要）、`session`、`json`、`ctype`。
- nginx（任何现代版本）+ PHP-FPM；或 Apache ≥ 2.4 + mod_rewrite；或宝塔面板。
- 不需要 Composer、npm、Node、Redis、APCu。

## 2. 上线步骤（通用）

以下假设项目放在 `/srv/myapp`，PHP-FPM 以 `www-data` 运行（Debian/Ubuntu 默认）。用了独立 FPM 池（见第 5 节）时，把 `www-data` 换成该池的用户。

```bash
# 1) 代码
sudo git clone https://example.com/you/myapp.git /srv/myapp
cd /srv/myapp

# 2) 机密配置：.env.php 不进 git
sudo cp .env.php.example .env.php
sudo nano .env.php            # APP_ENV 必须是 'prod'；填数据库账号等

# 3) 权限（见第 3 节）
sudo chown -R root:root /srv/myapp && sudo chmod -R u=rwX,go=rX /srv/myapp
sudo chown -R www-data:www-data storage public/uploads
sudo chmod -R u=rwX,g=rX,o= storage
sudo chown root:www-data .env.php && sudo chmod 640 .env.php

# 4) 检查 + 建表：一定要以 PHP 的运行用户执行（否则生成的文件属于 root，PHP 写不进去）
sudo -u www-data php bin/ink check
sudo -u www-data php bin/ink migrate

# 5) web 服务器：nginx 见第 4 节，宝塔见第 7 节，Apache 见第 8 节

# 6) 冒烟测试
curl -sI https://example.com/            # 200，有 X-Content-Type-Options: nosniff，没有 X-Powered-By
curl -sI https://example.com/about.php   # 301 → /about
curl -sI https://example.com/.env.php    # 404
```

`APP_ENV` 的取值顺序：`.env.php` 里的 `APP_ENV` > 进程环境变量 `APP_ENV` > `config.php` 的 `'env'` > `prod`。
不认识的值一律当作 `prod`。**生产环境绝不能是 `dev`**（`dev` 会在错误页里显示异常信息和调用栈）。

## 3. 目录权限

| 路径 | 谁要写 | 建议 |
|---|---|---|
| 代码（`ink/` `pages/` `layouts/` `public/` `config.php` …） | 只有部署用户 | PHP 和 nginx 只读 |
| `storage/`（`logs/` `sessions/` `ratelimit/` `db.sqlite`） | PHP | 属于 PHP 用户，`o=` 不给其他人读（里面有 session 和数据库） |
| `public/uploads/` | PHP | 属于 PHP 用户；nginx 要能读（`store_upload()` 存的文件是 0644） |
| `.env.php` | 没人写 | `640`，组 = PHP 用户 |

注意：

- SQLite 不只写 `storage/db.sqlite`，还要在 `storage/` 里建临时日志文件，所以 **`storage/` 目录本身**也必须可写。
- 用 root 跑过 `bin/ink`（比如 `migrate`）后，`storage/db.sqlite` 和当天的日志会属于 root，PHP 就写不进去了：
  数据库写操作变成 500（"attempt to write a readonly database"），日志退回到 PHP 的 `error_log`。修复：`sudo chown -R www-data:www-data storage`。以后用 `sudo -u www-data php bin/ink …`。
- `git pull` 不会动 `storage/` 里的运行数据（它们在 `.gitignore` 里，目录靠 `.gitkeep` 保留）。

## 4. nginx

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/myapp.conf
sudo nano /etc/nginx/sites-available/myapp.conf     # 改 server_name、root、fastcgi_pass、证书
sudo ln -s /etc/nginx/sites-available/myapp.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

这个 server 块只做四件事：

1. 隐藏文件和目录（`.git`、`.env.php`、`.htaccess` …）→ 404；`/.well-known/` 例外（申请证书要用）。
2. 任何以 `.php`（以及 `.php5`/`.php7`…、`.phtml`、`.phar`）结尾的 URL 都交给路由器，路由器会 301 到去掉 `.php` 的地址或者返回 404。所以 **`public/` 里的 PHP 文件永远不会被直接执行或下载**，`index.php` 也只通过 `@ink` 运行。
3. `public/` 里真实存在的文件直接返回；`/assets/` 加 7 天缓存（`asset()` 生成的链接带 `?v=<修改时间>`，文件一改链接就变），`/assets/` 和 `/uploads/` 加 `nosniff`；
   `/uploads/` 还加 `Content-Security-Policy: sandbox; default-src 'none'`：访客上传的文件和网站同源，直接打开时浏览器也不会运行里面的脚本。
4. 其他请求一律交给 `public/index.php`（`SCRIPT_FILENAME` 固定，`include fastcgi_params` 写在覆盖项之前，所以 `SCRIPT_NAME` / `PHP_SELF` 永远是 `/index.php`）。

其他说明：

- `root` 必须是 `public/`，**绝不能**是项目根目录。
- `client_max_body_size 8m` 要和 PHP 的 `upload_max_filesize` / `post_max_size` 一起调，并且大于页面里 `store_upload()` 的 `$maxBytes`（默认 5 MB）。
  PHP 的出厂值是 `upload_max_filesize = 2M`，比 `store_upload()` 的默认上限还小，请在 FPM 池里调大（第 5 节）。
  请求体超过 `post_max_size` 时 PHP 会把 `$_POST` 整个丢掉，表现为 **所有提交都 403（CSRF 校验失败）**。
- 有些发行版的 `fastcgi_params`（例如本项目实验用的 Debian nginx 1.26 包）把 `HTTP_HOST` 设成 `$host`，不带端口。生产环境用 80/443 没有影响；如果需要固定的绝对地址，在 `config.php` 里设置 `'base_url' => 'https://example.com'`，`url()` 就会用它。
- 想在 nginx 层再加一道限流（推荐给登录和 API）：

  ```nginx
  # http {} 级，例如 /etc/nginx/conf.d/myapp-limit.conf
  limit_req_zone $binary_remote_addr zone=myapp_api:10m rate=5r/s;
  ```

  然后在 `location @ink {}` 里加 `limit_req zone=myapp_api burst=20 nodelay; limit_req_status 429;`。
  在 Cloudflare 后面时，必须先配好真实 IP（第 6 节），否则限的是 Cloudflare 节点的 IP。

## 5. 独立的 PHP-FPM 池（推荐）

**不要在 nginx 里用 `fastcgi_param PHP_VALUE` / `PHP_ADMIN_VALUE`。** 这两个参数改的是处理这个请求的 FPM 进程，
改完不会恢复。共享池里的进程下一次处理**别的网站**的请求时，用的还是这个值。

这不是理论风险。在本项目的实验服务器上，nginx 把请求交给系统共享池 `/run/php/php8.4-fpm.sock`，结果 inkphp 的每个请求都失败了：

```
PHP Warning: open_basedir restriction in effect. File(/srv/…/public/index.php) is not within
the allowed path(s): (/var/www/another-site/:/tmp/)
```

原因是同一台机器上另一个网站的配置里写了 `fastcgi_param PHP_ADMIN_VALUE "open_basedir=$document_root/:/tmp/"`，它的值留在了共享的 FPM 进程里。

正确做法：每个应用一个 FPM 池，`open_basedir`、上传上限等都写在池配置里。

```ini
; /etc/php/8.4/fpm/pool.d/myapp.conf
[myapp]
user = myapp
group = myapp
listen = /run/php/myapp.sock
listen.owner = www-data          ; nginx 的用户要能连这个 socket
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 8              ; 按内存算：每个进程约 30–60 MB
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 1000
request_terminate_timeout = 30s
request_slowlog_timeout = 3s
slowlog = /srv/myapp/storage/logs/php-slow.log

php_admin_value[open_basedir] = /srv/myapp/:/tmp/      ; 项目根目录，不是 public/
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /srv/myapp/storage/logs/php-error.log
php_admin_value[upload_max_filesize] = 8M
php_admin_value[post_max_size] = 10M
php_admin_value[memory_limit] = 128M
```

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin myapp
sudo chown -R myapp:myapp /srv/myapp/storage /srv/myapp/public/uploads
sudo chown root:myapp /srv/myapp/.env.php
sudo php-fpm8.4 -t && sudo systemctl reload php8.4-fpm
# nginx.conf 里：fastcgi_pass unix:/run/php/myapp.sock;
```

好处：别的网站的 `PHP_ADMIN_VALUE` 影响不到你；你的慢请求也不会占满别的网站的进程；`open_basedir` 只放行本项目。
实验服务器上就是用这样一个独立池（`open_basedir` = 项目根目录 + `/tmp/`）验证的 `deploy/nginx.conf`。

## 6. Cloudflare

### 6.1 SSL 模式

推荐 **Full (strict)**：源站 nginx 监听 443，证书用 Cloudflare Origin CA 或 Let's Encrypt。这样 PHP 能直接看到 HTTPS（`is_https()` 为真，
session cookie 用 `__Host-ink` 并带 `Secure`），`config.php` 里的 `trust_proxy` 保持 `false`。

只有在 **Flexible**（Cloudflare → 源站走 http）时才设 `'trust_proxy' => true`：此时 `is_https()` 会相信 `X-Forwarded-Proto` / `CF-Visitor`。
这两个请求头谁都能伪造，所以开了 `trust_proxy` 就**必须**让源站只接受 Cloudflare 的连接（6.3）。

### 6.2 真实访客 IP

`client_ip()` 只读 `REMOTE_ADDR`，从不读 `X-Forwarded-For`（可伪造）。在 Cloudflare 后面，要由 nginx 把 `REMOTE_ADDR` 换成真实访客 IP：

```bash
{
  echo "# Cloudflare IP ranges, generated $(date -u +%F) from https://www.cloudflare.com/ips/"
  for ip in $(curl -fsS https://www.cloudflare.com/ips-v4) $(curl -fsS https://www.cloudflare.com/ips-v6); do
    echo "set_real_ip_from $ip;"
  done
  echo "real_ip_header CF-Connecting-IP;"
} | sudo tee /etc/nginx/snippets/cloudflare-realip.conf
```

然后取消 `deploy/nginx.conf` 里 `include /etc/nginx/snippets/cloudflare-realip.conf;` 的注释，`sudo nginx -t && sudo systemctl reload nginx`。
Cloudflare 的网段偶尔会变，可以每月重新生成一次（cron）。

`set_real_ip_from` 只信任来自 Cloudflare 网段的 `CF-Connecting-IP`，直接连源站的人伪造这个头没有用。
实验服务器上用同样的配置验证过：带 `CF-Connecting-IP` 时 `client_ip()` 返回该 IP（IPv4/IPv6 都可以），只带 `X-Forwarded-For` 时不受影响。

### 6.3 源站只放行 Cloudflare（推荐）

否则别人可以绕过 Cloudflare 的 WAF 和限流，直接打源站。用 ufw 的例子（执行前先确认 SSH 端口已放行，并删掉原来放行 80/443 的规则）：

```bash
for ip in $(curl -fsS https://www.cloudflare.com/ips-v4) $(curl -fsS https://www.cloudflare.com/ips-v6); do
  sudo ufw allow proto tcp from "$ip" to any port 80,443
done
```

也可以改用 Cloudflare 的 Authenticated Origin Pulls（mTLS）。

### 6.4 缓存

- 内核默认给所有 PHP 响应发 `Cache-Control: private, no-cache`，Cloudflare 不会缓存。
- 页面调用 `cache_public($seconds)` 后发 `public, max-age=min(60,$seconds), s-maxage=$seconds`。Cloudflare 默认**不缓存 HTML**，
  需要在 Cloudflare → Caching → Cache Rules 里为这些路径开启「Eligible for cache」并使用源站的 Cache-Control。
- 本次请求开过 session 或要发 Set-Cookie 时，内核会自动把 `public` 降级为 `private, no-cache` 并记一条 `warn` 日志，所以登录用户的页面不会被缓存。
- `config.php` 设了 `'trust_proxy' => true`（源站只接受 Cloudflare 的连接，6.3）时，日志里的 `rid` 就是 `CF-Ray`，可以和 Cloudflare 的日志对上。
  `trust_proxy` 为 `false` 时 `rid` 是随机的：`CF-Ray` 头谁都能伪造。

## 7. 宝塔面板

1. **软件商店**：装 Nginx 和 PHP 8.4（≥ 8.2）。PHP 设置 → 安装扩展：确认 `fileinfo` 已安装（`store_upload()` 需要；宝塔编译的 PHP 常常要在这里手动安装）。
2. **添加站点**：根目录选**项目根目录**（例如 `/www/wwwroot/example.com`，里面有 `ink/`、`pages/`、`public/`），PHP 版本选 8.4。把代码放进去。
3. **网站设置 → 网站目录**：「运行目录」选 `/public`，保存。
4. **伪静态**：把 `deploy/baota.conf` 的全部内容粘贴进去，保存。
5. **权限**：宝塔的 PHP 以 `www` 用户运行：
   ```bash
   cd /www/wwwroot/example.com
   chown -R www:www storage public/uploads
   cp .env.php.example .env.php && chown root:www .env.php && chmod 640 .env.php   # 再编辑 .env.php
   sudo -u www /www/server/php/84/bin/php bin/ink check
   sudo -u www /www/server/php/84/bin/php bin/ink migrate
   ```
6. **SSL**：宝塔 → SSL → Let's Encrypt。`baota.conf` 保留了 `/.well-known/`，证书验证不受影响。

宝塔下的已知差异（实验室里用宝塔同款 nginx 规则复现过）：

- 宝塔自带的 PHP 规则（`enable-php-84.conf` 里的 `location ~ [^/]\.php(/|$)`）排在伪静态**前面**，它会直接执行 `public/` 下的**任何** `.php` 文件。
  - `baota.conf` 已经用 `location ^~ /uploads/` 把上传目录里的 PHP 文件拦成 404，并加了 `nosniff` 和 CSP `sandbox` 头。
  - 其他位置靠规则保证：`public/` 下除 `index.php` 外不能有任何 `.php`，`bin/ink check` 会把它当作错误。
  - `/about.php` 这类网址在宝塔下返回 404，而不是 301 到 `/about`。这个差异无害。
- **更安全的做法（推荐）**：网站设置 → 配置文件：
  1. 删掉 `include enable-php-84.conf;` 那一行。
  2. 把 `deploy/nginx.conf` 里从 `location ~ /\.(?!well-known/)` 到 `location @ink { … }` 的几段 location 粘贴进去，`fastcgi_pass` 改成 `enable-php-84.conf` 里的那个 socket（通常是 `unix:/tmp/php-cgi-84.sock`）。
  3. 把伪静态清空。

  这样宝塔的行为就和第 4 节的 nginx 完全一致。注意：在面板里切换 PHP 版本时，宝塔会重写这个文件，切换后要重新检查。
- 「防跨站攻击」（open_basedir）必须放行**项目根目录**，不能只放行 `public/`，因为内核在 `public/` 的上一级。
  如果页面空白、日志里有 `open_basedir restriction in effect`，就是这里只放行了 `public/`。
- 宝塔自带的静态规则（`location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$` 等）也排在伪静态前面，所以 `pages/og.png.php` 这类"动态图片"网址在宝塔下会 404。

## 8. Apache / 虚拟主机

`public/.htaccess` 已经写好：真实文件直接返回，`.php` 文件和隐藏路径（`/.well-known/` 除外）绝不当文件返回，其他一切交给 `index.php`。
它需要 **Apache ≥ 2.4**（用到 `[END]`）和 mod_rewrite。（本仓库的自动测试只覆盖 nginx 和 `php -S`，没有在 Apache 上实测。）

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /srv/myapp/public
    <Directory /srv/myapp/public>
        AllowOverride All
        Require all granted
        # 用 PHP-FPM/CGI 时，页面要读 Authorization 头（Bearer token）必须有下面这一行（Apache ≥ 2.4.13）
        CGIPassAuth On
    </Directory>
</VirtualHost>
```

- `DocumentRoot` 必须是 `public/`。虚拟主机只允许用固定的 `public_html` 时，把项目放在它的上一级，再让 `public_html` 指向 `public/`（软链接，或者主机面板里改网站目录）。
  做不到就不要在这种主机上用 inkphp，因为 `pages/`、`.env.php` 绝不能放在网站根目录里。
- 权限同第 3 节，Apache/PHP 的用户通常是 `www-data` 或 `apache`。

## 9. 更新与发布

最简单：

```bash
cd /srv/myapp
sudo git pull
sudo -u www-data php bin/ink check && sudo -u www-data php bin/ink migrate
```

OPcache 默认（`validate_timestamps=1`）几秒内就会发现文件变化，不需要重启 FPM。
如果你关闭了 `opcache.validate_timestamps`，发布后要 `sudo systemctl reload php8.4-fpm`。

想要原子发布、可回滚：每个版本放在 `releases/<版本>/`，`storage/`、`public/uploads/`、`.env.php` 软链接到 `shared/`，
最后 `ln -sfn releases/<版本> current.new && mv -T current.new current` 原子地切换 `current`（nginx 的 `root` 指向 `/srv/myapp/current/public`）。`deploy/nginx.conf` 用的是 `$realpath_root`，切换后新请求立刻使用新版本。
`open_basedir` 要放行整个 `/srv/myapp/`（同时包含 `releases/` 和 `shared/`）。

## 10. 定时任务

```cron
# 删除 30 天前的日志（每天一个文件 storage/logs/YYYY-MM-DD.log，UTC 日期）
15 3 * * * www-data find /srv/myapp/storage/logs -name '*.log' -mtime +30 -delete
# 可选（访问量很小的网站）：删除旧的 session 文件和限流计数文件
20 3 * * * www-data find /srv/myapp/storage/sessions -name 'sess_*' -mmin +1440 -delete
25 3 * * * www-data find /srv/myapp/storage/ratelimit -type f -mmin +1440 -delete
```

内核自己会清理：每次开启 session 有 1% 的概率删除旧 session 文件；每次 `rate_limit()` 有 1% 的概率删除窗口已经结束的计数文件
（计数文件的修改时间被设成窗口结束的时间，所以上面的 `find -mmin` 也不会删掉还在窗口里的计数）。访问量很小的网站很少触发这些清理，可以加上面两条可选的 cron。
session 的闲置超时不靠清理：每次开启 session 时都会检查（`config('session_lifetime')`），上面的 `-mmin +1440` 要不小于这个值（默认 86400 秒 = 1440 分钟）。
放在 `/etc/cron.d/myapp` 时需要上面这样的「用户」一列；用 `crontab -e` 时去掉 `www-data`。

## 11. HSTS 和 CSP（推荐）

内核默认的安全头里没有这两个，因为它们和部署方式有关：

- **HSTS**：所有访客都能用 HTTPS 访问、并且确定以后一直用 HTTPS 时，打开 `deploy/nginx.conf` TLS 部分那行注释：
  `add_header Strict-Transport-Security "max-age=31536000" always;`（宝塔：SSL 页面里的「强制 HTTPS」和 HSTS 开关）。
- **CSP**：示例应用（htmx 从 `/assets/` 加载，没有内联脚本）可以用下面这条。写在 `location @ink {}` 里（只给页面加，不影响静态文件）：
  ```nginx
  add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'" always;
  ```
  `style-src 'unsafe-inline'` 是给 htmx 自己插入的指示器样式用的。用了 `hx-on:*` 属性或内联 `<script>` 时，这条 CSP 会拦住它们：改成外部 `.js` 文件，或者按需放宽。
  先用 `Content-Security-Policy-Report-Only` 试一段时间，看浏览器控制台有没有被拦的内容。

## 12. 常见问题

| 现象 | 原因与处理 |
|---|---|
| 所有 PHP 请求 `No input file specified` / 502 | FPM 进程带着别的网站的 `open_basedir`（第 5 节），或 `open_basedir` 只放行了 `public/`。用独立 FPM 池，并把 `open_basedir` 设为项目根目录 |
| 所有表单提交都 403 | 请求体超过 `post_max_size`，PHP 丢弃了 `$_POST`（连 `_csrf` 一起）。调大 `post_max_size` / `upload_max_filesize` / `client_max_body_size` |
| 写数据库全部 500 | SQLite：`storage/` 或 `db.sqlite` 不可写（日志里是 "attempt to write a readonly database"，常见原因是用 root 跑过 `bin/ink`） |
| 数据库相关页面全部 503 | 连不上数据库（详见日志，错误页不会显示 DSN 和密码），或者数据库一直被锁/死锁 |
| 数据库相关页面全部 500，日志里是 "no such table" | 还没执行 `bin/ink migrate` |
| `storage/logs/` 里没有日志 | 目录不可写，日志退回到 PHP 的 `error_log`（FPM 池的 `php_admin_value[error_log]`，或 nginx 的 error.log） |
| `client_ip()` 总是 Cloudflare 的 IP | 没配 `real_ip`（第 6.2 节） |
| 网站是 https，但 `url()` 生成的是 `http://` 地址 | 源站走 http（Cloudflare Flexible），PHP 看不到 https。改用 Full (strict)，或设 `'base_url'`，或设 `trust_proxy` 并锁定源站（第 6 节） |
| 页面上显示了异常信息和调用栈 | `APP_ENV` 是 `dev`。检查 `.env.php` |

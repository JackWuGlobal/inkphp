<?php
// Home page: "/" only. params() with no arguments = no path params, so "/anything-else" is a 404.
// Text is translated inline with t(['zh' => …, 'en' => …]); the language comes from locale().
params();
layout('site');

// [URL, file that serves it, what it shows]. A URL without a leading "/" is not a link (POST only).
$examples = [
    ['/about', 'pages/about.php', ['zh' => '普通页面 + 布局，长段落的双语写法', 'en' => 'A plain page in the layout, long bilingual text']],
    ['/guestbook', 'pages/guestbook.php', ['zh' => '表单、CSRF、校验、SQL、flash、htmx 局部刷新', 'en' => 'Form, CSRF, validation, SQL, flash, htmx partial refresh']],
    ['/search?q=ink', 'pages/search.php', ['zh' => 'htmx 实时搜索', 'en' => 'Live search with htmx']],
    ['/search/ink', 'pages/search.php', ['zh' => '同一个文件，路径参数 params(0, 1)', 'en' => 'Same file, path param via params(0, 1)']],
    ['/login', 'pages/login.php', ['zh' => '登录：password_verify、限流、只接受本站 next=', 'en' => 'Log in: password_verify, rate limit, local-only next=']],
    ['POST /logout', 'pages/logout.php', ['zh' => '退出（只接受带 CSRF 的 POST）', 'en' => 'Log out (POST with CSRF only)']],
    ['/admin', 'pages/admin.php', ['zh' => '后台：require_login，删除留言（htmx 原地删除）', 'en' => 'Admin: require_login, delete entries (in place with htmx)']],
    ['/api/notes', 'pages/api/notes.php', ['zh' => 'JSON API：GET 列表，POST 新建', 'en' => 'JSON API: GET list, POST create']],
    ['/robots.txt', 'pages/robots.txt.php', ['zh' => '动态生成的 robots.txt，允许 CDN 缓存', 'en' => 'A generated robots.txt that a CDN may cache']],
];
?>
<h1><?= e(t(['zh' => '你好，这里是 inkphp', 'en' => 'Hello from inkphp'])) ?></h1>

<p><?= e(t([
    'zh' => '一个网址 = 一个文件 = 一个功能。下面每个网址都由 pages/ 里的一个文件完整实现：参数、请求方法、登录、CSRF、校验、SQL 和 HTML 都写在这个文件里。除了页面自己调用的函数，没有任何东西会悄悄运行。',
    'en' => 'One URL = one file = one feature. Each URL below is implemented completely by one file in pages/: params, methods, login, CSRF, validation, SQL and HTML all live in that file. Nothing runs unless the page calls it.',
])) ?></p>

<div class="scroll">
    <table>
        <thead>
            <tr>
                <th><?= e(t(['zh' => '网址', 'en' => 'URL'])) ?></th>
                <th><?= e(t(['zh' => '文件', 'en' => 'File'])) ?></th>
                <th><?= e(t(['zh' => '演示内容', 'en' => 'Shows'])) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($examples as [$url, $file, $what]): ?>
                <tr>
                    <td><?php if ($url[0] === '/'): ?><a href="<?= e($url) ?>"><?= e($url) ?></a><?php else: ?><?= e($url) ?><?php endif ?></td>
                    <td><code><?= e($file) ?></code></td>
                    <td><?= e(t($what)) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>

<h2><?= e(t(['zh' => '一个页面长什么样', 'en' => 'What a page looks like'])) ?></h2>
<pre><code><?= e(<<<'PHP'
<?php // pages/hello.php → /hello/<name>
[$name] = params(1);                  // exactly one path param, else 404
layout('site', ['title' => 'Hello']);
?>
<h1>Hello, <?= e($name) ?></h1>
PHP) ?></code></pre>

<p class="muted"><?= e(t([
    'zh' => '先运行迁移（migrations/*.sql）建好数据表，留言板、搜索、后台和 API 才能用。后台账号在 .env.php 里设置（见 .env.php.example）。',
    'en' => 'Run the migrations (migrations/*.sql) first: the guestbook, search, admin and API need their tables. The admin account is set in .env.php (see .env.php.example).',
])) ?></p>

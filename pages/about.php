<?php
// About: a plain page. params() = no path params; no allow() = GET and HEAD only.
// Short strings use t([...]); for long text a locale() switch keeps each language readable as a block.
params();
layout('site', ['title' => t(['zh' => '关于', 'en' => 'About'])]);
?>
<?php if (locale() === 'zh'): ?>
    <h1>关于 inkphp</h1>
    <p>inkphp 是一个为 AI 编程助手（以及人）设计的小型 PHP 框架：读一个文件，就能知道一个网址做了什么。</p>
    <ul>
        <li><strong>一个网址 = 一个文件 = 一个功能。</strong><code>/about</code> 就是 <code>pages/about.php</code>。</li>
        <li><strong>不调用就不运行。</strong>没有中间件、守卫文件或自动加载的文件；页面需要什么，就自己调用什么。</li>
        <li><strong>默认安全。</strong>最短的写法就是安全的写法：<code>e()</code> 转义输出，SQL 只用参数，表单有 CSRF，跳转只去本站。</li>
        <li><strong>零依赖、零构建。</strong>不用 Composer，不用 npm；部署就是复制文件。</li>
    </ul>
    <p>这个页面就是一个普通的 PHP 文件，外面套了 <code>layouts/site.php</code>。</p>
<?php else: ?>
    <h1>About inkphp</h1>
    <p>inkphp is a small PHP framework made for AI coding agents (and people): read one file and you know what a URL does.</p>
    <ul>
        <li><strong>One URL = one file = one feature.</strong> <code>/about</code> is <code>pages/about.php</code>.</li>
        <li><strong>Nothing runs that the page didn't call.</strong> No middleware, guard files or auto-included files; a page calls what it needs.</li>
        <li><strong>Safe by default.</strong> The shortest way is the safe way: <code>e()</code> for output, parameterized SQL only, CSRF on forms, local-only redirects.</li>
        <li><strong>Zero dependencies, zero build.</strong> No Composer, no npm; deploying is copying files.</li>
    </ul>
    <p>This page is a plain PHP file wrapped in <code>layouts/site.php</code>.</p>
<?php endif ?>

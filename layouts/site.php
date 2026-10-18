<?php
// The site frame for pages that call layout('site', ['title' => …]).
// Vars: $content (the page's HTML output), $title (optional plain text).
// It shows the nav, the language switch, login state and one flash('ok') message. user() and flash() never
// start a session for a visitor who has no session cookie, so plain pages stay cookie-free.
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
$nav = [
    '/' => t(['zh' => '首页', 'en' => 'Home']),
    '/about' => t(['zh' => '关于', 'en' => 'About']),
    '/guestbook' => t(['zh' => '留言板', 'en' => 'Guestbook']),
    '/search' => t(['zh' => '搜索', 'en' => 'Search']),
    '/admin' => t(['zh' => '后台', 'en' => 'Admin']),
];
$languageNames = ['zh' => '中文', 'en' => 'English'];
$ok = flash('ok');
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? '') !== '' ? $title . ' · inkphp' : 'inkphp') ?></title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <script src="<?= e(asset('htmx.min.js')) ?>" defer></script>
</head>
<body>
<header class="top">
    <nav class="wrap">
        <a class="brand" href="/">inkphp</a>
        <?php foreach ($nav as $href => $label): ?>
            <?php $current = $href === $path || ($href !== '/' && str_starts_with($path, $href . '/')); ?>
            <a href="<?= e($href) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
        <span class="spacer"></span>
        <?php foreach ((array) config('locales', ['zh', 'en']) as $code): ?>
            <?php if ($code === locale()): ?>
                <strong class="lang"><?= e($languageNames[$code] ?? $code) ?></strong>
            <?php else: ?>
                <a class="lang" href="?<?= e(http_build_query(['lang' => $code] + $_GET)) ?>" hreflang="<?= e($code) ?>"><?= e($languageNames[$code] ?? $code) ?></a>
            <?php endif ?>
        <?php endforeach ?>
        <?php if (user() !== null): ?>
            <form class="inline" method="post" action="/logout">
                <?= csrf_field() ?>
                <button class="link"><?= e(t(['zh' => '退出', 'en' => 'Log out'])) ?></button>
            </form>
        <?php else: ?>
            <a href="/login"><?= e(t(['zh' => '登录', 'en' => 'Log in'])) ?></a>
        <?php endif ?>
    </nav>
</header>
<main class="wrap">
    <?php if ($ok !== null): ?>
        <p class="flash" role="status"><?= e($ok) ?></p>
    <?php endif ?>
    <?= $content ?>
</main>
<?php if (APP_ENV === 'dev'): // version and timing help while developing; prod does not advertise them ?>
    <footer class="wrap">
        inkphp <?= e(INK_VERSION) ?> · <?= e(number_format((hrtime(true) - INK_START) / 1e6, 1)) ?> ms
    </footer>
<?php endif ?>
</body>
</html>

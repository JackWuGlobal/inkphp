<?php
// The site frame for pages that call layout('site', ['title' => …]).
// Vars: $content (the page's HTML output), $title (optional plain text).
// It shows the nav, the language switch, login state and one flash('ok') message. user() and flash() never
// start a session for a visitor who has no session cookie, so plain pages stay cookie-free.
// Languages: every link goes through href() (keeps /en); locale_urls() gives this page in each language, used for
// the language switch and for the hreflang / canonical tags search engines read.
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
$versions = locale_urls();           // ['zh' => '/about', 'en' => '/en/about', 'x-default' => '/about']
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
    <?php if (config('locale_url')): // one URL per language: tell search engines about all of them ?>
        <link rel="canonical" href="<?= e(url($versions[locale()])) ?>">
        <?php foreach ($versions as $code => $u): ?>
            <link rel="alternate" hreflang="<?= e($code) ?>" href="<?= e(url($u)) ?>">
        <?php endforeach ?>
    <?php endif ?>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <script src="<?= e(asset('htmx.min.js')) ?>" defer></script>
</head>
<body>
<header class="top">
    <nav class="wrap">
        <a class="brand" href="<?= e(href('/')) ?>">inkphp</a>
        <?php foreach ($nav as $to => $label): ?>
            <?php $link = href($to); $current = $link === $path || ($to !== '/' && str_starts_with($path, $link . '/')); ?>
            <a href="<?= e($link) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
        <span class="spacer"></span>
        <?php foreach ((array) config('locales', ['zh', 'en']) as $code): ?>
            <?php if ($code === locale()): ?>
                <strong class="lang"><?= e($languageNames[$code] ?? $code) ?></strong>
            <?php else: ?>
                <a class="lang" href="<?= e($versions[$code]) ?>" hreflang="<?= e($code) ?>"><?= e($languageNames[$code] ?? $code) ?></a>
            <?php endif ?>
        <?php endforeach ?>
        <?php if (user() !== null): ?>
            <form class="inline" method="post" action="<?= e(href('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="link"><?= e(t(['zh' => '退出', 'en' => 'Log out'])) ?></button>
            </form>
        <?php else: ?>
            <a href="<?= e(href('/login')) ?>"><?= e(t(['zh' => '登录', 'en' => 'Log in'])) ?></a>
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

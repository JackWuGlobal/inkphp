<?php
// Error page used by abort() and by the kernel for 404/405/500/503 …  Vars: $code, $message, $rid.
// Keep it static and simple: it must not fail. In dev the kernel adds the error details before </body>.
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($code) ?> <?= e($message) ?></title>
    <style>
        body { font: 16px/1.6 system-ui, sans-serif; max-width: 40rem; margin: 12vh auto; padding: 0 1rem; color: #222; }
        h1 { font-size: 4rem; margin: 0; color: #888; }
        small { color: #999; }
    </style>
</head>
<body>
    <h1><?= e($code) ?></h1>
    <p><?= e($message) ?></p>
    <p><a href="<?= e(href('/')) ?>">← Home / 首页</a></p>
    <small>ref <?= e($rid) ?></small>
</body>
</html>

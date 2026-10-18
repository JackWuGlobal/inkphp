<?php
// 调试页面 - 不引入 init.php，直接查看原始环境变量
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug - 环境变量诊断</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        h2 { color: #333; border-bottom: 2px solid #333; padding-bottom: 5px; }
        table { background: white; border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #333; color: white; }
        .value { color: #0066cc; }
    </style>
</head>
<body>
    <h1>inkphp 环境诊断</h1>

    <h2>当前URL: <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A') ?></h2>

    <h2>关键服务器变量</h2>
    <table>
        <tr><th>变量名</th><th>值</th></tr>
        <tr><td>REQUEST_URI</td><td class="value"><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'NOT SET') ?></td></tr>
        <tr><td>SCRIPT_NAME</td><td class="value"><?= htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? 'NOT SET') ?></td></tr>
        <tr><td>SCRIPT_FILENAME</td><td class="value"><?= htmlspecialchars($_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET') ?></td></tr>
        <tr><td>PATH_INFO</td><td class="value"><?= htmlspecialchars($_SERVER['PATH_INFO'] ?? 'NOT SET') ?></td></tr>
        <tr><td>DOCUMENT_ROOT</td><td class="value"><?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET') ?></td></tr>
        <tr><td>PHP_SELF</td><td class="value"><?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'NOT SET') ?></td></tr>
    </table>

    <h2>FastCGI 相关变量</h2>
    <table>
        <tr><th>变量名</th><th>值</th></tr>
        <?php foreach ($_SERVER as $key => $value): ?>
            <?php if (stripos($key, 'FASTCGI') !== false || stripos($key, 'SCRIPT') !== false || stripos($key, 'PATH') !== false): ?>
                <tr>
                    <td><?= htmlspecialchars($key) ?></td>
                    <td class="value"><?= htmlspecialchars($value) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
    </table>

    <h2>$_GET 参数</h2>
    <table>
        <tr><th>键</th><th>值</th></tr>
        <?php if (empty($_GET)): ?>
            <tr><td colspan="2">无参数</td></tr>
        <?php else: ?>
            <?php foreach ($_GET as $key => $value): ?>
                <tr>
                    <td><?= htmlspecialchars($key) ?></td>
                    <td class="value"><?= htmlspecialchars($value) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <h2>测试URL</h2>
    <ul>
        <li><a href="/debug">/debug</a> - 基础路径</li>
        <li><a href="/debug/123">/debug/123</a> - 一个参数</li>
        <li><a href="/debug/123/edit">/debug/123/edit</a> - 两个参数</li>
    </ul>
</body>
</html>

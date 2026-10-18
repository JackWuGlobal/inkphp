<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';

// 测试案例：
// - key1 是必填（无默认值）
// - key2 是可选（有默认值）
$id = $_GET['key1'];
$action = $_GET['key2'] ?? 'view';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>架构测试页面</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-8">
        <h1 class="text-4xl font-bold mb-6">inkphp 架构测试</h1>

        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-2xl font-bold mb-4">当前请求信息</h2>
            <div class="space-y-2">
                <p><strong>ID (key1):</strong> <?= htmlspecialchars($id) ?></p>
                <p><strong>Action (key2):</strong> <?= htmlspecialchars($action) ?></p>
                <p><strong>PATH_INFO:</strong> <?= htmlspecialchars($_SERVER['PATH_INFO'] ?? '无') ?></p>
                <p><strong>SCRIPT_NAME:</strong> <?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?></p>
            </div>
        </div>

        <div class="bg-blue-50 rounded-lg p-6">
            <h2 class="text-xl font-bold mb-4">测试用例</h2>
            <ul class="space-y-2 text-sm">
                <li>✅ <a href="/test/123" class="text-blue-600 hover:underline">/test/123</a> → ID=123, Action=view (默认)</li>
                <li>✅ <a href="/test/456/edit" class="text-blue-600 hover:underline">/test/456/edit</a> → ID=456, Action=edit</li>
                <li>❌ <a href="/test" class="text-blue-600 hover:underline">/test</a> → 404 (缺少必填参数 key1)</li>
                <li>❌ <a href="/test/789/edit/extra" class="text-blue-600 hover:underline">/test/789/edit/extra</a> → 404 (参数超出上限)</li>
            </ul>
        </div>

        <div class="mt-6 p-4 bg-green-50 rounded">
            <h3 class="font-bold mb-2">架构验证点：</h3>
            <ol class="list-decimal list-inside space-y-1 text-sm">
                <li>Nginx 成功将 /test/123 映射到 test.php + PATH_INFO=/123</li>
                <li>init.php 自动将 PATH_INFO 切片注入 $_GET['key1'], $_GET['key2']</li>
                <li>init.php 扫描本页面源码，检测到 key1 (必填) 和 key2 (可选)</li>
                <li>参数数量校验：拒绝超出 key2 的请求（如 /test/1/2/3）</li>
                <li>必填参数校验：拒绝缺少 key1 的请求（如 /test）</li>
            </ol>
        </div>
    </div>
</body>
</html>

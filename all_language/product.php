<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';
echo $lang;
// 自动感知：key1 是必填，key2 是可选
$id = $_GET['key1'];              // 必填（没有默认值）
$tab = $_GET['key2'] ?? 'info';   // 可选（有默认值）
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>产品详情</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-8">
        <h1 class="text-3xl font-bold mb-4">产品 #<?= htmlspecialchars($id) ?></h1>
        <p class="text-gray-600">当前标签页: <?= htmlspecialchars($tab) ?></p>

        <div class="mt-8 p-4 bg-blue-50 rounded">
            <h2 class="font-bold mb-2">URL 测试：</h2>
            <ul class="space-y-2 text-sm">
                <li>✅ /product/123 → 显示产品 123，默认 info 标签</li>
                <li>✅ /product/123/specs → 显示产品 123，specs 标签</li>
                <li>❌ /product → 404（缺少必填参数 id）</li>
                <li>❌ /product/123/specs/extra → 404（超出最大参数）</li>
            </ul>
        </div>
    </div>
</body>
</html>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';
echo $lang='en';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>欢迎使用 inkphp</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-8">
        <h1 class="text-4xl font-bold text-gray-800 mb-4">欢迎使用 inkphp</h1>
        <p class="text-lg text-gray-600 mb-4">
            极简 PHP 框架 - think more. ink less.
        </p>

        <?php if (isset($_GET['key1'])): ?>
            <div class="p-4 bg-yellow-50 rounded mb-4">
                <p class="text-sm">
                    <strong>提示：</strong>您访问了带参数的首页
                    <?php
                    $params = [];
                    for ($i = 1; $i <= 3; $i++) {
                        if (isset($_GET["key$i"])) {
                            $params[] = "key$i = " . htmlspecialchars($_GET["key$i"]);
                        }
                    }
                    echo '(' . implode(', ', $params) . ')';
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="mt-8 space-y-4">
            <div class="p-4 bg-white rounded shadow">
                <h2 class="font-bold text-xl mb-2">测试路由</h2>
                <ul class="space-y-2 text-sm text-gray-600">
                    <li>→ <a href="/" class="text-blue-600 hover:underline"></a> - 首页</li>
                    <li>→ <a href="/123" class="text-blue-600 hover:underline">123</a> - 首页带参数</li>
                    <li>→ <a href="/about" class="text-blue-600 hover:underline">about</a> - 关于页面</li>
                    <li>→ <a href="/product/123" class="text-blue-600 hover:underline">product/123</a> - 产品详情</li>
                    <li>→ <a href="/product/123/specs" class="text-blue-600 hover:underline">product/123/specs</a> - 产品规格</li>
                </ul>
            </div>

            <div class="p-4 bg-blue-50 rounded">
                <h2 class="font-bold mb-2">路由原理</h2>
                <p class="text-sm text-gray-700">
                    inkphp 使用<strong>物理寻址回溯</strong>和<strong>静态源码感知</strong>：
                </p>
                <ol class="mt-2 text-sm text-gray-700 list-decimal list-inside space-y-1">
                    <li>Nginx 尝试直接匹配物理文件（$uri.php）</li>
                    <li>找到文件后，剩余路径段转为参数</li>
                    <li>init.php 分析源码，自动验证参数数量</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>

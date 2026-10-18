<?php
// 纯净测试 - 打印所有 $_SERVER 变量
header('Content-Type: text/plain; charset=utf-8');

echo "=== RAW SERVER VARIABLES ===\n\n";

echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'NOT SET') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'NOT SET') . "\n";
echo "PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'NOT SET') . "\n";
echo "PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? 'NOT SET') . "\n";
echo "QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'NOT SET') . "\n";

echo "\n=== ALL \$_SERVER ===\n\n";
foreach ($_SERVER as $key => $value) {
    if (!is_string($value)) continue;
    echo sprintf("%-30s = %s\n", $key, $value);
}

echo "\n=== \$_GET ===\n\n";
print_r($_GET);

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';
// 获取URL中的id参数
$id = isset($_GET['key1']) ? $_GET['key1'] : null;
$name = isset($_GET['key2']) ? $_GET['key2'] : null;
// 输出id
if ($id !== null & $name!== null) {
    echo "ID: " . htmlspecialchars($id);
    echo "Name: " . htmlspecialchars($name);
} else {
    abort(404);
}
?>
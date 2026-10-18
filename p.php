<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';
// 获取URL中的id参数
$id = isset($_GET['id']) ? $_GET['id'] : null;
$name = isset($_GET['name']) ? $_GET['name'] : null;
// 输出id
if ($id !== null & $name!== null) {
    echo "ID: " . htmlspecialchars($id);
    echo "Name: " . htmlspecialchars($name);
} else {
    abort(404);
}
?>
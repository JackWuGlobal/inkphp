<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';

// 获取浏览器语言偏好
$lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';

// 解析语言代码
$detected_lang = 'en'; // 默认英文

if ($lang) {
    // 提取第一个语言代码
    preg_match('/^([a-z\-]+)/i', $lang, $matches);
    $primary_lang = strtolower($matches[1] ?? '');

    // 判断语言类型
    if (strpos($primary_lang, 'zh-cn') !== false ||
        strpos($primary_lang, 'zh-hans') !== false ||
        $primary_lang === 'zh') {
        // 简体中文
        $detected_lang = 'zh';
    } elseif (strpos($primary_lang, 'zh-tw') !== false ||
              strpos($primary_lang, 'zh-hk') !== false ||
              strpos($primary_lang, 'zh-hant') !== false) {
        // 繁体中文
        $detected_lang = 'zh-hant';
    } elseif (strpos($primary_lang, 'en') !== false) {
        // 英文
        $detected_lang = 'en';
    }
}

// 执行 302 重定向
header('Location: /' . $detected_lang, true, 302);
exit;

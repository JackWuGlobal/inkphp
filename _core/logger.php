<?php
/**
 * inkphp 访问日志记录器
 *
 * 功能：记录访问用户的时间、IP、浏览器指纹信息
 * 使用：require_once '_core/logger.php'; log_visitor();
 */

/**
 * 获取客户端真实IP地址
 *
 * @return string IP地址
 */
function get_client_ip() {
    $ip = '';

    // 检查代理IP（按优先级）
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 可能包含多个IP，取第一个
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    // 验证IP格式
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return 'UNKNOWN';
}

/**
 * 生成浏览器指纹
 *
 * 基于 User-Agent、Accept-Language、Accept-Encoding 等生成唯一标识
 *
 * @return string 浏览器指纹（MD5哈希）
 */
function get_browser_fingerprint() {
    $components = [
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        $_SERVER['HTTP_ACCEPT'] ?? '',
    ];

    // 生成指纹
    $fingerprint_string = implode('|', $components);
    return md5($fingerprint_string);
}

/**
 * 获取浏览器信息（简化版）
 *
 * @return string 浏览器名称和版本
 */
function get_browser_info() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    // 简单的浏览器识别
    if (preg_match('/MSIE|Trident/i', $user_agent)) {
        return 'IE';
    } elseif (preg_match('/Edge/i', $user_agent)) {
        return 'Edge';
    } elseif (preg_match('/Chrome/i', $user_agent)) {
        return 'Chrome';
    } elseif (preg_match('/Safari/i', $user_agent)) {
        return 'Safari';
    } elseif (preg_match('/Firefox/i', $user_agent)) {
        return 'Firefox';
    } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
        return 'Opera';
    }

    return 'Other';
}

/**
 * 记录访问者信息到日志文件
 *
 * @param string $log_file 日志文件路径（可选，默认为 _logs/access.txt）
 * @return bool 是否记录成功
 */
function log_visitor($log_file = null) {
    // 默认日志文件路径
    if ($log_file === null) {
        $log_file = __DIR__ . '/../_logs/access.txt';
    }

    // 确保日志目录存在
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    // 收集访问信息
    $timestamp = date('Y-m-d H:i:s');
    $ip = get_client_ip();
    $fingerprint = get_browser_fingerprint();
    $browser = get_browser_info();
    $url = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $referer = $_SERVER['HTTP_REFERER'] ?? '-';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '-';

    // 格式化日志行（类似 Apache Combined Log Format，但添加了指纹）
    $log_line = sprintf(
        "[%s] %s %s %s %s | Browser: %s | Fingerprint: %s | Referer: %s | UA: %s\n",
        $timestamp,
        $ip,
        $method,
        $url,
        $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
        $browser,
        $fingerprint,
        $referer,
        $user_agent
    );

    // 写入日志文件（追加模式）
    $result = file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);

    return $result !== false;
}

/**
 * 按日期记录访问日志（可选）
 *
 * 每天生成一个新的日志文件，便于管理
 *
 * @return bool 是否记录成功
 */
function log_visitor_daily() {
    $date = date('Y-m-d');
    $log_file = __DIR__ . "/../_logs/access_{$date}.txt";
    return log_visitor($log_file);
}

/**
 * 读取最近的访问记录
 *
 * @param int $limit 读取条数（默认100条）
 * @param string $log_file 日志文件路径
 * @return array 访问记录数组
 */
function get_recent_visits($limit = 100, $log_file = null) {
    if ($log_file === null) {
        $log_file = __DIR__ . '/../_logs/access.txt';
    }

    if (!file_exists($log_file)) {
        return [];
    }

    // 读取最后N行
    $lines = [];
    $file = new SplFileObject($log_file, 'r');
    $file->seek(PHP_INT_MAX);
    $last_line = $file->key();

    $start = max(0, $last_line - $limit);
    $file->seek($start);

    while (!$file->eof()) {
        $line = trim($file->current());
        if (!empty($line)) {
            $lines[] = $line;
        }
        $file->next();
    }

    return array_reverse($lines);
}

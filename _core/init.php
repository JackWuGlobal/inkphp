<?php
/**
 * inkphp 核心初始化文件 - 静态源码感知架构
 *
 * 设计理念：
 * 1. Nginx 负责物理寻址：将 URL 映射到磁盘上的 .php 文件
 * 2. init.php 负责逻辑安检：通过静态扫描源码自动判定允许的参数上限
 *
 * 所有业务页面的第一行必须引入此文件：
 * require_once '_core/init.php';
 */

// ============================================================
// 0. PATH_INFO 解析与 404 熔断（在任何业务逻辑前执行）
// ============================================================

// A. 路径解构：从 PATH_INFO 提取参数
$path_info = $_SERVER['PATH_INFO'] ?? '';

if ($path_info !== '') {
    // 将 /123/edit 切片为 ['123', 'edit']
    $segments = array_values(array_filter(explode('/', trim($path_info, '/'))));

    // 自动注入到 $_GET：key1, key2, key3...
    foreach ($segments as $index => $value) {
        $_GET['key' . ($index + 1)] = urldecode($value);
    }

    $url_param_count = count($segments);
} else {
    $url_param_count = 0;
}

// B. 静态源码感知：扫描调用者的源码
$caller_file = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? null;

if ($caller_file && file_exists($caller_file)) {
    // 高速扫描：只读取前 2KB 源码（优化性能）
    $handle = fopen($caller_file, 'r');
    $source_code = fread($handle, 2048);
    fclose($handle);

    // 提取特征：找到所有 $_GET['keyN'] 的使用
    preg_match_all('/\$_GET\[([\'"])key(\d+)\1\]/', $source_code, $matches);

    if (!empty($matches[2])) {
        $key_indices = array_map('intval', $matches[2]);
        $max_key = max($key_indices);

        // 检测每个 keyN 是否是可选参数（带有 ?? 或 isset）
        $required_keys = [];
        for ($i = 1; $i <= $max_key; $i++) {
            $key_name = "key$i";

            // 检查是否有 ?? 默认值
            $has_default = preg_match('/\$_GET\[[\'"]' . $key_name . '[\'"]\]\s*\?\?/', $source_code);

            // 检查是否在 isset 中
            $has_isset = preg_match('/isset\([^)]*\$_GET\[[\'"]' . $key_name . '[\'"]\]/', $source_code);

            // 如果既没有 ?? 也没有 isset，则为必填
            if (!$has_default && !$has_isset && in_array($i, $key_indices)) {
                $required_keys[] = $i;
            }
        }

        // C. 404 熔断校验

        // 上限校验：URL 提供的参数不能超过源码声明的最大索引
        if ($url_param_count > $max_key) {
            abort(404);
        }

        // 下限校验：URL 必须提供所有必填参数
        if (!empty($required_keys)) {
            $max_required = max($required_keys);
            if ($url_param_count < $max_required) {
                abort(404);
            }
        }
    }
}

// ============================================================
// 1. 基础设置
// ============================================================

// 设置字符编码
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0);  // 生产环境关闭，开发时可改为1

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// Session 启动
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 2. 加载配置文件（如果存在）
// ============================================================

$config = [];
$config_file = __DIR__ . '/../_config.php';

if (file_exists($config_file)) {
    $config = require $config_file;
}

// 调试模式（必须在使用前定义）
define('DEBUG', $config['debug'] ?? false);

if (DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// ============================================================
// 开发环境禁用 PHP 页面缓存
// ============================================================

if (DEBUG) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ============================================================
// 3. 数据库初始化（Medoo）
// ============================================================

$db = null;

// 如果存在 medoo.php 和数据库配置
if (file_exists(__DIR__ . '/medoo.php') && isset($config['database'])) {
    require_once __DIR__ . '/medoo.php';

    try {
        $db = new Medoo\Medoo($config['database']);
    } catch (Exception $e) {
        if (DEBUG) {
            die('数据库连接失败: ' . $e->getMessage());
        } else {
            die('数据库连接失败');
        }
    }
}

// ============================================================
// 4. 加载核心功能
// ============================================================

// 加载访问日志记录器
if (file_exists(__DIR__ . '/logger.php')) {
    require_once __DIR__ . '/logger.php';

    // 自动记录所有页面访问（可选，根据需要开启）
    // log_visitor();
}

// 加载鉴权系统（如果存在）
if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}

// 加载缓存系统（如果存在）
if (file_exists(__DIR__ . '/cache.php')) {
    require_once __DIR__ . '/cache.php';
}

// 加载辅助函数（如果存在）
if (file_exists(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}

// ============================================================
// 5. 内置辅助函数
// ============================================================

/**
 * 终止执行并显示 HTTP 错误页面
 *
 * @param int $code HTTP 状态码 (403, 404, 500, 503)
 * @param string $message 可选的错误消息（保留用于扩展）
 */
function abort($code = 404, $message = '') {
    http_response_code($code);
    header_remove('X-Powered-By');

    $error_files = [
        403 => __DIR__ . '/../_errors/403.html',
        404 => __DIR__ . '/../_errors/404.html',
        500 => __DIR__ . '/../_errors/500.html',
        503 => __DIR__ . '/../_errors/503.html',
    ];

    if (isset($error_files[$code]) && file_exists($error_files[$code])) {
        readfile($error_files[$code]);
    }
    exit;
}

/**
 * HTML转义输出
 *
 * @param string $str 要转义的字符串
 * @return string 转义后的字符串
 */
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 重定向
 *
 * @param string $url 目标URL
 * @param int $code HTTP状态码
 */
function redirect($url, $code = 302) {
    header("Location: $url", true, $code);
    exit;
}

/**
 * JSON响应
 *
 * @param mixed $data 要输出的数据
 * @param int $code HTTP状态码
 */
function json_response($data, $code = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * 成功响应
 *
 * @param mixed $data 数据
 * @param string $message 消息
 */
function success($data = null, $message = 'success') {
    json_response([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * 错误响应
 *
 * @param string $message 错误消息
 * @param int $code HTTP状态码
 */
function error($message = 'error', $code = 400) {
    json_response([
        'success' => false,
        'message' => $message
    ], $code);
}

/**
 * 检测是否为 htmx 请求
 *
 * @return bool
 */
function is_htmx() {
    return isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
}

/**
 * 检测是否为 AJAX 请求
 *
 * @return bool
 */
function is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * 获取当前完整URL
 *
 * @return string
 */
function current_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['REQUEST_URI'];
    return "$protocol://$host$uri";
}

/**
 * 获取请求方法
 *
 * @return string GET, POST, PUT, DELETE 等
 */
function request_method() {
    return $_SERVER['REQUEST_METHOD'];
}

/**
 * 检查请求方法
 *
 * @param string $method 期望的方法（GET, POST, PUT, DELETE 等）
 * @return bool
 */
function is_method($method) {
    return strtoupper(request_method()) === strtoupper($method);
}

/**
 * CSRF Token 生成
 *
 * @return string
 */
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF Token 验证
 *
 * @param string $token 要验证的token
 * @return bool
 */
function csrf_verify($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    }

    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 生成 CSRF 隐藏字段
 *
 * @return string HTML input 标签
 */
function csrf_field() {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * 简单的输入验证
 *
 * @param array $data 要验证的数据
 * @param array $rules 验证规则 ['field' => 'required|email']
 * @return array 错误数组，为空表示验证通过
 */
function validate($data, $rules) {
    $errors = [];

    foreach ($rules as $field => $rule_string) {
        $rules_array = explode('|', $rule_string);
        $value = $data[$field] ?? null;

        foreach ($rules_array as $rule) {
            // required
            if ($rule === 'required' && empty($value)) {
                $errors[$field] = "$field 不能为空";
                break;
            }

            // email
            if ($rule === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "$field 必须是有效的邮箱地址";
                break;
            }

            // numeric
            if ($rule === 'numeric' && !empty($value) && !is_numeric($value)) {
                $errors[$field] = "$field 必须是数字";
                break;
            }

            // min:X
            if (preg_match('/^min:(\d+)$/', $rule, $matches)) {
                $min = (int)$matches[1];
                if (!empty($value) && mb_strlen($value) < $min) {
                    $errors[$field] = "$field 最少需要 $min 个字符";
                    break;
                }
            }

            // max:X
            if (preg_match('/^max:(\d+)$/', $rule, $matches)) {
                $max = (int)$matches[1];
                if (!empty($value) && mb_strlen($value) > $max) {
                    $errors[$field] = "$field 最多允许 $max 个字符";
                    break;
                }
            }
        }
    }

    return $errors;
}

/**
 * Flash 消息（用于页面跳转后显示提示）
 *
 * @param string $key 消息键名
 * @param string $message 消息内容（为空则获取）
 * @return string|null
 */
function flash($key = 'message', $message = null) {
    if ($message !== null) {
        // 设置 flash 消息
        $_SESSION["flash_$key"] = $message;
        return null;
    } else {
        // 获取并清除 flash 消息
        $flash_message = $_SESSION["flash_$key"] ?? null;
        unset($_SESSION["flash_$key"]);
        return $flash_message;
    }
}

/**
 * 调试输出（仅在 DEBUG 模式下）
 *
 * @param mixed $var 要输出的变量
 * @param bool $die 是否终止执行
 */
function dd($var, $die = true) {
    if (DEBUG) {
        echo '<pre>';
        var_dump($var);
        echo '</pre>';

        if ($die) {
            exit;
        }
    }
}

// ============================================================
// 6. 全局变量（方便访问）
// ============================================================

// 使 $db 和 $config 在全局范围内可用
$GLOBALS['db'] = $db;
$GLOBALS['config'] = $config;

// ============================================================
// 7. 自定义错误处理（生产环境友好）
// ============================================================

if (!DEBUG) {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // 记录错误到日志
        $log_message = sprintf(
            "[%s] Error %d: %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $errno,
            $errstr,
            $errfile,
            $errline
        );

        $error_log = __DIR__ . '/../_logs/error.log';
        @file_put_contents($error_log, $log_message, FILE_APPEND | LOCK_EX);

        // 显示友好的错误页面
        if (is_htmx() || is_ajax()) {
            error('服务器内部错误', 500);
        } else {
            abort(500);
        }

        return true;
    });
}

// ============================================================
// 初始化完成
// ============================================================

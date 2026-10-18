# inkphp 开发指南

> 极简PHP框架开发准则 | think less. ink more.

本文档是基于 `nginx.conf` 和 `_core/init.php` 的官方开发指南。如有疑问，请以 nginx.conf 为最高准则。

---

## 目录

1. [核心概念](#核心概念)
2. [路由规则](#路由规则)
3. [文件结构](#文件结构)
4. [页面开发](#页面开发)
5. [参数处理](#参数处理)
6. [安全准则](#安全准则)
7. [htmx 集成](#htmx-集成)
8. [常见模式](#常见模式)
9. [禁止事项](#禁止事项)

---

## 核心概念

### 设计理念

inkphp 将路由下沉到 Nginx 层，实现：
- **URL = 文件路径**：看到 URL 就知道对应哪个 PHP 文件
- **零 PHP 路由开销**：Nginx 直接定位文件，比传统框架快 200-500 倍
- **静态源码感知**：init.php 自动分析你的代码，智能验证参数

### 两层架构

```
请求 → Nginx（物理寻址 + 参数分割）→ PHP 文件（业务逻辑）
         ↓                              ↓
      nginx.conf                    _core/init.php
```

**详细实现请查看源码：**
- 路由规则：`nginx.conf`（第 111-163 行）
- 参数解析：`_core/init.php`（第 14-82 行）

---

## 路由规则

### 从右向左物理寻址

Nginx 使用**从右向左回溯**算法，最多支持 10 层路径：

```
访问 /product/123/edit

寻址顺序：
1. /product/123/edit.php     ← 完整路径匹配
2. /product/123.php          ← 回溯一级，/edit 成为 PATH_INFO
3. /product.php              ← 回溯两级，/123/edit 成为 PATH_INFO
4. /index.php                ← 最终回退，/product/123/edit 成为 PATH_INFO
```

**首个匹配到的物理文件即为目标，剩余路径段自动转为参数。**

### 路由映射表

| URL | 匹配文件 | 参数 |
|-----|---------|------|
| `/` | `index.php` | 无 |
| `/about` | `about.php` | 无 |
| `/123` | `index.php` | key1=123 |
| `/product` | `product.php` | 无 |
| `/product/123` | `product.php` | key1=123 |
| `/product/123/edit` | `product.php` 或 `product/123.php`* | key1=123, key2=edit 或 key1=edit |
| `/admin/user/list` | `admin/user.php` 或 `admin.php` | 取决于文件存在性 |

*取决于哪个文件先存在

### URL 规范化（自动重定向）

Nginx 自动处理以下情况（301 永久重定向）：

```
/product/      → /product        # 移除末尾斜杠
/product.php   → /product        # 移除文件后缀
/index         → /               # 移除冗余 index
/index.php/123 → /123            # 移除 index.php
```

### 路径深度限制

超过 10 层路径自动返回 404：
```
/a/b/c/d/e/f/g/h/i/j/k  → 404（11层，超限）
```

---

## 文件结构

### 目录约定

```
inkphp/
├── index.php              # 首页（也处理 /123 等根级参数）
├── about.php              # 独立页面
├── product.php            # 模块入口（处理 /product 和 /product/123）
├── product/               # 模块子功能目录
│   ├── create.php         # /product/create
│   ├── edit.php           # /product/edit
│   └── delete.php         # /product/delete
│
├── _core/                 # 核心代码（私有，禁止访问）
│   ├── init.php           # 统一初始化
│   ├── medoo.php          # 数据库
│   ├── auth.php           # 鉴权
│   └── helpers.php        # 工具函数
│
├── _config.php            # 配置文件（私有）
├── _errors/               # 错误页面（私有，仅内部访问）
├── _logs/                 # 日志目录（私有）
├── _themes/               # 主题模板（私有）
│
├── assets/                # 静态资源（公开）
│   ├── css/
│   ├── js/
│   └── images/
│
└── nginx.conf             # Nginx 配置
```

### 下划线私有约定

**以 `_` 开头的文件/目录禁止外部访问：**

```
_core/          # 403 Forbidden
_config.php     # 403 Forbidden
_themes/        # 403 Forbidden
_logs/          # 403 Forbidden
```

例外：`_errors/` 目录通过 `internal` 指令仅供错误页面使用。

### 同名禁止共存

```
❌ 错误配置：
search.php
search/          # 冲突！路由行为不可预测

✅ 正确配置：
search.php       # 单一功能页面
  或
search/          # 功能模块目录
└── index.php    # 不推荐，应直接用 search.php
```

---

## 页面开发

### 标准页面模板

```php
<?php
// ========================================
// 1. 初始化（必须第一行）
// ========================================
require_once '_core/init.php';

// ========================================
// 2. 权限检查（可选）
// ========================================
// require_login();
// require_role('admin');

// ========================================
// 3. 参数获取
// ========================================
$id = $_GET['key1'] ?? null;        // 可选参数
$action = $_GET['key2'] ?? 'view';  // 带默认值

// ========================================
// 4. 业务逻辑
// ========================================
$data = $db->get('products', '*', ['id' => $id]);

// ========================================
// 5. 渲染输出
// ========================================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>页面标题</title>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-4">
        <!-- 内容 -->
    </div>
</body>
</html>
```

### 嵌套目录的初始化路径

根据文件深度调整 require 路径：

```php
// 根目录：index.php, about.php
require_once '_core/init.php';

// 一级目录：product/edit.php
require_once '../_core/init.php';

// 二级目录：admin/user/list.php
require_once '../../_core/init.php';

// 或使用 DOCUMENT_ROOT（推荐，路径统一）
require_once $_SERVER['DOCUMENT_ROOT'] . '/_core/init.php';
```

---

## 参数处理

### 自动参数注入

PATH_INFO 自动解析为 `$_GET['keyN']`：

```
URL: /product/123/edit
         ↓
PATH_INFO: /123/edit
         ↓
$_GET['key1'] = '123'
$_GET['key2'] = 'edit'
```

### 静态源码感知

init.php 会扫描你的源码，自动判断参数规则：

```php
// product.php
$id = $_GET['key1'];              // 必填（无默认值）
$tab = $_GET['key2'] ?? 'info';   // 可选（有默认值）
```

**自动验证行为：**
```
/product         → 404（缺少必填 key1）
/product/123     → ✅（key1=123, key2=info）
/product/123/spec→ ✅（key1=123, key2=spec）
/product/123/a/b → 404（超出 key2 上限）
```

### 参数命名建议

使用语义化变量名重命名 key：

```php
// 推荐
$product_id = $_GET['key1'];
$action = $_GET['key2'] ?? 'view';

// 不推荐
$key1 = $_GET['key1'];  // 语义不明
```

---

## 安全准则

### 输出转义（XSS 防护）

**所有用户输入必须转义后输出：**

```php
// ✅ 正确
<?= e($user_input) ?>
<?= htmlspecialchars($var, ENT_QUOTES, 'UTF-8') ?>

// ❌ 危险
<?= $user_input ?>
<?php echo $_GET['name']; ?>
```

### SQL 注入防护

**使用 Medoo 参数化查询：**

```php
// ✅ 安全（Medoo 自动参数化）
$user = $db->get('users', '*', ['id' => $_GET['id']]);
$users = $db->select('users', '*', ['name[~]' => $keyword]);

// ❌ 危险（永不拼接 SQL）
$db->query("SELECT * FROM users WHERE id=" . $_GET['id']);
```

### CSRF 防护

**表单必须包含 CSRF Token：**

```php
// 生成隐藏字段
<form method="POST">
    <?= csrf_field() ?>
    <input name="title" required>
    <button type="submit">提交</button>
</form>

// 验证 Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        die('CSRF 验证失败');
    }
    // 处理表单...
}
```

### 文件上传

```php
// 验证文件类型
$allowed = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($_FILES['file']['type'], $allowed)) {
    die('不允许的文件类型');
}

// 验证文件大小（5MB）
if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
    die('文件过大');
}

// 生成安全文件名
$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '.' . $ext;
move_uploaded_file($_FILES['file']['tmp_name'], "uploads/$filename");
```

---

## htmx 集成

### 检测 htmx 请求

```php
if (is_htmx()) {
    // 只返回 HTML 片段
    echo '<div class="success">操作成功</div>';
    exit;
}
// 返回完整页面
```

### 常用模式

**删除操作：**
```html
<tr id="row-123">
    <td>产品名称</td>
    <td>
        <button hx-delete="/product/delete?id=123"
                hx-target="#row-123"
                hx-swap="outerHTML"
                hx-confirm="确定删除？"
                class="text-red-500">
            删除
        </button>
    </td>
</tr>
```

```php
// product/delete.php
require_once '../_core/init.php';
require_login();

if (is_method('DELETE')) {
    $id = $_GET['id'] ?? 0;
    $db->delete('products', ['id' => $id]);
    echo '';  // 返回空，行消失
    exit;
}
```

**表单提交：**
```html
<form hx-post="/product/create"
      hx-target="#result"
      hx-swap="innerHTML"
      class="space-y-4">
    <?= csrf_field() ?>
    <input name="name" required class="border rounded px-3 py-2">
    <button type="submit">创建</button>
</form>
<div id="result"></div>
```

**实时搜索：**
```html
<input type="text"
       hx-get="/search"
       hx-trigger="keyup changed delay:300ms"
       hx-target="#results"
       placeholder="搜索...">
<div id="results"></div>
```

**加载更多：**
```html
<div id="products">
    <!-- 产品列表 -->
</div>
<button hx-get="/product?page=2"
        hx-target="#products"
        hx-swap="beforeend"
        hx-indicator="#loading">
    加载更多
</button>
<span id="loading" class="htmx-indicator">加载中...</span>
```

---

## 常见模式

### CRUD 模块结构

```
product.php              # GET /product       列表
                         # GET /product/123   详情
product/
├── create.php           # GET/POST /product/create
├── edit.php             # GET/POST /product/edit/123
└── delete.php           # DELETE /product/delete?id=123
```

### API 端点

```php
<?php
// api/products.php
require_once '../_core/init.php';

header('Content-Type: application/json');

$products = $db->select('products', '*', [
    'status' => 'active',
    'ORDER' => ['id' => 'DESC']
]);

success($products);  // 返回 JSON
```

### 数据库操作（Medoo）

```php
// 查询多条
$products = $db->select('products', '*', [
    'status' => 'active',
    'ORDER' => ['created_at' => 'DESC'],
    'LIMIT' => 20
]);

// 查询单条
$product = $db->get('products', '*', ['id' => 123]);

// 插入
$db->insert('products', [
    'name' => '新产品',
    'price' => 99.00,
    'created_at' => Medoo::raw('NOW()')
]);
$new_id = $db->id();

// 更新
$db->update('products',
    ['price' => 79.00],
    ['id' => 123]
);

// 删除
$db->delete('products', ['id' => 123]);

// 搜索（LIKE）
$results = $db->select('products', '*', [
    'name[~]' => $keyword
]);

// 分页
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$products = $db->select('products', '*', [
    'ORDER' => ['id' => 'DESC'],
    'LIMIT' => [($page - 1) * $per_page, $per_page]
]);
```

### 多语言目录结构

```
zh/
└── about.php            # /zh/about
en/
└── about.php            # /en/about
```

---

## 禁止事项

### 绝对禁止

```php
❌ eval()                      // 代码注入风险
❌ extract()                   // 变量污染风险
❌ 拼接 SQL                    // SQL 注入风险
❌ 未转义输出用户输入          // XSS 风险
❌ 硬编码数据库密码            // 使用 _config.php
❌ include() 替代 require_once // 错误处理不严格
```

### 不推荐

```php
⚠️ 同名文件和目录共存         // 路由冲突
⚠️ 超过 3 层目录嵌套          // 保持扁平
⚠️ 使用绝对路径 require       // 用 DOCUMENT_ROOT 或相对路径
⚠️ 在 _core/ 外编写业务逻辑   // 保持核心简洁
```

---

## 内置函数速查

| 函数 | 用途 |
|------|------|
| `e($str)` | HTML 转义输出 |
| `redirect($url)` | 页面重定向 |
| `json_response($data)` | JSON 输出 |
| `success($data)` | 成功响应 |
| `error($msg)` | 错误响应 |
| `abort(404)` | 终止并显示错误页 |
| `is_htmx()` | 检测 htmx 请求 |
| `is_ajax()` | 检测 AJAX 请求 |
| `is_method('POST')` | 检查请求方法 |
| `csrf_token()` | 获取 CSRF Token |
| `csrf_verify()` | 验证 CSRF Token |
| `csrf_field()` | 生成隐藏表单字段 |
| `validate($data, $rules)` | 表单验证 |
| `flash($key, $msg)` | Flash 消息 |
| `dd($var)` | 调试输出（仅 DEBUG） |
| `current_url()` | 获取当前完整 URL |

---

## 源码参考

深入了解实现细节，请查看：

- **路由算法**：`nginx.conf` 第 111-163 行
- **参数解析**：`_core/init.php` 第 14-82 行
- **安全函数**：`_core/init.php` 第 185-460 行
- **日志记录**：`_core/logger.php`

---

## 技术栈

- PHP 8.2+
- Nginx（必须，路由依赖）
- Medoo（数据库，单文件）
- htmx 1.9.10（前端交互）
- Tailwind CSS（样式）

---

**think less. ink more.** - 在任何层级落墨

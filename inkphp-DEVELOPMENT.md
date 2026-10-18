# inkphp Development Guide

> 为AI开发助手（Claude Code）准备的完整开发指南

## 项目定位

**inkphp** - ThinkPHP的轻量替代
- 口号：think less. ink more.
- 理念：在任何层级落墨
- 目标：极简、透明、快速

## 核心创新：路由查询两步对撞法

访问 `/product/edit` 时，Nginx按此顺序：

```
1. /product/edit.php          ← 精确文件匹配（最常用）
2. /product.php?key1=edit     ← 参数回溯兜底
```

访问 `/product/edit/1` 时，Nginx按此顺序：

```
1. /product/edit/1.php        ← 精确文件匹配
2. /product/edit.php?key1=1   ← 参数回溯兜底
```

访问 `/123` 时（单段路径）：

```
1. /123.php                   ← 精确文件匹配
2. /index.php?key1=123        ← 回溯到根目录 index.php
```

**关键：路由由Nginx+Linux内核处理，0 PHP开销**

## 文件结构

```
inkphp/
├── index.php              # 首页（也处理 /123 等单段路径参数）
├── about.php              # 简单页面（单文件）
├── product.php            # 模块入口（处理 /product 和 /product/123 等参数）
│
├── product/               # 模块子功能（目录只作为组织容器）
│   ├── edit.php          # /product/edit
│   ├── create.php        # /product/create
│   └── delete.php        # /product/delete
│
├── _core/                 # 核心（下划线=私有）
│   ├── init.php          # 统一初始化
│   ├── medoo.php         # 数据库（单文件）
│   ├── auth.php          # 鉴权
│   ├── cache.php         # 缓存
│   └── helpers.php       # 工具函数
│
├── _themes/               # 主题（私有）
│   └── default/
│       └── layout.php
│
├── _config.php            # 配置（私有）
│
└── assets/                # 静态资源（公开）
    └── themes/
        └── default/
            └── style.css
```

## 命名规则

**同名禁止共存：**
```
❌ 错误：
search.php
search/          # 冲突！

✅ 正确：
search.php       # 单一功能
product/         # 模块功能
```

## 技术栈

- PHP 8.2+ 纯原生
- Medoo（数据库，单文件）
- htmx 1.9.10（CDN）
- Tailwind CSS（CDN）
- MySQL/PostgreSQL

## 页面文件标准结构

```php
<?php
// 1. 初始化（必须第一行）
require_once '../_core/init.php';

// 2. 权限检查（可选）
require_login();

// 3. 业务逻辑
$data = $db->select('products', '*', [
    'status' => 'active',
    'ORDER' => ['created_at' => 'DESC']
]);

// 4. 模板渲染
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-4">
        <!-- HTML模板 -->
    </div>
</body>
</html>
```

## 核心原则（必须遵守）

### 安全
```php
// ✅ 输出必须转义
<?= htmlspecialchars($var, ENT_QUOTES, 'UTF-8') ?>

// ✅ 数据库必须参数化
$db->select('users', '*', ['id' => $_GET['id']]);

// ❌ 禁止拼接SQL
$db->query("SELECT * FROM users WHERE id=" . $_GET['id']);
```

### 文件引入
```php
// ✅ 使用 require_once
require_once '../_core/init.php';

// ❌ 不用 include（错误处理级别不够）
```

### 路径
```php
// ✅ 相对路径或__DIR__
require_once __DIR__ . '/../_core/init.php';

// ❌ 不用绝对路径
```

## 数据库操作（Medoo）

```php
// 查询
$products = $db->select('products', '*', [
    'status' => 'active',
    'ORDER' => ['created_at' => 'DESC'],
    'LIMIT' => 20
]);

// 单条
$product = $db->get('products', '*', ['id' => 123]);

// 插入
$db->insert('products', [
    'name' => 'inkphp',
    'price' => 99,
    'created_at' => Medoo::raw('NOW()')
]);
$last_id = $db->id();

// 更新
$db->update('products', 
    ['price' => 79], 
    ['id' => 123]
);

// 删除
$db->delete('products', ['id' => 123]);

// JOIN
$posts = $db->select('posts', [
    '[>]users' => ['user_id' => 'id']
], [
    'posts.id',
    'posts.title',
    'users.name (author)'
]);

// 搜索（LIKE）
$products = $db->select('products', '*', [
    'OR' => [
        'name[~]' => $_GET['q'],
        'description[~]' => $_GET['q']
    ]
]);

// 分页
$page = $_GET['page'] ?? 1;
$per_page = 20;
$products = $db->select('products', '*', [
    'ORDER' => ['id' => 'DESC'],
    'LIMIT' => [($page - 1) * $per_page, $per_page]
]);
```

## htmx集成

```php
// 检测htmx请求
if (is_htmx()) {
    // 只返回片段
    echo "<div>内容</div>";
    exit;
}
```

```html
<!-- 删除按钮 -->
<button hx-delete="/admin/posts/delete?id=123"
        hx-target="#post-123"
        hx-swap="outerHTML"
        hx-confirm="确定删除？"
        class="bg-red-500 text-white px-4 py-2 rounded">
    删除
</button>

<!-- 表单提交 -->
<form hx-post="/admin/posts/create"
      hx-target="#result"
      class="space-y-4">
    <input name="title" required 
           class="border rounded px-3 py-2 w-full">
    <button type="submit">提交</button>
</form>

<!-- 实时搜索 -->
<input hx-get="/search"
       hx-trigger="keyup changed delay:500ms"
       hx-target="#results"
       placeholder="搜索...">

<!-- 加载更多 -->
<button hx-get="/products?page=2"
        hx-target="#products"
        hx-swap="beforeend">
    加载更多
</button>
```

## 鉴权系统

```php
// 登录
if (login($_POST['username'], $_POST['password'])) {
    redirect('/admin/dashboard');
}

// 要求登录
require_login();

// 角色检查
require_role('admin');

// 权限检查
require_permission('posts.delete');

// 条件检查
if (has_permission('posts.update')) {
    // 显示编辑按钮
}

// 获取当前用户
$user = current_user();
```

## 缓存系统

```php
// 缓存5分钟
$products = cache_remember('products', 300, function() {
    global $db;
    return $db->select('products', '*');
});

// 清除缓存
cache_forget('products');

// 使用场景：
// ✅ 数据库查询结果
// ✅ API响应
// ✅ 复杂计算
// ❌ 个性化内容
// ❌ 实时数据
```

## 常见模式

### CRUD模块
```
product.php             # GET  /product           列表
                        # GET  /product/123       详情（通过 key1 参数）
product/
├── create.php          # GET/POST /product/create  创建
├── edit.php            # GET/POST /product/edit    编辑
└── delete.php          # DELETE   /product/delete  删除
```

### API端点
```php
<?php
// api/products.php
require_once '../_core/init.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$products = $db->select('products', '*', [
    'status' => 'active'
]);

echo json_encode($products);
```

### 文件上传
```php
// 验证
$allowed = ['image/jpeg', 'image/png'];
if (!in_array($_FILES['file']['type'], $allowed)) {
    die('不允许的文件类型');
}

// 保存
$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '.' . $ext;
move_uploaded_file($_FILES['file']['tmp_name'], "uploads/$filename");
```

## 性能优化

```php
// 数据库索引
CREATE INDEX idx_status ON products(status);
CREATE INDEX idx_created ON products(created_at);

// OpCache（php.ini）
opcache.enable=1
opcache.memory_consumption=256

// 避免N+1查询（用JOIN）
// 避免SELECT *（指定字段）
```

## 调试

```php
// 开发模式（_config.php）
'debug' => true,

// 调试输出
var_dump($data);
exit;

// SQL调试
echo $db->last();
```

## 绝对禁止

```php
❌ eval()
❌ extract()
❌ 拼接SQL
❌ 未转义的输出
❌ 硬编码密码
```

## AI开发助手指南

**给Claude Code的提示：**

1. **遵循两步对撞哲学**
   - 优先创建单文件（如 product.php）
   - 目录只作为组织容器（如 product/edit.php）
   - 模块入口文件处理参数（如 product.php?key1=123）

2. **保持极简**
   - 不过度抽象
   - 优先函数而非类

3. **文件路由优先**
   - 不建议routes.php
   - URL = 文件结构

4. **安全第一**
   - 输出必转义
   - 查询必参数化

5. **性能意识**
   - 建议缓存
   - 建议索引

## Git提交规范

```bash
feat: 添加产品管理模块
fix: 修复登录bug
docs: 更新文档
perf: 优化查询
```

## 版本

v0.1.0 - 核心路由实现

---

**核心哲学：在任何层级落墨。保持简单、透明、灵活。**

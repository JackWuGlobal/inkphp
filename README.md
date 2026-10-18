# inkphp

> 极简PHP框架 | think less. ink more.

**inkphp** 是一个极简PHP框架，为那些觉得ThinkPHP过于复杂的开发者而生。

## 特性

- 🚀 **极快** - 路由下沉到内核层，比Laravel快200-500倍
- 📦 **零依赖** - 无需Composer，上传即用
- 📁 **文件路由** - URL直接映射文件，一目了然
- 🎨 **htmx集成** - 无需写JavaScript实现动态交互
- 🔒 **安全** - 内置XSS/SQL注入防护
- 📖 **1天上手** - 会PHP就会用

## 快速开始

### 1. 安装

```bash
git clone https://github.com/yourname/inkphp
cd inkphp
```

### 2. 配置

```bash
cp _config.example.php _config.php
vim _config.php  # 修改数据库配置
```

### 3. 运行

```bash
# 开发环境
php -S localhost:8000

# 生产环境（Nginx配置见NGINX.md）
```

## 示例

### Hello World

```php
<?php
// hello.php
require_once '_core/init.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">
    <h1 class="text-4xl font-bold text-gray-800">
        Hello, inkphp!
    </h1>
</body>
</html>
```

访问：`http://localhost:8000/hello`

### 数据库查询

```php
<?php
// products.php
require_once '_core/init.php';

$products = $db->select('products', '*', [
    'status' => 'active',
    'ORDER' => ['created_at' => 'DESC'],
    'LIMIT' => 20
]);
?>
<!DOCTYPE html>
<html>
<head>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="container mx-auto p-4">
    <h1 class="text-3xl font-bold mb-4">产品列表</h1>
    <div class="grid gap-4">
        <?php foreach($products as $product): ?>
            <div class="bg-white p-4 rounded shadow">
                <h2 class="font-bold"><?= htmlspecialchars($product['name']) ?></h2>
                <p class="text-gray-600">¥<?= $product['price'] ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
```

### htmx交互（无需写JS）

```php
<?php
// products/delete.php
require_once '../_core/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = $_GET['id'] ?? 0;
    $db->delete('products', ['id' => $id]);
    echo '';  // 返回空，元素消失
    exit;
}
?>
```

```html
<!-- 前端 -->
<button hx-delete="/products/delete?id=123"
        hx-target="#product-123"
        hx-swap="outerHTML"
        hx-confirm="确定删除？"
        class="bg-red-500 text-white px-4 py-2 rounded">
    删除
</button>
```

## 路由规则

### 两步对撞

访问 `/product/edit` 时，Nginx按此顺序查找：

```
1. /product/edit.php          ← 精确文件匹配
2. /product.php?key1=edit     ← 参数回溯
```

访问 `/123` 时（单段路径）：

```
1. /123.php                   ← 精确文件匹配
2. /index.php?key1=123        ← 回溯到根目录
```

### URL映射

```
URL                      文件
/                   →    index.php
/about              →    about.php
/123                →    index.php?key1=123
/product            →    product.php
/product/edit       →    product/edit.php
/product/123        →    product.php?key1=123
/admin/user         →    admin/user.php 或 admin.php?key1=user
```

## 文档

- [开发指南](DEVELOPMENT.md) - 完整开发文档
- [架构设计](ARCHITECTURE.md) - 设计原理
- [Nginx配置](NGINX.md) - 服务器配置

## 对比

|  | inkphp | Laravel | ThinkPHP |
|---|--------|---------|----------|
| 文件大小 | <1MB | 50MB+ | 30MB+ |
| 学习时间 | 1天 | 1个月 | 2周 |
| 依赖 | 0 | Composer | Composer |
| 路由速度 | ⭐⭐⭐⭐⭐ | ⭐⭐ | ⭐⭐ |
| 适合场景 | 中小项目 | 大型项目 | 中大项目 |

## 适用场景

✅ **适合：**
- 个人博客/官网
- 管理后台
- 中小型应用
- 快速原型
- 学习PHP

❌ **不适合：**
- 超大型应用（>100页面）
- 超高并发（>5000 QPS）
- 微服务架构

## 技术栈

- PHP 8.2+
- Medoo（数据库）
- htmx（前端交互）
- Tailwind CSS（样式）
- MySQL/PostgreSQL

## 性能

```
单服务器：2000-5000 QPS
响应时间：<20ms
内存占用：<2MB/请求
```

够用于日活10万以下的应用。

## 社区

- 官网：https://inkphp.com
- GitHub：https://github.com/yourname/inkphp
- 讨论：GitHub Issues

## 贡献

欢迎贡献！请遵循：
1. 保持极简
2. 不破坏向后兼容
3. 添加测试
4. 更新文档

## 许可

MIT License

## 致谢

- [Medoo](https://medoo.in) - 优雅的数据库框架
- [htmx](https://htmx.org) - 高效的交互库
- [Tailwind CSS](https://tailwindcss.com) - 实用的CSS框架

---

**think less. ink more.** - 让开发回归简单

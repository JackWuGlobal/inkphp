<?php
// Log in. The one admin account comes from .env.php: ADMIN_USER and ADMIN_PASSWORD_HASH, where the hash is made with
//   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
// After login we go to ?next= (set by require_login()), but only if it is a path on this site.
params();
allow('GET', 'POST');

$next = $_GET['next'] ?? href('/admin');
// A path on this site only: never "//evil.com", "/\evil.com", "https://evil.com" or control characters
// ("/<tab>/evil.com" is read as "//evil.com" by browsers). redirect() refuses those too; this picks a better default.
if (!is_string($next) || !preg_match('~^/(?![/\\\\])[^\x00-\x1f\x7f]*$~D', $next)) {
    $next = href('/admin');
}
if (user() !== null) redirect($next);              // already logged in

$hash = (string) env('ADMIN_PASSWORD_HASH', '');
$name = '';
$error = null;

if (is_post()) {
    csrf_check();
    rate_limit('login:' . ip_key(), 5, 300);       // 5 tries per 5 minutes per IP (IPv6: per /64) → else 429
    [$in, $errors] = validate(input(), ['user' => 'required|max:100']);
    $name = (string) $in['user'];
    // Per account as well, before the (slow) password check: changing IP addresses does not get around this one.
    if (!$errors) rate_limit('login-user:' . strtolower($name), 20, 3600);
    $password = input('password');                 // raw: passwords are never trimmed or validated
    $ok = !$errors && $hash !== '' && is_string($password)
        && password_verify($password, $hash)
        && hash_equals((string) env('ADMIN_USER', 'admin'), $name);
    if ($ok) {
        login($name);                              // new session id and new CSRF token
        redirect($next, 303);
    }
    $error = t(['zh' => '用户名或密码不对。', 'en' => 'Wrong user name or password.']);
    http_response_code(422);
}

layout('site', ['title' => t(['zh' => '登录', 'en' => 'Log in'])]);
?>
<h1><?= e(t(['zh' => '登录', 'en' => 'Log in'])) ?></h1>

<?php if ($hash === ''): ?>
    <p class="notice"><?= e(t([
        'zh' => '还没有设置后台账号：在 .env.php 里填写 ADMIN_USER 和 ADMIN_PASSWORD_HASH（用 password_hash() 生成，见本文件开头的注释）。',
        'en' => 'No admin account yet: set ADMIN_USER and ADMIN_PASSWORD_HASH in .env.php (make the hash with password_hash(), see the comment at the top of this file).',
    ])) ?></p>
<?php endif ?>

<!-- no action attribute: the form posts to this same URL, ?next= included -->
<form class="box" method="post">
    <?= csrf_field() ?>
    <?php if ($error !== null): ?>
        <p class="error" role="alert"><?= e($error) ?></p>
    <?php endif ?>

    <label for="user"><?= e(t(['zh' => '用户名', 'en' => 'User name'])) ?></label>
    <input id="user" name="user" type="text" autocomplete="username" required value="<?= e($name) ?>">

    <label for="password"><?= e(t(['zh' => '密码', 'en' => 'Password'])) ?></label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>

    <button><?= e(t(['zh' => '登录', 'en' => 'Log in'])) ?></button>
</form>

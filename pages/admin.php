<?php
// Admin: guestbook moderation, logged-in users only (log in at /login).
// The URL /admin is this file. Sub-pages would go in pages/admin/<name>.php (index.php is used only at the root).
// Delete works as a plain form POST (then redirect back) and, with htmx, in place: the row disappears.
params();
allow('GET', 'POST');
$me = require_login();                             // not logged in → redirect to /login?next=%2Fadmin

if (is_post()) {
    csrf_check();
    [$in, $errors] = validate(input(), ['delete' => 'required|int|min:1']);
    if ($errors) abort(400);
    q('DELETE FROM guestbook WHERE id = ?', [$in['delete']]);
    if (is_htmx()) {
        // The htmx target is the table row: an empty answer removes it, and this out-of-band element
        // replaces the counter (#count) elsewhere on the page.
        echo '<span id="count" hx-swap-oob="true">' . e(val('SELECT COUNT(*) FROM guestbook')) . '</span>';
        exit;
    }
    flash('ok', t(['zh' => '已删除。', 'en' => 'Deleted.']));
    redirect('/admin', 303);
}

$count = val('SELECT COUNT(*) FROM guestbook');
$entries = all('SELECT id, name, message, created_at FROM guestbook ORDER BY id DESC LIMIT 100');
$confirm = t(['zh' => '确定删除这条留言吗？', 'en' => 'Delete this message?']);

layout('site', ['title' => t(['zh' => '后台', 'en' => 'Admin'])]);
?>
<h1><?= e(t(['zh' => '后台', 'en' => 'Admin'])) ?></h1>
<p>
    <?= e(t(['zh' => '已登录：{me}。', 'en' => 'Logged in as {me}.'], ['me' => $me])) ?>
    <?= e(t(['zh' => '留言总数：', 'en' => 'Messages in total: '])) ?><strong><span id="count"><?= e($count) ?></span></strong>
</p>

<?php if (!$entries): ?>
    <p class="muted"><?= e(t(['zh' => '还没有留言。', 'en' => 'No messages yet.'])) ?></p>
<?php else: ?>
    <div class="scroll">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= e(t(['zh' => '名字', 'en' => 'Name'])) ?></th>
                    <th><?= e(t(['zh' => '留言', 'en' => 'Message'])) ?></th>
                    <th><?= e(t(['zh' => '时间 (UTC)', 'en' => 'Time (UTC)'])) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?= e($row['id']) ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td class="msg"><?= e($row['message']) ?></td>
                        <td><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                        <td>
                            <form method="post" action="/admin"
                                hx-post="/admin" hx-target="closest tr" hx-swap="outerHTML" hx-confirm="<?= e($confirm) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete" value="<?= e($row['id']) ?>">
                                <button class="danger"><?= e(t(['zh' => '删除', 'en' => 'Delete'])) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

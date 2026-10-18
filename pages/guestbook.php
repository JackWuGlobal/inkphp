<?php
// Guestbook: GET shows the form and the latest entries; POST adds an entry (then redirects back: PRG).
// htmx: the list asks this same URL with the HX-Request header and gets back only the list, no layout.
// Table: migrations/001_guestbook.sql.
params();
allow('GET', 'POST');

$form = ['name' => '', 'message' => ''];
$errors = [];

if (is_post()) {
    csrf_check();                                  // no valid _csrf field → 403
    rate_limit('guestbook:' . ip_key(), 5, 60);    // at most 5 posts a minute per IP (IPv6: per /64) → else 429
    [$form, $errors] = validate(input(), [
        'name' => 'required|max:40',
        'message' => 'required|min:2|max:500',
    ]);
    if (!$errors) {
        q('INSERT INTO guestbook (name, message) VALUES (?, ?)', [$form['name'], $form['message']]);
        flash('ok', t(['zh' => '谢谢留言！', 'en' => 'Thanks for your message!']));
        redirect('/guestbook', 303);               // a reload of the next page never posts twice
    }
    http_response_code(422);                       // show the form again, with the errors and the input
}

$entries = all('SELECT id, name, message, created_at FROM guestbook ORDER BY id DESC LIMIT 50');
$empty = t(['zh' => '还没有留言，来写第一条吧。', 'en' => 'No messages yet. Be the first!']);

header('Vary: HX-Request');                        // one URL, two responses: keep caches apart
if (is_htmx()) {
    echo partial('entries', ['entries' => $entries, 'empty' => $empty]);
    exit;                                          // just the fragment: layout() was never called
}

layout('site', ['title' => t(['zh' => '留言板', 'en' => 'Guestbook'])]);
?>
<h1><?= e(t(['zh' => '留言板', 'en' => 'Guestbook'])) ?></h1>

<form class="box" method="post" action="/guestbook">
    <?= csrf_field() ?>

    <label for="name"><?= e(t(['zh' => '名字', 'en' => 'Name'])) ?></label>
    <input id="name" name="name" type="text" maxlength="40" required value="<?= e($form['name']) ?>"
        <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>>
    <?php if (isset($errors['name'])): ?>
        <p class="error" id="name-error"><?= e($errors['name']) ?></p>
    <?php endif ?>

    <label for="message"><?= e(t(['zh' => '留言', 'en' => 'Message'])) ?></label>
    <textarea id="message" name="message" maxlength="500" required
        <?= isset($errors['message']) ? 'aria-invalid="true" aria-describedby="message-error"' : '' ?>><?= e($form['message']) ?></textarea>
    <?php if (isset($errors['message'])): ?>
        <p class="error" id="message-error"><?= e($errors['message']) ?></p>
    <?php endif ?>

    <button><?= e(t(['zh' => '发表留言', 'en' => 'Post message'])) ?></button>
</form>

<h2>
    <?= e(t(['zh' => '最新留言', 'en' => 'Latest messages'])) ?>
    <button type="button" class="link" hx-get="/guestbook" hx-target="#entries"><?= e(t(['zh' => '刷新', 'en' => 'Refresh'])) ?></button>
</h2>
<!-- htmx reloads the list every 30 s, and when "Refresh" is clicked -->
<div id="entries" hx-get="/guestbook" hx-trigger="every 30s">
    <?= partial('entries', ['entries' => $entries, 'empty' => $empty]) ?>
</div>

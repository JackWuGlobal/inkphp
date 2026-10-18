<?php
// Search the guestbook: /search?q=word (the form) or /search/word (a shareable path).
// htmx: typing in the box asks this same URL with the HX-Request header and gets back only the results.
[$word] = params(0, 1);                            // 0 or 1 path param: /search or /search/<word>

// The path param wins over ?q=. validate() trims, and rejects arrays (?q[]=x) and invalid UTF-8.
[$in, $errors] = validate(['q' => $word ?? $_GET['q'] ?? null], ['q' => 'max:100']);
$q = $errors ? '' : (string) $in['q'];

$results = [];
if ($q !== '') {
    // LIKE with % and _ in the user's text escaped ("!" works as the escape char in SQLite, PostgreSQL and MySQL).
    // SQLite LIKE ignores ASCII case; on PostgreSQL use ILIKE for the same behaviour.
    $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $results = all(
        "SELECT id, name, message, created_at FROM guestbook
         WHERE name LIKE ? ESCAPE '!' OR message LIKE ? ESCAPE '!'
         ORDER BY id DESC LIMIT 20",
        [$like, $like],
    );
}
$empty = $q === '' ? '' : t(['zh' => '没有找到“{q}”。', 'en' => 'Nothing found for “{q}”.'], ['q' => $q]);

header('Vary: HX-Request');                        // one URL, two responses: keep caches apart
if (is_htmx()) {
    echo partial('entries', ['entries' => $results, 'empty' => $empty]);
    exit;                                          // just the results: layout() was never called
}

layout('site', ['title' => t(['zh' => '搜索', 'en' => 'Search'])]);
?>
<h1><?= e(t(['zh' => '搜索留言', 'en' => 'Search messages'])) ?></h1>

<form class="row" method="get" action="/search" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" autofocus
        aria-label="<?= e(t(['zh' => '搜索', 'en' => 'Search'])) ?>"
        placeholder="<?= e(t(['zh' => '输入名字或留言内容…', 'en' => 'Type a name or some words…'])) ?>"
        hx-get="/search" hx-trigger="input changed delay:300ms, search" hx-target="#results">
    <button><?= e(t(['zh' => '搜索', 'en' => 'Search'])) ?></button>
</form>
<?php if ($errors): ?>
    <p class="error"><?= e($errors['q']) ?></p>
<?php endif ?>

<div id="results">
    <?= partial('entries', ['entries' => $results, 'empty' => $empty]) ?>
</div>

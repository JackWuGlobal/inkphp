<?php
// A list of guestbook entries. Used by pages/guestbook.php (full page and htmx refresh) and pages/search.php.
// Vars: $entries (rows with name, message, created_at), $empty (text shown when the list is empty).
?>
<?php if (!$entries): ?>
    <p class="muted"><?= e($empty ?? '') ?></p>
<?php else: ?>
    <ul class="entries">
        <?php foreach ($entries as $row): ?>
            <li>
                <strong><?= e($row['name']) ?></strong>
                <span class="muted"><?= e(substr((string) $row['created_at'], 0, 16)) ?> UTC</span>
                <p class="msg"><?= e($row['message']) ?></p>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

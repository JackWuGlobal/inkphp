<?php
// JSON API for notes (table: migrations/002_notes.sql).
//   GET  /api/notes?limit=20  → 200 {"notes": [...]}, newest first (limit 1..100, default 20)
//   POST /api/notes           → 201 {"note": {...}}  body {"text": "..."} as JSON, or a form field "text"
// POST needs a logged-in user and the CSRF token: the X-CSRF-Token header (fetch) or the _csrf form field
// (a same-site HTML form with csrf_field()). From the site's own JavaScript:
//   fetch('/api/notes', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token},
//                        body: JSON.stringify({text: 'Hello'})})
// where token is csrf_token() printed into the page. Under /api every error is JSON, e.g. {"error":401,"message":…}:
// 400 bad JSON · 401 not logged in · 403 bad CSRF token · 405 other methods · 422 {"errors": {field: message}}.
params();
allow('GET', 'POST');

if (is_post()) {
    require_login();                               // → 401 JSON (not a redirect) because this is /api
    csrf_check();                                  // → 403 JSON
    [$in, $errors] = validate(input(), ['text' => 'required|max:500']);
    if ($errors) json(['errors' => $errors], 422);
    q('INSERT INTO notes (text) VALUES (?)', [$in['text']]);
    $note = one('SELECT id, text, created_at FROM notes WHERE id = ?', [db()->lastInsertId()]);
    json(['note' => $note], 201);
}

[$query, $errors] = validate($_GET, ['limit' => 'int|min:1|max:100']);
if ($errors) json(['errors' => $errors], 422);
$notes = all('SELECT id, text, created_at FROM notes ORDER BY id DESC LIMIT ?', [$query['limit'] ?? 20]);
json(['notes' => $notes]);

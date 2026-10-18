-- Notes: the resource behind the JSON API in pages/api/notes.php.
-- SQLite. PostgreSQL / MySQL: change id and created_at exactly as described in 001_guestbook.sql.

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY,
    text       TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

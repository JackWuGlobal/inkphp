-- Guestbook entries: used by pages/guestbook.php, pages/search.php and pages/admin.php.
-- Written for SQLite (the default config). The INSERTs in the pages do not name id or created_at, so the
-- same pages work on PostgreSQL and MySQL with these column definitions instead:
--   PostgreSQL: id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY
--               created_at TIMESTAMPTZ NOT NULL DEFAULT now()
--   MySQL:      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
--               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
--               and end the CREATE TABLE with ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
-- Times are UTC (SQLite CURRENT_TIMESTAMP is always UTC).

CREATE TABLE guestbook (
    id         INTEGER PRIMARY KEY,
    name       VARCHAR(40) NOT NULL,
    message    TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

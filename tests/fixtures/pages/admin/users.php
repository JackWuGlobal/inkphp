<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
[$id] = params(0, 1);
allow("GET", "POST");
require_login();
if (is_post()) {
    csrf_check();
}
echo "admin/users";

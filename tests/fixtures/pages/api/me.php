<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params();
allow("GET");
$uid = require_login(); // under /api: 401 JSON instead of a redirect
json(["id" => $uid]);

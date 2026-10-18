<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params();
header("Content-Type: text/plain; charset=utf-8");
echo "User-agent: *\n";

<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params();
header("Content-Type: application/xml; charset=utf-8");
echo "<urlset/>";

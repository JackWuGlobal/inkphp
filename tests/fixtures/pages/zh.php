<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
[$section] = params(0, 1); // pages/zh.php and pages/zh/ side by side
echo "zh ", e($section ?? "");

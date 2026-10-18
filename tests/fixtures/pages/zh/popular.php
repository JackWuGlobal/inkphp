<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
[$page] = params(0, 1);
echo "zh/popular ", e($page ?? "");

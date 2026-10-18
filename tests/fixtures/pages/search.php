<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
[$q] = params(1);
echo "search ", e($q);

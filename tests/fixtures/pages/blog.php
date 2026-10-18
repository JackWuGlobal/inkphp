<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
[$slug] = params(0, 1);
echo "blog ", e($slug ?? "");

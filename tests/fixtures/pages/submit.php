<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params();
allow("POST");
csrf_check(); // no token in the test request: 403
echo "submitted";

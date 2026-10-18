<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
[$name] = params(0, 1); // the fallback for any URL no deeper file claims
echo "index ", e($name ?? "");

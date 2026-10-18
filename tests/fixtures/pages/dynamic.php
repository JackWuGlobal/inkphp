<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params(0, 2 + 1); // not a literal: bin/ink routes/test cannot read it, so the page itself decides
echo "dynamic";

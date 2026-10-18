<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params();
allow("GET", "POST");
echo is_post() ? "posted" : "form";

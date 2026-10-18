<?php
// Fixture for tests/routes.txt (never run by bin/ink test in-process; served as pages/ in the HTTP check).
params();
layout(null, ['title' => t(['en' => 'Members'])]); // vars that are not literals: never matter for the status
allow('GET', 'POST');
require_login('/signin?from=members');
echo "members";

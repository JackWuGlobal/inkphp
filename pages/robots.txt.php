<?php
// /robots.txt, generated (a real public/robots.txt file would win over this page).
// Non-prod servers ask crawlers to stay away; prod only hides the private parts.
// cache_public(): this answer is the same for everyone and starts no session, so a CDN may keep it for a day.
// So it must not depend on the request: no url() here (it uses the Host header unless config('base_url') is set),
// no t()/locale() (the language would come from the visitor's cookie or Accept-Language).
params();
cache_public(86400);
header('Content-Type: text/plain; charset=utf-8');

echo "User-agent: *\n";
if (APP_ENV !== 'prod') {
    echo "Disallow: /\n";
} else {
    echo "Disallow: /admin\n";
    echo "Disallow: /login\n";
    echo "Disallow: /api/\n";
}

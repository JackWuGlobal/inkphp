<?php
// Log out: POST with a CSRF token only (the button in layouts/site.php). A GET link would let any other
// site log our users out, so GET is answered 405.
params();
allow('POST');
csrf_check();
logout();
flash('ok', t(['zh' => '已退出登录。', 'en' => 'You are logged out.']));
redirect(href('/'), 303);

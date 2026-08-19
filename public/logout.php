<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
if (is_post()) { verify_csrf(); Auth::logout(); }
redirect('login.php');


<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app/Services/AuthService.php';

use App\Services\AuthService;

AuthService::logout();
header("Location: login.php?msg=logged_out");
exit;

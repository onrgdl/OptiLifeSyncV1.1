<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'ok' => true,
    'status' => 'online',
    'time' => time(),
    'version' => '1.1.0'
], JSON_UNESCAPED_UNICODE);

<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$out = [
    'ok'      => true,
    'status'  => 'online',
    'time'    => time(),
    'version' => '1.2.0',
];

// ?db=1 → veritabanına hafif bir sorgu atar (Supabase ücretsiz planda 7 gün
// hareketsizlikte duraklamasın diye keep-alive için kullanılır).
if (($_GET['db'] ?? '') === '1') {
    $out['db'] = false;
    try {
        require_once __DIR__ . '/../config/db.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $out['db'] = (int)$pdo->query('SELECT 1')->fetchColumn() === 1;
        }
    } catch (\Throwable $e) {
        error_log('[ping] ' . $e->getMessage());
    }
    if (!$out['db']) {
        http_response_code(503);
        $out['ok'] = false;
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

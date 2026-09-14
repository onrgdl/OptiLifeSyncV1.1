<?php
declare(strict_types=1);

/**
 * OptiLifeSync - Vercel Serverless Front-Controller & Router
 * 
 * Vercel Serverless PHP ortamında gelen tüm sayfa isteklerini
 * ilgili PHP betiklerine yönlendirir.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
$path = trim((string)$path, '/');

// Kök dizin: Varsayılan olarak Dashboard'a yönlendir
if ($path === '') {
    require dirname(__DIR__) . '/dashboard.php';
    exit;
}

// BMR & Makro Hesaplayıcı sayfası
if ($path === 'index.php' || $path === 'bmr' || $path === 'bmr.php' || $path === 'calculator' || $path === 'calculator.php') {
    require dirname(__DIR__) . '/index.php';
    exit;
}

// Sayfa Yönlendirme Haritası
$routes = [
    'dashboard'      => 'dashboard.php',
    'nutrition'      => 'nutrition.php',
    'workout'        => 'workout.php',
    'reminders'      => 'reminders.php',
    'reports'        => 'reports.php',
    'guide'          => 'guide.php',
    'download'       => 'download.php',
    'login'          => 'login.php',
    'logout'         => 'logout.php',
    'creator'        => 'creator.php',
    // 'setup' ve 'setup-supabase' route'ları güvenlik nedeniyle kaldırıldı.
    // Bu dosyalara web üzerinden erişim artık mümkün değil.
];

$cleanSlug = preg_replace('/\.php$/i', '', strtolower($path));

if (isset($routes[$cleanSlug])) {
    $targetFile = dirname(__DIR__) . '/' . $routes[$cleanSlug];
    if (file_exists($targetFile)) {
        require $targetFile;
        exit;
    }
}

// Doğrudan kök dizindeki PHP dosyası çağrılmışsa (örn. /creator.php, /login.php vb.)
$directFile = dirname(__DIR__) . '/' . $cleanSlug . '.php';
if (file_exists($directFile) && !str_starts_with($cleanSlug, '.')) {
    require $directFile;
    exit;
}

// /api/... çağrıları router'a düşerse ilgili API dosyasını çalıştır
if (str_starts_with($path, 'api/')) {
    $apiFile = __DIR__ . '/' . basename($path);
    if (file_exists($apiFile) && basename($path) !== 'index.php') {
        require $apiFile;
        exit;
    }
}

// 404 Sayfa Bulunamadı
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>404 Sayfa Bulunamadı</title>';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '<body class="bg-dark text-light d-flex align-items-center justify-content-center min-vh-100 text-center">';
echo '<div><h1 class="display-3 fw-bold text-info">404</h1><p class="lead">Aradığınız sayfa bulunamadı: <code>' . htmlspecialchars($path) . '</code></p>';
echo '<a href="/dashboard.php" class="btn btn-info mt-3">Dashboard\'a Git</a></div></body></html>';

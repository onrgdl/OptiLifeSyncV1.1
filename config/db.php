<?php
declare(strict_types=1);

/**
 * OptiLifeSync - Veritabanı ve Sistem Yapılandırması
 * 
 * Bu dosya hem yerel XAMPP ortamında (MySQL) hem de Vercel + Supabase (PostgreSQL)
 * bulut ortamında tek bir kod tabanıyla sorunsuz çalışacak şekilde tasarlanmıştır.
 */

require_once __DIR__ . '/app.php';

// Hata Raporlama (Güvenlik: hataları ekrana basma, dosyaya logla)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// Vercel serverless ortamında dosya sistemi salt-okunurdur; logları geçici dizine yönlendir
$isVercel = !empty($_SERVER['VERCEL']) || !empty(getenv('VERCEL'));
$logDir = $isVercel ? sys_get_temp_dir() : (__DIR__ . '/../logs');
if (!$isVercel && !is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
ini_set('error_log', rtrim($logDir, '/\\') . '/php_errors.log');

// ── Veritabanı Bağlantı Ayarlarını Belirle ────────────────────────────
$dbUrl    = Config::get('DATABASE_URL');
$dbDriver = strtolower((string) Config::get('DB_DRIVER', 'mysql'));

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo     = null;
$dbError = null;

if (!empty($dbUrl)) {
    // 1. SUPABASE / POSTGRESQL CONNECTION STRING (DATABASE_URL)
    // Örnek: postgresql://postgres.[ref]:[pass]@aws-0-[region].pooler.supabase.com:6543/postgres?sslmode=require
    $parsed = parse_url($dbUrl);
    $rawScheme = strtolower($parsed['scheme'] ?? 'pgsql');
    $isPg = in_array($rawScheme, ['postgres', 'postgresql', 'pgsql'], true);

    $host   = $parsed['host'] ?? 'localhost';
    $port   = $parsed['port'] ?? ($isPg ? 5432 : 3306);
    $user   = isset($parsed['user']) ? urldecode($parsed['user']) : '';
    $pass   = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
    $dbName = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';

    $sslmode = 'require';
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $queryParams);
        if (!empty($queryParams['sslmode'])) {
            $sslmode = $queryParams['sslmode'];
        }
    }

    if ($isPg) {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslmode}";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        $pdo = null;
        $dbError = $e->getMessage();
    }
} elseif ($dbDriver === 'pgsql' || $dbDriver === 'postgres' || $dbDriver === 'postgresql') {
    // 2. Ayrı Ayrı PostgreSQL Değişkenleri
    $host    = (string) Config::get('DB_HOST', 'localhost');
    $port    = (string) Config::get('DB_PORT', '5432');
    $dbName  = (string) Config::get('DB_NAME', 'postgres');
    $user    = (string) Config::get('DB_USER', 'postgres');
    $pass    = (string) Config::get('DB_PASS', '');
    $sslmode = (string) Config::get('DB_SSLMODE', 'require');

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslmode}";

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        $pdo = null;
        $dbError = $e->getMessage();
    }
} else {
    // 3. Varsayılan Yerel XAMPP MySQL
    $dbHost    = (string) Config::get('DB_HOST', 'localhost');
    $dbPort    = (string) Config::get('DB_PORT', '3306');
    $dbName    = (string) Config::get('DB_NAME', 'gyp_db');
    $dbUser    = (string) Config::get('DB_USER', 'root');
    $dbPass    = (string) Config::get('DB_PASS', '');
    $dbCharset = (string) Config::get('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    } catch (\PDOException $e) {
        $pdo = null;
        $dbError = $e->getMessage();
    }
}

/**
 * Veritabanı sürücüsünden bağımsız olarak son eklenen ID'yi güvenle döndürür.
 * MySQL: PDO::lastInsertId()
 * PostgreSQL: Tablo sequence'i veya oturumun lastval() fonksiyonu
 */
function dbLastInsertId(PDO $pdo, string $table = ''): int {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        if (!empty($table)) {
            try {
                $val = $pdo->lastInsertId("{$table}_id_seq");
                if ($val !== false && $val !== '0' && $val !== '') {
                    return (int) $val;
                }
            } catch (\Throwable) {
                // Sequence adı farklıysa lastval()'e düş
            }
        }
        try {
            return (int) $pdo->query("SELECT lastval()")->fetchColumn();
        } catch (\Throwable) {
            return (int) $pdo->lastInsertId();
        }
    }
    return (int) $pdo->lastInsertId();
}

/**
 * PDO nesnesini döndüren yardımcı fonksiyon
 */
function getDbConnection(): ?PDO {
    global $pdo;
    return $pdo;
}

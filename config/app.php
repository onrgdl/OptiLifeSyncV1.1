<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Uygulama Yapılandırması
 *
 * Önce projenin kök dizininde .env dosyasını okur.
 * Bulunamazsa sistem ortam değişkenlerine (getenv) düşer.
 * Sonra da bu dosyadaki varsayılan değerlere.
 *
 * Kullanım (diğer PHP dosyalarından):
 *   require_once __DIR__ . '/config/app.php';
 *   $key = Config::get('GEMINI_API_KEY');
 */

// ── .env Dosyasını Yükle ──────────────────────────────────────────────
(function (): void {
    $envFile = dirname(__DIR__) . '/.env';   // Gyp/.env

    if (!file_exists($envFile)) {
        return;  // .env yoksa sistem env'e düş
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Yorum satırlarını atla
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        // KEY=VALUE formatını çöz
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Zaten set edilmişse override etme (system env öncelikli)
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
})();


// ── Config Sınıfı ─────────────────────────────────────────────────────
final class Config
{
    /** Tüm varsayılan değerler — .env yoksa buradan okunur */
    private const DEFAULTS = [
        // Veritabanı
        'DB_DRIVER'     => 'mysql',
        'DATABASE_URL'  => '',
        'DB_HOST'       => 'localhost',
        'DB_PORT'       => '3306',
        'DB_NAME'       => 'gyp_db',
        'DB_USER'       => 'root',
        'DB_PASS'       => '',
        'DB_CHARSET'    => 'utf8mb4',
        'DB_SSLMODE'    => 'require',

        // Gemini
        'GEMINI_API_KEY'=> '',
        'GEMINI_MODEL'  => 'gemini-2.5-flash-lite',

        // Uygulama
        'APP_ENV'       => 'production',
        'APP_DEBUG'     => 'false',
        'APP_TIMEZONE'  => 'Europe/Istanbul',

    ];

    /**
     * Yapılandırma değeri döner.
     *
     * Arama önceliği:
     *   1. $_ENV (yüklenmiş .env dosyası)
     *   2. getenv() (sistem ortam değişkeni)
     *   3. Bu sınıftaki DEFAULTS
     *   4. $fallback parametresi
     *
     * @param  string      $key      Değişken adı
     * @param  mixed       $fallback Hiçbir yerde bulunamazsa döner
     * @return mixed
     */
    public static function get(string $key, mixed $fallback = null): mixed
    {
        // 1. $_ENV
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        // 2. getenv
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        // 3. Defaults
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }

        // 4. Fallback
        return $fallback;
    }

    /** Gemini API anahtarının girilip girilmediğini kontrol eder */
    public static function hasGeminiKey(): bool
    {
        $key = self::get('GEMINI_API_KEY', '');
        return !empty($key) && $key !== 'BURAYA_GEMINI_API_KEY_YAZIN';
    }

    /** PDO bağlantı dizesini döner */
    public static function getDsn(): string
    {
        return sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            self::get('DB_HOST'),
            self::get('DB_NAME'),
            self::get('DB_CHARSET')
        );
    }
}

// ── Timezone Ayarla ───────────────────────────────────────────────────
date_default_timezone_set(Config::get('APP_TIMEZONE'));

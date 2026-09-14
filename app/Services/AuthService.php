<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Config;

require_once __DIR__ . '/../../config/app.php';

/**
 * AuthService
 * 
 * OptiLifeSync - Çok Kullanıcılı Kimlik Doğrulama & Creator Yönetim Servisi
 * ────────────────────────────────────────────────────────────────────────
 * 1. PIN tabanlı hızlı oturum açma (Username + PIN)
 * 2. Basit kullanıcı kaydı (Yalnızca Username + PIN)
 * 3. PIN unutulması durumunda:
 *    a) Kurtarma Kodu (Recovery Code) ile kullanıcının kendi PIN'ini sıfırlaması
 *    b) Creator tarafından tek tıkla PIN sıfırlama
 * 4. Creator Yönetici İşlemleri (Tüm kullanıcıları görme, silme, kullanıcı paneline göz atma)
 * 5. Katı Veri İzolasyonu (Kimse kimsenin planını göremez)
 */
class AuthService
{
    private ?PDO $db;
    private const AUTH_COOKIE_NAME = 'optilife_auth';
    private const AUTH_COOKIE_DAYS = 30;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
        self::startSession();
    }

    /**
     * Güvenli HMAC anahtarı döner.
     * APP_SECRET tanımlı değilse RuntimeException fırlatır (fail-closed güvenlik politikası).
     */
    public static function getSecretKey(): string
    {
        $customKey = (string)\Config::get('APP_SECRET', '');
        if ($customKey === '') {
            throw new \RuntimeException(
                'APP_SECRET ortam değişkeni tanımlı değil. ' .
                '.env dosyasına veya Vercel ortam değişkenlerine ekleyin. ' .
                'Üretmek için: php -r "echo bin2hex(random_bytes(32));"'
            );
        }
        return hash('sha256', 'optilife_hmac_secret_salt_2026_' . $customKey);
    }

    /**
     * İsteğin HTTPS üzerinden gelip gelmediğini kapsamlı kontrol eder (Ters Proxy / Vercel Edge dahil)
     */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['VERCEL']) || !empty(getenv('VERCEL'))) {
            return true;
        }
        return false;
    }

    /**
     * URL ve Çerez güvenli Base64 kodlayıcı (URL-safe, '+' ve '/' yerine '-' ve '_' kullanır)
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL ve Çerez güvenli Base64 çözücü
     */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Güvenli session başlatma ve çerezden otomatik oturum kurtarma (Vercel Stateless Serverless uyumlu)
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                ini_set('session.cookie_httponly', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_lifetime', (string)(self::AUTH_COOKIE_DAYS * 86400));
                ini_set('session.gc_maxlifetime', (string)(self::AUTH_COOKIE_DAYS * 86400));
                ini_set('session.cookie_path', '/');
                ini_set('session.cookie_samesite', 'Lax');
                if (self::isHttps()) {
                    ini_set('session.cookie_secure', '1');
                }
            }
            session_start();
        }

        // Vercel Serverless ve çoklu cihaz uyumu:
        // Eğer Session kaybolmuşsa veya boşsa, imzalı optilife_auth çerezinden oturumu anında geri yükle:
        if ((empty($_SESSION['user']) || empty($_SESSION['user']['id'])) && !empty($_COOKIE[self::AUTH_COOKIE_NAME])) {
            self::restoreSessionFromCookie((string)$_COOKIE[self::AUTH_COOKIE_NAME]);
        }
    }

    /**
     * Session dosya kilitlerini (lock) erken serbest bırakmak için yardımcı metot (API performansı için)
     */
    public static function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * İmzalı çerezden kullanıcı oturumunu doğrular ve geri yükler.
     * Yalnızca tek geçerli APP_SECRET ile imzalanan çerezler kabul edilir.
     */
    public static function restoreSessionFromCookie(string $token): bool
    {
        $token = trim($token);
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$encoded, $sig] = $parts;

        // HMAC imza doğrulaması — anahtar yoksa veya geçersizse oturumu geri yükleme
        try {
            $secretKey = self::getSecretKey();
        } catch (\Throwable) {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $encoded, $secretKey);
        if (!hash_equals($expectedSig, $sig)) {
            return false;
        }

        $json = self::base64UrlDecode($encoded);
        if (!$json || !str_starts_with($json, '{')) {
            $json = base64_decode($encoded);
        }
        if (!$json) {
            return false;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['id']) || empty($payload['exp'])) {
            return false;
        }

        // Süre kontrolü
        if ($payload['exp'] < time()) {
            return false;
        }

        // Oturumu güvenle geri yükle
        $_SESSION['user'] = [
            'id'       => (int)$payload['id'],
            'name'     => $payload['name'] ?? $payload['username'],
            'username' => $payload['username'],
            'role'     => $payload['role'] ?? 'user',
            'email'    => $payload['email'] ?? ($payload['username'] . '@optilifesync.local'),
        ];

        // Kayan oturum süresi (Sliding expiration): Çerez süresine 15 günden az kaldıysa çerezi yenile
        if (($payload['exp'] - time()) < (15 * 86400) && !headers_sent()) {
            self::issueAuthCookie($_SESSION['user']);
        }

        return true;
    }

    /**
     * Kullanıcıya 30 günlük kalıcı, URL-safe ve kurcalanamaz imzalı auth çerezi üretir
     */
    public static function issueAuthCookie(array $user): string
    {
        $exp = time() + (self::AUTH_COOKIE_DAYS * 86400);
        $payload = [
            'id'       => (int)$user['id'],
            'username' => $user['username'] ?? $user['name'],
            'name'     => $user['name'] ?? $user['username'],
            'role'     => $user['role'] ?? 'user',
            'email'    => $user['email'] ?? '',
            'exp'      => $exp,
        ];

        $encoded = self::base64UrlEncode(json_encode($payload));
        $sig     = hash_hmac('sha256', $encoded, self::getSecretKey());
        $token   = "{$encoded}.{$sig}";

        $isHttps = self::isHttps();

        if (!headers_sent()) {
            setcookie(self::AUTH_COOKIE_NAME, $token, [
                'expires'  => $exp,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        $_COOKIE[self::AUTH_COOKIE_NAME] = $token;
        return $token;
    }

    /**
     * Oturumdaki mevcut kullanıcı bilgilerini döner
     */
    public static function getCurrentUser(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    /**
     * Oturumdaki mevcut kullanıcının ID'sini döner (Giriş yapılmamışsa 0)
     */
    public static function getCurrentUserId(): int
    {
        self::startSession();
        return (int)($_SESSION['user']['id'] ?? 0);
    }

    /**
     * Kullanıcı giriş yapmış mı?
     */
    public static function isLoggedIn(): bool
    {
        return self::getCurrentUserId() > 0;
    }

    /**
     * Oturumdaki kullanıcı Creator mı?
     * Yalnızca DB'den gelen role === 'creator' alanına bakılır.
     * id===1 ve username==='onrgdl' kısayolları güvenlik açığı oluşturduğu için kaldırıldı.
     */
    public static function isCreator(): bool
    {
        self::startSession();
        $user = $_SESSION['user'] ?? null;
        if (!$user) return false;
        return ($user['role'] ?? '') === 'creator';
    }

    /**
     * Oturumdaki kullanıcı şu anda bir hesaba "göz atıyor" mu (impersonate)?
     */
    public static function isImpersonating(): bool
    {
        self::startSession();
        return !empty($_SESSION['impersonated_by']);
    }

    /**
     * Sayfa veya API için oturum kontrolü yapar
     */
    public static function requireAuth(bool $isApi = false): int
    {
        self::startSession();
        $userId = self::getCurrentUserId();
        if ($userId > 0) {
            return $userId;
        }

        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'Oturum süreniz doldu veya giriş yapmadınız.',
                'require_login' => true
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
        header("Location: login.php?redirect={$redirect}");
        exit;
    }

    /**
     * Yalnızca Creator erişebilir
     */
    public static function requireCreator(): void
    {
        self::requireAuth(false);
        if (!self::isCreator()) {
            header("Location: dashboard.php?error=unauthorized");
            exit;
        }
    }

    /**
     * Kullanıcı adı ve PIN ile giriş yap
     */
    public function login(string $username, string $pin): array
    {
        $username = trim($username);
        $pin = trim($pin);

        if ($username === '' || $pin === '') {
            return ['ok' => false, 'error' => 'Kullanıcı adı ve PIN boş bırakılamaz.'];
        }

        // Kaba Kuvvet (Brute-Force) Koruması: 5 hatalı denemede 60 saniye kilitleme
        self::startSession();
        $failedAttempts = (int)($_SESSION['login_failed_attempts'] ?? 0);
        $lastFailedTime = (int)($_SESSION['login_last_failed_time'] ?? 0);
        $lockoutSeconds = 60;

        if ($failedAttempts >= 5) {
            $elapsed = time() - $lastFailedTime;
            if ($elapsed < $lockoutSeconds) {
                $remaining = $lockoutSeconds - $elapsed;
                return [
                    'ok' => false,
                    'error' => "Çok fazla hatalı PIN denemesi yapıldı. Güvenliğiniz için lütfen {$remaining} saniye bekleyin."
                ];
            } else {
                // Kilit süresi doldu, sayacı sıfırla
                $_SESSION['login_failed_attempts'] = 0;
                $failedAttempts = 0;
            }
        }

        // Giriş yalnızca username ile — name alanı artık kullanılmıyor (kullanıcı adı tarama saldırısını önler)
        $stmt = $this->db->prepare("
            SELECT id, name, username, role, pin_hash, password_hash, email, recovery_code
            FROM users
            WHERE LOWER(username) = LOWER(:u)
            LIMIT 1
        ");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Kullanıcı adı bulunamadı mesajını kasıtlı olarak genel tutuyoruz (kullanıcı adı taramasını engeller)
            return ['ok' => false, 'error' => 'Kullanıcı adı veya PIN hatalı.'];
        }

        // PIN Doğrulama — 1234 arka kapısı kaldırıldı
        $valid = false;
        if (!empty($user['pin_hash']) && password_verify($pin, $user['pin_hash'])) {
            $valid = true;
        } elseif (!empty($user['password_hash']) && password_verify($pin, $user['password_hash'])) {
            $valid = true;
        }

        if (!$valid) {
            $_SESSION['login_failed_attempts'] = $failedAttempts + 1;
            $_SESSION['login_last_failed_time'] = time();
            $remainingAttempts = max(0, 5 - ($failedAttempts + 1));
            $msg = 'Kullanıcı adı veya PIN hatalı.';
            if ($remainingAttempts > 0 && $remainingAttempts <= 3) {
                $msg .= " ({$remainingAttempts} deneme hakkınız kaldı)";
            } elseif ($remainingAttempts === 0) {
                $msg .= " Art arda 5 hatalı giriş nedeniyle sistem 60 saniye kilitlendi.";
            }
            return ['ok' => false, 'error' => $msg];
        }

        // Oturumu başlat
        $this->setUserSession($user);

        return ['ok' => true, 'user' => $_SESSION['user']];
    }

    /**
     * Sadece Kullanıcı Adı ve PIN ile Hızlı Kayıt
     */
    public function register(string $username, string $pin, ?string $name = null): array
    {
        $username = trim($username);
        $pin = trim($pin);
        $name = trim((string)$name);

        if (strlen($username) < 3 || strlen($username) > 30) {
            return ['ok' => false, 'error' => 'Kullanıcı adı 3 ile 30 karakter arasında olmalıdır.'];
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return ['ok' => false, 'error' => 'Kullanıcı adı sadece harf, rakam, nokta ve alt çizgi içerebilir.'];
        }

        // Yeni kayıtlar için minimum PIN 6 hane
        if (strlen($pin) < 6) {
            return ['ok' => false, 'error' => 'PIN kodu en az 6 haneli olmalıdır.'];
        }

        // Kullanıcı adı çakışma kontrolü
        $chk = $this->db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            return ['ok' => false, 'error' => "'{$username}' kullanıcı adı zaten alınmış. Lütfen başka bir ad seçin."];
        }

        // Kurtarma kodu üret (Örn: REC-7F89-A2C4)
        $recoveryCode = 'REC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4)) . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $displayName = $name !== '' ? $name : $username;
        $email = $username . '@optilifesync.local';

        // Rol her zaman 'user' — Creator rolü yalnızca DB'de elle atanır
        $role = 'user';

        $stmt = $this->db->prepare("
            INSERT INTO users (
                name, username, role, email, pin_hash, recovery_code,
                gender, birth_date, height_cm, weight_kg, goal, activity_level
            ) VALUES (
                :name, :username, :role, :email, :pin_hash, :recovery_code,
                'male', '1996-01-01', 170.00, 70.00, 'maintain', 'moderately_active'
            )
        ");
        $stmt->execute([
            ':name' => $displayName,
            ':username' => $username,
            ':role' => $role,
            ':email' => $email,
            ':pin_hash' => $pinHash,
            ':recovery_code' => $recoveryCode,
        ]);

        $newUserId = (int)$this->db->lastInsertId();

        // Yeni kullanıcı için varsayılan Dinamik Makro Hedefleri oluştur (BMR/TDEE hazır olsun)
        try {
            $mStmt = $this->db->prepare("
                INSERT INTO macro_targets (user_id, day_type, calories, protein_g, carbs_g, fat_g, extra_calories, extra_protein_g, source)
                VALUES 
                    (:uid1, 'rest', 2200, 150.00, 250.00, 65.00, 0, 0, 'auto'),
                    (:uid2, 'training', 2600, 180.00, 300.00, 75.00, 400, 30.00, 'auto')
            ");
            $mStmt->execute([':uid1' => $newUserId, ':uid2' => $newUserId]);
        } catch (\Throwable) {
            // ignore duplicate if any
        }

        // Oturumu başlat
        $newUser = [
            'id' => $newUserId,
            'name' => $displayName,
            'username' => $username,
            'role' => $role,
            'email' => $email,
            'recovery_code' => $recoveryCode,
        ];
        $this->setUserSession($newUser);

        return [
            'ok' => true,
            'user' => $newUser,
            'recovery_code' => $recoveryCode,
        ];
    }

    /**
     * Kurtarma Kodu ile PIN Sıfırlama
     */
    public function resetPinWithRecoveryCode(string $username, string $recoveryCode, string $newPin): array
    {
        $username = trim($username);
        $recoveryCode = trim(strtoupper($recoveryCode));
        $newPin = trim($newPin);

        if ($username === '' || $recoveryCode === '' || $newPin === '') {
            return ['ok' => false, 'error' => 'Tüm alanları doldurmanız gerekmektedir.'];
        }

        // Yeni PIN için minimum 6 hane (yalnızca PIN sıfırlamada)
        if (strlen($newPin) < 6) {
            return ['ok' => false, 'error' => 'Yeni PIN en az 6 haneli olmalıdır.'];
        }

        $stmt = $this->db->prepare("
            SELECT id, username, recovery_code 
            FROM users 
            WHERE LOWER(username) = LOWER(:u)
            LIMIT 1
        ");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['ok' => false, 'error' => 'Kullanıcı bulunamadı.'];
        }

        $storedCode = strtoupper(trim((string)$user['recovery_code']));
        $cleanInput = str_replace(['-', ' '], '', $recoveryCode);
        $cleanStored = str_replace(['-', ' '], '', $storedCode);

        // Sabit zamanlı karşılaştırma (timing attack önlemi)
        if (!hash_equals($cleanStored, $cleanInput)) {
            return ['ok' => false, 'error' => 'Kurtarma kodu hatalı! Lütfen kaydettiğiniz kodu kontrol edin.'];
        }

        // Yeni PIN'i kaydet ve yeni bir kurtarma kodu üret
        $newPinHash = password_hash($newPin, PASSWORD_DEFAULT);
        $newRecovery = 'REC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4)) . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));

        $uStmt = $this->db->prepare("
            UPDATE users 
            SET pin_hash = :pin, recovery_code = :rec 
            WHERE id = :id
        ");
        $uStmt->execute([
            ':pin' => $newPinHash,
            ':rec' => $newRecovery,
            ':id' => $user['id']
        ]);

        return [
            'ok' => true,
            'message' => 'PIN kodunuz başarıyla sıfırlandı! Yeni PIN kodunuzla giriş yapabilirsiniz.',
            'new_recovery_code' => $newRecovery
        ];
    }

    /**
     * Creator Yetkisi: Bir kullanıcının PIN'ini doğrudan sıfırlama
     */
    public function creatorResetPin(int $targetUserId, string $newPin): array
    {
        if (!self::isCreator()) {
            return ['ok' => false, 'error' => 'Bu işlem için Creator yetkisi gereklidir.'];
        }

        $newPin = trim($newPin);
        if (strlen($newPin) < 4) {
            return ['ok' => false, 'error' => 'Yeni PIN en az 4 haneli olmalıdır.'];
        }

        $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE users SET pin_hash = :pin WHERE id = :id");
        $stmt->execute([':pin' => $pinHash, ':id' => $targetUserId]);

        return ['ok' => true, 'message' => "Kullanıcının PIN kodu başarıyla güncellendi."];
    }

    /**
     * Creator Yetkisi: Tüm kullanıcıları istatistikleriyle birlikte listeleme
     * Güvenlik: recovery_code artık döndürülmüyor
     */
    public function creatorGetAllUsers(): array
    {
        if (!self::isCreator()) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT 
                u.id, u.name, u.username, u.role, u.email, u.gender, u.birth_date,
                u.height_cm, u.weight_kg, u.goal, u.activity_level, u.created_at,
                (SELECT COUNT(*) FROM food_logs fl JOIN daily_logs dl ON fl.daily_log_id = dl.id WHERE dl.user_id = u.id) as total_meals,
                (SELECT COUNT(*) FROM workouts w WHERE w.user_id = u.id) as total_workouts,
                (SELECT COUNT(*) FROM supplements s WHERE s.user_id = u.id AND s.is_active = 1) as total_supplements,
                (SELECT COUNT(*) FROM reminders r WHERE r.user_id = u.id AND r.is_active = 1) as total_reminders
            FROM users u
            ORDER BY (u.role = 'creator') DESC, u.id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Creator Yetkisi: Bir kullanıcıyı ve tüm bağlı verilerini silme
     */
    public function creatorDeleteUser(int $targetUserId): array
    {
        if (!self::isCreator()) {
            return ['ok' => false, 'error' => 'Bu işlem için Creator yetkisi gereklidir.'];
        }

        if ($targetUserId === self::getCurrentUserId()) {
            return ['ok' => false, 'error' => 'Kendi hesabınızı silemezsiniz.'];
        }

        $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id AND role != 'creator'");
        $stmt->execute([':id' => $targetUserId]);

        return ['ok' => true, 'message' => 'Kullanıcı ve tüm verileri başarıyla silindi.'];
    }

    /**
     * Creator Yetkisi: Başka bir kullanıcının paneline göz at (Impersonate)
     */
    public function impersonateUser(int $targetUserId): array
    {
        if (!self::isCreator() && !self::isImpersonating()) {
            return ['ok' => false, 'error' => 'Yetkisiz işlem.'];
        }

        // Güvenlik: recovery_code artık çekilmiyor
        $stmt = $this->db->prepare("SELECT id, name, username, role, email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $targetUserId]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            return ['ok' => false, 'error' => 'Kullanıcı bulunamadı.'];
        }

        if (empty($_SESSION['impersonated_by'])) {
            $_SESSION['impersonated_by'] = $_SESSION['user'];
        }

        $_SESSION['user'] = [
            'id' => (int)$targetUser['id'],
            'name' => $targetUser['name'],
            'username' => $targetUser['username'] ?? $targetUser['name'],
            'role' => $targetUser['role'] ?? 'user',
            'email' => $targetUser['email'] ?? '',
        ];

        return ['ok' => true, 'target_user' => $_SESSION['user']];
    }

    /**
     * Creator Yetkisi: Göz atma modundan çık ve kendi Creator hesabına dön
     */
    public function stopImpersonating(): void
    {
        self::startSession();
        if (!empty($_SESSION['impersonated_by'])) {
            $_SESSION['user'] = $_SESSION['impersonated_by'];
            unset($_SESSION['impersonated_by']);
        }
    }

    /**
     * Oturumu sonlandır (Logout)
     */
    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        // İmzalı kalıcı auth çerezini de sıfırla — secure flag doğru kullanılıyor
        $isHttps = self::isHttps();
        if (!headers_sent()) {
            setcookie(self::AUTH_COOKIE_NAME, '', [
                'expires'  => time() - 42000,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        unset($_COOKIE[self::AUTH_COOKIE_NAME]);
        session_destroy();
    }

    /**
     * Session'a kullanıcı bilgilerini yazar ve kalıcı auth çerezi üretir
     */
    private function setUserSession(array $user): void
    {
        self::startSession();
        if (!headers_sent()) {
            // true: eski oturum ID'sini anında geçersiz kıl (oturum sabitleme saldırısını önler)
            session_regenerate_id(true);
        }
        // Başarılı girişte kaba kuvvet sayacını sıfırla
        unset($_SESSION['login_failed_attempts'], $_SESSION['login_last_failed_time']);

        $userRecord = [
            'id'       => (int)$user['id'],
            'name'     => $user['name'],
            'username' => $user['username'] ?? $user['name'],
            'role'     => $user['role'] ?? 'user',
            'email'    => $user['email'] ?? '',
        ];

        $_SESSION['user'] = $userRecord;

        // Vercel Serverless ve çoklu cihaz uyumlu 30 günlük imzalı auth çerezi ver
        self::issueAuthCookie($userRecord);
    }
}

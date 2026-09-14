<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use DateTime;

/**
 * WorkoutService
 *
 * OptiLifeSync - Spor & Antrenman Modülü Servisi
 *
 * Sorumluluklar:
 * 1. workouts tablosunda haftalık/günlük antrenman CRUD işlemleri
 * 2. Dinamik Makro Senkronizasyonu: Antrenman eklenince daily_logs ve
 *    makro hedeflerine otomatik +400 kcal & +30g protein ekleme/kaldırma
 * 3. Antrenman Sonrası Takviye Tetikleyicisi: Antrenman tamamlandığında
 *    15 dakika sonrasına "Whey Protein ve Magnezyum Al" alarmı kurma
 */
class WorkoutService
{
    private PDO $db;
    private static bool $schemaMigrated = false;

    // Spor takibi sadece takip amaçlıdır (beslenmeye ekstra kalori/protein eklenmez)
    public const EXTRA_CALORIES = 0;
    public const EXTRA_PROTEIN_G = 0.0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureSchemaCompatibility();
    }

    /**
     * PostgreSQL (Supabase) üzerindeki eski uk_workouts_user_date kısıtlamasını
     * (günde sadece 1 antrenmana izin veren eski kısıtlama) otomatik olarak kaldırır.
     */
    private function ensureSchemaCompatibility(): void
    {
        if (self::$schemaMigrated) {
            return;
        }

        try {
            $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $this->db->exec("ALTER TABLE workouts DROP CONSTRAINT IF EXISTS uk_workouts_user_date;");
            }
            self::$schemaMigrated = true;
        } catch (\Throwable) {
            // DDL yetkisi veya bağlantı durumunda sessizce devam et
        }
    }

    /**
     * Belirli bir tarih aralığındaki (haftalık) antrenmanları çeker.
     * Tarih (Y-m-d) anahtarlı dizi döner.
     *
     * @param  int    $userId
     * @param  string $startDate Y-m-d
     * @param  string $endDate   Y-m-d
     * @return array<string, array>
     */
    public function getWorkoutsByRange(int $userId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, tarih, antrenman_tipi, zorluk_seviyesi,
                   tamamlandi_mi, tamamlanma_saati, created_at, updated_at
            FROM workouts
            WHERE user_id = :user_id
              AND tarih BETWEEN :start_date AND :end_date
            ORDER BY tarih ASC, id ASC
        ");
        $stmt->execute([
            ':user_id'    => $userId,
            ':start_date' => $startDate,
            ':end_date'   => $endDate,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $indexed = [];
        foreach ($rows as $row) {
            $row['tamamlandi_mi'] = (bool)$row['tamamlandi_mi'];
            $indexed[$row['tarih']][] = $row;
        }

        return $indexed;
    }

    /**
     * ID'ye göre antrenman kaydını çeker.
     */
    public function getWorkoutById(int $workoutId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM workouts
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $workoutId, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['tamamlandi_mi'] = (bool)$row['tamamlandi_mi'];
            return $row;
        }
        return null;
    }

    /**
     * O günkü ilk antrenman kaydını çeker (geriye dönük uyumluluk).
     */
    public function getWorkoutByDate(int $userId, string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM workouts
            WHERE user_id = :user_id AND tarih = :tarih
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':tarih' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['tamamlandi_mi'] = (bool)$row['tamamlandi_mi'];
            return $row;
        }
        return null;
    }

    /**
     * O günkü tüm antrenman kayıtlarını çeker.
     */
    public function getWorkoutsByDate(int $userId, string $date): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM workouts
            WHERE user_id = :user_id AND tarih = :tarih
            ORDER BY id ASC
        ");
        $stmt->execute([':user_id' => $userId, ':tarih' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['tamamlandi_mi'] = (bool)$row['tamamlandi_mi'];
        }
        return $rows;
    }

    /**
     * Bir güne antrenman kaydeder veya belirli bir kaydı günceller.
     * Otomatik olarak Dinamik Makroyu (+400 kcal, +30g protein) etkinleştirir.
     *
     * @param  int       $userId
     * @param  string    $date       Y-m-d
     * @param  string    $type       Örn: 'Ağırlık', 'Kardiyo', 'HIIT', 'Pilates'
     * @param  string    $difficulty Örn: 'Kolay', 'Orta', 'Zor'
     * @param  int|null  $workoutId  Mevcut kaydı güncellemek için id (null ise yeni ekler/varsa günceller)
     * @return array     Kayıt ve dinamik makro durumu
     */
    public function saveWorkout(int $userId, string $date, string $type, string $difficulty = 'Orta', ?int $workoutId = null): array
    {
        $validDifficulties = ['Kolay', 'Orta', 'Zor'];
        if (!in_array($difficulty, $validDifficulties, true)) {
            $difficulty = 'Orta';
        }

        if ($workoutId !== null && $workoutId > 0) {
            $stmt = $this->db->prepare("
                UPDATE workouts
                SET antrenman_tipi   = :antrenman_tipi,
                    zorluk_seviyesi  = :zorluk_seviyesi,
                    updated_at       = CURRENT_TIMESTAMP
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([
                ':antrenman_tipi'  => $type,
                ':zorluk_seviyesi' => $difficulty,
                ':id'              => $workoutId,
                ':user_id'         => $userId,
            ]);
            $targetId = $workoutId;
        } else {
            // Aynı gün aynı tür ve HENÜZ TAMAMLANMAMIŞ antrenman varsa zorluğu güncelle,
            // aksi halde (farklı tür veya yeni seans) yeni antrenman ekle
            $check = $this->db->prepare("
                SELECT id FROM workouts
                WHERE user_id = :user_id AND tarih = :tarih AND antrenman_tipi = :antrenman_tipi AND tamamlandi_mi = 0
                LIMIT 1
            ");
            $check->execute([
                ':user_id'        => $userId,
                ':tarih'          => $date,
                ':antrenman_tipi' => $type,
            ]);
            $existingId = $check->fetchColumn();

            if ($existingId) {
                $upd = $this->db->prepare("
                    UPDATE workouts
                    SET zorluk_seviyesi = :zorluk_seviyesi,
                        updated_at      = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $upd->execute([
                    ':zorluk_seviyesi' => $difficulty,
                    ':id'              => $existingId,
                ]);
                $targetId = (int)$existingId;
            } else {
                try {
                    $ins = $this->db->prepare("
                        INSERT INTO workouts (user_id, tarih, antrenman_tipi, zorluk_seviyesi, tamamlandi_mi)
                        VALUES (:user_id, :tarih, :antrenman_tipi, :zorluk_seviyesi, 0)
                    ");
                    $ins->execute([
                        ':user_id'         => $userId,
                        ':tarih'           => $date,
                        ':antrenman_tipi'  => $type,
                        ':zorluk_seviyesi' => $difficulty,
                    ]);
                    $targetId = (int)$this->db->lastInsertId();
                } catch (\PDOException $pe) {
                    // PostgreSQL üzerinde uk_workouts_user_date kısıtı henüz silinmediyse kaldırıp tekrar dene
                    if (str_contains($pe->getMessage(), 'uk_workouts_user_date') || $pe->getCode() === '23505') {
                        $this->db->exec("ALTER TABLE workouts DROP CONSTRAINT IF EXISTS uk_workouts_user_date;");
                        $ins = $this->db->prepare("
                            INSERT INTO workouts (user_id, tarih, antrenman_tipi, zorluk_seviyesi, tamamlandi_mi)
                            VALUES (:user_id, :tarih, :antrenman_tipi, :zorluk_seviyesi, 0)
                        ");
                        $ins->execute([
                            ':user_id'         => $userId,
                            ':tarih'           => $date,
                            ':antrenman_tipi'  => $type,
                            ':zorluk_seviyesi' => $difficulty,
                        ]);
                        $targetId = (int)$this->db->lastInsertId();
                    } else {
                        throw $pe;
                    }
                }
            }
        }

        // Günlük aktivite kaydı: daily_logs.workout_done = 1
        $macroResult = $this->syncDynamicMacro($userId, $date, true);

        $workout = $this->getWorkoutById($targetId, $userId);

        return [
            'ok'            => true,
            'workout'       => $workout,
            'dynamic_macro' => $macroResult,
            'message'       => "{$type} antrenmanı başarıyla kaydedildi! 💪",
        ];
    }

    /**
     * Antrenmanı siler. O gün başka antrenman kalmadıysa dinamik makroyu normale döndürür.
     *
     * @param  int  $workoutId
     * @param  int  $userId
     * @return array
     */
    public function deleteWorkout(int $workoutId, int $userId): array
    {
        // Önce antrenmanın tarihini bul
        $check = $this->db->prepare("SELECT tarih FROM workouts WHERE id = :id AND user_id = :user_id");
        $check->execute([':id' => $workoutId, ':user_id' => $userId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \RuntimeException("Antrenman kaydı bulunamadı.");
        }

        $date = $row['tarih'];

        // Kaydı sil
        $del = $this->db->prepare("DELETE FROM workouts WHERE id = :id AND user_id = :user_id");
        $del->execute([':id' => $workoutId, ':user_id' => $userId]);

        // O gün için başka antrenman var mı kontrol et
        $rem = $this->db->prepare("SELECT COUNT(*) FROM workouts WHERE user_id = :user_id AND tarih = :tarih");
        $rem->execute([':user_id' => $userId, ':tarih' => $date]);
        $hasRemaining = (int)$rem->fetchColumn() > 0;

        // Kalan antrenman yoksa dinamik makroyu normale çek (workout_done = 0)
        $macroResult = $this->syncDynamicMacro($userId, $date, $hasRemaining);

        return [
            'ok'            => true,
            'deleted_id'    => $workoutId,
            'has_workout'   => $hasRemaining,
            'dynamic_macro' => $macroResult,
            'message'       => "Antrenman başarıyla kaldırıldı.",
        ];
    }

    /**
     * Antrenmanı "Tamamlandı" olarak işaretler.
     * 
     * İŞLEV 2: Antrenman Sonrası Takviye Tetikleyicisi
     * - workouts.tamamlandi_mi = 1
     * - workouts.tamamlanma_saati = Şimdiki saat
     * - reminders tablosuna 15 dakika sonrasına: "Whey Protein ve Magnezyum Al" alarmı kurar
     *
     * @param  int  $workoutId
     * @param  int  $userId
     * @return array
     */
    public function completeWorkout(int $workoutId, int $userId): array
    {
        $now = new DateTime();
        $currentTime   = $now->format('H:i:s');
        $reminderTime  = (clone $now)->modify('+15 minutes')->format('H:i:s');
        $shortRemindAt = substr($reminderTime, 0, 5); // "15:45"
        $todayDayIndex = (int) $now->format('w');      // 0=Pazar..6=Cumartesi

        // 1. workouts tablosunu güncelle
        $stmt = $this->db->prepare("
            UPDATE workouts
            SET tamamlandi_mi    = 1,
                tamamlanma_saati = :currentTime,
                updated_at       = CURRENT_TIMESTAMP
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([
            ':currentTime' => $currentTime,
            ':id'          => $workoutId,
            ':user_id'     => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            $existing = $this->db->prepare("SELECT * FROM workouts WHERE id = :id AND user_id = :user_id");
            $existing->execute([':id' => $workoutId, ':user_id' => $userId]);
            $wRow = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$wRow) {
                throw new \RuntimeException("Antrenman kaydı bulunamadı.");
            }
        }

        // daily_logs senkronizasyonu
        $checkDate = $this->db->prepare("SELECT tarih FROM workouts WHERE id = ?");
        $checkDate->execute([$workoutId]);
        $wDate = $checkDate->fetchColumn() ?: date('Y-m-d');
        $this->syncDynamicMacro($userId, (string)$wDate, true);

        return [
            'ok'               => true,
            'workout_id'       => $workoutId,
            'tamamlandi_mi'    => true,
            'tamamlanma_saati' => substr($currentTime, 0, 5),
            'reminder'         => null,
            'message'          => "Tebrikler! Antrenman başarıyla tamamlandı. Harika bir iş çıkardın! 💪🔥",
        ];
    }

    /**
     * Dinamik Makro Senkronizasyonu:
     * Antrenman günü ise daily_logs.workout_done = 1 yapar.
     * Antrenman yoksa/silindiyse workout_done = 0 yapar.
     *
     * @param  int    $userId
     * @param  string $date       Y-m-d
     * @param  bool   $isTraining Antrenman var mı?
     * @return array  Güncel dinamik makro değerleri
     */
    public function syncDynamicMacro(int $userId, string $date, bool $isTraining): array
    {
        // 1. daily_logs satırını bul veya oluştur
        $chk = $this->db->prepare("SELECT id, workout_done FROM daily_logs WHERE user_id = :u AND log_date = :d");
        $chk->execute([':u' => $userId, ':d' => $date]);
        $log = $chk->fetch(PDO::FETCH_ASSOC);

        $workoutVal = $isTraining ? 1 : 0;

        if ($log) {
            $upd = $this->db->prepare("UPDATE daily_logs SET workout_done = :w, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute([':w' => $workoutVal, ':id' => $log['id']]);
        } else {
            $ins = $this->db->prepare("
                INSERT INTO daily_logs (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
                VALUES (:u, :d, 0, 0, 0, 0, :w)
            ");
            $ins->execute([':u' => $userId, ':d' => $date, ':w' => $workoutVal]);
        }

        // 2. Kullanıcı profilini alıp BMR ve Dinamik Makro hesapla
        $profStmt = $this->db->prepare("SELECT * FROM users WHERE id = :u");
        $profStmt->execute([':u' => $userId]);
        $user = $profStmt->fetch(PDO::FETCH_ASSOC);

        $weight = $user ? (float)$user['weight_kg'] : 80.0;
        $height = $user ? (float)$user['height_cm'] : 175.0;
        $gender = $user ? $user['gender'] : 'male';
        $goal   = $user ? $user['goal'] : 'gain';
        $act    = $user ? $user['activity_level'] : 'moderately_active';
        $age    = 28;
        if (!empty($user['birth_date'])) {
            try {
                $age = (int)(new DateTime($user['birth_date']))->diff(new DateTime())->y;
            } catch (\Throwable $e) {}
        }

        require_once __DIR__ . '/MetabolismCalculator.php';
        $calc = new MetabolismCalculator($weight, $height, $age, $gender, $act, $goal);
        $macros = $calc->getDailyMacros($isTraining);

        return [
            'is_training'     => $isTraining,
            'extra_calories'  => $isTraining ? self::EXTRA_CALORIES : 0,
            'extra_protein_g' => $isTraining ? self::EXTRA_PROTEIN_G : 0,
            'target_macros'   => $macros,
        ];
    }
}

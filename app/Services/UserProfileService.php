<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/MetabolismCalculator.php';
require_once __DIR__ . '/MacroSaver.php';

use PDO;
use DateTime;

/**
 * UserProfileService
 *
 * Kullanıcı profil parametrelerinin (Kilo, Boy, Yaş, Cinsiyet, Aktivite, Hedef)
 * ve günlük antrenman durumunun BMR/TDEE Hesaplayıcı, Beslenme Modülü ve
 * Dashboard arasında %100 senkronize ve kalıcı (persistent) kalmasını sağlar.
 *
 * @package App\Services
 */
class UserProfileService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Kullanıcının veritabanında saklanan en son güncel profilini döner.
     *
     * @param int $userId
     * @return array{
     *     weight: float,
     *     height: float,
     *     age: int,
     *     gender: string,
     *     activity: string,
     *     goal: string,
     *     birth_date: string
     * }
     */
    public function getProfile(int $userId = 1): array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                'weight'     => 80.0,
                'height'     => 175.0,
                'age'        => 28,
                'gender'     => 'male',
                'activity'   => 'moderately_active',
                'goal'       => 'maintain',
                'birth_date' => '1996-01-01',
            ];
        }

        $age = 28;
        if (!empty($user['birth_date'])) {
            try {
                $age = (int)(new DateTime($user['birth_date']))->diff(new DateTime())->y;
                if ($age <= 0) $age = 28;
            } catch (\Throwable) {
                $age = 28;
            }
        }

        return [
            'weight'     => (float)($user['weight_kg'] ?? 80.0),
            'height'     => (float)($user['height_cm'] ?? 175.0),
            'age'        => $age,
            'gender'     => (string)($user['gender'] ?? 'male'),
            'activity'   => (string)($user['activity_level'] ?? 'moderately_active'),
            'goal'       => (string)($user['goal'] ?? 'maintain'),
            'birth_date' => (string)($user['birth_date'] ?? '1996-01-01'),
        ];
    }

    /**
     * O günkü antrenman durumunu döner.
     *
     * @param int $userId
     * @param string|null $date (Y-m-d)
     * @return bool
     */
    public function getTodayWorkoutStatus(int $userId = 1, ?string $date = null): bool
    {
        $date = $date ?? date('Y-m-d');
        $stmt = $this->db->prepare("SELECT workout_done FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
        $stmt->execute([$userId, $date]);
        $val = $stmt->fetchColumn();

        return $val !== false ? (bool)$val : false;
    }

    /**
     * Kullanıcının girdiği profil parametrelerini users tablosuna,
     * bugünkü daily_logs tablosuna ve macro_targets tablosuna kalıcı olarak yazar.
     *
     * @param int $userId
     * @param float $weight
     * @param float $height
     * @param int $age
     * @param string $gender
     * @param string $activity
     * @param string $goal
     * @param bool|null $isTraining
     * @return array Güncel profil dizisi
     */
    public function updateProfile(
        int $userId,
        float $weight,
        float $height,
        int $age,
        string $gender,
        string $activity,
        string $goal,
        ?bool $isTraining = null
    ): array {
        // Değer sınırlandırma & güvenlik
        $weight = max(30.0, min(300.0, $weight));
        $height = max(100.0, min(250.0, $height));
        $age    = max(10, min(120, $age));

        $allowedGenders = ['male', 'female', 'other'];
        if (!in_array($gender, $allowedGenders, true)) {
            $gender = 'male';
        }

        $allowedActivities = ['sedentary', 'lightly_active', 'moderately_active', 'very_active', 'extra_active'];
        if (!in_array($activity, $allowedActivities, true)) {
            $activity = 'moderately_active';
        }

        $allowedGoals = ['lose', 'gain', 'maintain'];
        if (!in_array($goal, $allowedGoals, true)) {
            $goal = 'maintain';
        }

        // Yaşa göre dinamik doğum tarihi belirle (yıl başına ayarla)
        $birthYear = (int)date('Y') - $age;
        $birthDate = sprintf('%04d-01-01', $birthYear);

        // 1. users tablosunu güncelle
        $stmt = $this->db->prepare("
            UPDATE users
            SET weight_kg      = :weight,
                height_cm      = :height,
                birth_date     = :birth_date,
                gender         = :gender,
                activity_level = :activity,
                goal           = :goal,
                updated_at     = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':weight'     => $weight,
            ':height'     => $height,
            ':birth_date' => $birthDate,
            ':gender'     => $gender,
            ':activity'   => $activity,
            ':goal'       => $goal,
            ':id'         => $userId,
        ]);

        // 2. Bugünün daily_logs kaydını bul/oluştur ve güncelle
        $today = date('Y-m-d');
        $checkDaily = $this->db->prepare("SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
        $checkDaily->execute([$userId, $today]);
        $dailyId = $checkDaily->fetchColumn();

        if ($dailyId) {
            if ($isTraining !== null) {
                $this->db->prepare("
                    UPDATE daily_logs
                    SET weight_kg    = :weight,
                        workout_done = :workout,
                        updated_at   = CURRENT_TIMESTAMP
                    WHERE id = :id
                ")->execute([
                    ':weight'  => $weight,
                    ':workout' => $isTraining ? 1 : 0,
                    ':id'      => $dailyId,
                ]);
            } else {
                $this->db->prepare("
                    UPDATE daily_logs
                    SET weight_kg  = :weight,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ")->execute([
                    ':weight' => $weight,
                    ':id'     => $dailyId,
                ]);
            }
        } else {
            $workoutDoneVal = ($isTraining !== null && $isTraining) ? 1 : 0;
            $this->db->prepare("
                INSERT INTO daily_logs
                    (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done, weight_kg, water_ml)
                VALUES
                    (?, ?, 0, 0, 0, 0, ?, ?, 0)
            ")->execute([$userId, $today, $workoutDoneVal, $weight]);
        }

        // 3. Makroları yeniden hesapla ve macro_targets tablosuna yaz
        $calculator = new MetabolismCalculator(
            weightKg:      $weight,
            heightCm:      $height,
            age:           $age,
            gender:        $gender,
            activityLevel: $activity,
            goal:          $goal
        );

        $saver = new MacroSaver($this->db);
        $saver->saveAll($userId, $calculator);

        return [
            'weight'     => $weight,
            'height'     => $height,
            'age'        => $age,
            'gender'     => $gender,
            'activity'   => $activity,
            'goal'       => $goal,
            'birth_date' => $birthDate,
        ];
    }
}

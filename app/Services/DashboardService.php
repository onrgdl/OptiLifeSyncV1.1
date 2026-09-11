<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use DateTime;
use App\Services\MetabolismCalculator;
use App\Services\ReminderService;

/**
 * OptiLifeSync - DashboardService
 * 
 * Dashboard için tek merkezli, yüksek performanslı veri sağlayıcı.
 * Hem api/dashboard.php hem de dashboard.php ilk sunucu render'ında (SSR hydration)
 * çağrılarak çift ağ gecikmesini ortadan kaldırır.
 */
class DashboardService
{
    public static function getUserProfile(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [
            'weight_kg' => 70.0,
            'height_cm' => 170.0,
            'birth_date' => '1996-01-01',
            'gender' => 'male',
            'activity_level' => 'moderately_active',
            'goal' => 'maintain',
        ];
    }

    public static function calcAge(string $birthDate): int
    {
        return (int) (new DateTime($birthDate))->diff(new DateTime())->y;
    }

    public static function getOrCreateDailyLog(PDO $pdo, int $userId, string $date): array
    {
        $stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE user_id=? AND log_date=? LIMIT 1");
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $ins = $pdo->prepare("
                INSERT INTO daily_logs (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
                VALUES (?, ?, 0, 0, 0, 0, 0)
            ");
            $ins->execute([$userId, $date]);
            $stmt->execute([$userId, $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $row ?: [];
    }

    public static function getDashboardData(PDO $pdo, int $userId, ?string $date = null): array
    {
        $today = $date ?: date('Y-m-d');
        $profile = self::getUserProfile($pdo, $userId);
        $age = self::calcAge($profile['birth_date']);
        $dailyLog = self::getOrCreateDailyLog($pdo, $userId, $today);
        $isTraining = (bool) ($dailyLog['workout_done'] ?? false);

        // Sabit ve bilimsel metabolizma hesaplayıcı
        $calc = new MetabolismCalculator(
            (float)($profile['weight_kg'] ?? 80),
            (float)($profile['height_cm'] ?? 175),
            $age,
            $profile['gender'] ?? 'male',
            $profile['activity_level'] ?? 'moderately_active',
            $profile['goal'] ?? 'maintain'
        );
        $target = $calc->getDailyMacros();
        $bmr    = $calc->getBMR();
        $tdee   = $calc->getTDEE();

        // Tüketilen
        $consumed = [
            'calories'  => (float)($dailyLog['total_calories'] ?? 0),
            'protein_g' => (float)($dailyLog['total_protein_g'] ?? 0),
            'carbs_g'   => (float)($dailyLog['total_carbs_g'] ?? 0),
            'fat_g'     => (float)($dailyLog['total_fat_g'] ?? 0),
        ];

        // Yüzde hesabı
        $pct = fn($c, $t) => $t > 0 ? min(999, round($c / $t * 100, 1)) : 0;

        // Kalan
        $remaining = [
            'calories'  => round($target['calories']  - $consumed['calories'],  1),
            'protein_g' => round($target['protein_g'] - $consumed['protein_g'], 1),
            'carbs_g'   => round($target['carbs_g']   - $consumed['carbs_g'],   1),
            'fat_g'     => round($target['fat_g']     - $consumed['fat_g'],     1),
        ];

        // Yaklaşan alarmlar (sonraki 3 saat)
        $nowTime = date('H:i:s');
        $futTime = date('H:i:s', strtotime('+180 minutes'));
        $todayIdx = (int)date('w');

        $rStmt = $pdo->prepare("
            SELECT r.id, r.supplement_id, r.type, r.label, r.remind_at, r.days_of_week,
                   s.dose_amount, s.dose_unit, s.form
            FROM reminders r
            LEFT JOIN supplements s ON s.id = r.supplement_id
            WHERE r.user_id = ? AND r.is_active = 1
              AND (r.end_date IS NULL OR r.end_date >= ?)
              AND (s.id IS NULL OR (s.is_active = 1 AND (s.end_date IS NULL OR s.end_date >= ?)))
              AND r.remind_at BETWEEN ? AND ?
            ORDER BY r.remind_at
            LIMIT 10
        ");
        $rStmt->execute([$userId, $today, $today, $nowTime, $futTime]);
        $allReminders = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        $upcomingAlarms = [];
        foreach ($allReminders as $r) {
            $days = json_decode($r['days_of_week'] ?? '[]', true);
            if (!in_array($todayIdx, $days, true)) continue;
            $upcomingAlarms[] = [
                'id'           => (int)$r['id'],
                'type'         => $r['type'],
                'label'        => $r['label'],
                'remind_at'    => substr($r['remind_at'], 0, 5),
                'dose'         => ($r['dose_amount'] ?? '') . ' ' . ($r['dose_unit'] ?? ''),
                'form'         => $r['form'] ?? 'tablet',
                'minutes_left' => max(0, (int)((strtotime(date('Y-m-d').' '.$r['remind_at']) - time()) / 60)),
            ];
        }

        // Son öğünler (id dahil)
        $recentMeals = [];
        if (!empty($dailyLog['id'])) {
            $fStmt = $pdo->prepare("
                SELECT id, food_label, meal_type, calories, protein_g, carbs_g, fat_g, logged_at
                FROM food_logs
                WHERE daily_log_id = ?
                ORDER BY logged_at DESC, id DESC LIMIT 15
            ");
            $fStmt->execute([$dailyLog['id']]);
            $recentMeals = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Bugünkü kilo
        $weight = $dailyLog['weight_kg'] ?? $profile['weight_kg'];

        // Akıllı Su Hesaplama
        $userWeight      = (float)($weight ?? 80.0);
        $baseWaterTarget = (int) round($userWeight * 35);
        $waterBonus      = $isTraining ? 500 : 0;
        $waterTarget     = $baseWaterTarget + $waterBonus;
        $waterConsumed   = (int)($dailyLog['water_ml'] ?? 0);
        $waterPct        = $waterTarget > 0 ? min(999, round(($waterConsumed / $waterTarget) * 100, 1)) : 0;
        $waterRemaining  = max(0, $waterTarget - $waterConsumed);

        $waterData = [
            'consumed_ml'   => $waterConsumed,
            'target_ml'     => $waterTarget,
            'base_target'   => $baseWaterTarget,
            'workout_bonus' => $waterBonus,
            'pct'           => $waterPct,
            'remaining_ml'  => $waterRemaining,
            'glasses'       => round($waterConsumed / 250, 1),
        ];

        return [
            'ok'           => true,
            'date'         => $today,
            'day_name'     => ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'][(int)date('w')],
            'is_training'  => $isTraining,
            'bmr'          => $bmr,
            'tdee'         => $tdee,
            'target'       => $target,
            'consumed'     => $consumed,
            'remaining'    => $remaining,
            'progress_pct' => [
                'calories'  => $pct($consumed['calories'],  $target['calories']),
                'protein_g' => $pct($consumed['protein_g'], $target['protein_g']),
                'carbs_g'   => $pct($consumed['carbs_g'],   $target['carbs_g']),
                'fat_g'     => $pct($consumed['fat_g'],     $target['fat_g']),
            ],
            'water'           => $waterData,
            'upcoming_alarms' => $upcomingAlarms,
            'recent_meals'    => $recentMeals,
            'weight_today'    => $weight,
            'daily_log_id'    => (int)($dailyLog['id'] ?? 0),
        ];
    }
}

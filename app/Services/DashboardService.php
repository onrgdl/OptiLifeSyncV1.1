<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/MetabolismCalculator.php';
require_once __DIR__ . '/ReminderService.php';
require_once __DIR__ . '/MedicationTracker.php';

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
    /** İstek içi profil önbelleği (aynı istekte 3 kez SELECT * FROM users çalışmasın) */
    private static array $profileCache = [];

    public static function clearProfileCache(): void
    {
        self::$profileCache = [];
    }

    public static function getUserProfile(PDO $pdo, int $userId): array
    {
        if (isset(self::$profileCache[$userId])) {
            return self::$profileCache[$userId];
        }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return self::$profileCache[$userId] = $row;
        }
        return [
            'weight_kg' => 70.0,
            'height_cm' => 170.0,
            'birth_date' => '1996-01-01',
            'gender' => 'male',
            'activity_level' => 'moderately_active',
            'goal' => 'maintain',
        ];
    }

    /** Bitmek üzere olan takviyeler (stok sütunları yoksa boş liste). */
    private static function safeLowStock(PDO $pdo, int $userId): array
    {
        try {
            return (new ReminderService($pdo))->getLowStock($userId);
        } catch (\Throwable) {
            return [];
        }
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

    /**
     * @param bool $lite true: yalnızca makro/su verisi (alarm, kilo, doz, stok, antrenman, öğün listesi sorgulanmaz).
     *                   Beslenme sayfası ve su/makro bildirimleri için daha az sorgu.
     */
    public static function getDashboardData(PDO $pdo, int $userId, ?string $date = null, bool $lite = false): array
    {
        $today = $date ?: date('Y-m-d');
        self::clearProfileCache(); // her yüklemede taze başla, sonra istek içinde paylaş
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

        // Yaklaşan alarmlar (şu anki dakika ve sonraki 3 saat)
        $nowTime = date('H:i:00', strtotime('-5 minutes'));
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
        $allReminders = [];
        if (!$lite) {
            $rStmt->execute([$userId, $today, $today, $nowTime, $futTime]);
            $allReminders = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        }

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
        if (!$lite && !empty($dailyLog['id'])) {
            $fStmt = $pdo->prepare("
                SELECT id, food_label, meal_type, calories, protein_g, carbs_g, fat_g, logged_at
                FROM food_logs
                WHERE daily_log_id = ?
                ORDER BY logged_at DESC, id DESC LIMIT 15
            ");
            $fStmt->execute([$dailyLog['id']]);
            $recentMeals = $fStmt->fetchAll(PDO::FETCH_ASSOC);
            $tz = new \DateTimeZone(date_default_timezone_get());
            foreach ($recentMeals as &$rm) {
                try {
                    $rm['time'] = (new DateTime((string)$rm['logged_at']))->setTimezone($tz)->format('H:i');
                } catch (\Throwable) {
                    $rm['time'] = null;
                }
            }
            unset($rm);
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
            'weight'          => $lite ? null : self::getWeightInfo($pdo, $userId),
            'doses'           => $lite ? null : (new MedicationTracker($pdo))->getDosesForDate($userId, $today),
            'low_stock'       => $lite ? [] : self::safeLowStock($pdo, $userId),
            'workouts_today'  => $lite ? [] : self::getWorkoutsForDate($pdo, $userId, $today),
            'daily_log_id'    => (int)($dailyLog['id'] ?? 0),
        ];
    }

    /**
     * Kilo geçmişi (kaydedilmiş günler), eskiden yeniye.
     */
    public static function getWeightHistory(PDO $pdo, int $userId, int $days = 90): array
    {
        $from = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));
        $stmt = $pdo->prepare("
            SELECT log_date, weight_kg FROM daily_logs
            WHERE user_id = ? AND weight_kg IS NOT NULL AND log_date >= ?
            ORDER BY log_date ASC
        ");
        $stmt->execute([$userId, $from]);
        return array_map(fn($r) => [
            'date'      => substr((string)$r['log_date'], 0, 10),
            'weight_kg' => round((float)$r['weight_kg'], 1),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Son kilo, bugün kayıt girilip girilmediği ve ~7 / ~30 gün önceye göre değişim.
     */
    public static function getWeightInfo(PDO $pdo, int $userId): array
    {
        $hist = self::getWeightHistory($pdo, $userId, 45);
        $profile = self::getUserProfile($pdo, $userId);
        $today = date('Y-m-d');
        $latest = $hist ? end($hist) : null;
        $findBefore = function (int $daysAgo) use ($hist): ?float {
            $cut = date('Y-m-d', strtotime("-{$daysAgo} days"));
            $val = null;
            foreach ($hist as $h) {
                if ($h['date'] <= $cut) {
                    $val = $h['weight_kg'];
                }
            }
            return $val;
        };
        $current = $latest ? (float)$latest['weight_kg'] : (float)($profile['weight_kg'] ?? 0);
        $w7  = $findBefore(7);
        $w30 = $findBefore(30);
        return [
            'current'       => round($current, 1),
            'logged_today'  => $latest && $latest['date'] === $today,
            'last_date'     => $latest['date'] ?? null,
            'change_7d'     => $w7 !== null ? round($current - $w7, 1) : null,
            'change_30d'    => $w30 !== null ? round($current - $w30, 1) : null,
            'goal'          => $profile['goal'] ?? 'maintain',
            'spark'         => array_slice(array_column($hist, 'weight_kg'), -14),
        ];
    }

    /**
     * Kullanıcının üst üste kaç gündür beslenme kaydı girdiğini hesaplar.
     * "Bugün" henüz kayıt girilmemişse zincir kırılmış sayılmaz (gün henüz bitmedi);
     * hesaplama en son kayıt girilen günden geriye doğru devam eder.
     */
    public static function getStreak(PDO $pdo, int $userId, ?string $today = null): int
    {
        $today = $today ?: date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT log_date FROM daily_logs
            WHERE user_id = ? AND total_calories > 0
            ORDER BY log_date DESC
            LIMIT 400
        ");
        $stmt->execute([$userId]);
        $loggedSet = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        $streak = 0;
        $cursor = new DateTime($today);

        if (!isset($loggedSet[$today])) {
            $cursor->modify('-1 day');
        }

        while (isset($loggedSet[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor->modify('-1 day');
        }

        return $streak;
    }

    /**
     * Haftalık 7 günlük (Pazartesi - Pazar) detay dökümünü ve özetini döner.
     */
    public static function getWeeklyBreakdown(PDO $pdo, int $userId, ?string $refDateStr = null): array
    {
        $today = date('Y-m-d');
        $refDateStr = $refDateStr ?: $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDateStr)) {
            $refDateStr = $today;
        }

        try {
            $refDate = new DateTime($refDateStr);
        } catch (\Throwable) {
            $refDate = new DateTime($today);
        }

        $dayOfWeek = (int)$refDate->format('N');
        $monday    = (clone $refDate)->modify('-' . ($dayOfWeek - 1) . ' days');
        $sunday    = (clone $monday)->modify('+6 days');

        $weekStart = $monday->format('Y-m-d');
        $weekEnd   = $sunday->format('Y-m-d');

        $profile = self::getUserProfile($pdo, $userId);
        $age     = self::calcAge($profile['birth_date'] ?? '1996-01-01');

        $calc = new MetabolismCalculator(
            (float)($profile['weight_kg'] ?? 70),
            (float)($profile['height_cm'] ?? 170),
            $age,
            $profile['gender'] ?? 'male',
            $profile['activity_level'] ?? 'moderately_active',
            $profile['goal'] ?? 'maintain'
        );
        $targetMacros = $calc->getDailyMacros(false);

        // 1. daily_logs
        $dailyLogs = [];
        $stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
        $stmt->execute([$userId, $weekStart, $weekEnd]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dailyLogs[$r['log_date']] = $r;
        }

        // 2. workouts
        $workouts = [];
        $stmt = $pdo->prepare("SELECT * FROM workouts WHERE user_id = ? AND tarih BETWEEN ? AND ?");
        $stmt->execute([$userId, $weekStart, $weekEnd]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $workouts[$r['tarih']] = $r;
        }

        // 3. food_logs
        $foodLogsByDate = [];
        $stmt = $pdo->prepare("
            SELECT fl.*, dl.log_date
            FROM food_logs fl
            JOIN daily_logs dl ON fl.daily_log_id = dl.id
            WHERE dl.user_id = ? AND dl.log_date BETWEEN ? AND ?
            ORDER BY fl.logged_at ASC
        ");
        $stmt->execute([$userId, $weekStart, $weekEnd]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $foodLogsByDate[$r['log_date']][] = $r;
        }

        $dayNamesTr = [
            1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba',
            4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'
        ];

        $days = [];
        $daysWithFood = 0;

        for ($i = 0; $i < 7; $i++) {
            $cur     = (clone $monday)->modify("+{$i} days");
            $dateStr = $cur->format('Y-m-d');
            $isoDay  = (int)$cur->format('N');

            $w   = $workouts[$dateStr] ?? null;
            $dl  = $dailyLogs[$dateStr] ?? null;
            $fls = $foodLogsByDate[$dateStr] ?? [];

            $workoutCompleted = ($w !== null && !empty($w['tamamlandi_mi'])) || (!empty($dl['workout_done']));

            $calConsumed  = (float)($dl['total_calories']  ?? 0);
            $protConsumed = (float)($dl['total_protein_g'] ?? 0);
            $carbConsumed = (float)($dl['total_carbs_g']   ?? 0);
            $fatConsumed  = (float)($dl['total_fat_g']     ?? 0);

            if ($calConsumed == 0 && count($fls) > 0) {
                foreach ($fls as $fl) {
                    $calConsumed  += (float)$fl['calories'];
                    $protConsumed += (float)$fl['protein_g'];
                    $carbConsumed += (float)$fl['carbs_g'];
                    $fatConsumed  += (float)$fl['fat_g'];
                }
            }

            if ($calConsumed > 0 || count($fls) > 0) {
                $daysWithFood++;
            }

            $diffCal      = $calConsumed - $targetMacros['calories'];
            $adherencePct = $targetMacros['calories'] > 0 ? round(($calConsumed / $targetMacros['calories']) * 100, 1) : 0;

            $days[] = [
                'date'           => $dateStr,
                'day_name'       => $dayNamesTr[$isoDay],
                'short_date'     => $cur->format('d/m'),
                'is_today'       => ($dateStr === $today),
                'is_past'        => ($dateStr < $today),
                'workout'        => $w,
                'workout_done'   => $workoutCompleted,
                'target'         => $targetMacros,
                'consumed'       => [
                    'calories'  => $calConsumed,
                    'protein_g' => $protConsumed,
                    'carbs_g'   => $carbConsumed,
                    'fat_g'     => $fatConsumed,
                ],
                'diff_calories'  => $diffCal,
                'adherence_pct'  => $adherencePct,
                'food_logs'      => $fls,
            ];
        }

        return [
            'week_start'     => $weekStart,
            'week_end'       => $weekEnd,
            'days'           => $days,
            'days_with_food' => $daysWithFood,
        ];
    }

    /** O günün antrenmanları (Bugün kartı için). */
    public static function getWorkoutsForDate(PDO $pdo, int $userId, string $date): array
    {
        try {
            $q = $pdo->prepare("
                SELECT id, antrenman_tipi, zorluk_seviyesi, planlanan_saat, sure_dk, tamamlandi_mi, atlandi_mi
                  FROM workouts WHERE user_id = ? AND tarih = ? ORDER BY planlanan_saat NULLS LAST, id
            ");
            $q->execute([$userId, $date]);
            return array_map(fn($w) => [
                'id'     => (int)$w['id'],
                'type'   => $w['antrenman_tipi'],
                'level'  => $w['zorluk_seviyesi'],
                'time'   => $w['planlanan_saat'] ? substr((string)$w['planlanan_saat'], 0, 5) : null,
                'status' => (int)$w['tamamlandi_mi'] === 1 ? 'done' : ((int)($w['atlandi_mi'] ?? 0) === 1 ? 'skipped' : 'open'),
            ], $q->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Son N günün (bugün hariç) makro eğilimi: her makroda hedefin altında / içinde / üstünde
     * kalınan gün sayısı ve okunabilir öneriler. Yalnızca öğün girilmiş günler sayılır.
     */
    public static function getMacroTrend(PDO $pdo, int $userId, int $days = 7): array
    {
        $profile = self::getUserProfile($pdo, $userId);
        $calc = new MetabolismCalculator(
            (float)($profile['weight_kg'] ?? 70),
            (float)($profile['height_cm'] ?? 170),
            self::calcAge($profile['birth_date'] ?? '1996-01-01'),
            $profile['gender'] ?? 'male',
            $profile['activity_level'] ?? 'moderately_active',
            $profile['goal'] ?? 'maintain'
        );
        $t = $calc->getDailyMacros();

        $q = $pdo->prepare("
            SELECT log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g
              FROM daily_logs
             WHERE user_id = ? AND log_date >= ? AND log_date < ? AND total_calories > 0
             ORDER BY log_date
        ");
        $q->execute([$userId, date('Y-m-d', strtotime("-{$days} days")), date('Y-m-d')]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $defs = [
            'calories'  => ['col' => 'total_calories',  'name' => 'Kalori',       'low' => 0.85, 'high' => 1.10],
            'protein_g' => ['col' => 'total_protein_g', 'name' => 'Protein',      'low' => 0.85, 'high' => 1.30],
            'carbs_g'   => ['col' => 'total_carbs_g',   'name' => 'Karbonhidrat', 'low' => 0.70, 'high' => 1.20],
            'fat_g'     => ['col' => 'total_fat_g',     'name' => 'Yağ',          'low' => 0.70, 'high' => 1.20],
        ];
        $out = [];
        $insights = [];
        $n = count($rows);
        foreach ($defs as $k => $d) {
            $target = (float)($t[$k] ?? 0);
            $low = $ok = $high = 0;
            $sumPct = 0.0;
            foreach ($rows as $r) {
                $pct = $target > 0 ? (float)$r[$d['col']] / $target : 0;
                $sumPct += $pct;
                if ($pct < $d['low']) {
                    $low++;
                } elseif ($pct > $d['high']) {
                    $high++;
                } else {
                    $ok++;
                }
            }
            $avg = $n ? (int)round($sumPct / $n * 100) : null;
            $out[$k] = ['name' => $d['name'], 'low' => $low, 'ok' => $ok, 'high' => $high, 'avg_pct' => $avg, 'target' => round($target)];
            if ($n >= 3) {
                if ($low >= max(2, (int)ceil($n * 0.5))) {
                    $insights[] = ['level' => 'warn', 'macro' => $k, 'text' => "{$d['name']}: son {$n} kayıtlı günde {$low} gün hedefin altında (ort. %{$avg})."];
                } elseif ($high >= max(2, (int)ceil($n * 0.5))) {
                    $insights[] = ['level' => 'warn', 'macro' => $k, 'text' => "{$d['name']}: son {$n} kayıtlı günde {$high} gün hedefin üstünde (ort. %{$avg})."];
                }
            }
        }
        if ($n >= 3 && !$insights) {
            $insights[] = ['level' => 'ok', 'macro' => null, 'text' => "Son {$n} günde makroların genel olarak hedefte 👏"];
        } elseif ($n < 3) {
            $insights[] = ['level' => 'info', 'macro' => null, 'text' => 'Eğilim için en az 3 gün öğün kaydı gerekiyor (şu an ' . $n . ' gün).'];
        }
        return ['days_logged' => $n, 'window' => $days, 'macros' => $out, 'insights' => $insights];
    }

}

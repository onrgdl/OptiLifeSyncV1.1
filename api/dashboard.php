<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Dashboard Unified Data API
 *
 * Tek endpoint'ten tüm dashboard verilerini döner.
 *
 * Actions (POST: action):
 *   load          → Sayfa yüklenince tüm günlük özet
 *   quick_search  → Modal: lokal takviye arama
 *   quick_add     → Seçilen öğeyi daily_log'a ekle + makro güncelle
 *   toggle_workout→ O günkü workout_done alanını tersine çevir
 */

header('Content-Type: application/json; charset=utf-8');

// ── Güvenlik: Yalnızca yerel ağdan erişime izin ver ──────────────────────────
(function (): void {
    if (
        !empty($_SERVER['VERCEL']) ||
        !empty(getenv('VERCEL')) ||
        !empty($_SERVER['VERCEL_ENV']) ||
        !empty(getenv('VERCEL_ENV')) ||
        !empty($_SERVER['HTTP_X_VERCEL_ID'])
    ) {
        return;
    }
    $allowedSubnets = ['127.', '::1', '10.10.18.', '192.168.', '172.16.'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($allowedSubnets as $s) {
        if (str_starts_with($ip, $s) || $ip === $s) return;
    }
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Erişim reddedildi.']);
    exit;
})();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/MetabolismCalculator.php';
require_once __DIR__ . '/../app/Services/ReminderService.php';
require_once __DIR__ . '/../app/Services/SupplementRepository.php';
require_once __DIR__ . '/../app/Services/DailyNutritionTracker.php';
require_once __DIR__ . '/../app/Services/MacroSaver.php';

require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\{
    MetabolismCalculator, ReminderService,
    SupplementRepository, DailyNutritionTracker, AuthService
};

if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'DB bağlantısı yok.']);
    exit;
}

$authService = new AuthService($pdo);
$userId = AuthService::requireAuth(true);
$today  = date('Y-m-d');

$action = trim($_POST['action'] ?? $_GET['action'] ?? 'load');


// ── Kullanıcı profilini DB'den çek (yoksa demo değer) ────────────────
function getUserProfile(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: [
        'weight_kg' => 80, 'height_cm' => 175, 'birth_date' => '1996-01-01',
        'gender' => 'male', 'activity_level' => 'moderately_active', 'goal' => 'gain',
    ];
}

// ── Yaşı birth_date'ten hesapla ───────────────────────────────────────
function calcAge(string $birthDate): int {
    return (int) (new DateTime($birthDate))->diff(new DateTime())->y;
}

// ── Günlük log başlığını bul veya oluştur ────────────────────────────
function getOrCreateDailyLog(PDO $pdo, int $userId, string $date): array {
    $stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE user_id=? AND log_date=? LIMIT 1");
    $stmt->execute([$userId, $date]);
    $row = $stmt->fetch();

    if (!$row) {
        $ins = $pdo->prepare("
            INSERT INTO daily_logs (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
            VALUES (?, ?, 0, 0, 0, 0, 0)
        ");
        $ins->execute([$userId, $date]);
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch();
    }

    return $row;
}

try {
    match ($action) {

        // ── TAM DASHBOARD VERİSİ ──────────────────────────────────────
        'load' => (function () use ($pdo, $userId, $today): void {

            $profile    = getUserProfile($pdo, $userId);
            $age        = calcAge($profile['birth_date']);
            $dailyLog   = getOrCreateDailyLog($pdo, $userId, $today);
            $isTraining = (bool) $dailyLog['workout_done'];

            // Sabit, istikrarlı ve net günlük makro hedefi
            $calc = new MetabolismCalculator(
                (float)$profile['weight_kg'], (float)$profile['height_cm'],
                $age, $profile['gender'], $profile['activity_level'], $profile['goal']
            );
            $target = $calc->getDailyMacros();
            $bmr    = $calc->getBMR();
            $tdee   = $calc->getTDEE();

            // Tüketilen (o günkü food_logs + supplement_logs toplamı)
            $consumed = [
                'calories'  => (float)$dailyLog['total_calories'],
                'protein_g' => (float)$dailyLog['total_protein_g'],
                'carbs_g'   => (float)$dailyLog['total_carbs_g'],
                'fat_g'     => (float)$dailyLog['total_fat_g'],
            ];

            // Progress %
            $pct = fn($c, $t) => $t > 0 ? min(999, round($c / $t * 100, 1)) : 0;

            // Kalan
            $remaining = [
                'calories'  => round($target['calories']  - $consumed['calories'],  1),
                'protein_g' => round($target['protein_g'] - $consumed['protein_g'], 1),
                'carbs_g'   => round($target['carbs_g']   - $consumed['carbs_g'],   1),
                'fat_g'     => round($target['fat_g']     - $consumed['fat_g'],     1),
            ];

            // Yaklaşan alarmlar (sonraki 3 saat)
            $reminderSvc = new ReminderService($pdo);
            // getDueReminders ±5dk, genişletilmiş versiyon için doğrudan SQL
            $nowTime = date('H:i:s');
            $futTime = date('H:i:s', strtotime('+180 minutes'));
            $todayIdx = (int)date('w');

            $todayDate = date('Y-m-d');
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
            $rStmt->execute([$userId, $todayDate, $todayDate, $nowTime, $futTime]);
            $allReminders = $rStmt->fetchAll();

            $upcomingAlarms = [];
            foreach ($allReminders as $r) {
                $days = json_decode($r['days_of_week'] ?? '[]', true);
                if (!in_array($todayIdx, $days, true)) continue;
                $upcomingAlarms[] = [
                    'id'        => (int)$r['id'],
                    'type'      => $r['type'],
                    'label'     => $r['label'],
                    'remind_at' => substr($r['remind_at'], 0, 5),
                    'dose'      => ($r['dose_amount'] ?? '') . ' ' . ($r['dose_unit'] ?? ''),
                    'form'      => $r['form'] ?? 'tablet',
                    'minutes_left' => max(0, (int)((strtotime(date('Y-m-d').' '.$r['remind_at']) - time()) / 60)),
                ];
            }

            // Son öğünler (id dahil, silme işlemi için)
            $fStmt = $pdo->prepare("
                SELECT id, food_label, meal_type, calories, protein_g, carbs_g, fat_g, logged_at
                FROM food_logs
                WHERE daily_log_id = ?
                ORDER BY logged_at DESC, id DESC LIMIT 15
            ");
            $fStmt->execute([$dailyLog['id']]);
            $recentMeals = $fStmt->fetchAll();

            // Bugünkü kilo
            $weight = $dailyLog['weight_kg'] ?? $profile['weight_kg'];

            // Akıllı Su Hesaplama (Kilo * 35 ml + Antrenman Bonusu 500 ml)
            $userWeight      = (float)($weight ?? 80.0);
            $baseWaterTarget = (int) round($userWeight * 35); // örn: 80 * 35 = 2800 ml
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

            echo json_encode([
                'ok'           => true,
                'date'         => $today,
                'day_name'     => ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'][date('w')],
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
                'daily_log_id'    => (int)$dailyLog['id'],
            ]);
        })(),

        // ── HIZLI ARAMA (Modal: Lokal Takviye) ───────────────────────
        'quick_search' => (function () use ($pdo, $userId): void {
            $query = trim($_POST['q'] ?? '');
            if (strlen($query) < 2) { echo json_encode(['ok'=>true,'supplements'=>[]]); return; }

            // Lokal takviye arama
            $suppRepo   = new SupplementRepository($pdo);
            $localSupps = $suppRepo->searchByName($userId, $query);

            echo json_encode([
                'ok'          => true,
                'supplements' => $localSupps,
            ]);
        })(),

        // ── HIZLI EKLEME ──────────────────────────────────────────────
        'quick_add' => (function () use ($pdo, $userId, $today): void {
            $source     = $_POST['source']     ?? 'local';
            $dailyLog   = getOrCreateDailyLog($pdo, $userId, $today);
            $dailyLogId = (int)$dailyLog['id'];

            if ($source === 'food') {
                $stmt = $pdo->prepare("
                    INSERT INTO food_logs
                        (daily_log_id, meal_type, food_id, food_label, quantity, unit,
                         calories, protein_g, carbs_g, fat_g)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $dailyLogId,
                    $_POST['meal_type'] ?? 'snack',
                    $_POST['food_id']   ?? ('manual_' . md5(uniqid())),
                    $_POST['label']     ?? '',
                    (float)($_POST['quantity'] ?? 100),
                    'gram',
                    (float)($_POST['calories']  ?? 0),
                    (float)($_POST['protein_g'] ?? 0),
                    (float)($_POST['carbs_g']   ?? 0),
                    (float)($_POST['fat_g']     ?? 0),
                ]);
            } else {
                // Lokal takviye
                $suppRepo = new SupplementRepository($pdo);
                $supp = $suppRepo->findById((int)($_POST['supplement_id'] ?? 0), $userId);
                if (!$supp) { echo json_encode(['ok'=>false,'error'=>'Takviye bulunamadı']); return; }

                $stmt = $pdo->prepare("
                    INSERT INTO supplement_logs
                        (daily_log_id, supplement_id, taken_at, dose_taken, dose_unit, is_taken)
                    VALUES (?,?,?,?,?,1)
                ");
                $stmt->execute([
                    $dailyLogId, $supp['id'], date('H:i:s'),
                    $supp['dose_amount'], $supp['dose_unit']
                ]);
            }

            // daily_logs toplamlarını güncelle
            $pdo->prepare("
                UPDATE daily_logs
                SET total_calories  = (SELECT COALESCE(SUM(calories),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.calories_per_dose),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                    total_protein_g = (SELECT COALESCE(SUM(protein_g),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.protein_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                    total_carbs_g   = (SELECT COALESCE(SUM(carbs_g),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.carbs_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                    total_fat_g     = (SELECT COALESCE(SUM(fat_g),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.fat_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1)
                WHERE id = ?
            ")->execute([$dailyLogId]);

            echo json_encode(['ok' => true, 'message' => 'Eklendi ✅']);
        })(),

        // ── ANTRENMAN TOGGLE ──────────────────────────────────────────
        'toggle_workout' => (function () use ($pdo, $userId, $today): void {
            $log = getOrCreateDailyLog($pdo, $userId, $today);
            $new = $log['workout_done'] ? 0 : 1;
            $pdo->prepare("UPDATE daily_logs SET workout_done=? WHERE id=?")->execute([$new, $log['id']]);
            echo json_encode(['ok'=>true, 'workout_done'=> (bool)$new]);
        })(),

        // ── ALARMI DOĞRUDAN SİL ───────────────────────────────────────
        'delete_alarm' => (function () use ($pdo, $userId): void {
            $alarmId = (int)($_POST['alarm_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
            $stmt->execute([$alarmId, $userId]);
            echo json_encode(['ok' => true, 'message' => 'Alarm silindi.']);
        })(),

        // ── İLACI/TAKVİYEYİ BİTİR (Alarmı kaldır) ─────────────────────
        'finish_supplement' => (function () use ($pdo, $userId): void {
            $suppId = (int)($_POST['supplement_id'] ?? 0);
            $pdo->prepare("DELETE FROM reminders WHERE supplement_id = ? AND user_id = ?")->execute([$suppId, $userId]);
            $pdo->prepare("UPDATE supplements SET is_active = 0, end_date = CURRENT_DATE WHERE id = ? AND user_id = ?")->execute([$suppId, $userId]);
            echo json_encode(['ok' => true, 'message' => 'İlaç/takviye tamamlandı ve alarmları kaldırıldı.']);
        })(),

        // ── ÖĞÜNÜ SİL (Makroları güncelle) ────────────────────────────
        'delete_meal' => (function () use ($pdo, $userId): void {
            $mealId = (int)($_POST['meal_id'] ?? $_POST['food_log_id'] ?? 0);
            if ($mealId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Geçersiz öğün ID']);
                return;
            }

            // Kullanıcı yetki doğrulaması
            $stmt = $pdo->prepare("
                SELECT fl.id, fl.daily_log_id
                FROM food_logs fl
                JOIN daily_logs dl ON fl.daily_log_id = dl.id
                WHERE fl.id = ? AND dl.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$mealId, $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Öğün kaydı bulunamadı veya yetkiniz yok']);
                return;
            }

            $dailyLogId = (int)$row['daily_log_id'];

            // Öğün kaydını sil
            $pdo->prepare("DELETE FROM food_logs WHERE id = ?")->execute([$mealId]);

            // daily_logs toplamlarını anında yeniden hesapla
            $pdo->prepare("
                UPDATE daily_logs
                SET total_calories  = (SELECT COALESCE(SUM(calories),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.calories_per_dose),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                    total_protein_g = (SELECT COALESCE(SUM(protein_g),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.protein_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                    total_carbs_g   = (SELECT COALESCE(SUM(carbs_g),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.carbs_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                    total_fat_g     = (SELECT COALESCE(SUM(fat_g),0) FROM food_logs WHERE daily_log_id=daily_logs.id)
                                    + (SELECT COALESCE(SUM(s.fat_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1)
                WHERE id = ?
            ")->execute([$dailyLogId]);

            echo json_encode(['ok' => true, 'message' => 'Öğün kaydı silindi ve makrolar güncellendi.']);
        })(),

        // ── AKILLI SU EKLE / ÇIKAR ─────────────────────────────────────
        'add_water' => (function () use ($pdo, $userId, $today): void {
            $amount = (int)($_POST['amount'] ?? 250);
            $dailyLog = getOrCreateDailyLog($pdo, $userId, $today);
            $dailyLogId = (int)$dailyLog['id'];

            // MySQL ve PostgreSQL uyumlu GREATEST
            $stmt = $pdo->prepare("
                UPDATE daily_logs
                SET water_ml = GREATEST(0, water_ml + :amt), updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([':amt' => $amount, ':id' => $dailyLogId]);

            // Güncel değeri al
            $chk = $pdo->prepare("SELECT water_ml, workout_done, weight_kg FROM daily_logs WHERE id = ?");
            $chk->execute([$dailyLogId]);
            $updated = $chk->fetch();
            $newWater = (int)($updated['water_ml'] ?? 0);

            // Hedef hesapla
            $profile = getUserProfile($pdo, $userId);
            $userWeight = (float)($updated['weight_kg'] ?? $profile['weight_kg'] ?? 80.0);
            $baseTarget = (int)round($userWeight * 35);
            $bonus = !empty($updated['workout_done']) ? 500 : 0;
            $target = $baseTarget + $bonus;
            $pct = $target > 0 ? min(999, round(($newWater / $target) * 100, 1)) : 0;

            echo json_encode([
                'ok'            => true,
                'water_ml'      => $newWater,
                'target_ml'     => $target,
                'base_target'   => $baseTarget,
                'workout_bonus' => $bonus,
                'pct'           => $pct,
                'remaining_ml'  => max(0, $target - $newWater),
                'glasses'       => round($newWater / 250, 1),
                'message'       => $amount >= 0 ? "+{$amount} ml su eklendi 💧" : "{$amount} ml geri alındı",
            ], JSON_UNESCAPED_UNICODE);
        })(),

        // ── SU SIFIRLA ────────────────────────────────────────────────
        'reset_water' => (function () use ($pdo, $userId, $today): void {
            $dailyLog = getOrCreateDailyLog($pdo, $userId, $today);
            $pdo->prepare("UPDATE daily_logs SET water_ml = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$dailyLog['id']]);

            $profile = getUserProfile($pdo, $userId);
            $userWeight = (float)($dailyLog['weight_kg'] ?? $profile['weight_kg'] ?? 80.0);
            $baseTarget = (int)round($userWeight * 35);
            $bonus = !empty($dailyLog['workout_done']) ? 500 : 0;
            $target = $baseTarget + $bonus;

            echo json_encode([
                'ok'            => true,
                'water_ml'      => 0,
                'target_ml'     => $target,
                'pct'           => 0,
                'remaining_ml'  => $target,
                'glasses'       => 0,
                'message'       => 'Bugünkü su tüketimi sıfırlandı.',
            ], JSON_UNESCAPED_UNICODE);
        })(),

        default => (function() use ($action): void {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>"Bilinmeyen action: $action"]);
        })(),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

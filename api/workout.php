<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Spor ve Antrenman API Endpoint'i
 *
 * Desteklenen Action'lar (POST veya GET):
 *   get_week  → Haftanın 7 gününü ve antrenman kayıtlarını listeler
 *   save      → Seçilen güne antrenman kaydeder/günceller + Dinamik makroyu devreye alır (+400 kcal, +30g protein)
 *   complete  → Antrenmanı "Tamamlandı" işaretler + 15 dk sonrasına Takviye (Whey & Mg) alarmı kurar
 *   delete    → Antrenmanı siler + Kalan antrenman yoksa makroyu normale döndürür
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
    echo json_encode(['ok' => false, 'error' => 'Erişim reddedildi.'], JSON_UNESCAPED_UNICODE);
    exit;
})();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/WorkoutService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\WorkoutService;
use App\Services\AuthService;

if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Veritabanı bağlantısı kurulamadı.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$authService = new AuthService($pdo);
$userId = AuthService::requireAuth(true);
AuthService::closeSession();

$workoutService = new WorkoutService($pdo);

// Parametreleri al (POST veya JSON body; GET-only action'ı engelle)
$action = $_POST['action'] ?? '';

if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $action = $json['action'] ?? '';
            $_POST = array_merge($_POST, $json);
        }
    }
}

// get_week için GET de kabul et (sadece okuma)
if (empty($action) && isset($_GET['action']) && $_GET['action'] === 'get_week') {
    $action = 'get_week';
}



try {
    match ($action) {

        // ── 1. HAFTALIK GÖRÜNÜM VERİSİ ────────────────────────────────
        'get_week' => (function () use ($workoutService, $userId): void {
            // İstenen haftanın pazartesi ve pazarını hesapla
            $refDateStr = $_GET['date'] ?? $_POST['date'] ?? date('Y-m-d');
            $refDate    = new DateTime($refDateStr);

            // Pazartesi'yi bul (haftanın başlangıcı)
            $dayOfWeek = (int)$refDate->format('N'); // 1 (Pazartesi) - 7 (Pazar)
            $monday = (clone $refDate)->modify('-' . ($dayOfWeek - 1) . ' days');
            $sunday = (clone $monday)->modify('+6 days');

            $startStr = $monday->format('Y-m-d');
            $endStr   = $sunday->format('Y-m-d');

            // DB'den o haftadaki kayıtları çek
            $existingWorkouts = $workoutService->getWorkoutsByRange($userId, $startStr, $endStr);

            $dayNamesTr = [
                1 => 'Pazartesi',
                2 => 'Salı',
                3 => 'Çarşamba',
                4 => 'Perşembe',
                5 => 'Cuma',
                6 => 'Cumartesi',
                7 => 'Pazar',
            ];

            $days = [];
            $today = date('Y-m-d');
            $totalPlanned = 0;
            $totalCompleted = 0;

            for ($i = 0; $i < 7; $i++) {
                $cur = (clone $monday)->modify("+{$i} days");
                $curStr = $cur->format('Y-m-d');
                $isoDay = (int)$cur->format('N');

                $dayWorkouts = $existingWorkouts[$curStr] ?? [];
                $hasWorkout = !empty($dayWorkouts);

                if ($hasWorkout) {
                    foreach ($dayWorkouts as $w) {
                        $totalPlanned++;
                        if (!empty($w['tamamlandi_mi'])) {
                            $totalCompleted++;
                        }
                    }
                }

                $days[] = [
                    'date'             => $curStr,
                    'day_number'       => $cur->format('d'),
                    'month_name'       => $cur->format('M'),
                    'day_name'         => $dayNamesTr[$isoDay],
                    'is_today'         => ($curStr === $today),
                    'is_past'          => ($curStr < $today),
                    'has_workout'      => $hasWorkout,
                    'workouts'         => $dayWorkouts,
                    'workout'          => $dayWorkouts[0] ?? null,
                    'dynamic_macro'    => [
                        'active'         => $hasWorkout,
                        'extra_calories' => $hasWorkout ? 400 : 0,
                        'extra_protein'  => $hasWorkout ? 30 : 0,
                    ],
                ];
            }

            echo json_encode([
                'ok'              => true,
                'week_start'      => $startStr,
                'week_end'        => $endStr,
                'today'           => $today,
                'total_planned'   => $totalPlanned,
                'total_completed' => $totalCompleted,
                'days'            => $days,
            ], JSON_UNESCAPED_UNICODE);
        })(),

        // ── 2. ANTRENMAN KAYDET / GÜNCELLE ─────────────────────────────
        'save' => (function () use ($workoutService, $userId): void {
            $workoutId  = !empty($_POST['workout_id']) ? (int)$_POST['workout_id'] : null;
            $type       = trim($_POST['antrenman_tipi'] ?? '');
            $difficulty = trim($_POST['zorluk_seviyesi'] ?? 'Orta');

            // Eğer düzenleme modundaysak (belirli bir workout_id gönderilmişse)
            if ($workoutId !== null && $workoutId > 0) {
                $date = trim($_POST['tarih'] ?? '');
                if (empty($date) || empty($type)) {
                    http_response_code(422);
                    echo json_encode([
                        'ok'    => false,
                        'error' => 'Geçerli bir tarih ve antrenman tipi gereklidir.',
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }

                $res = $workoutService->saveWorkout($userId, $date, $type, $difficulty, $workoutId);
                echo json_encode([
                    'ok'      => true,
                    'message' => 'Antrenman başarıyla güncellendi.',
                    'count'   => 1,
                    'dates'   => [$date],
                    'workout' => $res['workout'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Yeni antrenman planlama (tekli, çoklu gün ve 2-3-4 haftalık yinelenen plan)
            $rawDates = $_POST['tarihler'] ?? $_POST['tarih'] ?? [];
            if (!is_array($rawDates)) {
                if (strpos((string)$rawDates, ',') !== false) {
                    $dates = array_filter(array_map('trim', explode(',', (string)$rawDates)));
                } else {
                    $trimmed = trim((string)$rawDates);
                    $dates = $trimmed !== '' ? [$trimmed] : [];
                }
            } else {
                $dates = array_filter(array_map('trim', $rawDates));
            }
            $dates = array_values(array_unique(array_filter($dates)));

            if (empty($dates) || empty($type)) {
                http_response_code(422);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'En az bir tarih ve antrenman tipi seçilmelidir.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $repeatWeeks = max(1, min(4, (int)($_POST['repeat_weeks'] ?? 1)));
            $savedCount = 0;
            $allSavedDates = [];

            foreach ($dates as $baseDate) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate)) {
                    continue;
                }
                for ($w = 0; $w < $repeatWeeks; $w++) {
                    $targetDate = (new DateTime($baseDate))->modify("+{$w} weeks")->format('Y-m-d');
                    $lastRes = $workoutService->saveWorkout($userId, $targetDate, $type, $difficulty);
                    if (!empty($lastRes['ok'])) {
                        $savedCount++;
                        $allSavedDates[] = $targetDate;
                    }
                }
            }

            if ($savedCount === 0) {
                http_response_code(500);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Antrenman planı kaydedilemedi.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $successMsg = $repeatWeeks > 1
                ? "{$repeatWeeks} haftalık antrenman rutini başarıyla oluşturuldu! (Toplam {$savedCount} antrenman)"
                : ($savedCount > 1 ? "{$savedCount} antrenman başarıyla planlandı." : "{$type} antrenmanı başarıyla kaydedildi! 💪");

            echo json_encode([
                'ok'           => true,
                'message'      => $successMsg,
                'count'        => $savedCount,
                'repeat_weeks' => $repeatWeeks,
                'dates'        => array_values(array_unique($allSavedDates)),
            ], JSON_UNESCAPED_UNICODE);
        })(),

        // ── 3. ANTRENMANI TAMAMLA (BİTİR) + 15 DK ALARMI ──────────────
        'complete' => (function () use ($workoutService, $userId): void {
            $workoutId = (int)($_POST['workout_id'] ?? 0);

            if ($workoutId <= 0) {
                http_response_code(422);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Geçersiz antrenman ID.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $res = $workoutService->completeWorkout($workoutId, $userId);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        })(),

        // ── 4. ANTRENMANI SİL / İPTAL ET ───────────────────────────────
        'delete' => (function () use ($workoutService, $userId): void {
            $workoutId = (int)($_POST['workout_id'] ?? 0);

            if ($workoutId <= 0) {
                http_response_code(422);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Geçersiz antrenman ID.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $res = $workoutService->deleteWorkout($workoutId, $userId);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        })(),

        default => (function () use ($action): void {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => "Bilinmeyen action: '{$action}'",
            ], JSON_UNESCAPED_UNICODE);
        })(),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

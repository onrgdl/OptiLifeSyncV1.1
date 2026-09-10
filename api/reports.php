<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Haftalık Rapor API Endpoint'i
 *
 * Desteklenen Action'lar (POST):
 *   ai_analysis → Gemini AI ile haftalık beslenme ve performans koçluğu analizi üretir
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/GeminiService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\GeminiService;
use App\Services\AuthService;

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Veritabanı bağlantısı yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$authService = new AuthService($pdo);
$userId = AuthService::requireAuth(true);

$action = trim($_POST['action'] ?? '');

try {
    match ($action) {
        'ai_analysis' => (function () use ($pdo, $userId): void {
            if (!Config::hasGeminiKey()) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Gemini API anahtarı tanımlanmamış. .env dosyanızı kontrol edin.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $weekStart = trim($_POST['week_start'] ?? '');
            $weekEnd   = trim($_POST['week_end']   ?? '');

            if (empty($weekStart) || empty($weekEnd)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Hafta başlangıç ve bitiş tarihleri gereklidir.'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Kullanıcı profilini çek
            $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $u = $uStmt->fetch() ?: [
                'weight_kg' => 80, 'height_cm' => 175, 'goal' => 'gain', 'activity_level' => 'moderately_active'
            ];

            // Haftalık günlük loglar
            $dStmt = $pdo->prepare("
                SELECT log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done, weight_kg
                FROM daily_logs
                WHERE user_id = ? AND log_date BETWEEN ? AND ?
                ORDER BY log_date ASC
            ");
            $dStmt->execute([$userId, $weekStart, $weekEnd]);
            $dailyLogs = $dStmt->fetchAll();

            // Haftalık antrenmanlar
            $wStmt = $pdo->prepare("
                SELECT tarih, antrenman_tipi, zorluk_seviyesi, tamamlandi_mi
                FROM workouts
                WHERE user_id = ? AND tarih BETWEEN ? AND ?
                ORDER BY tarih ASC
            ");
            $wStmt->execute([$userId, $weekStart, $weekEnd]);
            $workouts = $wStmt->fetchAll();

            // Özet istatistikler hazırla
            $totCal = 0.0; $totProt = 0.0; $totCarb = 0.0; $totFat = 0.0;
            $workoutCompletedCount = 0;
            $loggedDays = count($dailyLogs);

            foreach ($dailyLogs as $dl) {
                $totCal  += (float)$dl['total_calories'];
                $totProt += (float)$dl['total_protein_g'];
                $totCarb += (float)$dl['total_carbs_g'];
                $totFat  += (float)$dl['total_fat_g'];
                if (!empty($dl['workout_done'])) $workoutCompletedCount++;
            }

            $plannedWorkoutCount = count($workouts);

            $summaryPrompt = sprintf(
                "Kullanıcı Profili:\n- Hedef: %s\n- Kilo: %.1f kg, Boy: %.1f cm\n\n" .
                "Haftalık Veriler (%s - %s):\n" .
                "- Günlük Log Girilen Gün Sayısı: %d / 7\n" .
                "- Toplam Alınan Kalori: %.0f kcal (Günlük Ort: %.0f kcal)\n" .
                "- Toplam Protein: %.1f g (Günlük Ort: %.1f g)\n" .
                "- Toplam Karbonhidrat: %.1f g (Günlük Ort: %.1f g)\n" .
                "- Toplam Yağ: %.1f g (Günlük Ort: %.1f g)\n" .
                "- Planlanan Antrenman: %d, Tamamlanan Antrenman: %d\n\n" .
                "Lütfen bu verilere dayanarak kullanıcıya haftalık bir sağlık, beslenme ve antrenman değerlendirme raporu sun. Motive edici, yapıcı ve doğrudan uygulanabilir tavsiyeler ver.",
                $u['goal'] ?? 'genel sağlık',
                (float)($u['weight_kg'] ?? 80),
                (float)($u['height_cm'] ?? 175),
                $weekStart,
                $weekEnd,
                $loggedDays,
                $totCal,
                $loggedDays > 0 ? ($totCal / $loggedDays) : 0,
                $totProt,
                $loggedDays > 0 ? ($totProt / $loggedDays) : 0,
                $totCarb,
                $loggedDays > 0 ? ($totCarb / $loggedDays) : 0,
                $totFat,
                $loggedDays > 0 ? ($totFat / $loggedDays) : 0,
                $plannedWorkoutCount,
                $workoutCompletedCount
            );

            $gemini = new GeminiService(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-2.5-flash')
            );

            $advice = $gemini->generateWeeklyReportAdvice($summaryPrompt);

            echo json_encode([
                'ok'           => true,
                'week_start'   => $weekStart,
                'week_end'     => $weekEnd,
                'advice'       => $advice,
                'generated_at' => date('d.m.Y H:i'),
            ], JSON_UNESCAPED_UNICODE);
        })(),

        default => (function () use ($action): void {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Bilinmeyen action: '{$action}'"], JSON_UNESCAPED_UNICODE);
        })(),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Alarm API Endpoint'leri
 *
 * Bu dosya tek bir PHP dosyasında tüm AJAX isteklerini karşılar.
 * Harici framework veya bağımlılık gerektirmez.
 *
 * Desteklenen action'lar (POST parametresi: action):
 *   check_due        → Zamanı gelen alarmları listele (polling)
 *   add_supplement   → Yeni takviye/ilaç ekle
 *   update_schedule  → Bir takviyelerin saat listesini güncelle
 *   delete_supplement→ Takviyeyi sil
 *   toggle_reminder  → Bir alarm satırını aktif/pasif yap
 *   list             → Tüm takviyeler + schedule'ları listele
 *
 * Tüm yanıtlar: Content-Type: application/json
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
    $allowedSubnets = ['127.', '::1', '10.', '192.168.', '172.'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($allowedSubnets as $s) {
        if (str_starts_with($ip, $s) || $ip === $s) return;
    }
    // PHP filter ile tüm RFC1918 özel ağlarını kabul et
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return;
    }
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Erişim reddedildi.']);
    exit;
})();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/ReminderService.php';
require_once __DIR__ . '/../app/Services/MedicationTracker.php';

require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\ReminderService;
use App\Services\MedicationTracker;
use App\Services\AuthService;

// ── Bağlantı kontrolü ────────────────────────────────────────────────
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Veritabanı bağlantısı yok.']);
    exit;
}

$authService = new AuthService($pdo);
$userId = AuthService::requireAuth(true);
AuthService::closeSession(); // Session kilidini serbest bırak

$service = new ReminderService($pdo);
$tracker = new MedicationTracker($pdo);

// ── İstek verisi ─────────────────────────────────────────────────────
$action = trim($_POST['action'] ?? '');  // GET ile tetiklemeyi engelle
$input  = (array) ($_POST ?? []);


// ── Router ───────────────────────────────────────────────────────────
try {
    match ($action) {

        // ── Zamanı gelen alarmları döndür (JS polling bu endpoint'i çağırır) ──
        'check_due' => (function () use ($service, $userId): void {
            $due = $service->getDueReminders($userId);
            echo json_encode([
                'ok'        => true,
                'due'       => $due,
                'count'     => count($due),
                'server_time'=> date('H:i:s'),
            ]);
        })(),

        // ── Tüm takviye/ilaç listesini döndür ────────────────────────
        'list' => (function () use ($service, $userId): void {
            $items = $service->getSupplementsWithSchedule($userId);
            echo json_encode(['ok' => true, 'items' => $items]);
        })(),

        // ── Yeni takviye / ilaç ekle ─────────────────────────────────
        'add_supplement' => (function () use ($service, $userId, $input): void {
            if (empty(trim($input['name'] ?? ''))) {
                throw new \InvalidArgumentException('İlaç/takviye adı boş olamaz.');
            }
            $newId = $service->addSupplement($userId, $input);

            // Saat listesi de gönderildiyse hemen işle
            $times = array_filter(
                explode(',', $input['schedule_times'] ?? ''),
                fn($t) => trim($t) !== ''
            );
            if (!empty($times)) {
                $service->updateSchedule($newId, $userId, array_values($times));
            }

            echo json_encode(['ok' => true, 'id' => $newId, 'message' => 'Kaydedildi.']);
        })(),

        // ── Saat listesini güncelle ───────────────────────────────────
        'update_schedule' => (function () use ($service, $userId, $input): void {
            $suppId = (int)($input['supplement_id'] ?? 0);
            if ($suppId <= 0) {
                throw new \InvalidArgumentException('Geçersiz supplement_id.');
            }

            // Virgülle ayrılmış saatler veya JSON dizisi kabul et
            $rawTimes = $input['schedule_times'] ?? '';
            if (str_starts_with(trim($rawTimes), '[')) {
                $times = json_decode($rawTimes, true) ?? [];
            } else {
                $times = array_map('trim', explode(',', $rawTimes));
            }
            $times = array_filter($times, fn($t) => $t !== '');

            $service->updateSchedule($suppId, $userId, array_values($times));
            echo json_encode(['ok' => true, 'message' => 'Alarm saatleri güncellendi.']);
        })(),

        // ── Takviyeyi sil (bağlı tüm alarmları da siler) ──────────────
        'delete_supplement' => (function () use ($service, $userId, $input): void {
            $suppId = (int)($input['supplement_id'] ?? 0);
            $result = $service->deleteSupplement($suppId, $userId);
            echo json_encode(['ok' => $result, 'message' => $result ? 'İlaç/takviye ve tüm alarmları silindi.' : 'Kayıt bulunamadı.']);
        })(),

        // ── İlacı/Takviyeyi Bitir (Kürü tamamla & Alarmları kaldır) ────
        'finish_supplement' => (function () use ($service, $userId, $input): void {
            $suppId = (int)($input['supplement_id'] ?? 0);
            $result = $service->finishSupplement($suppId, $userId);
            echo json_encode(['ok' => $result, 'message' => $result ? 'İlaç tamamlandı olarak işaretlendi ve tüm alarmları kaldırıldı.' : 'Kayıt bulunamadı.']);
        })(),

        // ── Tek bir alarmı doğrudan sil ───────────────────────────────
        'delete_reminder' => (function () use ($service, $userId, $input): void {
            $remId  = (int)($input['reminder_id'] ?? 0);
            $result = $service->deleteReminder($remId, $userId);
            echo json_encode(['ok' => $result, 'message' => $result ? 'Alarm başarıyla silindi.' : 'Alarm bulunamadı.']);
        })(),

        // ── Reminder'ı aç/kapat ───────────────────────────────────────
        'toggle_reminder' => (function () use ($service, $userId, $input): void {
            $remId  = (int)($input['reminder_id'] ?? 0);
            $result = $service->toggleReminder($remId, $userId);
            echo json_encode(['ok' => $result]);
        })(),

        // ── Bugünün (veya verilen günün) doz listesi ──────────────────
        'doses' => (function () use ($tracker, $userId, $input): void {
            echo json_encode(['ok' => true] + $tracker->getDosesForDate($userId, $input['date'] ?? null), JSON_UNESCAPED_UNICODE);
        })(),

        // ── Dozu "Aldım" / "Atla" olarak işaretle ─────────────────────
        'log_dose' => (function () use ($tracker, $pdo, $userId, $input): void {
            $suppId = (int)($input['supplement_id'] ?? 0);
            if ($suppId <= 0 && !empty($input['reminder_id'])) {
                $q = $pdo->prepare("SELECT supplement_id FROM reminders WHERE id = ? AND user_id = ?");
                $q->execute([(int)$input['reminder_id'], $userId]);
                $suppId = (int) $q->fetchColumn();
            }
            if ($suppId <= 0) {
                // İlaca bağlı olmayan hatırlatıcı: kaydedilecek doz yok, sessizce onayla
                echo json_encode(['ok' => true, 'skipped' => true]);
                return;
            }
            $status = ($input['status'] ?? 'taken') === 'skipped' ? 'skipped' : 'taken';
            $res = $tracker->logDose($userId, $suppId, $input['scheduled_time'] ?? null, $input['log_date'] ?? null, $status);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        })(),

        // ── Doz işaretini geri al ─────────────────────────────────────
        'undo_dose' => (function () use ($tracker, $userId, $input): void {
            $ok = $tracker->undoDose($userId, (int)($input['supplement_id'] ?? 0), $input['scheduled_time'] ?? null, $input['log_date'] ?? null);
            echo json_encode(['ok' => $ok]);
        })(),

        // ── Uyum (adherence) istatistiği ──────────────────────────────
        'adherence' => (function () use ($tracker, $userId, $input): void {
            echo json_encode(['ok' => true] + $tracker->getAdherence($userId, (int)($input['days'] ?? 7)), JSON_UNESCAPED_UNICODE);
        })(),

        // ── Android uygulaması için tüm aktif alarmlar ────────────────
        'native_list' => (function () use ($tracker, $userId): void {
            echo json_encode(['ok' => true, 'alarms' => $tracker->getNativeAlarmList($userId), 'server_time' => date('c')], JSON_UNESCAPED_UNICODE);
        })(),

        // ── Özel hatırlatıcı ekle (su, öğün, antrenman, diğer) ────────
        'add_custom' => (function () use ($service, $userId, $input): void {
            $days = $input['days'] ?? '';
            $days = is_array($days) ? $days : array_filter(explode(',', (string)$days), fn($d) => $d !== '');
            $id = $service->addCustomReminder($userId, (string)($input['label'] ?? ''), (string)($input['type'] ?? 'custom'), (string)($input['time'] ?? ''), $days);
            echo json_encode(['ok' => true, 'id' => $id, 'message' => 'Hatırlatıcı eklendi.']);
        })(),

        // ── Özel hatırlatıcıları listele ──────────────────────────────
        'list_custom' => (function () use ($service, $userId): void {
            echo json_encode(['ok' => true, 'items' => $service->getCustomReminders($userId)], JSON_UNESCAPED_UNICODE);
        })(),

        // ── Bilinmeyen action ─────────────────────────────────────────
        default => (function () use ($action): void {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Bilinmeyen action: '{$action}'"]);
        })(),
    };

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

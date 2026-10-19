<?php

declare(strict_types=1);

/**
 * OptiLifeSync — Akıllı bildirim API'si (Aşama 2)
 *
 * POST action:
 *   prefs        → bildirim tercihleri
 *   save_prefs   → tercihleri kaydet
 *   plan         → telefona kurulacak akıllı alarmlar
 *   compose      → alarm çaldığında bildirim içeriği (kind, ref)
 *   act          → bildirim butonu işlemi (do, ref, date)
 *   day_status   → gün sonu ekranı verisi
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/NotificationPlanner.php';

use App\Services\AuthService;
use App\Services\MedicationTracker;
use App\Services\NotificationPlanner;

if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Veritabanı bağlantısı yok.']);
    exit;
}

$authService = new AuthService($pdo);
$userId = AuthService::requireAuth(true);
AuthService::closeSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST gerekli.']);
    exit;
}

$planner = new NotificationPlanner($pdo);
$action  = trim((string)($_POST['action'] ?? ''));
$ref     = json_decode((string)($_POST['ref'] ?? '{}'), true);
$ref     = is_array($ref) ? $ref : [];

try {
    switch ($action) {
        case 'prefs':
            echo json_encode(['ok' => true, 'prefs' => $planner->getPrefs($userId), 'defaults' => NotificationPlanner::DEFAULTS], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_prefs':
            $in = json_decode((string)($_POST['prefs'] ?? '{}'), true);
            if (!is_array($in)) {
                throw new \InvalidArgumentException('Geçersiz ayar verisi.');
            }
            echo json_encode(['ok' => true, 'prefs' => $planner->savePrefs($userId, $in), 'message' => 'Bildirim ayarları kaydedildi.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'plan':
            $doses = (new MedicationTracker($pdo))->getNativeAlarmList($userId);
            echo json_encode(['ok' => true, 'alarms' => $planner->plan($userId, $doses)], JSON_UNESCAPED_UNICODE);
            break;

        case 'compose':
            $kind = preg_replace('/[^a-z_]/', '', (string)($_POST['kind'] ?? ''));
            echo json_encode(['ok' => true] + $planner->compose($userId, $kind, $ref), JSON_UNESCAPED_UNICODE);
            break;

        case 'act':
            $do = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['do'] ?? ''));
            echo json_encode($planner->act($userId, $do, $ref, $_POST['date'] ?? null), JSON_UNESCAPED_UNICODE);
            break;

        case 'day_status':
            echo json_encode(['ok' => true] + $planner->dayStatus($userId, $_POST['date'] ?? null), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bilinmeyen işlem.']);
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[notify] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sunucu hatası. Lütfen tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
}

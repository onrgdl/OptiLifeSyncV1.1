<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Gemini Besin Analizi API Endpoint'i
 *
 * Kullanıcının girdiği serbest metin öğünü Gemini AI ile analiz eder,
 * makro değerleri çözer ve veritabanına kaydeder.
 *
 * Actions (POST: action):
 *   analyze        → Metni analiz et, veritabanına kaydet
 *   analyze_only   → Metni analiz et, kaydetme (önizleme)
 *   get_today      → Bugünkü food_logs listesini getir
 *
 * İstek Örneği:
 *   POST /api/analyze_food.php
 *   action=analyze
 *   meal_text=150 gr ızgara tavuk ve 1 kutu kola
 *   meal_type=lunch
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/app.php';      // Config sınıfı + .env yükleme
require_once __DIR__ . '/../config/db.php';        // $pdo bağlantısı
require_once __DIR__ . '/../app/Services/GeminiService.php';

require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\GeminiService;
use App\Services\AuthService;

// ── Bağlantı Kontrol ─────────────────────────────────────────────────
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Veritabanı bağlantısı yok.']);
    exit;
}

$authService = new AuthService($pdo);
$userId = AuthService::requireAuth(true);
$today  = date('Y-m-d');

if (!Config::hasGeminiKey()) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'Gemini API anahtarı yapılandırılmamış.',
    ]);
    exit;
}

$action   = trim($_POST['action'] ?? '');  // GET ile tetiklemeyi engelle
$mealText = trim($_POST['meal_text'] ?? '');
$mealType = trim($_POST['meal_type'] ?? 'snack');

// ── Girdi Uzunluk Sınırı (Güvenlik + Maliyet Kontrolü) ───────────────────────
if (!empty($mealText) && mb_strlen($mealText) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Öğün metni en fazla 1000 karakter olabilir.']);
    exit;
}

// ── Router ───────────────────────────────────────────────────────────

try {
    match ($action) {

        // ─────────────────────────────────────────────────────────────
        // ANALİZ + KAYDET
        // ─────────────────────────────────────────────────────────────
        'analyze' => (function () use ($pdo, $userId, $today, $mealText, $mealType): void {

            if (empty($mealText)) {
                throw new \InvalidArgumentException('meal_text parametresi boş olamaz.');
            }

            $gemini = new GeminiService(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-2.5-flash')
            );

            // ── 1. Gemini'ye gönder ───────────────────────────────────
            $macros = $gemini->analyzeFood($mealText);

            // ── 2. daily_logs başlığını bul/oluştur ──────────────────
            $dailyLogId = getOrCreateDailyLog($pdo, $userId, $today);

            // ── 3. food_logs tablosuna ekle ───────────────────────────
            $foodLogId = insertFoodLog($pdo, $dailyLogId, $mealText, $mealType, $macros);

            // ── 4. daily_logs toplamlarını güncelle ───────────────────
            recalcDailyTotals($pdo, $dailyLogId);

            // ── 5. Güncel toplamları çek (dashboard için) ─────────────
            $totals = getDailyTotals($pdo, $dailyLogId);

            echo json_encode([
                'ok'          => true,
                'message'     => 'Öğün analiz edildi ve kaydedildi.',
                'food_log_id' => $foodLogId,
                'analyzed'    => [
                    'meal_text' => $mealText,
                    'meal_type' => $mealType,
                    'macros'    => [
                        'kalori'  => $macros['kalori'],
                        'protein' => $macros['protein'],
                        'karb'    => $macros['karb'],
                        'yag'     => $macros['yag'],
                    ],
                    'model_used' => $macros['model'],
                ],
                'daily_totals' => $totals,
            ]);
        })(),

        // ─────────────────────────────────────────────────────────────
        // SADECE ANALİZ (Önizleme — kaydetmez)
        // ─────────────────────────────────────────────────────────────
        'analyze_only' => (function () use ($mealText): void {

            if (empty($mealText)) {
                throw new \InvalidArgumentException('meal_text parametresi boş olamaz.');
            }

            $gemini = new GeminiService(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-2.5-flash')
            );

            $macros = $gemini->analyzeFood($mealText);

            echo json_encode([
                'ok'       => true,
                'preview'  => true,
                'message'  => 'Önizleme — veritabanına kaydedilmedi.',
                'analyzed' => [
                    'meal_text' => $mealText,
                    'macros'    => [
                        'kalori'  => $macros['kalori'],
                        'protein' => $macros['protein'],
                        'karb'    => $macros['karb'],
                        'yag'     => $macros['yag'],
                    ],
                    'model_used' => $macros['model'],
                ],
            ]);
        })(),

        // ─────────────────────────────────────────────────────────────
        // FOTOĞRAFLA ANALİZ (Önizleme)
        // ─────────────────────────────────────────────────────────────
        'analyze_image' => (function () use ($mealType): void {
            $userNotes = trim($_POST['notes'] ?? $_POST['user_notes'] ?? '');
            $img = getUploadedImageBase64();

            $gemini = new GeminiService(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-2.5-flash')
            );

            $result = $gemini->analyzeFoodImage($img['base64'], $img['mime'], $userNotes);

            echo json_encode([
                'ok'       => true,
                'preview'  => true,
                'message'  => 'Yemek fotoğrafı başarıyla analiz edildi.',
                'analyzed' => [
                    'food_label'  => $result['food_label'],
                    'description' => $result['description'],
                    'meal_type'   => $mealType,
                    'macros'      => [
                        'kalori'  => $result['kalori'],
                        'protein' => $result['protein'],
                        'karb'    => $result['karb'],
                        'yag'     => $result['yag'],
                    ],
                    'model_used'  => $result['model'],
                ],
            ], JSON_UNESCAPED_UNICODE);
        })(),

        // ─────────────────────────────────────────────────────────────
        // FOTOĞRAFLA ANALİZ ET VE DOĞRUDAN KAYDET
        // ─────────────────────────────────────────────────────────────
        'analyze_image_save' => (function () use ($pdo, $userId, $today, $mealType): void {
            $userNotes = trim($_POST['notes'] ?? $_POST['user_notes'] ?? '');
            $img = getUploadedImageBase64();

            $gemini = new GeminiService(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-2.5-flash')
            );

            $result = $gemini->analyzeFoodImage($img['base64'], $img['mime'], $userNotes);

            $dailyLogId = getOrCreateDailyLog($pdo, $userId, $today);
            $foodLogId  = insertFoodLog($pdo, $dailyLogId, $result['food_label'], $mealType, [
                'kalori'  => $result['kalori'],
                'protein' => $result['protein'],
                'karb'    => $result['karb'],
                'yag'     => $result['yag'],
            ]);
            recalcDailyTotals($pdo, $dailyLogId);
            $totals = getDailyTotals($pdo, $dailyLogId);

            echo json_encode([
                'ok'          => true,
                'message'     => 'Fotoğraftaki öğün analiz edildi ve kaydedildi.',
                'food_log_id' => $foodLogId,
                'analyzed'    => [
                    'food_label'  => $result['food_label'],
                    'description' => $result['description'],
                    'meal_type'   => $mealType,
                    'macros'      => [
                        'kalori'  => $result['kalori'],
                        'protein' => $result['protein'],
                        'karb'    => $result['karb'],
                        'yag'     => $result['yag'],
                    ],
                    'model_used'  => $result['model'],
                ],
                'daily_totals' => $totals,
            ], JSON_UNESCAPED_UNICODE);
        })(),

        // ─────────────────────────────────────────────────────────────
        // ÖZEL / DOĞRULANMIŞ ÖĞÜNÜ KAYDET (Modal onayından sonra)
        // ─────────────────────────────────────────────────────────────
        'save_custom' => (function () use ($pdo, $userId, $today, $mealType): void {
            $foodLabel = trim($_POST['food_label'] ?? $_POST['meal_text'] ?? 'Öğün');
            if (empty($foodLabel)) {
                $foodLabel = 'Öğün';
            }

            $calories = max(0.0, (float)($_POST['calories'] ?? $_POST['kalori'] ?? 0));
            $protein  = max(0.0, (float)($_POST['protein']  ?? $_POST['protein_g'] ?? 0));
            $carbs    = max(0.0, (float)($_POST['carbs']    ?? $_POST['karb'] ?? 0));
            $fat      = max(0.0, (float)($_POST['fat']      ?? $_POST['yag'] ?? 0));

            $dailyLogId = getOrCreateDailyLog($pdo, $userId, $today);
            $foodLogId  = insertFoodLog($pdo, $dailyLogId, $foodLabel, $mealType, [
                'kalori'  => $calories,
                'protein' => $protein,
                'karb'    => $carbs,
                'yag'     => $fat,
            ]);
            recalcDailyTotals($pdo, $dailyLogId);
            $totals = getDailyTotals($pdo, $dailyLogId);

            echo json_encode([
                'ok'          => true,
                'message'     => 'Öğün başarıyla kaydedildi.',
                'food_log_id' => $foodLogId,
                'daily_totals'=> $totals,
            ], JSON_UNESCAPED_UNICODE);
        })(),

        // ─────────────────────────────────────────────────────────────
        // BUGÜNKÜ KAYITLAR
        // ─────────────────────────────────────────────────────────────
        'get_today' => (function () use ($pdo, $userId, $today): void {

            $stmt = $pdo->prepare("
                SELECT
                    fl.id, fl.food_label, fl.meal_type, fl.quantity, fl.unit,
                    fl.calories, fl.protein_g, fl.carbs_g, fl.fat_g, fl.logged_at,
                    'gemini' AS source
                FROM food_logs fl
                JOIN daily_logs dl ON fl.daily_log_id = dl.id
                WHERE dl.user_id  = :user_id
                  AND dl.log_date = :date
                ORDER BY fl.logged_at DESC
            ");
            $stmt->execute([':user_id' => $userId, ':date' => $today]);
            $logs = $stmt->fetchAll();

            // Özet toplamlar
            $totals = array_reduce($logs, function ($carry, $row) {
                $carry['kalori']  += (float)$row['calories'];
                $carry['protein'] += (float)$row['protein_g'];
                $carry['karb']    += (float)$row['carbs_g'];
                $carry['yag']     += (float)$row['fat_g'];
                return $carry;
            }, ['kalori' => 0.0, 'protein' => 0.0, 'karb' => 0.0, 'yag' => 0.0]);

            echo json_encode([
                'ok'     => true,
                'date'   => $today,
                'logs'   => $logs,
                'totals' => array_map(fn($v) => round($v, 1), $totals),
            ]);
        })(),

        // ─────────────────────────────────────────────────────────────
        // BESİN KAYDI SİL
        // ─────────────────────────────────────────────────────────────
        'delete' => (function () use ($pdo, $userId): void {
            $foodLogId = (int)($_POST['food_log_id'] ?? $_POST['id'] ?? 0);
            if ($foodLogId <= 0) {
                throw new \InvalidArgumentException('Geçersiz food_log_id.');
            }

            // Doğrulama: kullanıcıya ait mi?
            $stmt = $pdo->prepare("
                SELECT fl.id, fl.daily_log_id
                FROM food_logs fl
                JOIN daily_logs dl ON fl.daily_log_id = dl.id
                WHERE fl.id = ? AND dl.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$foodLogId, $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Besin kaydı bulunamadı veya yetkiniz yok.']);
                return;
            }

            $dailyLogId = (int)$row['daily_log_id'];

            // Sil
            $pdo->prepare("DELETE FROM food_logs WHERE id = ?")->execute([$foodLogId]);

            // Toplamları yeniden hesapla
            recalcDailyTotals($pdo, $dailyLogId);
            $totals = getDailyTotals($pdo, $dailyLogId);

            echo json_encode([
                'ok'           => true,
                'message'      => 'Besin kaydı silindi.',
                'food_log_id'  => $foodLogId,
                'daily_totals' => $totals,
            ]);
        })(),

        default => (function () use ($action): void {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Bilinmeyen action: '{$action}'"]);
        })(),
    };

} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ════════════════════════════════════════════════════════════════════
// YARDIMCI FONKSİYONLAR (PDO)
// ════════════════════════════════════════════════════════════════════

/**
 * Bugünkü daily_logs satırını döner; yoksa oluşturur.
 *
 * @return int daily_log_id
 */
function getOrCreateDailyLog(PDO $pdo, int $userId, string $date): int
{
    // Var mı?
    $sel = $pdo->prepare(
        "SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1"
    );
    $sel->execute([$userId, $date]);
    $id = $sel->fetchColumn();

    if ($id) {
        return (int) $id;
    }

    // Yoksa oluştur
    $ins = $pdo->prepare("
        INSERT INTO daily_logs
            (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done)
        VALUES
            (?, ?, 0, 0, 0, 0, 0)
    ");
    $ins->execute([$userId, $date]);

    return function_exists('dbLastInsertId')
        ? dbLastInsertId($pdo, 'daily_logs')
        : (int) $pdo->lastInsertId();
}

/**
 * food_logs tablosuna Gemini analiz sonucunu INSERT eder.
 * Makro alanları Gemini'nin 'kalori/protein/karb/yag' adlandırmasından
 * DB şemasının 'calories/protein_g/carbs_g/fat_g' alanlarına çevrilir.
 *
 * @return int Yeni oluşturulan food_log ID'si
 */
function insertFoodLog(
    PDO    $pdo,
    int    $dailyLogId,
    string $mealText,
    string $mealType,
    array  $macros
): int {
    $validMealTypes = [
        'breakfast', 'lunch', 'dinner',
        'snack', 'pre_workout', 'post_workout',
    ];

    if (!in_array($mealType, $validMealTypes, true)) {
        $mealType = 'snack';
    }

    $stmt = $pdo->prepare("
        INSERT INTO food_logs
            (daily_log_id, meal_type, food_id, food_label, quantity, unit,
             calories, protein_g, carbs_g, fat_g)
        VALUES
            (:daily_log_id, :meal_type, :food_id, :food_label, :quantity, :unit,
             :calories, :protein_g, :carbs_g, :fat_g)
    ");

    $stmt->execute([
        ':daily_log_id' => $dailyLogId,
        ':meal_type'    => $mealType,
        ':food_id'      => 'gemini_' . md5($mealText . microtime()),  // Tekil sahte ID
        ':food_label'   => mb_substr($mealText, 0, 200),              // Max 200 karakter
        ':quantity'     => 1,
        ':unit'         => 'porsiyon',
        ':calories'     => $macros['kalori'],
        ':protein_g'    => $macros['protein'],
        ':carbs_g'      => $macros['karb'],
        ':fat_g'        => $macros['yag'],
    ]);

    return function_exists('dbLastInsertId')
        ? dbLastInsertId($pdo, 'food_logs')
        : (int) $pdo->lastInsertId();
}

/**
 * daily_logs.total_* alanlarını food_logs + supplement_logs üzerinden
 * tek bir SQL UPDATE ile yeniden hesaplar.
 */
function recalcDailyTotals(PDO $pdo, int $dailyLogId): void
{
    $pdo->prepare("
        UPDATE daily_logs
        SET
            total_calories  =
                COALESCE((SELECT SUM(calories)  FROM food_logs WHERE daily_log_id = daily_logs.id), 0)
                + COALESCE((SELECT SUM(s.calories_per_dose)
                            FROM supplement_logs sl
                            JOIN supplements s ON sl.supplement_id = s.id
                            WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1), 0),
            total_protein_g =
                COALESCE((SELECT SUM(protein_g) FROM food_logs WHERE daily_log_id = daily_logs.id), 0)
                + COALESCE((SELECT SUM(s.protein_g)
                            FROM supplement_logs sl
                            JOIN supplements s ON sl.supplement_id = s.id
                            WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1), 0),
            total_carbs_g   =
                COALESCE((SELECT SUM(carbs_g)   FROM food_logs WHERE daily_log_id = daily_logs.id), 0)
                + COALESCE((SELECT SUM(s.carbs_g)
                            FROM supplement_logs sl
                            JOIN supplements s ON sl.supplement_id = s.id
                            WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1), 0),
            total_fat_g     =
                COALESCE((SELECT SUM(fat_g)     FROM food_logs WHERE daily_log_id = daily_logs.id), 0)
                + COALESCE((SELECT SUM(s.fat_g)
                            FROM supplement_logs sl
                            JOIN supplements s ON sl.supplement_id = s.id
                            WHERE sl.daily_log_id = daily_logs.id AND sl.is_taken = 1), 0)
        WHERE id = ?
    ")->execute([$dailyLogId]);
}

/**
 * Güncel daily_log toplamlarını döner (dashboard için).
 */
function getDailyTotals(PDO $pdo, int $dailyLogId): array
{
    $stmt = $pdo->prepare("
        SELECT total_calories, total_protein_g, total_carbs_g, total_fat_g
        FROM daily_logs WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$dailyLogId]);
    $row = $stmt->fetch();

    return $row ? [
        'kalori'  => round((float)$row['total_calories'],  1),
        'protein' => round((float)$row['total_protein_g'], 2),
        'karb'    => round((float)$row['total_carbs_g'],   2),
        'yag'     => round((float)$row['total_fat_g'],     2),
    ] : ['kalori' => 0, 'protein' => 0, 'karb' => 0, 'yag' => 0];
}

/**
 * Yüklenen görsel verisini (dosya upload veya base64) doğrular ve base64 + mime döndürür.
 */
function getUploadedImageBase64(): array
{
    if (isset($_FILES['food_image']) && $_FILES['food_image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['food_image']['tmp_name'];
        if ($_FILES['food_image']['size'] > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('Yüklenen fotoğraf 10MB\'dan küçük olmalıdır.');
        }
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_file($f, $tmp);
            finfo_close($f);
            if (!empty($detected)) $mime = $detected;
        } elseif (function_exists('mime_content_type')) {
            $detected = mime_content_type($tmp);
            if (!empty($detected)) $mime = $detected;
        }
        $content = file_get_contents($tmp);
        if ($content === false) {
            throw new \RuntimeException('Görsel dosyası okunamadı.');
        }
        return [
            'base64' => base64_encode($content),
            'mime'   => $mime,
        ];
    }

    $raw = $_POST['image_data'] ?? $_POST['image_base64'] ?? '';
    if (!empty($raw)) {
        $mime = 'image/jpeg';
        if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,(.+)$/', $raw, $m)) {
            $mime = $m[1];
            $raw  = $m[2];
        }
        return [
            'base64' => $raw,
            'mime'   => $mime,
        ];
    }

    throw new \InvalidArgumentException('Lütfen bir yemek fotoğrafı yükleyin veya kameranızla çekin.');
}


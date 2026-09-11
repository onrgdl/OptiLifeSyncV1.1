<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Beslenme & Takviye Modülü
 * ─────────────────────────────────────────
 * Bu modül:
 *  1. Gemini AI Service  → Serbest metin ile besin analizi (cURL REST API)
 *  2. SupplementRepo     → Lokal takviye arama (MySQL)
 *  3. DailyNutrition     → Makro toplama + dinamik hedef eksik/fazla hesabı
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/MetabolismCalculator.php';
require_once __DIR__ . '/app/Services/GeminiService.php';
require_once __DIR__ . '/app/Services/SupplementRepository.php';
require_once __DIR__ . '/app/Services/DailyNutritionTracker.php';
require_once __DIR__ . '/app/Services/MacroSaver.php';
require_once __DIR__ . '/app/Services/UserProfileService.php';

use App\Services\{
    MetabolismCalculator,
    GeminiService,
    SupplementRepository,
    DailyNutritionTracker,
    MacroSaver,
    UserProfileService
};

$today  = date('Y-m-d');

// ── Öğün Türü İsimlendirmeleri ───────────────────────────────────────
$mealTypeLabels = [
    'breakfast'    => 'Kahvaltı',
    'lunch'        => 'Öğle',
    'dinner'       => 'Akşam',
    'snack'        => 'Ara Öğün',
    'pre_workout'  => 'Ant. Öncesi',
    'post_workout' => 'Ant. Sonrası',
];

// ── 1. Besin Kaydı Silme İşlemi (POST) ───────────────────────────────
$deleteSuccess   = false;
$deletedFoodName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_food' && $pdo) {
    $delId = (int)($_POST['food_log_id'] ?? 0);
    if ($delId > 0) {
        $chk = $pdo->prepare("
            SELECT fl.id, fl.food_label, fl.daily_log_id
            FROM food_logs fl
            JOIN daily_logs dl ON fl.daily_log_id = dl.id
            WHERE fl.id = ? AND dl.user_id = ?
            LIMIT 1
        ");
        $chk->execute([$delId, $userId]);
        $flRow = $chk->fetch();
        if ($flRow) {
            $dId = (int)$flRow['daily_log_id'];
            $deletedFoodName = $flRow['food_label'];
            $pdo->prepare("DELETE FROM food_logs WHERE id = ?")->execute([$delId]);

            // Toplamları yeniden hesapla
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
            ")->execute([$dId]);

            $deleteSuccess = true;
        }
    }
}

// ── Kullanıcı Profili ve Antrenman Durumu Senkronizasyonu ─────────
$profileService = $pdo ? new UserProfileService($pdo) : null;
$profileUpdated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['weight']) && $profileService) {
    $userProfile = $profileService->updateProfile(
        userId:     $userId,
        weight:     (float)$_POST['weight'],
        height:     (float)$_POST['height'],
        age:        (int)$_POST['age'],
        gender:     (string)$_POST['gender'],
        activity:   (string)$_POST['activity'],
        goal:       (string)$_POST['goal']
    );
    if (isset($_POST['update_profile'])) {
        $profileUpdated = true;
    }
} else {
    // GET isteği veya weight alanı içermeyen POST (örneğin sadece besin silme)
    if ($profileService) {
        $userProfile = $profileService->getProfile($userId);
    } else {
        $userProfile = [
            'weight'   => 70.0,
            'height'   => 170.0,
            'age'      => 30,
            'gender'   => 'male',
            'activity' => 'moderately_active',
            'goal'     => 'maintain',
        ];
    }
}

// ── Form verileri ────────────────────────────────────────────────────
$foodQuery   = trim($_POST['food_query']   ?? '');
$mealType    = $_POST['meal_type']         ?? 'lunch';
$suppQuery   = trim($_POST['supp_query']   ?? '');
$saveToDb    = isset($_POST['save_to_db']);

$tracker = new DailyNutritionTracker($pdo ?? new PDO('sqlite::memory:'));

// ── Metabolizma & Sabit Günlük Makro Hedefi ───────────────────────────
$calc = new MetabolismCalculator(
    weightKg:      $userProfile['weight'],
    heightCm:      $userProfile['height'],
    age:           $userProfile['age'],
    gender:        $userProfile['gender'],
    activityLevel: $userProfile['activity'],
    goal:          $userProfile['goal']
);
$macroTarget = $calc->getDailyMacros();
$calcSummary = $calc->getSummary();

// ── 2. Besin Analizi (Gemini AI) & Kaydetme ────────────────────────────
$selectedFood  = null;
$foodError     = null;
$hasGeminiKey  = Config::hasGeminiKey();
$savedSuccess  = false;

if ($foodQuery !== '') {
    if ($hasGeminiKey) {
        try {
            $gemini = new GeminiService(
                Config::get('GEMINI_API_KEY'),
                Config::get('GEMINI_MODEL', 'gemini-3.5-flash')
            );
            $macros = $gemini->analyzeFood($foodQuery);

            $selectedFood = [
                'food_id'        => 'gemini_' . md5($foodQuery . microtime()),
                'food_label'     => $foodQuery,
                'porsiyon_ozeti' => $macros['porsiyon_ozeti'] ?? '',
                'meal_type'      => $mealType,
                'quantity'       => 1,
                'unit'           => 'porsiyon',
                'source'         => 'gemini_ai',
                'calories'       => $macros['kalori'],
                'protein_g'      => $macros['protein'],
                'carbs_g'        => $macros['karb'],
                'fat_g'          => $macros['yag'],
                'fiber_g'        => 0.0,
                'model_used'     => $macros['model'] ?? 'Gemini AI',
            ];

            // İsteğe bağlı DB'ye kaydet
            if ($saveToDb && $pdo) {
                $stmt = $pdo->prepare("SELECT id FROM daily_logs WHERE user_id = ? AND log_date = ? LIMIT 1");
                $stmt->execute([$userId, $today]);
                $dId = $stmt->fetchColumn();
                if (!$dId) {
                    $ins = $pdo->prepare("INSERT INTO daily_logs (user_id, log_date, total_calories, total_protein_g, total_carbs_g, total_fat_g, workout_done) VALUES (?, ?, 0, 0, 0, 0, 0)");
                    $ins->execute([$userId, $today]);
                    $dId = function_exists('dbLastInsertId')
                        ? dbLastInsertId($pdo, 'daily_logs')
                        : (int)$pdo->lastInsertId();
                } else {
                    $dId = (int)$dId;
                }
                $insFood = $pdo->prepare("
                    INSERT INTO food_logs (daily_log_id, meal_type, food_id, food_label, quantity, unit, calories, protein_g, carbs_g, fat_g)
                    VALUES (?, ?, ?, ?, 1, 'porsiyon', ?, ?, ?, ?)
                ");
                $insFood->execute([$dId, $mealType, $selectedFood['food_id'], $selectedFood['food_label'], $selectedFood['calories'], $selectedFood['protein_g'], $selectedFood['carbs_g'], $selectedFood['fat_g']]);

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
                                        + (SELECT COALESCE(SUM(s.fat_g),0) FROM supplement_logs sl JOIN supplements s ON sl.supplement_id=s.id WHERE sl.daily_log_id=daily_logs.id AND sl.is_taken=1),
                        updated_at      = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([$dId]);

                $savedSuccess = true;
            }
        } catch (\Throwable $e) {
            $foodError = 'Gemini Analiz Hatası: ' . $e->getMessage();
        }
    } else {
        $foodError = 'Gemini API anahtarı tanımlanmamış. Lütfen .env dosyasında GEMINI_API_KEY değerini kontrol edin.';
    }
}

// ── 3. Bugünkü Kayıtlı Besinleri Veritabanından Yükle ─────────────────
$todayFoodLogs = [];
if ($pdo) {
    $stmt = $pdo->prepare("
        SELECT fl.id, fl.food_label, fl.meal_type, fl.quantity, fl.unit, fl.calories, fl.protein_g, fl.carbs_g, fl.fat_g, fl.logged_at
        FROM food_logs fl
        JOIN daily_logs dl ON fl.daily_log_id = dl.id
        WHERE dl.user_id = ? AND dl.log_date = ?
        ORDER BY fl.logged_at DESC, fl.id DESC
    ");
    $stmt->execute([$userId, $today]);
    $todayFoodLogs = $stmt->fetchAll();

    foreach ($todayFoodLogs as $fl) {
        $tracker->addEntry([
            'id'         => (int)$fl['id'],
            'food_id'    => 'db_' . $fl['id'],
            'food_label' => $fl['food_label'],
            'meal_type'  => $fl['meal_type'],
            'source'     => 'local_db',
            'calories'   => (float)$fl['calories'],
            'protein_g'  => (float)$fl['protein_g'],
            'carbs_g'    => (float)$fl['carbs_g'],
            'fat_g'      => (float)$fl['fat_g'],
            'fiber_g'    => 0.0,
            'logged_at'  => $fl['logged_at'],
        ]);
    }
}

// Eğer kaydedilmeden sadece anlık önizleme yapıldıysa tracker'a ekle
if ($foodQuery !== '' && !$saveToDb && $selectedFood !== null) {
    $tracker->addEntry($selectedFood);
}

// ── Lokal Takviye Arama ──────────────────────────────────────────────
$suppResults = [];
$suppError   = null;

if ($suppQuery !== '' && $pdo) {
    try {
        $suppRepo    = new SupplementRepository($pdo);
        $suppResults = $suppRepo->searchByName(userId: $userId, query: $suppQuery);

        foreach ($suppResults as $supp) {
            $tracker->addEntry($suppRepo->toMacroEntry($supp));
        }
    } catch (\Throwable $e) {
        $suppError = $e->getMessage();
    }
}

// ── Eksik / Fazla Hesabı ─────────────────────────────────────────────
$deficits = $tracker->getDeficits($macroTarget);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - Beslenme & Takviye</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <style>
        .card       { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; }
        .form-control, .form-select { background: var(--bg); border-color: var(--border); color: #f8fafc; }
        .form-control:focus, .form-select:focus { background: var(--bg); border-color: var(--accent); color: #f8fafc; box-shadow: 0 0 0 .25rem rgba(56,189,248,.2); }
        .progress   { height: 10px; background: rgba(255,255,255,.07); border-radius: 99px; }
        .badge-ai   { background: linear-gradient(135deg, #0284c7, #6366f1); color: #fff; }
        .badge-local{ background: #10b981; color: #fff; }

        /* Makro renk sistemi */
        .m-calorie  { color: #f87171; }
        .m-protein  { color: #60a5fa; }
        .m-carb     { color: #facc15; }
        .m-fat      { color: #c084fc; }
        .bg-calorie { background: rgba(248,113,113,.15); border: 1px solid rgba(248,113,113,.3); }
        .bg-protein { background: rgba(96,165,250,.15);  border: 1px solid rgba(96,165,250,.3); }
        .bg-carb    { background: rgba(250,204,21,.15);  border: 1px solid rgba(250,204,21,.3); }
        .bg-fat     { background: rgba(192,132,252,.15); border: 1px solid rgba(192,132,252,.3); }

        .status-deficit  { color: #f87171; }
        .status-on_track { color: #4ade80; }
        .status-over     { color: #fb923c; }
    </style>
</head>
<body>
<?php $activePage = 'nutrition'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <button class="hamburger btn-ghost btn-topbar" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="topbar-title">Beslenme & Takviye Modülü</div>
                <div class="topbar-sub">OptiLifeSync · Gemini AI Destekli Besin Analizi</div>
            </div>
        </div>
        <div class="topbar-right d-flex gap-2">
            <button type="button" class="btn-topbar text-white" style="background:linear-gradient(135deg,#ec4899,#8b5cf6);border:none" onclick="openPhotoModal()" title="Kamera ile Yemek Analizi">
                <i class="bi bi-camera-fill me-1"></i> <span class="d-none d-sm-inline">Fotoğrafla Analiz</span>
            </button>
            <a href="dashboard.php" class="btn-topbar btn-accent"><i class="bi bi-grid-1x2-fill"></i> <span class="d-none d-sm-inline">Dashboard</span></a>
        </div>
    </header>

    <div class="content">

    <?php if (!$hasGeminiKey): ?>
    <div class="alert alert-warning d-flex align-items-center mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
        <div>
            <strong>Gemini API Anahtarı Tanımlanmamış.</strong>
            Yapay zeka besin analizi için <code>.env</code> dosyanıza <code>GEMINI_API_KEY</code> değerini ekleyin.
            <a href="https://aistudio.google.com/app/apikey" target="_blank" class="alert-link ms-1">Google AI Studio'dan ücretsiz alabilirsiniz</a>.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($profileUpdated): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i>
        <div>
            <strong>Profil Parametreleri & Makro Hedefleri Kaydedildi!</strong> Verileriniz başarıyla saklandı ve BMR/TDEE ile Dashboard sayfalarına anında yansıtıldı.
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Kapat"></button>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="nutritionForm">
    <div class="row g-4">

        <!-- SOL KOLON: Giriş Alanları -->
        <div class="col-lg-4">

            <!-- Kullanıcı Profili -->
            <div class="card p-3 mb-3">
                <h6 class="text-secondary mb-3"><i class="bi bi-person-gear me-1"></i> Profil & Gün Tipi</h6>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label text-secondary small">Kilo (kg)</label>
                        <input type="number" name="weight" step="0.1" class="form-control form-control-sm" value="<?= $userProfile['weight'] ?>"></div>
                    <div class="col-6"><label class="form-label text-secondary small">Boy (cm)</label>
                        <input type="number" name="height" step="0.5" class="form-control form-control-sm" value="<?= $userProfile['height'] ?>"></div>
                    <div class="col-4"><label class="form-label text-secondary small">Yaş</label>
                        <input type="number" name="age" class="form-control form-control-sm" value="<?= $userProfile['age'] ?>"></div>
                    <div class="col-4"><label class="form-label text-secondary small">Cinsiyet</label>
                        <select name="gender" class="form-select form-select-sm">
                            <option value="male"   <?= $userProfile['gender']==='male'   ? 'selected':'' ?>>Erkek</option>
                            <option value="female" <?= $userProfile['gender']==='female' ? 'selected':'' ?>>Kadın</option>
                        </select></div>
                    <div class="col-4"><label class="form-label text-secondary small">Hedef</label>
                        <select name="goal" class="form-select form-select-sm">
                            <option value="lose"     <?= $userProfile['goal']==='lose'     ? 'selected':'' ?>>Kilo Ver</option>
                            <option value="maintain" <?= $userProfile['goal']==='maintain' ? 'selected':'' ?>>Koru</option>
                            <option value="gain"     <?= $userProfile['goal']==='gain'     ? 'selected':'' ?>>Al</option>
                        </select></div>
                    <div class="col-12"><label class="form-label text-secondary small">Aktivite</label>
                        <select name="activity" class="form-select form-select-sm">
                            <?php foreach (['sedentary'=>'Hareketsiz','lightly_active'=>'Hafif (1-3g)','moderately_active'=>'Orta (3-5g)','very_active'=>'Aktif (6-7g)','extra_active'=>'Ekstra'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $userProfile['activity']===$k ? 'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-12 mt-2">
                        <button type="submit" name="update_profile" value="1" class="btn btn-sm btn-outline-info w-100 fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i> Profili ve Günlük Hedefleri Kaydet
                        </button>
                    </div>
                </div>
            </div>

            <!-- Besin Analizi (Gemini AI) -->
            <div class="card p-3 mb-3">
                <h6 class="mb-3"><i class="bi bi-stars me-1 text-info"></i>Öğün Analizi <span class="badge badge-ai ms-1 small">Gemini AI</span></h6>

                <!-- Fotoğraf Çek/Yükle CTA -->
                <div class="p-2 mb-3 d-flex align-items-center justify-content-between rounded"
                     style="background:linear-gradient(135deg,rgba(236,72,153,.12),rgba(139,92,246,.12));border:1px solid rgba(236,72,153,.3);cursor:pointer;"
                     onclick="openPhotoModal()">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:20px">📸</span>
                        <div>
                            <div style="font-size:12px;font-weight:600;color:#f472b6">Fotoğraf Çek / Yükle (Vision AI)</div>
                            <div style="font-size:10px;color:var(--muted)">Tabağınızı fotoğraflayarak analiz edin</div>
                        </div>
                    </div>
                    <span class="badge" style="background:rgba(236,72,153,.25);color:#f472b6;font-size:10px;padding:5px 8px;border-radius:6px">Kamera Aç →</span>
                </div>

                <div class="mb-2">
                    <label class="form-label text-secondary small">Veya Serbest Metin Olarak Yazın:</label>
                    <textarea name="food_query" id="food_query" class="form-control form-control-sm" rows="3" placeholder="Örn: 150 gr ızgara tavuklu salata ve 1 kutu kola"><?= htmlspecialchars($foodQuery) ?></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label text-secondary small">Öğün Zamanı:</label>
                    <select name="meal_type" class="form-select form-select-sm">
                        <?php foreach (['breakfast'=>'Kahvaltı','lunch'=>'Öğle','dinner'=>'Akşam','snack'=>'Ara','pre_workout'=>'Antrenman Öncesi','post_workout'=>'Sonrası'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $mealType===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="save_to_db" name="save_to_db" value="1" checked>
                    <label class="form-check-label text-secondary small" for="save_to_db">
                        Günlük log tablosuna kaydet
                    </label>
                </div>
                <?php if ($foodError): ?>
                    <div class="alert alert-danger mt-2 py-1 small mb-0"><?= htmlspecialchars($foodError) ?></div>
                <?php endif; ?>
            </div>

            <!-- Takviye Arama (Lokal) -->
            <div class="card p-3 mb-3">
                <h6 class="mb-3"><i class="bi bi-capsule me-1 text-success"></i>Takviye Ekle <span class="badge badge-local ms-1 small">Lokal DB</span></h6>
                <input type="text" name="supp_query" id="supp_query" class="form-control form-control-sm" placeholder="Takviye adı (örn: Whey, D3, Kreatin)" value="<?= htmlspecialchars($suppQuery) ?>">
                <small class="text-secondary mt-1 d-block">supplements tablosundan otomatik çeker</small>
                <?php if ($suppError): ?>
                    <div class="alert alert-danger mt-2 py-1 small mb-0"><?= htmlspecialchars($suppError) ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" id="analyzeSubmitBtn" class="btn btn-info w-100 fw-semibold py-2">
                <i class="bi bi-lightning-charge me-1"></i> Analiz Et & Makroları Hesapla
            </button>
        </div>

        <!-- SAĞ KOLON: Sonuçlar -->
        <div class="col-lg-8">

            <!-- Sabit Günlük Makro Hedefi -->
            <div class="card p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h6 class="mb-0"><i class="bi bi-bullseye me-1 text-info"></i> Günlük Makro ve Kalori Hedefiniz</h6>
                        <div class="text-secondary small mt-1" style="font-size:11px">
                            Günlük Harcama (TDEE): <strong class="text-light"><?= number_format($calcSummary['tdee'], 0) ?> kcal</strong> · 
                            Hedef: <span class="text-info fw-semibold"><?= $calcSummary['goal'] === 'lose' ? 'Kilo Verme (-500 kcal)' : ($calcSummary['goal'] === 'gain' ? 'Kilo Alma (+300 kcal)' : 'Kiloyu Koruma') ?></span>
                        </div>
                    </div>
                    <span class="badge bg-success px-3 py-2">
                        <i class="bi bi-shield-check me-1"></i>Sabit İstikrarlı Hedef
                    </span>
                </div>
                <div class="row g-2">
                    <?php
                    $targetCards = [
                        ['label'=>'Kalori', 'key'=>'calories', 'unit'=>'kcal', 'cls'=>'bg-calorie m-calorie', 'icon'=>'bi-fire'],
                        ['label'=>'Protein','key'=>'protein_g','unit'=>'g',    'cls'=>'bg-protein m-protein', 'icon'=>'bi-egg-fried'],
                        ['label'=>'Karb',   'key'=>'carbs_g',  'unit'=>'g',    'cls'=>'bg-carb m-carb',       'icon'=>'bi-lightning'],
                        ['label'=>'Yağ',    'key'=>'fat_g',    'unit'=>'g',    'cls'=>'bg-fat m-fat',         'icon'=>'bi-droplet-half'],
                    ];
                    foreach ($targetCards as $tc):
                        $tval  = $deficits['target'][$tc['key']]    ?? 0;
                        $cval  = $deficits['consumed'][$tc['key']]  ?? 0;
                        $rval  = $deficits['remaining'][$tc['key']] ?? 0;
                        $pct   = min(100, $deficits['progress_pct'][$tc['key']] ?? 0);
                        $st    = $deficits['status'][$tc['key']]    ?? 'deficit';
                    ?>
                    <div class="col-sm-6 col-xl-3">
                        <div class="p-3 rounded <?= $tc['cls'] ?>">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small fw-semibold"><?= $tc['label'] ?></span>
                                <i class="bi <?= $tc['icon'] ?> small"></i>
                            </div>
                            <div class="fs-5 fw-bold"><?= $cval ?> <span class="fs-6 fw-normal text-secondary">/<?= $tval ?> <?= $tc['unit'] ?></span></div>
                            <div class="progress my-2">
                                <div class="progress-bar <?= $st==='over'?'bg-warning':($st==='on_track'?'bg-success':'bg-info') ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="small status-<?= $st ?>">
                                <?php if ($st === 'deficit'): ?>
                                    <i class="bi bi-arrow-down-circle me-1"></i><?= abs($rval) ?> <?= $tc['unit'] ?> eksik
                                <?php elseif ($st === 'over'): ?>
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= abs($rval) ?> <?= $tc['unit'] ?> aşıldı
                                <?php else: ?>
                                    <i class="bi bi-check-circle me-1"></i>Hedefe ulaşıldı
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Özet mesajı -->
                <div class="alert alert-dark border-secondary py-2 mt-3 mb-0 small text-center">
                    <i class="bi bi-info-circle me-1 text-info"></i>
                    <?= htmlspecialchars($deficits['summary_message']) ?>
                </div>
            </div>

            <!-- Gemini AI Analiz Sonucu -->
            <?php if ($selectedFood): ?>
            <div class="card p-3 mb-3 border-info">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0 text-info"><i class="bi bi-stars me-1"></i> Gemini AI Analiz Sonucu</h6>
                    <span class="badge badge-ai small"><?= htmlspecialchars($selectedFood['model_used'] ?? 'Gemini AI') ?></span>
                </div>
                <div class="p-3 bg-dark rounded border border-secondary border-opacity-25 mb-3">
                    <div class="text-light fw-bold mb-1">"<?= htmlspecialchars($selectedFood['food_label']) ?>"</div>
                    <?php if (!empty($selectedFood['porsiyon_ozeti'])): ?>
                        <div class="text-secondary small mb-2"><i class="bi bi-card-text me-1 text-info"></i><?= htmlspecialchars($selectedFood['porsiyon_ozeti']) ?></div>
                    <?php endif; ?>
                    <div class="row g-2 text-center">
                        <div class="col-3">
                            <div class="small text-secondary">Kalori</div>
                            <div class="fs-6 fw-bold m-calorie"><?= $selectedFood['calories'] ?> kcal</div>
                        </div>
                        <div class="col-3">
                            <div class="small text-secondary">Protein</div>
                            <div class="fs-6 fw-bold m-protein"><?= $selectedFood['protein_g'] ?> g</div>
                        </div>
                        <div class="col-3">
                            <div class="small text-secondary">Karb</div>
                            <div class="fs-6 fw-bold m-carb"><?= $selectedFood['carbs_g'] ?> g</div>
                        </div>
                        <div class="col-3">
                            <div class="small text-secondary">Yağ</div>
                            <div class="fs-6 fw-bold m-fat"><?= $selectedFood['fat_g'] ?> g</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Lokal Takviye Sonuçları -->
            <?php if (!empty($suppResults)): ?>
            <div class="card p-3 mb-3">
                <h6 class="mb-3"><i class="bi bi-capsule me-1 text-success"></i> Lokal Takviye Sonuçları
                    <span class="badge badge-local ms-1 small">MySQL • supplements tablosu</span>
                </h6>
                <div class="table-responsive">
                    <table class="table table-dark table-sm table-hover align-middle mb-0">
                        <thead><tr class="text-secondary">
                            <th>Ürün</th><th>Tür</th><th>Doz</th><th>Kalori</th><th>Protein</th><th>Karb</th><th>Yağ</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($suppResults as $sr): ?>
                            <tr>
                                <td class="fw-semibold text-light"><?= htmlspecialchars($sr['name']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($sr['type']) ?></span></td>
                                <td><?= $sr['dose_amount'] ?> <?= htmlspecialchars($sr['dose_unit']) ?></td>
                                <td class="m-calorie"><?= $sr['calories_per_dose'] ?></td>
                                <td class="m-protein"><?= $sr['protein_g'] ?>g</td>
                                <td class="m-carb"><?= $sr['carbs_g'] ?>g</td>
                                <td class="m-fat"><?= $sr['fat_g'] ?>g</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Günlük Detay Breakdown -->
            <div class="card p-3 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">
                        <i class="bi bi-list-check me-1 text-info"></i> Gün İçi Tüketim Listesi
                        <span class="badge bg-secondary ms-1 small"><?= count($deficits['entry_breakdown']) ?> kayıt</span>
                    </h6>
                    <?php if (!empty($todayFoodLogs)): ?>
                    <span class="text-secondary small d-none d-sm-inline">
                        <i class="bi bi-info-circle me-1"></i>Kayıtları silmek için sağdaki çöp kutusuna tıklayın
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($deficits['entry_breakdown'])): ?>
                    <div class="text-center py-4 text-secondary">
                        <i class="bi bi-journal-x fs-2 d-block mb-2 text-muted"></i>
                        Bugün henüz kaydedilmiş bir besin yok.<br>
                        <small class="text-muted">Sol taraftaki panelden öğününüzü yazıp analiz ederek ekleyebilirsiniz.</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle mb-0">
                            <thead>
                                <tr class="text-secondary" style="font-size:12px">
                                    <th>Besin</th>
                                    <th>Öğün</th>
                                    <th>Kaynak</th>
                                    <th>Kalori</th>
                                    <th>Protein</th>
                                    <th>Karb</th>
                                    <th>Yağ</th>
                                    <th>Saat</th>
                                    <th class="text-end">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($deficits['entry_breakdown'] as $row): 
                                $timeStr = !empty($row['logged_at']) ? date('H:i', strtotime($row['logged_at'])) : '—';
                                $mealLabel = $mealTypeLabels[$row['meal']] ?? htmlspecialchars($row['meal']);
                            ?>
                                <tr>
                                    <td class="fw-semibold text-light">
                                        <?= htmlspecialchars($row['label']) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-light border border-secondary small">
                                            <?= $mealLabel ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['source'] === 'gemini_ai'): ?>
                                            <span class="badge badge-ai small">Önizleme</span>
                                        <?php else: ?>
                                            <span class="badge bg-dark border border-secondary text-info small">Kayıtlı</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="m-calorie fw-bold"><?= $row['calories'] ?> kcal</td>
                                    <td class="m-protein"><?= $row['protein'] ?>g</td>
                                    <td class="m-carb"><?= $row['carbs'] ?>g</td>
                                    <td class="m-fat"><?= $row['fat'] ?>g</td>
                                    <td class="text-secondary small"><?= $timeStr ?></td>
                                    <td class="text-end">
                                        <?php if (!empty($row['id'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" 
                                                    onclick="deleteNutritionFood(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['label'])) ?>')" 
                                                    title="Bu besini sil">
                                                <i class="bi bi-trash3"></i> Sil
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /sağ kolon -->
    </div><!-- /row -->
    </form>

    <!-- Besin Silme Hidden Form -->
    <form id="deleteFoodForm" method="POST" action="nutrition.php" style="display:none;">
        <input type="hidden" name="action" value="delete_food">
        <input type="hidden" name="food_log_id" id="delete_food_log_id" value="">
    </form>

    </div><!-- /content -->
</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// Form submit işlemi: Doğrulama ve yükleniyor durumu
document.getElementById('nutritionForm').addEventListener('submit', function(e) {
    const food = document.getElementById('food_query').value.trim();
    const supp = document.getElementById('supp_query').value.trim();

    // Eğer her iki alan da boşsa kullanıcıyı bilgilendir
    if (!food && !supp) {
        e.preventDefault();
        Swal.fire({
            icon: 'info',
            title: 'Giriş Gerekli',
            text: 'Lütfen analiz edilecek bir öğün (örn: "150 gr tavuk, pilav ve ayran") veya bir takviye adı giriniz.',
            background: '#1e293b',
            color: '#f8fafc',
            confirmButtonColor: '#38bdf8'
        });
        return false;
    }

    // Yükleniyor durumunu butona yansıt
    const btn = document.getElementById('analyzeSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Yapay Zeka Analiz Ediyor...';
});

// Besin silme fonksiyonu
async function deleteNutritionFood(id, name) {
    const result = await Swal.fire({
        title: 'Besini Sil?',
        text: `"${name}" kaydını silmek istediğinize emin misiniz? Günlük kalori ve makro hedeflerinizden düşülecektir.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'Vazgeç',
        background: '#1e293b',
        color: '#f8fafc',
    });

    if (result.isConfirmed) {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('food_log_id', id);

        try {
            const res = await fetch(`${window.API_BASE}/analyze_food.php`, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({
                    icon: 'success',
                    title: 'Besin Silindi',
                    text: 'Kayıt başarıyla silindi ve makrolarınız güncellendi.',
                    toast: true,
                    position: 'top-end',
                    timer: 2000,
                    showConfirmButton: false,
                    background: '#1e293b',
                    color: '#f8fafc'
                }).then(() => {
                    window.location.href = 'nutrition.php';
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Hata',
                    text: data.error || 'Besin silinemedi.',
                    background: '#1e293b',
                    color: '#f8fafc'
                });
            }
        } catch (e) {
            // Ağ hatası durumunda form submit fallback
            document.getElementById('delete_food_log_id').value = id;
            document.getElementById('deleteFoodForm').submit();
        }
    }
}

// PHP'den dönen başarı veya hata durumunda SweetAlert göster
<?php if (!empty($deleteSuccess)): ?>
Swal.fire({
    icon: 'success',
    title: 'Besin Silindi!',
    text: <?= json_encode('"' . ($deletedFoodName ?: 'Öğün') . '" kaydı silindi ve makro hedefleriniz güncellendi.') ?>,
    timer: 2200,
    showConfirmButton: false,
    background: '#1e293b',
    color: '#f8fafc'
});
<?php elseif ($savedSuccess): ?>
Swal.fire({
    icon: 'success',
    title: 'Öğün Kaydedildi!',
    text: 'Yapay zeka analizi tamamlandı ve günlük makro hedefinize işlendi.',
    timer: 2000,
    showConfirmButton: false,
    background: '#1e293b',
    color: '#f8fafc'
});
<?php elseif ($foodError): ?>
Swal.fire({
    icon: 'error',
    title: 'Analiz Hatası',
    text: <?= json_encode($foodError) ?>,
    background: '#1e293b',
    color: '#f8fafc',
    confirmButtonColor: '#38bdf8'
});
<?php endif; ?>

// ─── FOTOĞRAFLA ANALİZ (GEMINI VISION) ──────────────────────────────
let selectedPhotoFile = null;
let photoModalInstance = null;

function openPhotoModal() {
    const el = document.getElementById('photoAnalysisModal');
    if (!photoModalInstance) {
        photoModalInstance = new bootstrap.Modal(el);
    }
    clearSelectedPhoto();
    photoModalInstance.show();
}

function handlePhotoSelected(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 10 * 1024 * 1024) {
        Swal.fire({ icon:'error', title:'Dosya Çok Büyük', text:'Lütfen 10MB\'dan küçük bir fotoğraf seçin.', background:'#111827', color:'#f8fafc' });
        input.value = '';
        return;
    }

    selectedPhotoFile = file;
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById('photoPreviewImg').src = e.target.result;
        document.getElementById('photoDropArea').classList.add('d-none');
        document.getElementById('photoPreviewContainer').classList.remove('d-none');
        document.getElementById('photoResultCard').classList.add('d-none');
        document.getElementById('photoAnalyzingSpinner').classList.add('d-none');
    };
    reader.readAsDataURL(file);
}

function clearSelectedPhoto() {
    selectedPhotoFile = null;
    const cam = document.getElementById('cameraFileInput');
    const gal = document.getElementById('galleryFileInput');
    if (cam) cam.value = '';
    if (gal) gal.value = '';
    const img = document.getElementById('photoPreviewImg');
    if (img) img.src = '';
    document.getElementById('photoDropArea').classList.remove('d-none');
    document.getElementById('photoPreviewContainer').classList.add('d-none');
    document.getElementById('photoResultCard').classList.add('d-none');
    document.getElementById('photoAnalyzingSpinner').classList.add('d-none');
    document.getElementById('photoUserNotes').value = '';
}

async function analyzeSelectedPhoto() {
    if (!selectedPhotoFile) {
        Swal.fire({ icon:'warning', title:'Görsel Seçilmedi', text:'Lütfen analiz edilecek bir yemek fotoğrafı seçin.', background:'#111827', color:'#f8fafc' });
        return;
    }

    const btn = document.getElementById('startPhotoAnalysisBtn');
    btn.disabled = true;
    document.getElementById('photoAnalyzingSpinner').classList.remove('d-none');
    document.getElementById('photoResultCard').classList.add('d-none');

    try {
        const fd = new FormData();
        fd.append('action', 'analyze_image');
        fd.append('food_image', selectedPhotoFile);
        fd.append('meal_type', document.getElementById('photoMealType').value);
        fd.append('notes', document.getElementById('photoUserNotes').value.trim());

        const res = await fetch(`${window.API_BASE}/analyze_food.php`, { method:'POST', body:fd });
        const data = await res.json();

        document.getElementById('photoAnalyzingSpinner').classList.add('d-none');
        btn.disabled = false;

        if (!data.ok) throw new Error(data.error ?? 'Analiz başarısız oldu.');

        const analyzed = data.analyzed;
        document.getElementById('photoResultFoodLabel').value = analyzed.food_label || 'Fotoğraflı Öğün';
        document.getElementById('photoResultDesc').textContent = analyzed.description ? `🔍 Tespit Edilenler: ${analyzed.description}` : 'Yemek içeriği analiz edildi.';
        document.getElementById('photoResultModel').textContent = `Model: ${analyzed.model_used || 'Gemini Vision'}`;
        
        document.getElementById('photoResultCal').value  = analyzed.macros.kalori || 0;
        document.getElementById('photoResultProt').value = analyzed.macros.protein || 0;
        document.getElementById('photoResultCarb').value = analyzed.macros.karb || 0;
        document.getElementById('photoResultFat').value  = analyzed.macros.yag || 0;

        document.getElementById('photoResultCard').classList.remove('d-none');

    } catch(e) {
        document.getElementById('photoAnalyzingSpinner').classList.add('d-none');
        btn.disabled = false;
        Swal.fire({ icon:'error', title:'Analiz Hatası', text: e.message, background:'#111827', color:'#f8fafc' });
    }
}

async function confirmSavePhotoFood() {
    const saveBtn = document.getElementById('confirmSavePhotoFoodBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Günlüğe Ekleniyor...';

    try {
        const fd = new FormData();
        fd.append('action', 'save_custom');
        fd.append('food_label', document.getElementById('photoResultFoodLabel').value.trim() || 'Fotoğraflı Öğün');
        fd.append('meal_type', document.getElementById('photoMealType').value);
        fd.append('calories', document.getElementById('photoResultCal').value);
        fd.append('protein', document.getElementById('photoResultProt').value);
        fd.append('carbs', document.getElementById('photoResultCarb').value);
        fd.append('fat', document.getElementById('photoResultFat').value);

        const res = await fetch(`${window.API_BASE}/analyze_food.php`, { method:'POST', body:fd });
        const data = await res.json();

        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Onayla ve Günlüğüme Ekle';

        if (!data.ok) throw new Error(data.error ?? 'Kaydetme hatası');

        if (photoModalInstance) photoModalInstance.hide();

        await Swal.fire({
            icon: 'success',
            title: 'Öğün Günlüğe Eklendi!',
            text: 'Fotoğraftaki besin değerleri makro hedefinize işlendi.',
            timer: 1800,
            showConfirmButton: false,
            background: '#111827',
            color: '#f8fafc'
        });

        // Sayfayı güncel verilerle yenile
        window.location.reload();
    } catch(e) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Onayla ve Günlüğüme Ekle';
        Swal.fire({ icon:'error', title:'Hata', text: e.message, background:'#111827', color:'#f8fafc' });
    }
}
</script>

<!-- ═══════════════════ FOTOĞRAFLA ANALİZ MODAL (Gemini Vision) ══════════════════ -->
<div class="modal fade" id="photoAnalysisModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);color:var(--text)">
        <div class="modal-header" style="border-bottom:1px solid var(--border)">
            <div>
                <h5 class="modal-title fw-bold" style="background:linear-gradient(135deg,#f472b6,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
                    <i class="bi bi-camera-fill me-1" style="-webkit-text-fill-color:#f472b6"></i> Gemini Vision ile Fotoğraftan Yemek Analizi
                </h5>
                <div style="font-size:12px;color:var(--muted)">
                    Tabağınızın veya yiyeceğinizin fotoğrafını yükleyin, yapay zeka besin değerlerini çıkarsın
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
            <!-- Gizli File Inputlar (Kamera & Galeri) -->
            <input type="file" id="cameraFileInput" accept="image/*" capture="environment" style="display:none" onchange="handlePhotoSelected(this)">
            <input type="file" id="galleryFileInput" accept="image/*" style="display:none" onchange="handlePhotoSelected(this)">

            <!-- Aşama 1: Fotoğraf Seçim Alanı -->
            <div id="photoDropArea" class="p-4 text-center mb-3" style="background:rgba(255,255,255,.02);border:2px dashed rgba(236,72,153,.35);border-radius:14px;transition:border-color .2s">
                <div style="font-size:38px;margin-bottom:8px">📸</div>
                <div class="fw-semibold mb-1" style="font-size:15px;color:var(--text)">Yemek Fotoğrafını Yükleyin veya Çekin</div>
                <div class="small text-muted mb-3">JPG, PNG veya WEBP (Maksimum 10MB)</div>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <button type="button" class="btn btn-sm btn-ghost px-3 py-2 text-white" onclick="document.getElementById('cameraFileInput').click()" style="background:rgba(236,72,153,.15);border-color:rgba(236,72,153,.4)">
                        <i class="bi bi-camera-fill me-1" style="color:#f472b6"></i> Kamera ile Çek
                    </button>
                    <button type="button" class="btn btn-sm btn-ghost px-3 py-2 text-white" onclick="document.getElementById('galleryFileInput').click()">
                        <i class="bi bi-image me-1" style="color:var(--accent)"></i> Galeriden Seç
                    </button>
                </div>
            </div>

            <!-- Aşama 2: Önizleme & Parametreler (Görsel seçilince görünür) -->
            <div id="photoPreviewContainer" class="d-none">
                <div class="row g-3">
                    <div class="col-md-5 text-center">
                        <div style="position:relative;border-radius:12px;overflow:hidden;border:1px solid var(--border);max-height:240px;background:#000;">
                            <img id="photoPreviewImg" src="" alt="Önizleme" style="width:100%;max-height:240px;object-fit:cover;display:block;">
                            <button type="button" class="btn btn-sm btn-dark position-absolute bottom-0 end-0 m-2 opacity-75" onclick="clearSelectedPhoto()" style="font-size:11px">
                                <i class="bi bi-arrow-repeat me-1"></i> Değiştir
                            </button>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="mb-2">
                            <label class="form-label text-secondary small mb-1">Öğün Zamanı</label>
                            <select id="photoMealType" class="form-select form-select-sm">
                                <option value="breakfast">🌅 Kahvaltı</option>
                                <option value="lunch" selected>☀️ Öğle Yemeği</option>
                                <option value="dinner">🌙 Akşam Yemeği</option>
                                <option value="snack">🍎 Ara Öğün</option>
                                <option value="pre_workout">⚡ Antrenman Öncesi</option>
                                <option value="post_workout">💪 Antrenman Sonrası</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small mb-1">Ek Not veya Açıklama (İsteğe Bağlı)</label>
                            <input type="text" id="photoUserNotes" class="form-control form-control-sm" placeholder="Örn: Yarısını yedim, sosu zeytinyağlı, 1 dilim ekmekle">
                            <div style="font-size:11px;color:var(--muted);margin-top:4px">
                                Porsiyon veya içerik belirtirseniz analiz çok daha hassas olur.
                            </div>
                        </div>
                        <button type="button" id="startPhotoAnalysisBtn" class="btn btn-info w-100 py-2 fw-semibold" onclick="analyzeSelectedPhoto()">
                            <i class="bi bi-stars me-1"></i> Gemini Vision ile Analiz Et
                        </button>
                    </div>
                </div>
            </div>

            <!-- Yükleniyor Göstergesi -->
            <div id="photoAnalyzingSpinner" class="d-none text-center py-4">
                <div class="spinner-border text-info mb-2" role="status" style="width:2.5rem;height:2.5rem;"></div>
                <div class="fw-semibold text-info" style="font-size:14px">Gemini Vision Tabağınızı İnceliyor...</div>
                <div class="small text-muted">Yiyecekler tespit ediliyor ve makrolar hesaplanıyor...</div>
            </div>

            <!-- Aşama 3: Analiz Sonucu ve Düzenleme / Onay Kartı -->
            <div id="photoResultCard" class="d-none mt-3 p-3" style="background:rgba(255,255,255,.03);border:1px solid rgba(56,189,248,.3);border-radius:12px">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="badge" style="background:rgba(34,197,94,.2);color:#4ade80;border:1px solid rgba(34,197,94,.3)">
                        <i class="bi bi-check-circle me-1"></i> Analiz Tamamlandı
                    </span>
                    <small id="photoResultModel" class="text-muted" style="font-size:11px"></small>
                </div>

                <div class="mb-2">
                    <label class="form-label text-secondary small mb-1">Tespit Edilen Yemek Adı</label>
                    <input type="text" id="photoResultFoodLabel" class="form-control form-control-sm text-light" style="background:var(--bg);border-color:var(--border)">
                </div>

                <div id="photoResultDesc" class="small text-muted mb-3 p-2 rounded" style="background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.05)"></div>

                <div class="row g-2 mb-3">
                    <div class="col-3 text-center">
                        <div class="p-2 rounded" style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25)">
                            <small class="text-danger fw-bold" style="font-size:11px">🔥 Kalori</small>
                            <input type="number" id="photoResultCal" class="form-control form-control-sm text-center text-danger fw-bold mt-1" style="background:var(--bg);border-color:rgba(248,113,113,.3)" step="1">
                            <small class="text-muted" style="font-size:10px">kcal</small>
                        </div>
                    </div>
                    <div class="col-3 text-center">
                        <div class="p-2 rounded" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.25)">
                            <small class="fw-bold" style="color:#60a5fa;font-size:11px">💪 Protein</small>
                            <input type="number" id="photoResultProt" class="form-control form-control-sm text-center fw-bold mt-1" style="color:#60a5fa;background:var(--bg);border-color:rgba(96,165,250,.3)" step="0.1">
                            <small class="text-muted" style="font-size:10px">gram</small>
                        </div>
                    </div>
                    <div class="col-3 text-center">
                        <div class="p-2 rounded" style="background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.25)">
                            <small class="text-warning fw-bold" style="font-size:11px">⚡ Karb</small>
                            <input type="number" id="photoResultCarb" class="form-control form-control-sm text-center text-warning fw-bold mt-1" style="background:var(--bg);border-color:rgba(250,204,21,.3)" step="0.1">
                            <small class="text-muted" style="font-size:10px">gram</small>
                        </div>
                    </div>
                    <div class="col-3 text-center">
                        <div class="p-2 rounded" style="background:rgba(192,132,252,.1);border:1px solid rgba(192,132,252,.25)">
                            <small class="fw-bold" style="color:#c084fc;font-size:11px">💧 Yağ</small>
                            <input type="number" id="photoResultFat" class="form-control form-control-sm text-center fw-bold mt-1" style="color:#c084fc;background:var(--bg);border-color:rgba(192,132,252,.3)" step="0.1">
                            <small class="text-muted" style="font-size:10px">gram</small>
                        </div>
                    </div>
                </div>

                <button type="button" id="confirmSavePhotoFoodBtn" class="btn btn-success w-100 py-2 fw-semibold" onclick="confirmSavePhotoFood()">
                    <i class="bi bi-check2-circle me-1"></i> Onayla ve Günlüğüme Ekle
                </button>
            </div>
        </div>

        <div class="modal-footer" style="border-top:1px solid var(--border)">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        </div>
    </div>
  </div>
</div>
</body>
</html>

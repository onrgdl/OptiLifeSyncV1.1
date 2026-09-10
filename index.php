<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/MetabolismCalculator.php';
require_once __DIR__ . '/app/Services/UserProfileService.php';
require_once __DIR__ . '/app/Services/MacroSaver.php';

use App\Services\MetabolismCalculator;
use App\Services\UserProfileService;

$savedSuccess = false;
$error = null;
$profileService = $pdo ? new UserProfileService($pdo) : null;

// Form değerlerini al ve kaydet / DB'den yükle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $weightKg       = isset($_POST['weight']) ? (float)$_POST['weight'] : 80.0;
    $heightCm       = isset($_POST['height']) ? (float)$_POST['height'] : 175.0;
    $age            = isset($_POST['age']) ? (int)$_POST['age'] : 28;
    $gender         = isset($_POST['gender']) ? (string)$_POST['gender'] : 'male';
    $activityLevel  = isset($_POST['activity']) ? (string)$_POST['activity'] : 'moderately_active';
    $goal           = isset($_POST['goal']) ? (string)$_POST['goal'] : 'maintain';

    if ($profileService) {
        try {
            $updated = $profileService->updateProfile(
                userId: $userId,
                weight: $weightKg,
                height: $heightCm,
                age: $age,
                gender: $gender,
                activity: $activityLevel,
                goal: $goal
            );
            $weightKg       = $updated['weight'];
            $heightCm       = $updated['height'];
            $age            = $updated['age'];
            $gender         = $updated['gender'];
            $activityLevel  = $updated['activity'];
            $goal           = $updated['goal'];
            $savedSuccess   = true;
        } catch (\Throwable $e) {
            $error = "Profil güncellenirken hata oluştu: " . $e->getMessage();
        }
    }
} else {
    if ($profileService) {
        $storedProfile  = $profileService->getProfile($userId);
        $weightKg       = $storedProfile['weight'];
        $heightCm       = $storedProfile['height'];
        $age            = $storedProfile['age'];
        $gender         = $storedProfile['gender'];
        $activityLevel  = $storedProfile['activity'];
        $goal           = $storedProfile['goal'];
    } else {
        $weightKg       = 80.0;
        $heightCm       = 175.0;
        $age            = 28;
        $gender         = 'male';
        $activityLevel  = 'moderately_active';
        $goal           = 'maintain';
    }
}

$calculator = null;
$summary = null;
$activeMacros = null;

try {
    $calculator = new MetabolismCalculator(
        weightKg: $weightKg,
        heightCm: $heightCm,
        age: $age,
        gender: $gender,
        activityLevel: $activityLevel,
        goal: $goal
    );

    $summary = $calculator->getSummary();
    $activeMacros = $calculator->getDailyMacros();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - Kişisel Sağlık Takip Sistemi</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <style>
        .card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        .form-control, .form-select {
            background-color: var(--bg);
            border-color: var(--border);
            color: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--bg);
            border-color: var(--accent);
            color: #f8fafc;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
        }
        .stat-card {
            border-radius: 12px;
            padding: 1rem;
            transition: all 0.2s;
        }
        .stat-calorie { background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05)); border: 1px solid rgba(239, 68, 68, 0.3); }
        .stat-protein { background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(59, 130, 246, 0.05)); border: 1px solid rgba(59, 130, 246, 0.3); }
        .stat-carb { background: linear-gradient(135deg, rgba(234, 179, 8, 0.15), rgba(234, 179, 8, 0.05)); border: 1px solid rgba(234, 179, 8, 0.3); }
        .stat-fat { background: linear-gradient(135deg, rgba(168, 85, 247, 0.15), rgba(168, 85, 247, 0.05)); border: 1px solid rgba(168, 85, 247, 0.3); }
        .badge-training {
            background: #22c55e;
            color: #022c22;
            font-weight: 600;
        }
        .badge-rest {
            background: #64748b;
            color: #f8fafc;
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php $activePage = 'bmr'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <button class="hamburger btn-ghost btn-topbar" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="topbar-title">BMR & Dinamik Makro Hesaplayıcı</div>
                <div class="topbar-sub">Mifflin-St Jeor Formülü ile Dinlenme vs Antrenman Hedefleri</div>
            </div>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php" class="btn-topbar btn-accent"><i class="bi bi-grid-1x2-fill"></i> <span class="d-none d-sm-inline">Dashboard</span></a>
        </div>
    </header>

    <div class="content">

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($savedSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
            <div>
                <strong>Profil Parametreleri & Makro Hedefleri Kaydedildi!</strong> Verileriniz başarıyla saklandı ve Beslenme ile Dashboard sayfalarına anında yansıtıldı.
            </div>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert" aria-label="Kapat"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- FORM ALANI -->
        <div class="col-lg-5">
            <div class="card p-4 shadow-sm">
                <h4 class="card-title text-light mb-3"><i class="bi bi-sliders me-2"></i>Kullanıcı Parametreleri</h4>
                <form method="POST" action="">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label text-secondary small">Kilo (kg)</label>
                            <input type="number" step="0.1" name="weight" class="form-control" value="<?= htmlspecialchars((string)$weightKg) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Boy (cm)</label>
                            <input type="number" step="0.5" name="height" class="form-control" value="<?= htmlspecialchars((string)$heightCm) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Yaş</label>
                            <input type="number" name="age" class="form-control" value="<?= htmlspecialchars((string)$age) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Cinsiyet</label>
                            <select name="gender" class="form-select">
                                <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Erkek</option>
                                <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Kadın</option>
                                <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Diğer</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small">Aktivite Seviyesi</label>
                            <select name="activity" class="form-select">
                                <option value="sedentary" <?= $activityLevel === 'sedentary' ? 'selected' : '' ?>>Hareketsiz (Masa başı / Spor yok)</option>
                                <option value="lightly_active" <?= $activityLevel === 'lightly_active' ? 'selected' : '' ?>>Hafif Aktif (Haftada 1-3 gün)</option>
                                <option value="moderately_active" <?= $activityLevel === 'moderately_active' ? 'selected' : '' ?>>Orta Aktif (Haftada 3-5 gün)</option>
                                <option value="very_active" <?= $activityLevel === 'very_active' ? 'selected' : '' ?>>Çok Aktif (Haftada 6-7 gün)</option>
                                <option value="extra_active" <?= $activityLevel === 'extra_active' ? 'selected' : '' ?>>Ekstra Aktif (Ağır antrenman/Sporcu)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-secondary small">Hedef</label>
                            <select name="goal" class="form-select">
                                <option value="lose" <?= $goal === 'lose' ? 'selected' : '' ?>>Kilo Ver (-500 kcal)</option>
                                <option value="maintain" <?= $goal === 'maintain' ? 'selected' : '' ?>>Kiloyu Koru (0 kcal)</option>
                                <option value="gain" <?= $goal === 'gain' ? 'selected' : '' ?>>Kilo Al (+300 kcal)</option>
                            </select>
                        </div>

                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-info w-100 py-2 fw-semibold">
                                <i class="bi bi-check2-circle me-1"></i> Profili Kaydet ve Hedefleri Güncelle
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- SONUÇLAR ALANI -->
        <div class="col-lg-7">
            <?php if ($summary && $activeMacros): ?>
                <div class="card p-4 shadow-sm mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="card-title text-light mb-0"><i class="bi bi-speedometer2 me-2"></i>Günlük İstikrarlı Hedefleriniz</h4>
                        <span class="badge bg-success px-3 py-2"><i class="bi bi-shield-check me-1"></i>SABİT HEDEF</span>
                    </div>

                    <!-- METABOLİZMA TEMEL METRİKLERİ (3'LÜ KART SİSTEMİ) -->
                    <div class="row g-2 mb-4">
                        <div class="col-sm-4">
                            <div class="p-3 rounded bg-dark border border-secondary border-opacity-25 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-secondary small fw-semibold">BMR (Bazal Metabolizma)</span>
                                    <i class="bi bi-heart-pulse text-danger small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-light"><?= number_format($summary['bmr'], 1) ?> <small class="fs-6 fw-normal text-secondary">kcal</small></div>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Dinlenme halinde organlarınızın yaktığı enerji</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded bg-dark border border-secondary border-opacity-25 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-secondary small fw-semibold">TDEE (Bakım Kalorisi)</span>
                                    <i class="bi bi-lightning-charge text-warning small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-info"><?= number_format($summary['tdee'], 1) ?> <small class="fs-6 fw-normal text-secondary">kcal</small></div>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Aktivitenizle kilonuzun sabit kaldığı toplam harcama</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded bg-dark border border-info border-opacity-50 h-100" style="background: rgba(14, 165, 233, 0.05) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-info small fw-bold">Günlük Hedef Kalori</span>
                                    <i class="bi bi-bullseye text-info small"></i>
                                </div>
                                <div class="fs-4 fw-bold text-white"><?= $activeMacros['calories'] ?> <small class="fs-6 fw-normal text-info">kcal</small></div>
                                <div style="font-size: 11px; color: #38bdf8; margin-top: 4px;">
                                    <?php if ($summary['goal'] === 'lose'): ?>
                                        <i class="bi bi-arrow-down me-1"></i>Kilo verme (TDEE -500 kcal açık)
                                    <?php elseif ($summary['goal'] === 'gain'): ?>
                                        <i class="bi bi-arrow-up me-1"></i>Kilo alma (TDEE +300 kcal fazlalık)
                                    <?php else: ?>
                                        <i class="bi bi-check2 me-1"></i>Kilo koruma (Bakım dengesi)
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- GÜNLÜK HEDEF MAKRO KARTLARI -->
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="stat-card stat-calorie">
                                <div class="d-flex justify-content-between">
                                    <span class="text-danger fw-semibold">Kalori Hedefi</span>
                                    <i class="bi bi-fire text-danger fs-5"></i>
                                </div>
                                <h2 class="fw-bold my-1 text-light"><?= $activeMacros['calories'] ?> <span class="fs-6 fw-normal text-secondary">kcal</span></h2>
                                <small class="text-secondary"><?= $summary['goal'] === 'lose' ? 'Sağlıklı kalori açığı dahil' : 'Net günlük hedef' ?></small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="stat-card stat-protein">
                                <div class="d-flex justify-content-between">
                                    <span class="text-primary fw-semibold">Protein Hedefi</span>
                                    <i class="bi bi-egg-fried text-primary fs-5"></i>
                                </div>
                                <h2 class="fw-bold my-1 text-light"><?= $activeMacros['protein_g'] ?> <span class="fs-6 fw-normal text-secondary">g</span></h2>
                                <small class="text-secondary">Kas gelişimi & onarımı (%30 oran)</small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="stat-card stat-carb">
                                <div class="d-flex justify-content-between">
                                    <span class="text-warning fw-semibold">Karbonhidrat</span>
                                    <i class="bi bi-lightning text-warning fs-5"></i>
                                </div>
                                <h2 class="fw-bold my-1 text-light"><?= $activeMacros['carbs_g'] ?> <span class="fs-6 fw-normal text-secondary">g</span></h2>
                                <small class="text-secondary">Enerji & glikojen depoları (%45 oran)</small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="stat-card stat-fat">
                                <div class="d-flex justify-content-between">
                                    <span class="text-purple fw-semibold" style="color: #c084fc;">Sağlıklı Yağ</span>
                                    <i class="bi bi-droplet-half fs-5" style="color: #c084fc;"></i>
                                </div>
                                <h2 class="fw-bold my-1 text-light"><?= $activeMacros['fat_g'] ?> <span class="fs-6 fw-normal text-secondary">g</span></h2>
                                <small class="text-secondary">Hormonal denge (%25 oran)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BİLGİLENDİRME PANELİ -->
                <div class="card p-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-info-circle text-info fs-5"></i>
                        <h6 class="text-light mb-0 fw-semibold">Metabolizma & Makro Hesaplama İlkeleri</h6>
                    </div>
                    <p class="text-secondary small mb-2">
                        Değerleriniz uluslararası <strong>Mifflin-St Jeor</strong> formülü ve <strong>Atwater termodinamik enerji sistemi</strong> (1g Protein = 4 kcal, 1g Karbonhidrat = 4 kcal, 1g Yağ = 9 kcal) kullanılarak biyokimyasal olarak %100 tutarlı biçimde hesaplanır.
                    </p>
                    <div class="d-flex flex-wrap gap-2 mt-1">
                        <span class="badge bg-dark border border-secondary text-secondary">BMR: Temel Yaşamsal İhtiyaç</span>
                        <span class="badge bg-dark border border-secondary text-secondary">TDEE: BMR × Aktivite Düzeyi</span>
                        <span class="badge bg-dark border border-info text-info">Hedef: Sürdürülebilir Kalori Dengesi</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- /content -->
</div><!-- /main -->
</body>
</html>

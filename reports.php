<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Haftalık Sağlık & Performans Raporu
 *
 * Kullanıcının seçilen haftadaki:
 *  - Kalori ve dinamik makro hedeflerine uyumunu
 *  - Gün gün tüketim ve açık/fazla analizini
 *  - Antrenman başarı oranını (planlanan vs yapılan)
 *  - İlaç/takviye düzenliliğini
 *  - Gemini AI haftalık koçluk değerlendirmesini sunar.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/MetabolismCalculator.php';
require_once __DIR__ . '/app/Services/GeminiService.php';

use App\Services\MetabolismCalculator;

$today  = date('Y-m-d');

// ── Hafta Hesabı ──────────────────────────────────────────────────────
$refDateStr = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDateStr)) {
    $refDateStr = $today;
}

try {
    $refDate = new DateTime($refDateStr);
} catch (\Throwable) {
    $refDate = new DateTime($today);
}

// Pazartesi (1) - Pazar (7)
$dayOfWeek = (int)$refDate->format('N');
$monday    = (clone $refDate)->modify('-' . ($dayOfWeek - 1) . ' days');
$sunday    = (clone $monday)->modify('+6 days');

$weekStart   = $monday->format('Y-m-d');
$weekEnd     = $sunday->format('Y-m-d');
$weekNumber  = (int)$monday->format('W');
$yearNumber  = $monday->format('Y');

// Navigasyon tarihleri
$prevWeekDate = (clone $monday)->modify('-7 days')->format('Y-m-d');
$nextWeekDate = (clone $monday)->modify('+7 days')->format('Y-m-d');
$isCurrentWeek = ($today >= $weekStart && $today <= $weekEnd);

// ── Kullanıcı Profili ────────────────────────────────────────────────
$userProfile = [
    'weight_kg' => 80.0, 'height_cm' => 175.0, 'birth_date' => '1996-01-01',
    'gender' => 'male', 'activity_level' => 'moderately_active', 'goal' => 'gain',
];
if ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    if ($row = $stmt->fetch()) {
        $userProfile = $row;
    }
}
$age = (int)(new DateTime($userProfile['birth_date']))->diff(new DateTime())->y;

$calc = new MetabolismCalculator(
    (float)$userProfile['weight_kg'],
    (float)$userProfile['height_cm'],
    $age,
    $userProfile['gender'],
    $userProfile['activity_level'],
    $userProfile['goal']
);
$restMacros     = $calc->getDailyMacros(false);
$trainingMacros = $calc->getDailyMacros(true);
$bmr            = $calc->getBMR();
$tdee           = $calc->getTDEE();

// ── Haftalık Verileri Çek ─────────────────────────────────────────────
$dailyLogs       = [];
$workouts        = [];
$foodLogsByDate  = [];
$suppLogsByDate  = [];

if ($pdo) {
    // 1. daily_logs
    $stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    while ($r = $stmt->fetch()) {
        $dailyLogs[$r['log_date']] = $r;
    }

    // 2. workouts
    $stmt = $pdo->prepare("SELECT * FROM workouts WHERE user_id = ? AND tarih BETWEEN ? AND ?");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    while ($r = $stmt->fetch()) {
        $workouts[$r['tarih']] = $r;
    }

    // 3. food_logs
    $stmt = $pdo->prepare("
        SELECT fl.*, dl.log_date
        FROM food_logs fl
        JOIN daily_logs dl ON fl.daily_log_id = dl.id
        WHERE dl.user_id = ? AND dl.log_date BETWEEN ? AND ?
        ORDER BY fl.logged_at ASC
    ");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    while ($r = $stmt->fetch()) {
        $foodLogsByDate[$r['log_date']][] = $r;
    }

    // 4. supplement_logs
    $stmt = $pdo->prepare("
        SELECT sl.is_taken, dl.log_date
        FROM supplement_logs sl
        JOIN daily_logs dl ON sl.daily_log_id = dl.id
        WHERE dl.user_id = ? AND dl.log_date BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, $weekStart, $weekEnd]);
    while ($r = $stmt->fetch()) {
        if (!isset($suppLogsByDate[$r['log_date']])) {
            $suppLogsByDate[$r['log_date']] = ['total' => 0, 'taken' => 0];
        }
        $suppLogsByDate[$r['log_date']]['total']++;
        if (!empty($r['is_taken'])) {
            $suppLogsByDate[$r['log_date']]['taken']++;
        }
    }
}

// ── 7 Günlük Tablo ve Toplamları İnşa Et ──────────────────────────────
$dayNamesTr = [
    1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba',
    4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'
];

$days              = [];
$totalCalConsumed  = 0.0;
$totalCalTarget    = 0.0;
$totalProtein      = 0.0;
$totalCarbs        = 0.0;
$totalFat          = 0.0;
$plannedWorkouts   = 0;
$completedWorkouts = 0;
$daysWithFood      = 0;
$weightsRecorded   = [];
$totalSuppPlanned  = 0;
$totalSuppTaken    = 0;

for ($i = 0; $i < 7; $i++) {
    $cur     = (clone $monday)->modify("+{$i} days");
    $dateStr = $cur->format('Y-m-d');
    $isoDay  = (int)$cur->format('N');

    $w   = $workouts[$dateStr] ?? null;
    $dl  = $dailyLogs[$dateStr] ?? null;
    $fls = $foodLogsByDate[$dateStr] ?? [];
    $sl  = $suppLogsByDate[$dateStr] ?? ['total' => 0, 'taken' => 0];

    // Antrenman durumu
    $hasWorkoutPlan   = ($w !== null);
    $workoutCompleted = ($w !== null && !empty($w['tamamlandi_mi'])) || (!empty($dl['workout_done']));
    if ($hasWorkoutPlan) {
        $plannedWorkouts++;
        if ($workoutCompleted) $completedWorkouts++;
    } elseif ($workoutCompleted) {
        $completedWorkouts++;
    }

    // Hedef: Antrenman günleri +400 kcal, +30g protein
    $dayTarget = ($hasWorkoutPlan || $workoutCompleted) ? $trainingMacros : $restMacros;
    $totalCalTarget += $dayTarget['calories'];

    // Tüketilen değerler
    $calConsumed  = (float)($dl['total_calories']  ?? 0);
    $protConsumed = (float)($dl['total_protein_g'] ?? 0);
    $carbConsumed = (float)($dl['total_carbs_g']   ?? 0);
    $fatConsumed  = (float)($dl['total_fat_g']     ?? 0);

    // Eğer daily_logs 0 ama food_logs varsa doğrudan topla
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

    $totalCalConsumed += $calConsumed;
    $totalProtein     += $protConsumed;
    $totalCarbs       += $carbConsumed;
    $totalFat         += $fatConsumed;

    // Takviye sayaçları
    $totalSuppPlanned += $sl['total'];
    $totalSuppTaken   += $sl['taken'];

    // Kilo kaydı
    if (!empty($dl['weight_kg'])) {
        $weightsRecorded[$dateStr] = (float)$dl['weight_kg'];
    }

    $diffCal      = $calConsumed - $dayTarget['calories'];
    $adherencePct = $dayTarget['calories'] > 0 ? round(($calConsumed / $dayTarget['calories']) * 100, 1) : 0;

    $days[] = [
        'date'           => $dateStr,
        'day_name'       => $dayNamesTr[$isoDay],
        'short_date'     => $cur->format('d/m'),
        'is_today'       => ($dateStr === $today),
        'is_past'        => ($dateStr < $today),
        'workout'        => $w,
        'workout_done'   => $workoutCompleted,
        'target'         => $dayTarget,
        'consumed'       => [
            'calories'  => $calConsumed,
            'protein_g' => $protConsumed,
            'carbs_g'   => $carbConsumed,
            'fat_g'     => $fatConsumed,
        ],
        'diff_calories'  => $diffCal,
        'adherence_pct'  => $adherencePct,
        'weight_kg'      => $dl['weight_kg'] ?? null,
        'food_logs'      => $fls,
        'supp_logs'      => $sl,
    ];
}

// ── Haftalık Ortalamalar & Oranlar ────────────────────────────────────
$divisorDays      = max(1, $daysWithFood);
$avgCalConsumed   = round($totalCalConsumed / $divisorDays);
$avgProtein       = round($totalProtein / $divisorDays, 1);
$avgCarbs         = round($totalCarbs / $divisorDays, 1);
$avgFat           = round($totalFat / $divisorDays, 1);
$avgCalTarget     = round($totalCalTarget / 7);

$netCalDifference = round($totalCalConsumed - $totalCalTarget);

// Makro kalori dağılımı (%)
$protKcal = $avgProtein * 4;
$carbKcal = $avgCarbs * 4;
$fatKcal  = $avgFat * 9;
$totalMacroKcal = $protKcal + $carbKcal + $fatKcal;

$pctProt = $totalMacroKcal > 0 ? round(($protKcal / $totalMacroKcal) * 100) : 0;
$pctCarb = $totalMacroKcal > 0 ? round(($carbKcal / $totalMacroKcal) * 100) : 0;
$pctFat  = $totalMacroKcal > 0 ? round(($fatKcal  / $totalMacroKcal) * 100) : 0;

// Spor başarı oranı
$workoutRate = $plannedWorkouts > 0 ? round(($completedWorkouts / $plannedWorkouts) * 100) : ($completedWorkouts > 0 ? 100 : 0);

// İlaç/takviye başarı oranı
$suppRate = $totalSuppPlanned > 0 ? round(($totalSuppTaken / $totalSuppPlanned) * 100) : 100;

// Kilo değişimi
$weightChange = null;
if (count($weightsRecorded) >= 2) {
    $firstW = reset($weightsRecorded);
    $lastW  = end($weightsRecorded);
    $weightChange = round($lastW - $firstW, 2);
}

// Chart.js verilerini JSON olarak hazırla
$chartLabels = array_column($days, 'day_name');
$chartDates  = array_column($days, 'short_date');
$chartLabelsFull = array_map(fn($d) => $d['day_name'] . ' (' . $d['short_date'] . ')', $days);

$chartConsumedCal = array_map(fn($d) => $d['consumed']['calories'], $days);
$chartTargetCal   = array_map(fn($d) => $d['target']['calories'], $days);
$chartProtein     = array_map(fn($d) => $d['consumed']['protein_g'], $days);
$chartCarbs       = array_map(fn($d) => $d['consumed']['carbs_g'], $days);
$chartFat         = array_map(fn($d) => $d['consumed']['fat_g'], $days);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - Haftalık Rapor</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --surface-hover: #1e293b;
        }

        .report-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            transition: transform .2s ease, border-color .2s ease;
        }
        .report-card:hover {
            border-color: rgba(56, 189, 248, 0.3);
        }

        .kpi-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
            margin-bottom: .4rem;
        }

        .kpi-big {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: .25rem;
        }

        .kpi-subtext {
            font-size: 12px;
            color: var(--muted);
        }

        .chart-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem;
            height: 100%;
        }

        /* Hafta Seçici Bar */
        .week-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: .75rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
        }

        /* AI Analiz Kutusu */
        .ai-banner {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.12), rgba(56, 189, 248, 0.08));
            border: 1px solid rgba(99, 102, 241, 0.35);
            border-radius: 16px;
            padding: 1.5rem;
            position: relative;
        }

        .badge-diff-plus {
            background: rgba(248, 113, 113, 0.2);
            color: #f87171;
            border: 1px solid rgba(248, 113, 113, 0.3);
        }
        .badge-diff-minus {
            background: rgba(56, 189, 248, 0.2);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }
        .badge-diff-ok {
            background: rgba(34, 197, 94, 0.2);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .day-row:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        @media print {
            .sidebar, .topbar, .week-bar button, .week-bar a, .btn-no-print, .mobile-bottom-nav {
                display: none !important;
            }
            body, .main, .content {
                background: #fff !important;
                color: #000 !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .report-card, .chart-card, .ai-banner, .week-bar {
                background: #fff !important;
                color: #000 !important;
                border: 1px solid #ccc !important;
                box-shadow: none !important;
            }
            .text-light { color: #000 !important; }
            .text-secondary, .text-muted { color: #555 !important; }
        }
    </style>
</head>
<body>
<?php $activePage = 'reports'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="hamburger btn-ghost btn-topbar" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="topbar-title">Haftalık Sağlık & Performans Raporu</div>
                <div class="topbar-sub"><?= date('d M', strtotime($weekStart)) ?> – <?= date('d M Y', strtotime($weekEnd)) ?> · <?= $weekNumber ?>. Hafta</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-ghost btn-no-print" onclick="window.print()" title="Yazdır veya PDF Kaydet">
                <i class="bi bi-printer"></i>
                <span class="d-none d-sm-inline">Yazdır / PDF</span>
            </button>
            <a href="dashboard.php" class="btn-topbar btn-accent"><i class="bi bi-grid-1x2-fill"></i> <span class="d-none d-sm-inline">Dashboard</span></a>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="content">

        <!-- ── Hafta Seçici Bar ──────────────────────────────────────── -->
        <div class="week-bar mb-4">
            <div class="d-flex align-items-center gap-2">
                <a href="reports.php?date=<?= $prevWeekDate ?>" class="btn btn-sm btn-outline-secondary px-3 py-1">
                    <i class="bi bi-chevron-left me-1"></i> Önceki Hafta
                </a>
                <?php if (!$isCurrentWeek): ?>
                    <a href="reports.php?date=<?= $currentWeekDate ?>" class="btn btn-sm btn-outline-info px-3 py-1">
                        Bu Hafta
                    </a>
                <?php endif; ?>
                <a href="reports.php?date=<?= $nextWeekDate ?>" class="btn btn-sm btn-outline-secondary px-3 py-1">
                    Sonraki Hafta <i class="bi bi-chevron-right ms-1"></i>
                </a>
            </div>

            <div class="d-flex align-items-center gap-3">
                <span class="text-secondary small d-none d-md-inline">
                    <i class="bi bi-calendar3 me-1 text-info"></i>
                    <strong><?= date('d.m.Y', strtotime($weekStart)) ?></strong> – <strong><?= date('d.m.Y', strtotime($weekEnd)) ?></strong>
                </span>
                <form method="GET" action="reports.php" class="d-flex align-items-center gap-1">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($refDateStr) ?>" onchange="this.form.submit()" style="max-width:145px; background:var(--bg); border-color:var(--border); color:#f8fafc;">
                </form>
            </div>
        </div>

        <!-- ── 5'Lİ HAFTALIK KPI GRID ─────────────────────────────────── -->
        <div class="row g-3 mb-4">
            <!-- 1. Kalori Dengesi -->
            <div class="col-sm-6 col-xl">
                <div class="report-card h-100">
                    <div class="kpi-title"><i class="bi bi-fire me-1 text-danger"></i> Haftalık Kalori</div>
                    <div class="kpi-big text-danger"><?= number_format($totalCalConsumed, 0, ',', '.') ?> <span class="fs-6 text-muted fw-normal">kcal</span></div>
                    <div class="kpi-subtext">
                        Günlük Ort: <strong><?= number_format($avgCalConsumed, 0, ',', '.') ?></strong> / <?= number_format($avgCalTarget, 0, ',', '.') ?> kcal
                    </div>
                    <div class="mt-2">
                        <?php if ($netCalDifference > 0): ?>
                            <span class="badge badge-diff-plus small">+<?= number_format($netCalDifference, 0, ',', '.') ?> kcal fazla</span>
                        <?php elseif ($netCalDifference < 0): ?>
                            <span class="badge badge-diff-minus small"><?= number_format($netCalDifference, 0, ',', '.') ?> kcal açık</span>
                        <?php else: ?>
                            <span class="badge badge-diff-ok small">Hedefte</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Protein Alımı -->
            <div class="col-sm-6 col-xl">
                <div class="report-card h-100">
                    <div class="kpi-title"><i class="bi bi-egg-fried me-1 text-primary"></i> Günlük Protein</div>
                    <div class="kpi-big text-primary"><?= $avgProtein ?> <span class="fs-6 text-muted fw-normal">g / gün</span></div>
                    <div class="kpi-subtext">
                        Toplam: <strong><?= number_format($totalProtein, 1, ',', '.') ?> g</strong>
                    </div>
                    <div class="mt-2">
                        <span class="badge bg-primary-subtle text-primary border border-primary small">
                            Hedef: <?= $restMacros['protein_g'] ?>g (Dinlenme) / <?= $trainingMacros['protein_g'] ?>g (Spor)
                        </span>
                    </div>
                </div>
            </div>

            <!-- 3. Makro Dağılımı -->
            <div class="col-sm-6 col-xl">
                <div class="report-card h-100">
                    <div class="kpi-title"><i class="bi bi-pie-chart me-1 text-warning"></i> Makro Dağılımı</div>
                    <div class="kpi-big text-warning"><?= $pctProt ?>% <span class="fs-6 text-muted fw-normal">Prot</span></div>
                    <div class="kpi-subtext">
                        Karb: <strong><?= $pctCarb ?>%</strong> · Yağ: <strong><?= $pctFat ?>%</strong>
                    </div>
                    <div class="progress mt-2" style="height: 6px; background: rgba(255,255,255,0.06);">
                        <div class="progress-bar bg-primary" style="width: <?= $pctProt ?>%" title="Protein"></div>
                        <div class="progress-bar bg-warning" style="width: <?= $pctCarb ?>%" title="Karbonhidrat"></div>
                        <div class="progress-bar" style="background:#c084fc; width: <?= $pctFat ?>%" title="Yağ"></div>
                    </div>
                </div>
            </div>

            <!-- 4. Antrenman Karnesi -->
            <div class="col-sm-6 col-xl">
                <div class="report-card h-100">
                    <div class="kpi-title"><i class="bi bi-activity me-1 text-success"></i> Spor Karnesi</div>
                    <div class="kpi-big text-success"><?= $completedWorkouts ?> <span class="fs-6 text-muted fw-normal">/ <?= max($plannedWorkouts, $completedWorkouts) ?> antrenman</span></div>
                    <div class="kpi-subtext">
                        Başarı Oranı: <strong>%<?= $workoutRate ?></strong>
                    </div>
                    <div class="mt-2">
                        <?php if ($workoutRate >= 80): ?>
                            <span class="badge bg-success-subtle text-success border border-success small">Harika Uyum 🔥</span>
                        <?php elseif ($workoutRate >= 50): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning small">Orta Düzey</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-muted border border-secondary small">Geliştirilebilir</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 5. Kilo & İlaç Uyum -->
            <div class="col-sm-6 col-xl">
                <div class="report-card h-100">
                    <div class="kpi-title"><i class="bi bi-speedometer2 me-1 text-info"></i> Kilo & İlaç</div>
                    <div class="kpi-big text-info">
                        <?php if ($weightChange !== null): ?>
                            <?= ($weightChange > 0 ? "+$weightChange" : $weightChange) ?> <span class="fs-6 text-muted fw-normal">kg</span>
                        <?php else: ?>
                            <?= (float)$userProfile['weight_kg'] ?> <span class="fs-6 text-muted fw-normal">kg</span>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-subtext">
                        İlaç / Takviye: <strong>%<?= $suppRate ?></strong> uyum
                    </div>
                    <div class="mt-2">
                        <span class="badge bg-info-subtle text-info border border-info small">
                            <?= ucfirst($userProfile['goal']) === 'Gain' ? 'Kilo Alma' : (ucfirst($userProfile['goal']) === 'Lose' ? 'Kilo Verme' : 'Kilo Koruma') ?> Hedefi
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── GRAFİKLER BÖLÜMÜ ──────────────────────────────────────── -->
        <div class="row g-4 mb-4">
            <!-- Grafik 1: Günlük Kalori Alımı vs Dinamik Hedef -->
            <div class="col-lg-8">
                <div class="chart-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-0 text-light fw-bold"><i class="bi bi-bar-chart-fill me-2 text-info"></i>Günlük Kalori Tüketimi & Hedef</h6>
                            <span class="text-secondary small">Alınan kalori sütunları ve antrenman bonuslu dinamik hedef çizgisi</span>
                        </div>
                        <span class="badge bg-dark border border-secondary text-secondary small">Haftalık 7 Gün</span>
                    </div>
                    <div style="height: 320px; position: relative;">
                        <canvas id="calorieChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Grafik 2: Makro Dağılımı ve Trendi -->
            <div class="col-lg-4">
                <div class="chart-card d-flex flex-column">
                    <div class="mb-3">
                        <h6 class="mb-0 text-light fw-bold"><i class="bi bi-pie-chart-fill me-2 text-warning"></i>Makro Enerji Payı</h6>
                        <span class="text-secondary small">Günlük ortalama kalori kaynakları</span>
                    </div>
                    <div class="flex-grow-1 d-flex align-items-center justify-content-center" style="min-height: 220px; position: relative;">
                        <canvas id="macroDoughnutChart"></canvas>
                    </div>
                    <div class="row g-2 text-center pt-3 border-top border-secondary border-opacity-25 mt-2">
                        <div class="col-4">
                            <div class="small text-muted">Protein</div>
                            <div class="fw-bold text-primary"><?= $avgProtein ?>g</div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Karb</div>
                            <div class="fw-bold text-warning"><?= $avgCarbs ?>g</div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Yağ</div>
                            <div class="fw-bold" style="color:#c084fc"><?= $avgFat ?>g</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 🤖 GEMINI AI HAFTALIK KOÇLUK DEĞERLENDİRMESİ ─────────────── -->
        <div class="ai-banner mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="fs-4">✨</div>
                    <div>
                        <h5 class="mb-0 text-light fw-bold">Yapay Zeka Haftalık Performans & Beslenme Koçu</h5>
                        <div class="text-secondary small">Gemini AI bu haftaki kalori dengeniz, makro tutarlılığınız ve spor performansınızı inceler.</div>
                    </div>
                </div>
                <button class="btn btn-sm btn-info text-dark fw-semibold px-3 py-1 btn-no-print" id="btnGenerateAi" onclick="generateAiReport()">
                    <i class="bi bi-stars me-1"></i> <span id="btnAiText">Analiz Oluştur</span>
                </button>
            </div>

            <div id="aiLoadingSpinner" class="d-none text-center py-4">
                <div class="spinner-border text-info spinner-border-sm me-2" role="status"></div>
                <span class="text-secondary">OptiLifeSync AI verilerinizi inceliyor, tavsiyeler hazırlanıyor...</span>
            </div>

            <div id="aiContentBox" class="bg-dark bg-opacity-50 p-3 rounded-3 border border-secondary border-opacity-25">
                <div class="text-secondary small" id="aiDefaultMessage">
                    <i class="bi bi-info-circle me-1 text-info"></i>
                    Bu haftanın kalori açığı/fazlası, protein yeterliliği ve antrenman düzeninizi içeren kişiselleştirilmiş değerlendirmeyi almak için yukarıdaki <strong>"Analiz Oluştur"</strong> butonuna basınız.
                </div>
                <div id="aiResultText" class="text-light" style="white-space: pre-wrap; line-height: 1.6; font-size: 14px; display: none;"></div>
            </div>
        </div>

        <!-- ── GÜN GÜN DETAYLI ANALİZ TABLOSU ─────────────────────────── -->
        <div class="card p-3 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="mb-0 text-light fw-bold"><i class="bi bi-calendar-week me-2 text-info"></i>Haftanın Günlük Dökümü</h6>
                    <span class="text-secondary small">7 gün boyunca kaydedilen besinler, hedefler ve antrenmanlar</span>
                </div>
                <span class="badge bg-secondary small"><?= $daysWithFood ?> gün veri kaydedildi</span>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-secondary" style="font-size: 12px; border-bottom: 1px solid var(--border);">
                            <th>Tarih</th>
                            <th>Spor Durumu</th>
                            <th>Alınan / Hedef Kalori</th>
                            <th>Fark</th>
                            <th>Protein</th>
                            <th>Karb</th>
                            <th>Yağ</th>
                            <th>Öğünler</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($days as $idx => $d): 
                        $isTodayClass = $d['is_today'] ? 'border-start border-3 border-info ps-2' : '';
                        $diff = $d['diff_calories'];
                    ?>
                        <tr class="day-row">
                            <td class="<?= $isTodayClass ?>">
                                <strong class="text-light"><?= $d['day_name'] ?></strong>
                                <div class="text-secondary small"><?= $d['short_date'] ?> <?php if ($d['is_today']): ?><span class="badge bg-info text-dark" style="font-size:9px">BUGÜN</span><?php endif; ?></div>
                            </td>

                            <!-- Spor Durumu -->
                            <td>
                                <?php if ($d['workout_done']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success small">
                                        <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($d['workout']['antrenman_tipi'] ?? 'Tamamlandı') ?>
                                    </span>
                                <?php elseif ($d['workout']): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning small">
                                        <i class="bi bi-clock me-1"></i> Planlandı (Yapılmadı)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-muted border border-secondary small">
                                        <i class="bi bi-moon me-1"></i> Dinlenme
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Alınan / Hedef Kalori -->
                            <td>
                                <div>
                                    <strong class="text-light"><?= number_format($d['consumed']['calories'], 0) ?></strong>
                                    <span class="text-secondary">/ <?= $d['target']['calories'] ?> kcal</span>
                                </div>
                                <div class="progress mt-1" style="height: 4px; max-width: 140px; background: rgba(255,255,255,0.06);">
                                    <div class="progress-bar bg-danger" style="width: <?= min(100, $d['adherence_pct']) ?>%"></div>
                                </div>
                            </td>

                            <!-- Fark -->
                            <td>
                                <?php if ($d['consumed']['calories'] == 0): ?>
                                    <span class="text-muted small">—</span>
                                <?php elseif ($diff > 0): ?>
                                    <span class="badge badge-diff-plus small">+<?= number_format($diff, 0) ?> kcal</span>
                                <?php elseif ($diff < 0): ?>
                                    <span class="badge badge-diff-minus small"><?= number_format($diff, 0) ?> kcal</span>
                                <?php else: ?>
                                    <span class="badge badge-diff-ok small">Hedefte</span>
                                <?php endif; ?>
                            </td>

                            <!-- Makrolar -->
                            <td><span class="text-primary fw-semibold"><?= $d['consumed']['protein_g'] ?>g</span></td>
                            <td><span class="text-warning fw-semibold"><?= $d['consumed']['carbs_g'] ?>g</span></td>
                            <td><span style="color:#c084fc;" class="fw-semibold"><?= $d['consumed']['fat_g'] ?>g</span></td>

                            <!-- Öğünler Mini Liste / Popover -->
                            <td>
                                <?php if (!empty($d['food_logs'])): ?>
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2 small" type="button" data-bs-toggle="collapse" data-bs-target="#meals-<?= $idx ?>" aria-expanded="false">
                                        <?= count($d['food_logs']) ?> öğün <i class="bi bi-chevron-down ms-1"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">Kayıt yok</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Açılır Öğün Detayı -->
                        <?php if (!empty($d['food_logs'])): ?>
                        <tr class="collapse" id="meals-<?= $idx ?>">
                            <td colspan="8" class="bg-dark bg-opacity-75 p-3">
                                <div class="small fw-semibold text-secondary mb-2"><i class="bi bi-list-ul me-1"></i><?= $d['day_name'] ?> Günü Tüketilen Öğünler:</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($d['food_logs'] as $f): ?>
                                        <div class="px-2 py-1 rounded bg-black bg-opacity-50 border border-secondary border-opacity-25 small text-light">
                                            <strong><?= htmlspecialchars($f['food_label']) ?></strong>
                                            <span class="text-muted">(<?= $f['calories'] ?> kcal · <?= $f['protein_g'] ?>p / <?= $f['carbs_g'] ?>k / <?= $f['fat_g'] ?>y)</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// ─── 1. KALORİ GRAFİĞİ (Bar + Line Combo) ────────────────────────────
const ctxCal = document.getElementById('calorieChart').getContext('2d');
new Chart(ctxCal, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabelsFull) ?>,
        datasets: [
            {
                label: 'Alınan Kalori (kcal)',
                data: <?= json_encode($chartConsumedCal) ?>,
                backgroundColor: 'rgba(248, 113, 113, 0.75)',
                borderColor: '#f87171',
                borderWidth: 1,
                borderRadius: 6,
                order: 2
            },
            {
                label: 'Hedef Kalori (kcal)',
                data: <?= json_encode($chartTargetCal) ?>,
                type: 'line',
                borderColor: '#38bdf8',
                backgroundColor: 'rgba(56, 189, 248, 0.1)',
                borderWidth: 2,
                borderDash: [5, 5],
                pointBackgroundColor: '#38bdf8',
                pointRadius: 4,
                fill: false,
                tension: 0.2,
                order: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: { color: '#94a3b8', font: { size: 12 } }
            },
            tooltip: {
                backgroundColor: '#111827',
                titleColor: '#f8fafc',
                bodyColor: '#e2e8f0',
                borderColor: '#334155',
                borderWidth: 1,
                callbacks: {
                    afterBody: function(items) {
                        const idx = items[0].dataIndex;
                        const diff = <?= json_encode(array_column($days, 'diff_calories')) ?>[idx];
                        if (diff > 0) return `Fark: +${diff} kcal fazla`;
                        if (diff < 0) return `Fark: ${diff} kcal açık`;
                        return 'Fark: Tam hedefte';
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { color: '#94a3b8' },
                grid: { color: 'rgba(255, 255, 255, 0.05)' }
            },
            y: {
                ticks: { color: '#94a3b8' },
                grid: { color: 'rgba(255, 255, 255, 0.05)' },
                suggestedMin: 1500
            }
        }
    }
});

// ─── 2. MAKRO DAĞILIMI (Doughnut Chart) ──────────────────────────────
const ctxMacro = document.getElementById('macroDoughnutChart').getContext('2d');
new Chart(ctxMacro, {
    type: 'doughnut',
    data: {
        labels: ['Protein (%<?= $pctProt ?>)', 'Karbonhidrat (%<?= $pctCarb ?>)', 'Yağ (%<?= $pctFat ?>)'],
        datasets: [{
            data: [<?= $pctProt ?: 30 ?>, <?= $pctCarb ?: 45 ?>, <?= $pctFat ?: 25 ?>],
            backgroundColor: ['#60a5fa', '#facc15', '#c084fc'],
            borderColor: '#111827',
            borderWidth: 3,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: '#94a3b8', font: { size: 11 }, padding: 14 }
            }
        },
        cutout: '70%'
    }
});

// ─── 3. GEMINI AI ANALİZ ENTEGRASYONU ────────────────────────────────
const weekStart = <?= json_encode($weekStart) ?>;
const weekEnd   = <?= json_encode($weekEnd) ?>;
const storageKey = `optilifesync_ai_report_${weekStart}_${weekEnd}`;

// Sayfa yüklendiğinde daha önce üretilmiş analiz varsa yükle
document.addEventListener('DOMContentLoaded', () => {
    const cached = localStorage.getItem(storageKey);
    if (cached) {
        displayAiAdvice(cached);
        document.getElementById('btnAiText').textContent = 'Yeniden Analiz Et';
    }
});

function displayAiAdvice(text) {
    document.getElementById('aiDefaultMessage').style.display = 'none';
    const box = document.getElementById('aiResultText');
    box.textContent = text;
    box.style.display = 'block';
}

async function generateAiReport() {
    const btn = document.getElementById('btnGenerateAi');
    const spinner = document.getElementById('aiLoadingSpinner');
    const defaultMsg = document.getElementById('aiDefaultMessage');

    btn.disabled = true;
    spinner.classList.remove('d-none');
    defaultMsg.style.display = 'none';

    const formData = new FormData();
    formData.append('action', 'ai_analysis');
    formData.append('week_start', weekStart);
    formData.append('week_end', weekEnd);

    try {
        const res = await fetch(`${window.API_BASE}/reports.php`, { method: 'POST', body: formData });
        const data = await res.json();

        if (data.ok && data.advice) {
            displayAiAdvice(data.advice);
            localStorage.setItem(storageKey, data.advice);
            document.getElementById('btnAiText').textContent = 'Yeniden Analiz Et';
            Swal.fire({
                icon: 'success',
                title: 'AI Raporu Hazır!',
                text: 'Haftalık koçluk değerlendirmeniz başarıyla üretildi.',
                timer: 2000,
                showConfirmButton: false,
                background: '#1e293b',
                color: '#f8fafc'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Hata',
                text: data.error || 'Yapay zeka analizi üretilemedi.',
                background: '#1e293b',
                color: '#f8fafc'
            });
            defaultMsg.style.display = 'block';
        }
    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Bağlantı Hatası',
            text: 'Yapay zeka servisine ulaşılamadı.',
            background: '#1e293b',
            color: '#f8fafc'
        });
        defaultMsg.style.display = 'block';
    } finally {
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}
</script>
</body>
</html>

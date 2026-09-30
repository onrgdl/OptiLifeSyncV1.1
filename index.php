<?php
declare(strict_types=1);

/**
 * OptiLifeSync - Profil & Hedefler (BMR / TDEE hesaplayıcı)
 * Mifflin-St Jeor formülü ile bazal metabolizma, günlük harcama ve makro hedefleri.
 */

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

$defaults = ['weight' => 70.0, 'height' => 170.0, 'age' => 30, 'gender' => 'male', 'activity' => 'moderately_active', 'goal' => 'maintain'];
$p = $profileService ? $profileService->getProfile($userId) : $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profileService) {
    try {
        $p = $profileService->updateProfile(
            userId: $userId,
            weight: max(25, min(350, (float)($_POST['weight'] ?? $p['weight']))),
            height: max(100, min(250, (float)($_POST['height'] ?? $p['height']))),
            age: max(12, min(110, (int)($_POST['age'] ?? $p['age']))),
            gender: (string)($_POST['gender'] ?? $p['gender']),
            activity: (string)($_POST['activity'] ?? $p['activity']),
            goal: (string)($_POST['goal'] ?? $p['goal'])
        );
        $savedSuccess = true;
    } catch (\Throwable $e) {
        $error = 'Profil kaydedilemedi: ' . $e->getMessage();
    }
}

$summary = null;
$macros = null;
try {
    $calc = new MetabolismCalculator(
        weightKg: (float)$p['weight'], heightCm: (float)$p['height'], age: (int)$p['age'],
        gender: (string)$p['gender'], activityLevel: (string)$p['activity'], goal: (string)$p['goal']
    );
    $summary = $calc->getSummary();
    $macros = $calc->getDailyMacros();
    } catch (\Throwable $e) {
    $error = $e->getMessage();
}

$h = (float)$p['height'] / 100;
$bmi = $h > 0 ? round((float)$p['weight'] / ($h * $h), 1) : 0;
[$bmiLabel, $bmiTone] = match (true) {
    $bmi < 18.5 => ['Zayıf', 'blue'],
    $bmi < 25   => ['Normal', 'accent'],
    $bmi < 30   => ['Fazla kilolu', 'yellow'],
    default     => ['Obez', 'red'],
};
$bmiPos = max(0, min(100, ($bmi - 15) / (40 - 15) * 100));
$waterTarget = (int)round((float)$p['weight'] * 35);
$weeklyChange = match ($p['goal']) { 'lose' => -0.45, 'gain' => 0.27, default => 0.0 };

$activityLabels = [
    'sedentary'         => ['Hareketsiz', 'Masa başı, spor yok'],
    'lightly_active'    => ['Hafif aktif', 'Haftada 1–3 gün'],
    'moderately_active' => ['Orta aktif', 'Haftada 3–5 gün'],
    'very_active'       => ['Çok aktif', 'Haftada 6–7 gün'],
    'extra_active'      => ['Sporcu', 'Günde 2 antrenman / ağır iş'],
];
$goalLabels = ['lose' => ['Kilo ver', '−500 kcal', 'bi-arrow-down'], 'maintain' => ['Koru', 'Denge', 'bi-dash'], 'gain' => ['Kilo al', '+300 kcal', 'bi-arrow-up']];
$fmt = fn($v) => number_format((float)$v, 0, ',', '.');
$v = '20260930';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Profil & Hedefler · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .pf-grid { display: grid; grid-template-columns: 400px minmax(0, 1fr); gap: 20px; align-items: start; }
        @media (max-width: 1100px) { .pf-grid { grid-template-columns: minmax(0, 1fr); } }
        .stack { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .card-pad { padding: 22px; }
        @media (max-width: 576px) { .card-pad { padding: 16px; } .stack { gap: 14px; } }
        .opt-grid { display: grid; gap: 8px; }
        .opt-grid.g3 { grid-template-columns: repeat(3, 1fr); }
        .opt input { display: none; }
        .opt label { display: block; border: 1px solid var(--border-strong); border-radius: 12px; padding: 10px 12px; cursor: pointer; transition: border-color .15s, background .15s; height: 100%; }
        .opt label b { display: block; font-size: 13.5px; }
        .opt label span { font-size: 11.5px; color: var(--muted); }
        .opt input:checked + label { border-color: var(--accent-bright); background: var(--accent-dim); }
        .opt input:checked + label b { color: var(--accent); }
        .g3 .opt label { text-align: center; }
        .big-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        @media (max-width: 576px) { .big-kpis { grid-template-columns: 1fr 1fr; } .big-kpis > :last-child { grid-column: span 2; } }
        .bk { background: var(--surface-2); border: 1px solid var(--border); border-radius: 16px; padding: 14px; }
        .bk.hl { background: var(--accent-dim); border-color: transparent; }
        .bk .l { font-size: 12px; font-weight: 650; color: var(--muted); }
        .bk .v { font-size: 26px; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
        .bk .v small { font-size: 13px; color: var(--muted); font-weight: 500; }
        .bk .d { font-size: 12px; color: var(--muted); }
        .macro-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .macro-table td { padding: 10px 12px; background: var(--surface-2); font-variant-numeric: tabular-nums; }
        .macro-table td:first-child { border-radius: 12px 0 0 12px; font-weight: 650; }
        .macro-table td:last-child { border-radius: 0 12px 12px 0; }
        .macro-table th { font-size: 11.5px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; padding: 0 12px; }
        .dotc { display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 8px; }
        .bmi-scale { position: relative; height: 10px; border-radius: 99px; background: linear-gradient(90deg, #60a5fa 0%, #60a5fa 14%, #34d399 14%, #34d399 40%, #fbbf24 40%, #fbbf24 60%, #fb7185 60%); margin: 14px 0 6px; }
        .bmi-scale i { position: absolute; top: 50%; width: 18px; height: 18px; border-radius: 50%; background: var(--surface); border: 3px solid var(--text); transform: translate(-50%, -50%); }
        .bmi-labels { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); }
    </style>
</head>
<body>
<?php $activePage = 'bmr'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Profil & Hedefler</div>
                <div class="topbar-sub">Kalori ve makro hedefleriniz bu bilgilere göre hesaplanır</div>
            </div>
        </div>
    </header>

    <div class="content">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($savedSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex gap-2 align-items-center"><i class="bi bi-check-circle-fill"></i><div>Profil kaydedildi. Tüm sayfalardaki hedefler güncellendi.</div><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <div class="pf-grid">
            <!-- Form -->
            <form method="POST" class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-person"></i>Bilgilerim</h2></div>
                <div class="row g-3">
                    <div class="col-4"><label class="form-label">Kilo (kg)</label><input type="number" step="0.1" name="weight" class="form-control" inputmode="decimal" value="<?= htmlspecialchars((string)$p['weight']) ?>" required></div>
                    <div class="col-4"><label class="form-label">Boy (cm)</label><input type="number" step="0.5" name="height" class="form-control" inputmode="decimal" value="<?= htmlspecialchars((string)$p['height']) ?>" required></div>
                    <div class="col-4"><label class="form-label">Yaş</label><input type="number" name="age" class="form-control" inputmode="numeric" value="<?= htmlspecialchars((string)$p['age']) ?>" required></div>
                </div>

                <label class="form-label mt-3">Cinsiyet</label>
                <div class="opt-grid g3">
                    <?php foreach (['male' => 'Erkek', 'female' => 'Kadın', 'other' => 'Diğer'] as $k => $l): ?>
                        <div class="opt"><input type="radio" name="gender" id="g_<?= $k ?>" value="<?= $k ?>" <?= $p['gender'] === $k ? 'checked' : '' ?>><label for="g_<?= $k ?>"><b><?= $l ?></b></label></div>
                    <?php endforeach; ?>
                </div>

                <label class="form-label mt-3">Hedef</label>
                <div class="opt-grid g3">
                    <?php foreach ($goalLabels as $k => [$l, $s, $ic]): ?>
                        <div class="opt"><input type="radio" name="goal" id="goal_<?= $k ?>" value="<?= $k ?>" <?= $p['goal'] === $k ? 'checked' : '' ?>><label for="goal_<?= $k ?>"><b><i class="bi <?= $ic ?> me-1"></i><?= $l ?></b><span><?= $s ?></span></label></div>
                    <?php endforeach; ?>
                </div>

                <label class="form-label mt-3">Aktivite</label>
                <div class="opt-grid">
                    <?php foreach ($activityLabels as $k => [$l, $s]): ?>
                        <div class="opt"><input type="radio" name="activity" id="a_<?= $k ?>" value="<?= $k ?>" <?= $p['activity'] === $k ? 'checked' : '' ?>><label for="a_<?= $k ?>" class="d-flex justify-content-between align-items-center"><b><?= $l ?></b><span><?= $s ?></span></label></div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn btn-primary w-100 mt-4 py-2"><i class="bi bi-check-lg me-1"></i>Kaydet ve hedefleri güncelle</button>
            </form>

            <!-- Sonuçlar -->
            <div class="stack">
                <?php if ($summary && $macros): ?>
                <section class="card card-pad">
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-bullseye"></i>Günlük hedefiniz</h2><span class="chip accent"><?= $goalLabels[$p['goal']][0] ?? '' ?></span></div>
                    <div class="big-kpis">
                        <div class="bk"><div class="l">Bazal metabolizma</div><div class="v"><?= $fmt($summary['bmr']) ?> <small>kcal</small></div><div class="d">Dinlenirken harcanan</div></div>
                        <div class="bk"><div class="l">Günlük harcama</div><div class="v"><?= $fmt($summary['tdee']) ?> <small>kcal</small></div><div class="d">Aktivite dahil</div></div>
                        <div class="bk hl"><div class="l" style="color:var(--accent)">Hedef kalori</div><div class="v"><?= $fmt($macros['calories']) ?> <small>kcal</small></div><div class="d"><?= $p['goal'] === 'lose' ? '≈ 0,45 kg/hafta kayıp' : ($p['goal'] === 'gain' ? '≈ 0,25 kg/hafta artış' : 'Kiloyu koruma') ?></div></div>
                    </div>

                    <table class="macro-table mt-3">
                        <thead><tr><th></th><th>Günlük hedef</th><th>Oran</th></tr></thead>
                        <tbody>
                            <tr><td><span class="dotc" style="background:var(--c-protein)"></span>Protein</td><td><?= $fmt($macros['protein_g']) ?> g</td><td>%30</td></tr>
                            <tr><td><span class="dotc" style="background:var(--c-carb)"></span>Karbonhidrat</td><td><?= $fmt($macros['carbs_g']) ?> g</td><td>%45</td></tr>
                            <tr><td><span class="dotc" style="background:var(--c-fat)"></span>Yağ</td><td><?= $fmt($macros['fat_g']) ?> g</td><td>%25</td></tr>
                        </tbody>
                    </table>
                    <div class="small mt-1" style="color:var(--muted)">Hedefler her gün sabittir; antrenman günlerinde yalnızca su hedefi artar.</div>
                </section>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <section class="card card-pad h-100">
                            <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-rulers"></i>Vücut kitle indeksi</h2><span class="chip <?= $bmiTone ?>"><?= $bmiLabel ?></span></div>
                            <div style="font-size:30px;font-weight:800;letter-spacing:-.03em"><?= number_format($bmi, 1, ',', '') ?></div>
                            <div class="bmi-scale"><i style="left:<?= $bmiPos ?>%"></i></div>
                            <div class="bmi-labels"><span>15</span><span>18,5</span><span>25</span><span>30</span><span>40</span></div>
                            <div class="small mt-2" style="color:var(--muted)">BMI kas kütlesini ayırt etmez; genel bir göstergedir.</div>
                        </section>
                    </div>
                    <div class="col-md-6">
                        <section class="card card-pad h-100">
                            <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-droplet" style="color:var(--c-water)"></i>Su hedefi</h2></div>
                            <div style="font-size:30px;font-weight:800;letter-spacing:-.03em;color:var(--c-water)"><?= $fmt($waterTarget) ?> <small style="font-size:14px;color:var(--muted);font-weight:500">ml / gün</small></div>
                            <div class="small mt-2" style="color:var(--muted)">Kilo başına 35 ml. Antrenman günlerinde +500 ml eklenir (≈ <?= (int)ceil(($waterTarget + 500) / 250) ?> bardak).</div>
                        </section>
                    </div>
                </div>

                <section class="card card-pad">
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-info-circle"></i>Nasıl hesaplanıyor?</h2></div>
                    <div class="small" style="color:var(--text-2);line-height:1.7">
                        Bazal metabolizma <b>Mifflin-St Jeor</b> formülüyle hesaplanır, aktivite katsayısı (<?= htmlspecialchars((string)($summary['activity_multiplier'] ?? '')) ?>) ile çarpılarak günlük harcama bulunur.
                        Hedefe göre <?= $p['goal'] === 'lose' ? '500 kcal düşülür' : ($p['goal'] === 'gain' ? '300 kcal eklenir' : 'değişiklik yapılmaz') ?>.
                        Makrolar kalorinin %30 protein, %45 karbonhidrat ve %25 yağ olarak dağıtılmasıyla bulunur. Özet sayfasında kilonuzu kaydettiğinizde profil kilonuz da güncellenir.
                        <div class="mt-2" style="color:var(--muted)">Bu değerler genel bilgilendirme amaçlıdır; tıbbi bir durumunuz varsa hekiminize danışın.</div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

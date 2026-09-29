<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Beslenme Günlüğü (v2)
 *
 *  • Gün gün gezinme (son 60 gün)
 *  • Kalori & makro hedefi, kalan miktarlar ve makro dağılımı
 *  • Öğünlere göre gruplanmış kayıtlar (silme)
 *  • Öğün ekleme: yapay zeka (metin / fotoğraf), sık yenenler, elle, takviye
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/DashboardService.php';

use App\Services\DashboardService;

$today = date('Y-m-d');
$minDate = date('Y-m-d', strtotime('-60 days'));
$date = (string)($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today || $date < $minDate) {
    $date = $today;
}
$isToday = $date === $today;
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

$data = null;
$meals = [];
if ($pdo) {
    try {
        $data = DashboardService::getDashboardData($pdo, (int)$userId, $date);
        $stmt = $pdo->prepare("
            SELECT fl.id, fl.food_label, fl.meal_type, fl.calories, fl.protein_g, fl.carbs_g, fl.fat_g, fl.logged_at
            FROM food_logs fl
            JOIN daily_logs dl ON fl.daily_log_id = dl.id
            WHERE dl.user_id = ? AND dl.log_date = ?
            ORDER BY fl.logged_at ASC, fl.id ASC
        ");
        $stmt->execute([$userId, $date]);
        $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('Beslenme sayfası hatası: ' . $e->getMessage());
    }
}

$mealTypes = [
    'breakfast'    => ['Kahvaltı', '🌅'],
    'lunch'        => ['Öğle yemeği', '☀️'],
    'dinner'       => ['Akşam yemeği', '🌙'],
    'snack'        => ['Ara öğün', '🍎'],
    'pre_workout'  => ['Antrenman öncesi', '⚡'],
    'post_workout' => ['Antrenman sonrası', '💪'],
];
$grouped = [];
foreach ($meals as $m) {
    $grouped[$m['meal_type']][] = $m;
}

$trDays = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
$trMonths = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$ts = strtotime($date);
$dateLabel = $isToday ? 'Bugün' : ($date === date('Y-m-d', strtotime('-1 day')) ? 'Dün' : $trDays[(int)date('w', $ts)]);
$dateSub = (int)date('j', $ts) . ' ' . $trMonths[(int)date('n', $ts)] . ' ' . date('Y', $ts);

$c = $data['consumed'] ?? ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];
$t = $data['target'] ?? ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];
$r = $data['remaining'] ?? $t;
$pct = $data['progress_pct'] ?? ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];

// Makro enerji dağılımı (kcal)
$kP = (float)$c['protein_g'] * 4; $kC = (float)$c['carbs_g'] * 4; $kF = (float)$c['fat_g'] * 9;
$kSum = max(1, $kP + $kC + $kF);
$split = ['p' => round($kP / $kSum * 100), 'c' => round($kC / $kSum * 100), 'f' => max(0, 100 - round($kP / $kSum * 100) - round($kC / $kSum * 100))];

// Kısa öneri
$tip = '';
if (empty($meals)) {
    $tip = $isToday ? 'Günün ilk öğününü ekleyerek başlayın.' : 'Bu gün için kayıt yok.';
} elseif ($r['calories'] < -150) {
    $tip = 'Kalori hedefinizi ' . number_format(abs($r['calories']), 0, ',', '.') . ' kcal aştınız. Kalan öğünlerde hafif seçimler yapabilirsiniz.';
} elseif ($r['protein_g'] > 25 && $isToday) {
    $tip = 'Protein hedefine ' . number_format($r['protein_g'], 0, ',', '.') . ' g kaldı. Yoğurt, yumurta, tavuk veya baklagiller iyi seçenekler.';
} elseif ($r['calories'] >= -150 && $r['calories'] <= 150) {
    $tip = 'Harika! Kalori hedefinizin tam üzerindesiniz. 🎯';
} elseif ($isToday) {
    $tip = 'Hedefe ' . number_format($r['calories'], 0, ',', '.') . ' kcal kaldı.';
}

$hasGeminiKey = Config::hasGeminiKey();
$fmt = fn($v) => number_format((float)$v, 0, ',', '.');
$v = '20260929';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Beslenme · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .nu-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
        @media (max-width: 1200px) { .nu-grid { grid-template-columns: minmax(0, 1fr); } }
        .stack { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .card-pad { padding: 20px; }
        @media (max-width: 576px) { .card-pad { padding: 16px; } .stack { gap: 14px; } }

        .day-nav { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        .day-nav .title { text-align: center; }
        .day-nav .title b { font-size: 22px; letter-spacing: -.02em; display: block; }
        .day-nav .title span { color: var(--muted); font-size: 13px; }
        .day-nav .icon-btn { width: 42px; height: 42px; font-size: 18px; }
        .day-nav .icon-btn.disabled { opacity: .35; pointer-events: none; }

        .sum { display: grid; grid-template-columns: auto 1fr; gap: 24px; align-items: center; }
        @media (max-width: 576px) { .sum { grid-template-columns: 1fr; justify-items: center; gap: 18px; } }
        .sum .ring { --size: 150px; --w: 13px; --c: var(--c-kcal); }
        .sum .ring.over { --c: var(--red); }
        .sum .ring b { font-size: 30px; font-weight: 800; letter-spacing: -.03em; display: block; text-align: center; line-height: 1; }
        .sum .ring span { font-size: 12px; color: var(--muted); display: block; text-align: center; margin-top: 4px; font-weight: 600; }
        .mrow { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; width: 100%; }
        .mcard { background: var(--surface-2); border: 1px solid var(--border); border-radius: 14px; padding: 12px; }
        .mcard .l { font-size: 12px; font-weight: 650; color: var(--muted); display: flex; align-items: center; gap: 6px; }
        .mcard .l::before { content: ''; width: 8px; height: 8px; border-radius: 3px; background: var(--mc); }
        .mcard .v { font-size: 20px; font-weight: 800; letter-spacing: -.02em; margin: 2px 0 8px; font-variant-numeric: tabular-nums; }
        .mcard .v small { font-size: 12px; color: var(--muted); font-weight: 500; }
        .mcard .bar { height: 6px; }
        .mcard .bar > span { background: var(--mc); }
        .mcard .rem { font-size: 11.5px; color: var(--muted); margin-top: 6px; }
        @media (max-width: 400px) { .mcard { padding: 10px; } .mcard .v { font-size: 17px; } }
        .tip { display: flex; gap: 10px; align-items: flex-start; margin-top: 18px; padding: 12px 14px; border-radius: 14px; background: var(--accent-dim); color: var(--text-2); font-size: 13.5px; }
        .tip i { color: var(--accent); margin-top: 1px; }

        .meal-group + .meal-group { margin-top: 18px; }
        .meal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .meal-head .h { font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .meal-head .k { font-size: 13px; color: var(--muted); font-weight: 600; font-variant-numeric: tabular-nums; }
        .food { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
        .food + .food { margin-top: 8px; }
        .food .nm { font-weight: 600; line-height: 1.3; }
        .food .mc { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .food .mc b { font-weight: 700; }
        .food .kc { font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .add-meal-btn { border: 1px dashed var(--border-strong); background: transparent; color: var(--muted); width: 100%; border-radius: 14px; padding: 10px; font-weight: 600; font-size: 13px; margin-top: 8px; }
        .add-meal-btn:hover { color: var(--accent); border-color: var(--accent-bright); }

        .split-bar { display: flex; height: 12px; border-radius: 99px; overflow: hidden; background: var(--surface-3); }
        .split-bar span { display: block; height: 100%; }
        .legend { display: flex; justify-content: space-between; margin-top: 10px; font-size: 12.5px; }
        .legend div { display: flex; align-items: center; gap: 6px; color: var(--muted); }
        .legend i { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
        .legend b { color: var(--text); }
        .add-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .add-tile { border: 1px solid var(--border); background: var(--surface-2); border-radius: 16px; padding: 14px; text-align: left; color: var(--text); transition: border-color .15s, transform .12s; }
        .add-tile:hover { border-color: var(--accent-bright); }
        .add-tile:active { transform: scale(.98); }
        .add-tile i { font-size: 20px; color: var(--accent); display: block; margin-bottom: 8px; }
        .add-tile b { display: block; font-size: 14px; }
        .add-tile span { font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<?php $activePage = 'nutrition'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Beslenme</div>
                <div class="topbar-sub">Öğünlerinizi kaydedin, hedefinizi takip edin</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-ghost d-mobile-none" onclick="OptiQuickAdd.open({ tab: 'photo' })"><i class="bi bi-camera"></i><span>Fotoğrafla</span></button>
            <button class="btn-topbar btn-accent" onclick="OptiQuickAdd.open()"><i class="bi bi-plus-lg"></i><span>Öğün ekle</span></button>
        </div>
    </header>

    <div class="content">
        <?php if (!$hasGeminiKey): ?>
            <div class="alert alert-warning d-flex gap-2 align-items-start">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <div><strong>Yapay zeka analizi kapalı.</strong> Vercel ortam değişkenlerine <code>GEMINI_API_KEY</code> eklenmemiş. Bu sürede öğünleri "Elle" veya "Sık yenenler" sekmesinden ekleyebilirsiniz.</div>
            </div>
        <?php endif; ?>

        <div class="day-nav">
            <a class="icon-btn <?= $prevDate < $minDate ? 'disabled' : '' ?>" href="nutrition.php?date=<?= $prevDate ?>" aria-label="Önceki gün"><i class="bi bi-chevron-left"></i></a>
            <div class="title">
                <b><?= $dateLabel ?></b>
                <span><?= $dateSub ?><?php if (!$isToday): ?> · <a href="nutrition.php" style="color:var(--accent);font-weight:600">Bugüne dön</a><?php endif; ?></span>
            </div>
            <a class="icon-btn <?= $isToday ? 'disabled' : '' ?>" href="nutrition.php?date=<?= $nextDate ?>" aria-label="Sonraki gün"><i class="bi bi-chevron-right"></i></a>
        </div>

        <div class="nu-grid">
            <div class="stack">
                <!-- Özet -->
                <section class="card card-pad fade-in">
                    <div class="sum">
                        <div class="ring <?= $r['calories'] < 0 ? 'over' : '' ?>" style="--p:<?= min(100, (float)$pct['calories']) ?>">
                            <div>
                                <b><?= $fmt(abs((float)$r['calories'])) ?></b>
                                <span><?= $r['calories'] >= 0 ? 'kcal kaldı' : 'kcal fazla' ?></span>
                            </div>
                        </div>
                        <div class="w-100">
                            <div class="d-flex justify-content-between align-items-baseline mb-3">
                                <div><span class="eyebrow">Alınan</span><div style="font-size:24px;font-weight:800;letter-spacing:-.02em"><?= $fmt($c['calories']) ?> <small style="font-size:13px;color:var(--muted);font-weight:500">/ <?= $fmt($t['calories']) ?> kcal</small></div></div>
                                <span class="chip <?= !empty($data['is_training']) ? 'purple' : '' ?>"><i class="bi <?= !empty($data['is_training']) ? 'bi-lightning-charge-fill' : 'bi-moon-stars' ?>"></i><?= !empty($data['is_training']) ? 'Antrenman' : 'Dinlenme' ?></span>
                            </div>
                            <div class="mrow">
                                <?php foreach ([['Protein', 'protein_g', '--c-protein'], ['Karb', 'carbs_g', '--c-carb'], ['Yağ', 'fat_g', '--c-fat']] as [$lbl, $k, $col]): ?>
                                    <div class="mcard" style="--mc:var(<?= $col ?>)">
                                        <div class="l"><?= $lbl ?></div>
                                        <div class="v"><?= $fmt($c[$k]) ?><small> / <?= $fmt($t[$k]) ?> g</small></div>
                                        <div class="bar"><span style="width:<?= min(100, (float)$pct[$k]) ?>%"></span></div>
                                        <div class="rem"><?= $r[$k] >= 0 ? $fmt($r[$k]) . ' g kaldı' : $fmt(abs((float)$r[$k])) . ' g fazla' ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($tip): ?><div class="tip"><i class="bi bi-lightbulb"></i><div><?= htmlspecialchars($tip) ?></div></div><?php endif; ?>
                </section>

                <!-- Öğünler -->
                <section class="card card-pad">
                    <div class="card-head">
                        <h2 class="card-title-sm"><i class="bi bi-journal-text"></i>Öğünler</h2>
                        <span class="chip"><?= count($meals) ?> kayıt</span>
                    </div>

                    <?php if (empty($meals)): ?>
                        <div class="empty-state">
                            <i class="bi bi-journal-plus"></i>
                            <?= $isToday ? 'Bugün henüz öğün eklenmedi.' : 'Bu gün için kayıt yok.' ?>
                            <div><button class="btn btn-primary btn-sm mt-3" onclick="OptiQuickAdd.open()"><i class="bi bi-plus-lg me-1"></i>Öğün ekle</button></div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($mealTypes as $mt => [$mlabel, $mic]):
                        if (empty($grouped[$mt])) continue;
                        $sum = array_sum(array_map(fn($x) => (float)$x['calories'], $grouped[$mt]));
                    ?>
                        <div class="meal-group">
                            <div class="meal-head">
                                <div class="h"><span><?= $mic ?></span><?= $mlabel ?></div>
                                <div class="k"><?= $fmt($sum) ?> kcal</div>
                            </div>
                            <?php foreach ($grouped[$mt] as $f): ?>
                                <div class="food" id="food-<?= (int)$f['id'] ?>">
                                    <div style="flex:1;min-width:0">
                                        <div class="nm"><?= htmlspecialchars($f['food_label']) ?></div>
                                        <div class="mc">
                                            <b style="color:var(--c-protein)">P</b> <?= $fmt($f['protein_g']) ?> g ·
                                            <b style="color:var(--c-carb)">K</b> <?= $fmt($f['carbs_g']) ?> g ·
                                            <b style="color:var(--c-fat)">Y</b> <?= $fmt($f['fat_g']) ?> g
                                        </div>
                                    </div>
                                    <div class="kc"><?= $fmt($f['calories']) ?> <small style="color:var(--muted);font-weight:500">kcal</small></div>
                                    <button class="icon-btn danger" style="border:0" title="Sil" onclick="deleteFood(<?= (int)$f['id'] ?>, <?= htmlspecialchars(json_encode($f['food_label']), ENT_QUOTES) ?>)"><i class="bi bi-trash3"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($meals)): ?>
                        <button class="add-meal-btn" onclick="OptiQuickAdd.open()"><i class="bi bi-plus-lg me-1"></i>Başka bir şey ekle</button>
                    <?php endif; ?>
                </section>
            </div>

            <div class="stack">
                <!-- Hızlı ekle -->
                <section class="card card-pad">
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-lightning"></i>Hızlı ekle</h2></div>
                    <div class="add-tiles">
                        <button class="add-tile" onclick="OptiQuickAdd.open({ tab: 'text' })"><i class="bi bi-stars"></i><b>Yazarak</b><span>Yapay zeka hesaplar</span></button>
                        <button class="add-tile" onclick="OptiQuickAdd.open({ tab: 'photo' })"><i class="bi bi-camera"></i><b>Fotoğraf</b><span>Tabağı çek, analiz et</span></button>
                        <button class="add-tile" onclick="OptiQuickAdd.open({ tab: 'frequent' })"><i class="bi bi-clock-history"></i><b>Sık yenenler</b><span>Tek dokunuşla</span></button>
                        <button class="add-tile" onclick="OptiQuickAdd.open({ tab: 'manual' })"><i class="bi bi-pencil"></i><b>Elle</b><span>Değerleri gir</span></button>
                    </div>
                </section>

                <!-- Makro dağılımı -->
                <section class="card card-pad">
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-pie-chart"></i>Enerji dağılımı</h2></div>
                    <?php if ($kP + $kC + $kF > 0): ?>
                        <div class="split-bar">
                            <span style="width:<?= $split['p'] ?>%;background:var(--c-protein)"></span>
                            <span style="width:<?= $split['c'] ?>%;background:var(--c-carb)"></span>
                            <span style="width:<?= $split['f'] ?>%;background:var(--c-fat)"></span>
                        </div>
                        <div class="legend">
                            <div><i style="background:var(--c-protein)"></i>Protein <b><?= $split['p'] ?>%</b></div>
                            <div><i style="background:var(--c-carb)"></i>Karb <b><?= $split['c'] ?>%</b></div>
                            <div><i style="background:var(--c-fat)"></i>Yağ <b><?= $split['f'] ?>%</b></div>
                        </div>
                        <div class="small mt-3" style="color:var(--muted)">Kalorinin makrolara göre dağılımı. Protein için genellikle %25–35 aralığı hedeflenir.</div>
                    <?php else: ?>
                        <div class="small" style="color:var(--muted)">Öğün ekledikçe protein, karbonhidrat ve yağın kaloriye katkısı burada görünür.</div>
                    <?php endif; ?>
                </section>

                <!-- Su özeti -->
                <?php if (!empty($data['water'])): $w = $data['water']; ?>
                <section class="card card-pad">
                    <div class="card-head">
                        <h2 class="card-title-sm"><i class="bi bi-droplet" style="color:var(--c-water)"></i>Su</h2>
                        <span class="small fw-semibold" style="color:var(--muted)"><?= $fmt($w['consumed_ml']) ?> / <?= $fmt($w['target_ml']) ?> ml</span>
                    </div>
                    <div class="bar"><span style="width:<?= min(100, (float)$w['pct']) ?>%;background:var(--c-water)"></span></div>
                    <?php if ($isToday): ?><a href="dashboard.php" class="small fw-semibold d-inline-block mt-2" style="color:var(--accent)">Su ekle →</a><?php endif; ?>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<?php require __DIR__ . '/includes/quick-add.php'; ?>
<script>
OptiQuickAdd.setDate(<?= json_encode($date) ?>);
document.addEventListener('opti:food-added', () => location.reload());

async function deleteFood(id, name) {
    const r = await Swal.fire({ icon: 'warning', title: 'Silinsin mi?', text: `"${name}" kaydı silinecek ve günlük toplamlardan düşülecek.`, showCancelButton: true, confirmButtonText: 'Sil', cancelButtonText: 'Vazgeç' });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('action', 'delete_meal');
    fd.append('meal_id', id);
    const res = await fetch(`${window.API_BASE}/dashboard.php`, { method: 'POST', body: fd, credentials: 'include' }).then(x => x.json());
    if (res.ok) location.reload();
    else Swal.fire({ icon: 'error', title: 'Silinemedi', text: res.error || '' });
}
</script>
</body>
</html>

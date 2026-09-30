<?php
declare(strict_types=1);

/**
 * OptiLifeSync - Haftalık Rapor (v2)
 *
 *  • Hafta hafta gezinme
 *  • Kalori / protein uyumu, su, antrenman, ilaç uyumu, egzersiz hacmi
 *  • Kilo trendi (son 90 gün)
 *  • Gün gün döküm (öğün detaylarıyla)
 *  • Yapay zeka koç değerlendirmesi (Gemini)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/DashboardService.php';
require_once __DIR__ . '/app/Services/MedicationTracker.php';
require_once __DIR__ . '/app/Services/ExerciseLogService.php';

use App\Services\DashboardService;
use App\Services\MedicationTracker;
use App\Services\ExerciseLogService;

$today = date('Y-m-d');
$ref = (string)($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
    $ref = $today;
}

$week = null; $adh = null; $weights = []; $vol = null; $waterByDate = [];
if ($pdo) {
    try {
        $week = DashboardService::getWeeklyBreakdown($pdo, (int)$userId, $ref);
        $adh = (new MedicationTracker($pdo))->getAdherence((int)$userId, 7, min($week['week_end'], $today));
        $weights = DashboardService::getWeightHistory($pdo, (int)$userId, 90);
        $exSvc = new ExerciseLogService($pdo);
        foreach ($exSvc->getWeeklyVolume((int)$userId, 26) as $wv) {
            if ($wv['week_start'] === $week['week_start']) { $vol = $wv; }
        }
        $st = $pdo->prepare("SELECT log_date, water_ml FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
        $st->execute([$userId, $week['week_start'], $week['week_end']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $waterByDate[substr((string)$r['log_date'], 0, 10)] = (int)$r['water_ml']; }
    } catch (\Throwable $e) {
        error_log('Rapor hatası: ' . $e->getMessage());
    }
}

$weekStart = $week['week_start'] ?? $today;
$weekEnd = $week['week_end'] ?? $today;
$prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$isCurrent = $today >= $weekStart && $today <= $weekEnd;
$trMonths = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$fmtD = fn($d) => (int)date('j', strtotime($d)) . ' ' . $trMonths[(int)date('n', strtotime($d))];
$shortDay = ['Pazartesi' => 'Pzt', 'Salı' => 'Sal', 'Çarşamba' => 'Çar', 'Perşembe' => 'Per', 'Cuma' => 'Cum', 'Cumartesi' => 'Cmt', 'Pazar' => 'Paz'];

// Haftalık özet
$days = $week['days'] ?? [];
$logged = array_values(array_filter($days, fn($d) => $d['consumed']['calories'] > 0));
$nLogged = count($logged);
$avg = fn($k) => $nLogged ? array_sum(array_map(fn($d) => (float)$d['consumed'][$k], $logged)) / $nLogged : 0;
$avgCal = $avg('calories'); $avgProt = $avg('protein_g'); $avgCarb = $avg('carbs_g'); $avgFat = $avg('fat_g');
$target = $days[0]['target'] ?? ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];
$onTarget = count(array_filter($logged, fn($d) => $d['target']['calories'] > 0 && abs($d['consumed']['calories'] - $d['target']['calories']) <= $d['target']['calories'] * 0.1));
$workoutsDone = count(array_filter($days, fn($d) => $d['workout_done']));
$workoutsPlanned = count(array_filter($days, fn($d) => !empty($d['workout']) || $d['workout_done']));
$waterVals = array_filter($waterByDate);
$avgWater = $waterVals ? array_sum($waterVals) / count($waterVals) : 0;
$weekBalance = array_sum(array_map(fn($d) => (float)$d['consumed']['calories'] - (float)$d['target']['calories'], $logged));

$fmt = fn($v) => number_format((float)$v, 0, ',', '.');
$chart = [
    'labels'  => array_map(fn($d) => $shortDay[$d['day_name']] ?? $d['day_name'], $days),
    'cal'     => array_map(fn($d) => round((float)$d['consumed']['calories']), $days),
    'target'  => array_map(fn($d) => round((float)$d['target']['calories']), $days),
    'prot'    => array_map(fn($d) => round((float)$d['consumed']['protein_g']), $days),
    'protT'   => array_map(fn($d) => round((float)$d['target']['protein_g']), $days),
    'water'   => array_map(fn($d) => $waterByDate[$d['date']] ?? 0, $days),
    'adh'     => array_map(fn($s) => $s['pct'], $adh['series'] ?? []),
    'adhLbl'  => array_map(fn($s) => $s['label'], $adh['series'] ?? []),
    'wLbl'    => array_map(fn($w) => date('d.m', strtotime($w['date'])), $weights),
    'w'       => array_map(fn($w) => $w['weight_kg'], $weights),
];
$mealLabels = ['breakfast' => 'Kahvaltı', 'lunch' => 'Öğle', 'dinner' => 'Akşam', 'snack' => 'Ara', 'pre_workout' => 'Ant. öncesi', 'post_workout' => 'Ant. sonrası'];
$v = '20260930';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Raporlar · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .card-pad { padding: 20px; }
        @media (max-width: 576px) { .card-pad { padding: 16px; } }
        .week-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .week-head h1 { font-size: 24px; margin: 0; letter-spacing: -.03em; }
        .week-head .sub { color: var(--muted); font-size: 13px; }
        .kpis { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
        @media (max-width: 1200px) { .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 576px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } }
        .kpi { padding: 16px; }
        .kpi .l { font-size: 12px; color: var(--muted); font-weight: 650; display: flex; align-items: center; gap: 6px; }
        .kpi .v { font-size: 24px; font-weight: 800; letter-spacing: -.03em; margin-top: 4px; font-variant-numeric: tabular-nums; }
        .kpi .v small { font-size: 12px; color: var(--muted); font-weight: 500; }
        .kpi .d { font-size: 12px; color: var(--muted); margin-top: 2px; }
        @media (max-width: 576px) { .kpi { padding: 12px; } .kpi .v { font-size: 19px; } }
        .rp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; align-items: start; }
        @media (max-width: 1000px) { .rp-grid { grid-template-columns: minmax(0, 1fr); } }
        .chart-box { position: relative; height: 240px; }
        .day-row { display: grid; grid-template-columns: 92px 1fr auto; gap: 12px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .day-row:last-child { border-bottom: 0; }
        .day-row.today { background: var(--accent-dim); margin: 0 -12px; padding: 12px; border-radius: 12px; border-bottom: 0; }
        .day-row .dn { font-weight: 700; }
        .day-row .dd { font-size: 12px; color: var(--muted); }
        .day-row .bars { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .day-row .bars .bar { height: 6px; }
        .day-row .meta { font-size: 12px; color: var(--muted); display: flex; gap: 10px; flex-wrap: wrap; }
        .day-row .meta b { color: var(--text); font-variant-numeric: tabular-nums; }
        .meal-chip { display: inline-flex; gap: 6px; align-items: center; padding: 6px 10px; border-radius: 10px; background: var(--surface-2); border: 1px solid var(--border); font-size: 12.5px; margin: 4px 4px 0 0; }
        .ai-box { line-height: 1.7; color: var(--text-2); }
        .ai-box h3, .ai-box h4 { font-size: 15px; margin: 16px 0 6px; color: var(--text); }
        .ai-box ul { padding-left: 20px; margin: 0 0 8px; }
        @media (max-width: 576px) { .day-row { grid-template-columns: 70px 1fr; } .day-row > :last-child { grid-column: 1 / -1; } }
    </style>
</head>
<body>
<?php $activePage = 'reports'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Raporlar</div>
                <div class="topbar-sub">Haftalık özet ve gelişim</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-accent" id="aiBtn"><i class="bi bi-stars"></i><span>Koç değerlendirmesi</span></button>
        </div>
    </header>

    <div class="content">
        <div class="week-head">
            <div>
                <h1><?= $isCurrent ? 'Bu hafta' : $fmtD($weekStart) . ' haftası' ?></h1>
                <div class="sub"><?= $fmtD($weekStart) ?> – <?= $fmtD($weekEnd) ?> <?= date('Y', strtotime($weekEnd)) ?> · <?= $nLogged ?>/7 gün kayıt</div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <a class="icon-btn" href="reports.php?date=<?= $prevWeek ?>" aria-label="Önceki hafta"><i class="bi bi-chevron-left"></i></a>
                <?php if (!$isCurrent): ?><a class="btn btn-light btn-sm" href="reports.php">Bu hafta</a><?php endif; ?>
                <a class="icon-btn <?= $isCurrent ? 'disabled' : '' ?>" style="<?= $isCurrent ? 'opacity:.35;pointer-events:none' : '' ?>" href="reports.php?date=<?= $nextWeek ?>" aria-label="Sonraki hafta"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>

        <!-- KPI -->
        <div class="kpis">
            <div class="card kpi">
                <div class="l"><i class="bi bi-fire" style="color:var(--c-kcal)"></i>Ort. kalori</div>
                <div class="v"><?= $fmt($avgCal) ?> <small>kcal</small></div>
                <div class="d">Hedef <?= $fmt($target['calories']) ?> · <?= $onTarget ?> gün ±%10</div>
            </div>
            <div class="card kpi">
                <div class="l"><i class="bi bi-egg" style="color:var(--c-protein)"></i>Ort. protein</div>
                <div class="v"><?= $fmt($avgProt) ?> <small>g</small></div>
                <div class="d">Hedef <?= $fmt($target['protein_g']) ?> g</div>
            </div>
            <div class="card kpi">
                <div class="l"><i class="bi bi-lightning-charge" style="color:var(--purple)"></i>Antrenman</div>
                <div class="v"><?= $workoutsDone ?><small> / <?= max($workoutsPlanned, $workoutsDone) ?></small></div>
                <div class="d"><?= $vol && $vol['volume'] ? $fmt($vol['volume']) . ' kg hacim' : 'Tamamlanan gün' ?></div>
            </div>
            <div class="card kpi">
                <div class="l"><i class="bi bi-capsule" style="color:var(--accent)"></i>İlaç uyumu</div>
                <div class="v"><?= ($adh['pct'] ?? null) === null ? '—' : '%' . $adh['pct'] ?></div>
                <div class="d"><?= (int)($adh['taken'] ?? 0) ?>/<?= (int)($adh['planned'] ?? 0) ?> doz</div>
            </div>
            <div class="card kpi">
                <div class="l"><i class="bi bi-droplet" style="color:var(--c-water)"></i>Ort. su</div>
                <div class="v"><?= $avgWater ? number_format($avgWater / 1000, 1, ',', '') : '—' ?> <small>L</small></div>
                <div class="d">Haftalık denge <?= $weekBalance > 0 ? '+' : '' ?><?= $fmt($weekBalance) ?> kcal</div>
            </div>
        </div>

        <!-- AI -->
        <section class="card card-pad mb-4" id="aiCard" hidden>
            <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-stars"></i>Koç değerlendirmesi</h2><span class="small" style="color:var(--muted)" id="aiTime"></span></div>
            <div class="ai-box" id="aiBox"></div>
        </section>

        <div class="rp-grid">
            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-fire" style="color:var(--c-kcal)"></i>Kalori</h2></div>
                <div class="chart-box"><canvas id="calChart"></canvas></div>
            </section>
            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-egg" style="color:var(--c-protein)"></i>Protein</h2></div>
                <div class="chart-box"><canvas id="protChart"></canvas></div>
            </section>
            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-capsule"></i>İlaç & takviye uyumu</h2><span class="chip">Son 7 gün</span></div>
                <div class="chart-box"><canvas id="adhChart"></canvas></div>
                <?php if (!empty($adh['by_supplement'])): ?>
                    <div class="mt-3">
                        <?php foreach ($adh['by_supplement'] as $s): if ($s['pct'] === null) continue; ?>
                            <div class="d-flex justify-content-between small mb-1"><span class="text-truncate me-2"><?= htmlspecialchars($s['name']) ?></span><b>%<?= $s['pct'] ?></b></div>
                            <div class="bar mb-2" style="height:6px"><span style="width:<?= $s['pct'] ?>%"></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-speedometer2"></i>Kilo</h2><span class="chip">Son 90 gün</span></div>
                <?php if (count($weights) >= 2): ?>
                    <div class="chart-box"><canvas id="weightChart"></canvas></div>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-graph-up"></i>Kilo trendi için en az iki gün kilonuzu kaydedin.<br><a href="dashboard.php" class="small fw-semibold" style="color:var(--accent)">Özet sayfasından kaydet →</a></div>
                <?php endif; ?>
            </section>
            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-droplet" style="color:var(--c-water)"></i>Su</h2></div>
                <div class="chart-box"><canvas id="waterChart"></canvas></div>
            </section>
            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-pie-chart"></i>Ortalama makro dağılımı</h2></div>
                <?php $kp = $avgProt * 4; $kc = $avgCarb * 4; $kf = $avgFat * 9; $ks = max(1, $kp + $kc + $kf); ?>
                <div class="chart-box" style="height:200px"><canvas id="macroChart"></canvas></div>
                <div class="d-flex justify-content-around mt-3 small">
                    <div class="text-center"><div style="color:var(--muted)">Protein</div><b><?= $fmt($avgProt) ?> g · %<?= round($kp / $ks * 100) ?></b></div>
                    <div class="text-center"><div style="color:var(--muted)">Karb</div><b><?= $fmt($avgCarb) ?> g · %<?= round($kc / $ks * 100) ?></b></div>
                    <div class="text-center"><div style="color:var(--muted)">Yağ</div><b><?= $fmt($avgFat) ?> g · %<?= round($kf / $ks * 100) ?></b></div>
                </div>
            </section>
        </div>

        <!-- Gün gün -->
        <section class="card card-pad mt-4">
            <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-list-ul"></i>Gün gün</h2></div>
            <?php foreach ($days as $i => $d):
                $c = (float)$d['consumed']['calories']; $t = (float)$d['target']['calories'];
                $pc = $t > 0 ? min(100, $c / $t * 100) : 0;
                $pp = $d['target']['protein_g'] > 0 ? min(100, $d['consumed']['protein_g'] / $d['target']['protein_g'] * 100) : 0;
                $diff = $c - $t;
            ?>
                <div class="day-row <?= $d['is_today'] ? 'today' : '' ?>">
                    <div><div class="dn"><?= $d['day_name'] ?></div><div class="dd"><?= $fmtD($d['date']) ?></div></div>
                    <div class="bars">
                        <div class="bar"><span style="width:<?= $pc ?>%;background:<?= $c > $t * 1.1 ? 'var(--red)' : 'var(--c-kcal)' ?>"></span></div>
                        <div class="bar"><span style="width:<?= $pp ?>%;background:var(--c-protein)"></span></div>
                        <div class="meta">
                            <span><b><?= $fmt($c) ?></b> / <?= $fmt($t) ?> kcal</span>
                            <span>P <b><?= $fmt($d['consumed']['protein_g']) ?></b> g</span>
                            <?php if (!empty($waterByDate[$d['date']])): ?><span>💧 <b><?= number_format($waterByDate[$d['date']] / 1000, 1, ',', '') ?></b> L</span><?php endif; ?>
                            <?php if ($d['workout_done']): ?><span style="color:var(--purple)"><i class="bi bi-lightning-charge-fill"></i> <?= htmlspecialchars($d['workout']['antrenman_tipi'] ?? 'Antrenman') ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <?php if ($c <= 0): ?>
                            <span class="small" style="color:var(--muted-2)">Kayıt yok</span>
                        <?php else: ?>
                            <span class="chip <?= abs($diff) <= $t * 0.1 ? 'accent' : ($diff > 0 ? 'yellow' : 'blue') ?>"><?= $diff > 0 ? '+' : '' ?><?= $fmt($diff) ?></span>
                            <?php if (!empty($d['food_logs'])): ?>
                                <button class="btn btn-link btn-sm p-0 ms-2" data-bs-toggle="collapse" data-bs-target="#meals-<?= $i ?>"><?= count($d['food_logs']) ?> öğün <i class="bi bi-chevron-down"></i></button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($d['food_logs'])): ?>
                    <div class="collapse" id="meals-<?= $i ?>">
                        <div class="pb-3">
                            <?php foreach ($d['food_logs'] as $f): ?>
                                <span class="meal-chip"><b><?= $mealLabels[$f['meal_type']] ?? '' ?></b> <?= htmlspecialchars($f['food_label']) ?> · <?= $fmt($f['calories']) ?> kcal</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const D = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;
const MACRO = <?= json_encode([round($kp), round($kc), round($kf)]) ?>;
const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const n0 = v => Math.round(v || 0).toLocaleString('tr-TR');
let charts = [];

function axis(t, extra = {}) {
    return { x: { grid: { display: false }, ticks: { color: t.muted }, border: { display: false } },
             y: Object.assign({ beginAtZero: true, grid: { color: t.grid }, ticks: { color: t.muted, maxTicksLimit: 5 }, border: { display: false } }, extra) };
}
function drawAll() {
    if (typeof Chart === 'undefined') return;
    charts.forEach(c => c.destroy()); charts = [];
    const t = { grid: cssVar('--border'), muted: cssVar('--muted'), kcal: cssVar('--c-kcal'), prot: cssVar('--c-protein'), carb: cssVar('--c-carb'), fat: cssVar('--c-fat'), water: cssVar('--c-water'), accent: cssVar('--accent-bright'), red: cssVar('--red') };
    const legend = { labels: { color: t.muted, usePointStyle: true, boxWidth: 8 } };
    const mk = (id, cfg) => { const el = document.getElementById(id); if (el) charts.push(new Chart(el, cfg)); };
    const target = (data, label) => ({ type: 'line', label, data, borderColor: t.muted, borderDash: [5, 4], pointRadius: 0, borderWidth: 1.5 });

    mk('calChart', { type: 'bar', data: { labels: D.labels, datasets: [
        { label: 'Alınan', data: D.cal, backgroundColor: D.cal.map((v, i) => v > D.target[i] * 1.1 ? t.red : t.kcal), borderRadius: 8, maxBarThickness: 30 },
        target(D.target, 'Hedef') ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend }, scales: axis(t) } });

    mk('protChart', { type: 'bar', data: { labels: D.labels, datasets: [
        { label: 'Protein (g)', data: D.prot, backgroundColor: t.prot, borderRadius: 8, maxBarThickness: 30 },
        target(D.protT, 'Hedef') ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend }, scales: axis(t) } });

    mk('adhChart', { type: 'bar', data: { labels: D.adhLbl, datasets: [
        { label: 'Uyum %', data: D.adh.map(v => v ?? 0), backgroundColor: D.adh.map(v => v === null ? t.grid : (v >= 80 ? t.accent : (v >= 50 ? t.carb : t.red))), borderRadius: 8, maxBarThickness: 30 } ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => D.adh[c.dataIndex] === null ? 'Planlı doz yok' : `%${c.raw}` } } }, scales: axis(t, { max: 100 }) } });

    mk('weightChart', { type: 'line', data: { labels: D.wLbl, datasets: [
        { label: 'Kilo (kg)', data: D.w, borderColor: t.accent, backgroundColor: t.accent + '22', fill: true, tension: .3, pointRadius: 2 } ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: axis(t, { beginAtZero: false }) } });

    mk('waterChart', { type: 'bar', data: { labels: D.labels, datasets: [
        { label: 'Su (ml)', data: D.water, backgroundColor: t.water, borderRadius: 8, maxBarThickness: 30 } ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: axis(t) } });

    mk('macroChart', { type: 'doughnut', data: { labels: ['Protein', 'Karbonhidrat', 'Yağ'], datasets: [
        { data: MACRO, backgroundColor: [t.prot, t.carb, t.fat], borderWidth: 0 } ] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'right', labels: { color: t.muted, usePointStyle: true } }, tooltip: { callbacks: { label: c => `${c.label}: ${n0(c.raw)} kcal` } } } } });
}
drawAll();
document.addEventListener('opti:theme', drawAll);

/* ── Basit ve güvenli Markdown → HTML ── */
function md(src) {
    const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const inline = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\*(.+?)\*/g, '<i>$1</i>');
    let html = '', inList = false;
    for (const raw of String(src).split('\n')) {
        const line = raw.trim();
        const li = line.match(/^([-*•]|\d+\.)\s+(.*)$/);
        if (li) { if (!inList) { html += '<ul>'; inList = true; } html += `<li>${inline(li[2])}</li>`; continue; }
        if (inList) { html += '</ul>'; inList = false; }
        const h = line.match(/^#{1,4}\s+(.*)$/);
        if (h) html += `<h4>${inline(h[1])}</h4>`;
        else if (line) html += `<p class="mb-2">${inline(line)}</p>`;
    }
    return html + (inList ? '</ul>' : '');
}

document.getElementById('aiBtn').addEventListener('click', async () => {
    const card = document.getElementById('aiCard'), box = document.getElementById('aiBox');
    card.hidden = false;
    box.innerHTML = '<div class="d-flex align-items-center gap-2" style="color:var(--muted)"><span class="spinner-border spinner-border-sm"></span>Haftanız değerlendiriliyor…</div>';
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    try {
        const fd = new FormData();
        fd.append('action', 'ai_analysis');
        fd.append('week_start', <?= json_encode($weekStart) ?>);
        fd.append('week_end', <?= json_encode($weekEnd) ?>);
        const res = await fetch(`${window.API_BASE}/reports.php`, { method: 'POST', body: fd, credentials: 'include' }).then(r => r.json());
        if (!res.ok) throw new Error(res.error || 'Değerlendirme alınamadı');
        box.innerHTML = md(res.advice);
        document.getElementById('aiTime').textContent = res.generated_at || '';
    } catch (e) {
        box.innerHTML = `<div class="alert alert-warning mb-0">${e.message.replace(/</g, '&lt;')}</div>`;
    }
});
</script>
</body>
</html>

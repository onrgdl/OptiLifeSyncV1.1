<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app/Services/MetabolismCalculator.php';
require_once __DIR__ . '/app/Services/ReminderService.php';
require_once __DIR__ . '/app/Services/DashboardService.php';

use App\Services\DashboardService;

$initialDashboardData = null;
$weeklyBreakdown = null;
$currentStreak = 0;
if ($pdo && isset($userId) && $userId > 0) {
    try {
        $initialDashboardData = DashboardService::getDashboardData($pdo, (int)$userId);
        $weeklyBreakdown      = DashboardService::getWeeklyBreakdown($pdo, (int)$userId);
        $currentStreak        = DashboardService::getStreak($pdo, (int)$userId);
    } catch (\Throwable $e) {
        error_log('Dashboard SSR hatası: ' . $e->getMessage());
    }
}

$hour = (int) date('H');
$greeting = $hour < 5 ? 'İyi geceler' : ($hour < 12 ? 'Günaydın' : ($hour < 18 ? 'İyi günler' : 'İyi akşamlar'));
$firstName = trim(explode(' ', (string)($currentUser['name'] ?? $currentUser['username'] ?? ''))[0] ?? '');
$defaultMeal = $hour < 11 ? 'breakfast' : ($hour < 16 ? 'lunch' : ($hour < 21 ? 'dinner' : 'snack'));
$trDays = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
$trMonths = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$todayLabel = $trDays[(int)date('w')] . ', ' . (int)date('j') . ' ' . $trMonths[(int)date('n')];
$v = '20260930';
$shortDay = ['Pazartesi' => 'Pzt', 'Salı' => 'Sal', 'Çarşamba' => 'Çar', 'Perşembe' => 'Per', 'Cuma' => 'Cum', 'Cumartesi' => 'Cmt', 'Pazar' => 'Paz'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Özet · OptiLifeSync</title>
<?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
<style>
    .dash { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 20px; }
    .span-12 { grid-column: span 12; } .span-8 { grid-column: span 8; } .span-7 { grid-column: span 7; }
    .span-6 { grid-column: span 6; } .span-5 { grid-column: span 5; } .span-4 { grid-column: span 4; }
    @media (max-width: 1200px) { .span-8, .span-7, .span-5, .span-4 { grid-column: span 12; } .span-6 { grid-column: span 6; } }
    @media (max-width: 768px) { .dash { gap: 14px; } .span-6 { grid-column: span 12; } }
    .card-pad { padding: 20px; }
    @media (max-width: 576px) { .card-pad { padding: 16px; } }

    /* Karşılama */
    .hello { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
    .hello h1 { font-size: 28px; margin: 0; letter-spacing: -.03em; }
    .hello .date { color: var(--muted); font-weight: 500; margin-top: 2px; }
    .hello-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    @media (max-width: 576px) { .hello h1 { font-size: 23px; } .hello { margin-bottom: 14px; } .mini-stat .v { font-size: 15px; } .mini-stat .l { font-size: 11px; } .big-ring { --size: 176px; } }
    .daytype { cursor: pointer; user-select: none; }
    .daytype.training { background: var(--purple-dim); color: var(--purple); border-color: transparent; }

    /* Enerji kartı */
    .energy { display: grid; grid-template-columns: auto 1fr; gap: 28px; align-items: center; }
    @media (max-width: 576px) { .energy { grid-template-columns: 1fr; gap: 20px; justify-items: center; } }
    .big-ring { --size: 196px; --w: 16px; }
    .big-ring .center { text-align: center; line-height: 1.1; }
    .big-ring .center .num { font-size: 38px; font-weight: 800; letter-spacing: -.04em; font-variant-numeric: tabular-nums; }
    .big-ring .center .lbl { font-size: 12.5px; color: var(--muted); font-weight: 600; margin-top: 4px; }
    .big-ring.over { --c: var(--red) !important; }
    .macro-list { display: flex; flex-direction: column; gap: 16px; width: 100%; }
    .macro .top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 7px; }
    .macro .name { font-weight: 650; font-size: 14px; display: flex; align-items: center; gap: 8px; }
    .macro .name::before { content: ''; width: 10px; height: 10px; border-radius: 3px; background: var(--mc); }
    .macro .val { font-size: 13px; color: var(--muted); font-variant-numeric: tabular-nums; }
    .macro .val b { color: var(--text); font-size: 15px; }
    .macro .bar > span { background: var(--mc); }
    .energy-foot { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 22px; }
    .mini-stat { background: var(--surface-2); border: 1px solid var(--border); border-radius: 14px; padding: 10px 12px; }
    .mini-stat .l { font-size: 11.5px; color: var(--muted); font-weight: 600; }
    .mini-stat .v { font-size: 17px; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }

    /* Dozlar */
    .dose-mini { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); }
    .dose-mini:last-child { border-bottom: 0; }
    .dose-mini .t { font-weight: 800; width: 48px; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .dose-mini .n { flex: 1; min-width: 0; }
    .dose-mini .n div:first-child { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dose-mini .n div:last-child { font-size: 12px; color: var(--muted); }
    .dose-mini.taken .n div:first-child { text-decoration: line-through; color: var(--muted); }
    .btn-take { background: var(--brand-grad); color: #fff; border: 0; border-radius: 10px; padding: 6px 12px; font-weight: 700; font-size: 12.5px; white-space: nowrap; }
    .ok-mark { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; background: var(--green-dim); color: var(--green); }

    /* Su */
    .water-wrap { display: flex; gap: 18px; align-items: center; }
    .bottle { width: 64px; height: 118px; border-radius: 18px 18px 22px 22px; border: 3px solid color-mix(in srgb, var(--c-water) 45%, transparent); position: relative; overflow: hidden; flex-shrink: 0; background: var(--surface-2); }
    .bottle::before { content: ''; position: absolute; top: -9px; left: 50%; transform: translateX(-50%); width: 26px; height: 10px; border-radius: 4px; background: color-mix(in srgb, var(--c-water) 45%, transparent); }
    .bottle .fill { position: absolute; left: 0; right: 0; bottom: 0; height: 0; background: linear-gradient(180deg, #67e8f9, var(--c-water)); transition: height .7s cubic-bezier(.2,.8,.2,1); }
    .bottle .fill::before { content: ''; position: absolute; top: -6px; left: -10%; width: 120%; height: 12px; background: radial-gradient(ellipse at center, rgba(255,255,255,.55), transparent 70%); }
    .water-num { font-size: 30px; font-weight: 800; letter-spacing: -.03em; color: var(--c-water); font-variant-numeric: tabular-nums; }
    .water-btns { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 16px; }
    .water-btns button { border: 1px solid var(--border); background: var(--surface-2); color: var(--text); border-radius: 12px; padding: 9px 4px; font-weight: 700; font-size: 13px; line-height: 1.15; }
    .water-btns button small { display: block; font-weight: 500; font-size: 10.5px; color: var(--muted); }
    .water-btns button:hover { border-color: var(--c-water); }
    .water-btns button:active { transform: scale(.96); }

    /* Kilo */
    .weight-num { font-size: 30px; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
    .spark { width: 100%; height: 46px; display: block; margin-top: 10px; }

    /* Öğünler */
    .meal-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
    .meal-row:last-child { border-bottom: 0; }
    .meal-ic { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; font-size: 17px; flex-shrink: 0; background: var(--surface-2); }
    .meal-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .meal-meta { font-size: 12px; color: var(--muted); }
    .meal-kcal { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* Hafta şeridi */
    .week-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
    .wday { text-align: center; padding: 10px 4px; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--border); }
    .wday.today { border-color: var(--accent-bright); background: var(--accent-dim); }
    .wday .d { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; }
    .wday .ring { --size: 42px; --w: 5px; margin: 8px auto 6px; }
    .wday .k { font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .wday .wk { font-size: 11px; color: var(--purple); height: 14px; }
    @media (max-width: 576px) { .week-strip { gap: 4px; } .wday { padding: 8px 2px; border-radius: 12px; } .wday .ring { --size: 34px; --w: 4px; } .wday .k { font-size: 10.5px; } }
    .chart-box { position: relative; height: 240px; }

    .skeleton { background: linear-gradient(90deg, var(--surface-2), var(--surface-3), var(--surface-2)); background-size: 200% 100%; animation: sk 1.2s infinite; border-radius: 12px; }
    @keyframes sk { to { background-position: -200% 0; } }
</style>
</head>
<body>
<?php $activePage = 'dashboard'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Özet</div>
                <div class="topbar-sub" id="dateLabel"><?= $todayLabel ?></div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-accent" data-bs-toggle="modal" data-bs-target="#quickAddModal">
                <i class="bi bi-plus-lg"></i><span>Öğün ekle</span>
            </button>
        </div>
    </header>

    <div class="content">
        <div class="hello fade-in">
            <div>
                <h1><?= $greeting ?><?= $firstName !== '' ? ', ' . htmlspecialchars($firstName) : '' ?> 👋</h1>
                <div class="date"><?= $todayLabel ?></div>
            </div>
            <div class="hello-actions">
                <?php if ($currentStreak > 0): ?>
                    <span class="chip yellow" title="Üst üste beslenme kaydı girilen gün sayısı"><i class="bi bi-fire"></i><?= (int)$currentStreak ?> gün seri</span>
                <?php endif; ?>
                <span class="chip daytype" id="workoutBadge" onclick="toggleWorkout()" title="Bugünü antrenman / dinlenme günü olarak işaretle">
                    <i class="bi bi-moon-stars"></i> Dinlenme günü
                </span>
            </div>
        </div>

        <div class="dash">
            <!-- ═══ ENERJİ ═══ -->
            <section class="card card-pad span-7 fade-in">
                <div class="card-head">
                    <h2 class="card-title-sm"><i class="bi bi-fire"></i>Bugünkü enerji</h2>
                    <a href="nutrition.php" class="small fw-semibold" style="color:var(--accent)">Beslenme →</a>
                </div>
                <div class="energy">
                    <div class="ring big-ring" id="calRing" style="--c:var(--c-kcal)">
                        <div class="center">
                            <div class="num" id="kpiCalRem">—</div>
                            <div class="lbl" id="kpiCalRemLbl">kcal kaldı</div>
                        </div>
                    </div>
                    <div class="macro-list">
                        <div class="macro" style="--mc:var(--c-kcal)">
                            <div class="top"><span class="name">Kalori</span><span class="val"><b id="kpiCalVal">—</b> / <span id="kpiCalTarget">—</span> kcal</span></div>
                            <div class="bar"><span id="kpiCalBar" style="width:0"></span></div>
                        </div>
                        <div class="macro" style="--mc:var(--c-protein)">
                            <div class="top"><span class="name">Protein</span><span class="val"><b id="kpiProtVal">—</b> / <span id="kpiProtTarget">—</span> g</span></div>
                            <div class="bar"><span id="kpiProtBar" style="width:0"></span></div>
                        </div>
                        <div class="macro" style="--mc:var(--c-carb)">
                            <div class="top"><span class="name">Karbonhidrat</span><span class="val"><b id="kpiCarbVal">—</b> / <span id="kpiCarbTarget">—</span> g</span></div>
                            <div class="bar"><span id="kpiCarbBar" style="width:0"></span></div>
                        </div>
                        <div class="macro" style="--mc:var(--c-fat)">
                            <div class="top"><span class="name">Yağ</span><span class="val"><b id="kpiFatVal">—</b> / <span id="kpiFatTarget">—</span> g</span></div>
                            <div class="bar"><span id="kpiFatBar" style="width:0"></span></div>
                        </div>
                    </div>
                </div>
                <div class="energy-foot">
                    <div class="mini-stat"><div class="l">Bazal (BMR)</div><div class="v" id="bmrVal">—</div></div>
                    <div class="mini-stat"><div class="l">Günlük harcama</div><div class="v" id="tdeeVal">—</div></div>
                    <div class="mini-stat"><div class="l">Protein kalan</div><div class="v" id="kpiProtRem">—</div></div>
                </div>
            </section>

            <!-- ═══ DOZLAR ═══ -->
            <section class="card card-pad span-5 fade-in">
                <div class="card-head">
                    <h2 class="card-title-sm"><i class="bi bi-capsule-pill"></i>İlaç & takviye</h2>
                    <span class="chip accent" id="doseChip">—</span>
                </div>
                <div id="doseMiniList"><div class="skeleton" style="height:120px"></div></div>
                <a href="reminders.php" class="btn btn-light btn-sm w-100 mt-3">Tümünü yönet <i class="bi bi-arrow-right ms-1"></i></a>
            </section>

            <!-- ═══ SU ═══ -->
            <section class="card card-pad span-6 fade-in">
                <div class="card-head">
                    <h2 class="card-title-sm"><i class="bi bi-droplet" style="color:var(--c-water)"></i>Su</h2>
                    <div class="d-flex gap-1">
                        <button class="icon-btn" onclick="quickAddWater(-250)" title="Son bardağı geri al"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button class="icon-btn" onclick="promptCustomWater()" title="Özel miktar"><i class="bi bi-pencil"></i></button>
                    </div>
                </div>
                <div class="water-wrap">
                    <div class="bottle"><div class="fill" id="waterFill"></div></div>
                    <div style="min-width:0">
                        <div><span class="water-num" id="waterConsumedVal">0</span> <span style="color:var(--muted)">/ <span id="waterTargetVal">—</span> ml</span></div>
                        <div class="small mt-1" style="color:var(--text-2)" id="waterStatusMsg">Kalan <b id="waterRemainingVal">—</b></div>
                        <div class="small mt-1" style="color:var(--green)" id="waterBonusBadge" hidden><i class="bi bi-lightning-charge-fill me-1"></i>Antrenman günü: +500 ml</div>
                    </div>
                </div>
                <div class="water-btns">
                    <button onclick="quickAddWater(200)">+200<small>bardak</small></button>
                    <button onclick="quickAddWater(330)">+330<small>kutu</small></button>
                    <button onclick="quickAddWater(500)">+500<small>şişe</small></button>
                    <button onclick="quickAddWater(1000)">+1 L<small>sürahi</small></button>
                </div>
            </section>

            <!-- ═══ KİLO ═══ -->
            <section class="card card-pad span-6 fade-in">
                <div class="card-head">
                    <h2 class="card-title-sm"><i class="bi bi-speedometer2"></i>Kilo</h2>
                    <span class="chip" id="weightChangeChip">—</span>
                </div>
                <div class="d-flex align-items-end justify-content-between gap-3">
                    <div>
                        <div><span class="weight-num" id="weightNow">—</span> <span style="color:var(--muted)">kg</span></div>
                        <div class="small" style="color:var(--muted)" id="weightMeta">—</div>
                    </div>
                    <form class="d-flex gap-2" onsubmit="saveWeight(event)">
                        <input type="number" step="0.1" min="25" max="350" inputmode="decimal" class="form-control" id="weightInput" placeholder="kg" style="width:96px">
                        <button class="btn btn-primary" type="submit" title="Kaydet"><i class="bi bi-check-lg"></i></button>
                    </form>
                </div>
                <svg class="spark" id="weightSpark" viewBox="0 0 300 46" preserveAspectRatio="none"></svg>
            </section>

            <!-- ═══ ÖĞÜNLER ═══ -->
            <section class="card card-pad span-6 fade-in">
                <div class="card-head">
                    <h2 class="card-title-sm"><i class="bi bi-egg-fried"></i>Bugün yediklerim</h2>
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#quickAddModal"><i class="bi bi-plus-lg"></i> Ekle</button>
                </div>
                <div id="mealList"><div class="skeleton" style="height:44px;margin-bottom:8px"></div><div class="skeleton" style="height:44px"></div></div>
            </section>

            <!-- ═══ HAFTA ═══ -->
            <section class="card card-pad span-6 fade-in">
                <div class="card-head">
                    <h2 class="card-title-sm"><i class="bi bi-calendar-week"></i>Bu hafta</h2>
                    <a href="reports.php" class="small fw-semibold" style="color:var(--accent)">Rapor →</a>
                </div>
                <?php if (!empty($weeklyBreakdown)): ?>
                    <div class="week-strip">
                        <?php foreach ($weeklyBreakdown['days'] as $d):
                            $t = (float)$d['target']['calories'];
                            $c = (float)$d['consumed']['calories'];
                            $p = $t > 0 ? min(100, round($c / $t * 100)) : 0;
                            $over = $t > 0 && $c > $t * 1.1;
                        ?>
                            <div class="wday <?= $d['is_today'] ? 'today' : '' ?>" title="<?= $d['day_name'] ?>: <?= number_format($c, 0, ',', '.') ?> / <?= number_format($t, 0, ',', '.') ?> kcal">
                                <div class="d"><?= $shortDay[$d['day_name']] ?? mb_substr($d['day_name'], 0, 3) ?></div>
                                <div class="ring" style="--p:<?= $p ?>;--c:<?= $over ? 'var(--red)' : 'var(--c-kcal)' ?>"></div>
                                <div class="k"><?= $c > 0 ? number_format($c / 1000, 1, ',', '') . 'k' : '—' ?></div>
                                <div class="wk"><?= $d['workout_done'] ? '<i class="bi bi-lightning-charge-fill"></i>' : '' ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-box mt-3"><canvas id="weeklyTrendChart"></canvas></div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php require __DIR__ . '/includes/quick-add.php'; ?>
<script>
/* ── Yardımcılar ─────────────────────────────────────────── */
const fmt = v => parseFloat(v || 0).toLocaleString('tr-TR', { maximumFractionDigits: 1 });
const fmt0 = v => Math.round(parseFloat(v || 0)).toLocaleString('tr-TR');
const setText = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const toast = (title, icon = 'success') => window.Swal && Swal.fire({ toast: true, position: 'top', icon, title, showConfirmButton: false, timer: 1800 });

async function apiPost(action, extra = {}, endpoint = 'dashboard.php') {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(extra)) fd.append(k, v);
    const r = await fetch(`${window.API_BASE}/${endpoint}`, { method: 'POST', body: fd, credentials: 'include' });
    return r.json();
}

/* ── Durum ───────────────────────────────────────────────── */
let dashData = <?= $initialDashboardData ? json_encode($initialDashboardData, JSON_UNESCAPED_UNICODE) : 'null' ?>;
let authFailureStreak = 0;

async function loadDashboard() {
    try {
        const data = await apiPost('load');
        if (!data.ok) {
            if (data.require_login && ++authFailureStreak >= 3) { location.href = 'login.php?redirect=dashboard.php'; return; }
            setTimeout(loadDashboard, 4000);
            return;
        }
        authFailureStreak = 0;
        dashData = data;
        renderDashboard(data);
    } catch (e) {
        setTimeout(loadDashboard, 4000);
    }
}

function renderDashboard(d) {
    if (!d) return;
    // Enerji
    const rem = d.remaining.calories;
    const ring = document.getElementById('calRing');
    ring.style.setProperty('--p', Math.min(100, d.progress_pct.calories));
    ring.classList.toggle('over', rem < 0);
    setText('kpiCalRem', fmt0(Math.abs(rem)));
    setText('kpiCalRemLbl', rem >= 0 ? 'kcal kaldı' : 'kcal fazla');
    const macro = (k, c, t, p) => {
        setText(`kpi${k}Val`, fmt0(c)); setText(`kpi${k}Target`, fmt0(t));
        const b = document.getElementById(`kpi${k}Bar`); if (b) b.style.width = Math.min(100, p) + '%';
    };
    macro('Cal', d.consumed.calories, d.target.calories, d.progress_pct.calories);
    macro('Prot', d.consumed.protein_g, d.target.protein_g, d.progress_pct.protein_g);
    macro('Carb', d.consumed.carbs_g, d.target.carbs_g, d.progress_pct.carbs_g);
    macro('Fat', d.consumed.fat_g, d.target.fat_g, d.progress_pct.fat_g);
    setText('bmrVal', fmt0(d.bmr) + ' kcal');
    setText('tdeeVal', fmt0(d.tdee) + ' kcal');
    setText('kpiProtRem', d.remaining.protein_g > 0 ? fmt0(d.remaining.protein_g) + ' g' : 'Tamam ✓');

    // Antrenman günü
    const wb = document.getElementById('workoutBadge');
    wb.classList.toggle('training', !!d.is_training);
    wb.innerHTML = d.is_training ? '<i class="bi bi-lightning-charge-fill"></i> Antrenman günü' : '<i class="bi bi-moon-stars"></i> Dinlenme günü';

    renderDoses(d.doses);
    renderWater(d.water);
    renderWeight(d.weight);
    renderMeals(d.recent_meals || []);
}

/* ── Dozlar ──────────────────────────────────────────────── */
function renderDoses(doses) {
    const el = document.getElementById('doseMiniList');
    const chip = document.getElementById('doseChip');
    if (!doses || !doses.doses || !doses.doses.length) {
        chip.textContent = '—';
        el.innerHTML = `<div class="empty-state py-3"><i class="bi bi-capsule"></i>Bugün planlı doz yok.<br><a href="reminders.php" class="small fw-semibold" style="color:var(--accent)">İlaç / takviye ekle →</a></div>`;
        return;
    }
    const s = doses.summary;
    chip.textContent = `${s.taken}/${s.total} alındı`;
    // Önce bekleyenler, sonra alınanlar; en fazla 5 satır
    const order = { due: 0, missed: 1, pending: 2, skipped: 3, taken: 4 };
    const list = [...doses.doses].sort((a, b) => (order[a.status] - order[b.status]) || a.time.localeCompare(b.time)).slice(0, 5);
    el.innerHTML = list.map(x => {
        const done = x.status === 'taken' || x.status === 'skipped';
        const tag = x.status === 'missed' ? '<span class="chip red" style="padding:1px 8px;font-size:11px">Kaçırıldı</span>' : x.status === 'due' ? '<span class="chip accent" style="padding:1px 8px;font-size:11px">Şimdi</span>' : '';
        return `<div class="dose-mini ${x.status}">
            <div class="t">${esc(x.time)}</div>
            <div class="n"><div>${esc(x.name)}</div><div>${esc(x.dose)} ${tag}</div></div>
            ${done ? `<div class="ok-mark" title="${x.status === 'taken' ? 'Alındı' : 'Atlandı'}"><i class="bi ${x.status === 'taken' ? 'bi-check2' : 'bi-dash'}"></i></div>`
                   : `<button class="btn-take" onclick="takeDose(${x.supplement_id}, '${x.time}')">Aldım</button>`}
        </div>`;
    }).join('');
}

async function takeDose(suppId, time) {
    const res = await apiPost('log_dose', { supplement_id: suppId, scheduled_time: time, status: 'taken' }, 'reminders.php');
    if (res.ok) { toast('Alındı olarak işaretlendi'); loadDashboard(); }
    else toast(res.error || 'Kaydedilemedi', 'error');
}
document.addEventListener('opti:dose-logged', loadDashboard);
document.addEventListener('opti:doses-synced', loadDashboard);

/* ── Su ──────────────────────────────────────────────────── */
function renderWater(w) {
    if (!w) return;
    setText('waterConsumedVal', fmt0(w.consumed_ml));
    setText('waterTargetVal', fmt0(w.target_ml));
    document.getElementById('waterFill').style.height = Math.min(100, w.pct) + '%';
    document.getElementById('waterStatusMsg').innerHTML = w.remaining_ml > 0
        ? `Kalan <b>${fmt0(w.remaining_ml)} ml</b> · yaklaşık ${Math.ceil(w.remaining_ml / 250)} bardak`
        : '<b style="color:var(--green)">Günlük hedef tamamlandı 🎉</b>';
    document.getElementById('waterBonusBadge').hidden = !(w.workout_bonus > 0);
}

async function quickAddWater(amount) {
    try {
        const data = await apiPost('add_water', { amount });
        if (!data.ok) throw new Error(data.error);
        if (dashData?.water) {
            Object.assign(dashData.water, { consumed_ml: data.water_ml, target_ml: data.target_ml, pct: data.pct, remaining_ml: data.remaining_ml, workout_bonus: data.workout_bonus });
            renderWater(dashData.water);
        }
        toast(data.message, amount >= 0 ? 'success' : 'info');
    } catch (e) { toast(e.message || 'Hata', 'error'); }
}

async function promptCustomWater() {
    const { value: ml } = await Swal.fire({
        title: 'Özel miktar', input: 'number', inputLabel: 'Eklenecek su (ml)', inputPlaceholder: 'ör. 400',
        showCancelButton: true, confirmButtonText: 'Ekle', cancelButtonText: 'Vazgeç',
        inputValidator: v => (!v || parseInt(v, 10) <= 0) ? 'Geçerli bir miktar girin' : undefined
    });
    if (ml) quickAddWater(parseInt(ml, 10));
}

/* ── Kilo ────────────────────────────────────────────────── */
function renderWeight(w) {
    if (!w) return;
    setText('weightNow', w.current ? fmt(w.current) : '—');
    document.getElementById('weightInput').placeholder = w.current ? String(w.current) : 'kg';
    const meta = w.logged_today ? 'Bugün kaydedildi' : (w.last_date ? `Son kayıt: ${new Date(w.last_date + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })}` : 'Henüz kayıt yok — bugün tartılın');
    setText('weightMeta', meta);
    const chip = document.getElementById('weightChangeChip');
    const ch = w.change_7d ?? w.change_30d;
    if (ch === null || ch === undefined) { chip.textContent = 'Değişim —'; chip.className = 'chip'; }
    else {
        const good = (w.goal === 'lose' && ch < 0) || (w.goal === 'gain' && ch > 0) || (w.goal === 'maintain' && Math.abs(ch) <= 0.5);
        chip.className = 'chip ' + (good ? 'accent' : (ch === 0 ? '' : 'yellow'));
        chip.innerHTML = `<i class="bi ${ch < 0 ? 'bi-arrow-down-right' : ch > 0 ? 'bi-arrow-up-right' : 'bi-dash'}"></i>${ch > 0 ? '+' : ''}${fmt(ch)} kg · ${w.change_7d !== null ? '7 gün' : '30 gün'}`;
    }
    // Mini grafik
    const svg = document.getElementById('weightSpark');
    const pts = w.spark || [];
    if (pts.length < 2) { svg.innerHTML = ''; return; }
    const min = Math.min(...pts) - .3, max = Math.max(...pts) + .3;
    const xy = pts.map((v, i) => [i / (pts.length - 1) * 300, 42 - (v - min) / (max - min) * 38]);
    const line = xy.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
    svg.innerHTML = `<defs><linearGradient id="wg" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${cssVar('--accent-bright')}" stop-opacity=".25"/><stop offset="1" stop-color="${cssVar('--accent-bright')}" stop-opacity="0"/></linearGradient></defs>
        <path d="${line} L300,46 L0,46 Z" fill="url(#wg)"/><path d="${line}" fill="none" stroke="${cssVar('--accent-bright')}" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>`;
}

async function saveWeight(e) {
    e.preventDefault();
    const inp = document.getElementById('weightInput');
    const val = inp.value.trim().replace(',', '.');
    if (!val) return;
    const res = await apiPost('set_weight', { weight_kg: val });
    if (!res.ok) { toast(res.error || 'Kaydedilemedi', 'error'); return; }
    inp.value = '';
    toast('Kilo kaydedildi');
    loadDashboard();
}

/* ── Öğünler ─────────────────────────────────────────────── */
const MEAL = {
    breakfast: ['🌅', 'Kahvaltı'], lunch: ['☀️', 'Öğle'], dinner: ['🌙', 'Akşam'],
    snack: ['🍎', 'Ara öğün'], pre_workout: ['⚡', 'Antrenman öncesi'], post_workout: ['💪', 'Antrenman sonrası']
};
function renderMeals(meals) {
    const el = document.getElementById('mealList');
    if (!meals.length) {
        el.innerHTML = `<div class="empty-state py-3"><i class="bi bi-journal-plus"></i>Bugün henüz öğün eklenmedi.<br>
            <button class="btn btn-primary btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#quickAddModal"><i class="bi bi-plus-lg me-1"></i>İlk öğünü ekle</button></div>`;
        return;
    }
    el.innerHTML = meals.map(m => {
        const [ic, lb] = MEAL[m.meal_type] || ['🍽️', 'Öğün'];
        const time = m.time || '';
        return `<div class="meal-row">
            <div class="meal-ic">${ic}</div>
            <div style="flex:1;min-width:0">
                <div class="meal-name" title="${esc(m.food_label)}">${esc(m.food_label)}</div>
                <div class="meal-meta">${lb}${time ? ' · ' + esc(time) : ''} · P ${fmt0(m.protein_g)} · K ${fmt0(m.carbs_g)} · Y ${fmt0(m.fat_g)}</div>
            </div>
            <div class="meal-kcal">${fmt0(m.calories)} <small style="color:var(--muted);font-weight:500">kcal</small></div>
            <button class="icon-btn danger" style="border:0" onclick="deleteDashboardMeal(${parseInt(m.id, 10)})" title="Sil"><i class="bi bi-trash3"></i></button>
        </div>`;
    }).join('');
}

async function deleteDashboardMeal(mealId) {
    const r = await Swal.fire({ icon: 'warning', title: 'Öğün silinsin mi?', text: 'Günlük kalori ve makrolardan düşülecek.', showCancelButton: true, confirmButtonText: 'Sil', cancelButtonText: 'Vazgeç' });
    if (!r.isConfirmed) return;
    const data = await apiPost('delete_meal', { meal_id: mealId });
    if (data.ok) { toast('Öğün silindi'); loadDashboard(); } else toast(data.error || 'Silinemedi', 'error');
}

/* ── Antrenman / dinlenme ────────────────────────────────── */
async function toggleWorkout() {
    const data = await apiPost('toggle_workout');
    if (data.ok) { toast(data.workout_done ? 'Antrenman günü olarak işaretlendi' : 'Dinlenme günü'); loadDashboard(); }
}

document.addEventListener('opti:food-added', loadDashboard);

/* ── Haftalık grafik ─────────────────────────────────────── */
<?php if (!empty($weeklyBreakdown)): ?>
const WEEK = <?= json_encode(array_map(fn($d) => ['l' => $shortDay[$d['day_name']] ?? mb_substr($d['day_name'], 0, 3), 'c' => round((float)$d['consumed']['calories']), 't' => round((float)$d['target']['calories'])], $weeklyBreakdown['days']), JSON_UNESCAPED_UNICODE) ?>;
let weekChart = null;
function drawWeekChart() {
    const ctx = document.getElementById('weeklyTrendChart');
    if (!ctx || typeof Chart === 'undefined') return;
    if (weekChart) weekChart.destroy();
    const grid = cssVar('--border'), muted = cssVar('--muted'), kcal = cssVar('--c-kcal'), text = cssVar('--text');
    weekChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: WEEK.map(d => d.l),
            datasets: [
                { type: 'bar', label: 'Alınan', data: WEEK.map(d => d.c), backgroundColor: kcal, borderRadius: 8, maxBarThickness: 28 },
                { type: 'line', label: 'Hedef', data: WEEK.map(d => d.t), borderColor: muted, borderDash: [5, 4], pointRadius: 0, borderWidth: 1.5, tension: 0 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: muted, boxWidth: 10, boxHeight: 10, usePointStyle: true } }, tooltip: { callbacks: { label: c => `${c.dataset.label}: ${fmt0(c.raw)} kcal` } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: muted }, border: { display: false } },
                y: { beginAtZero: true, grid: { color: grid }, ticks: { color: muted, maxTicksLimit: 5 }, border: { display: false } }
            }
        }
    });
}
drawWeekChart();
document.addEventListener('opti:theme', () => { drawWeekChart(); if (dashData) renderWeight(dashData.weight); });
<?php endif; ?>

/* ── Başlat ──────────────────────────────────────────────── */
if (dashData) renderDashboard(dashData); else loadDashboard();
setInterval(() => { if (!document.hidden) loadDashboard(); }, 60000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadDashboard(); });
</script>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app/Services/DashboardService.php';

use App\Services\DashboardService;

$initialDashboardData = null;
$weeklyBreakdown = null;
if ($pdo && isset($userId) && $userId > 0) {
    try {
        $initialDashboardData = DashboardService::getDashboardData($pdo, (int)$userId);
        $weeklyBreakdown      = DashboardService::getWeeklyBreakdown($pdo, (int)$userId);
    } catch (\Throwable $e) {
        $initialDashboardData = null;
        $weeklyBreakdown      = null;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>OptiLifeSync — Dashboard</title>
<?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/sidebar.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="assets/js/alarm-engine.js"></script>

<style>
/* ═══════════════════════════════════════════════════════════
   GLOBAL RESET & TOKENS
═══════════════════════════════════════════════════════════ */
:root {
    --bg:          #0f172a;
    --surface:     #1e293b;
    --surface-2:   #283548;
    --border:      rgba(255,255,255,.09);
    --accent:      #38bdf8;
    --accent-dim:  rgba(56,189,248,.12);
    --green:       #22c55e;
    --red:         #f87171;
    --yellow:      #facc15;
    --purple:      #c084fc;
    --text:        #f8fafc;
    --muted:       #94a3b8;
    --sidebar-w:   240px;
}
*, *::before, *::after { box-sizing: border-box; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    max-width: 100vw;
    overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }
button { cursor: pointer; border: none; background: none; }

/* TOPBAR */
.topbar {
    position: sticky; top: 0; z-index: 50;
    background: rgba(8,15,30,.85);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    height: 60px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px;
}
.topbar-left { display: flex; align-items: center; gap: 12px; }
.topbar-title { font-size: 16px; font-weight: 600; }
.topbar-sub   { font-size: 12px; color: var(--muted); }
.topbar-right { display: flex; align-items: center; gap: 8px; }

.btn-topbar {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    border-radius: 10px;
    font-size: 13px; font-weight: 500;
    transition: all .15s;
}
.btn-accent {
    background: var(--accent); color: #0c1a27;
}
.btn-accent:hover { background: #7dd3fc; }
.btn-ghost  {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
}
.btn-ghost:hover { border-color: var(--accent); color: var(--accent); }

/* CONTENT AREA */
.content { padding: 24px; flex: 1; }

/* SECTION HEADER */
.section-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px;
}
.section-title {
    font-size: 15px; font-weight: 600; color: var(--text);
    display: flex; align-items: center; gap: 8px;
}

/* ═══════════════════════════════════════════════════════════
   CARDS & TILES
═══════════════════════════════════════════════════════════ */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
}
.card-body { padding: 20px; }

/* KPI tiles — üstteki 4 büyük kart */
.kpi-tile {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px 20px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s, transform .15s;
}
.kpi-tile:hover { border-color: rgba(255,255,255,.15); transform: translateY(-1px); }
.kpi-tile .glow {
    position: absolute; top: -40px; right: -40px;
    width: 120px; height: 120px;
    border-radius: 50%;
    opacity: .12;
    filter: blur(30px);
}
.kpi-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin-bottom: 6px; }
.kpi-value { font-size: 28px; font-weight: 700; line-height: 1; margin-bottom: 4px; }
.kpi-sub   { font-size: 12px; color: var(--muted); }

/* Progress Bar */
.gyp-progress {
    height: 8px; background: rgba(255,255,255,.06);
    border-radius: 99px; overflow: hidden; margin: 10px 0 6px;
}
.gyp-progress-fill {
    height: 100%; border-radius: 99px;
    transition: width .6s cubic-bezier(.34,1.56,.64,1);
}

/* Donut / Ring */
.ring-wrap { position: relative; width: 72px; height: 72px; flex-shrink:0; }
.ring-wrap svg { transform: rotate(-90deg); }
.ring-center {
    position: absolute; inset: 0;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; line-height: 1.1;
}
.ring-center small { font-size: 9px; color: var(--muted); font-weight: 400; }

/* Workout badge */
.workout-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px;
    border-radius: 99px;
    font-size: 12px; font-weight: 600;
    transition: all .2s;
}
.workout-badge.on  { background: rgba(34,197,94,.15); border: 1px solid rgba(34,197,94,.35); color: var(--green); }
.workout-badge.off { background: rgba(100,116,139,.1); border: 1px solid rgba(100,116,139,.2); color: var(--muted); }

/* Alarm item */
.alarm-item {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 16px;
    border-radius: 12px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    margin-bottom: 8px;
    transition: border-color .15s;
}
.alarm-item:hover { border-color: rgba(255,255,255,.12); }
.alarm-time {
    font-size: 14px; font-weight: 700;
    color: var(--accent); min-width: 40px;
}
.alarm-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.alarm-icon.med   { background: rgba(248,113,113,.15); color: var(--red);    }
.alarm-icon.supp  { background: rgba(34,197,94,.15);   color: var(--green);  }
.alarm-icon.vit   { background: rgba(250,204,21,.15);  color: var(--yellow); }
.alarm-label  { font-size: 13px; font-weight: 500; }
.alarm-dose   { font-size: 11px; color: var(--muted); }
.alarm-badge  {
    margin-left: auto;
    background: rgba(56,189,248,.1);
    border: 1px solid rgba(56,189,248,.25);
    color: var(--accent);
    border-radius: 99px; padding: 3px 10px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}

/* Meal row */
.meal-row {
    display: flex; align-items: center;
    padding: 10px 14px; gap: 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    margin-bottom: 6px;
}
.meal-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.meal-name { font-size: 13px; font-weight: 500; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.meal-type { font-size: 11px; color: var(--muted); }
.meal-cal  { font-size: 13px; font-weight: 600; color: var(--red); margin-left: auto; white-space: nowrap; }

/* Empty state */
.empty-state {
    text-align: center; padding: 32px 20px; color: var(--muted);
}
.empty-state i { font-size: 32px; display: block; margin-bottom: 8px; }

/* ═══════════════════════════════════════════════════════════
   QUICK ADD MODAL (Bootstrap base + custom)
═══════════════════════════════════════════════════════════ */
.modal-content {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    color: var(--text);
}
.modal-header { border-bottom: 1px solid var(--border); padding: 18px 22px; }
.modal-body   { padding: 20px 22px; }
.modal-footer { border-top: 1px solid var(--border); padding: 14px 22px; }

.search-input {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    color: var(--text);
    padding: 12px 16px;
    width: 100%;
    font-size: 14px;
    outline: none;
    transition: border-color .2s;
}
.search-input:focus { border-color: var(--accent); }
.search-input::placeholder { color: var(--muted); }

.result-tabs .tab-btn {
    background: none; border: none;
    padding: 6px 14px; border-radius: 8px;
    color: var(--muted); font-size: 13px; font-weight: 500;
    transition: all .15s;
}
.result-tabs .tab-btn.active { background: var(--accent-dim); color: var(--accent); }

.result-item {
    display: flex; align-items: center;
    padding: 12px 14px; gap: 12px;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    margin-bottom: 6px;
    cursor: pointer;
    transition: border-color .15s, transform .1s;
}
.result-item:hover { border-color: rgba(56,189,248,.4); transform: translateX(2px); }
.result-item.selected { border-color: var(--accent); background: var(--accent-dim); }

.result-icon {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.result-icon.food { background: rgba(250,204,21,.1); }
.result-icon.supp { background: rgba(34,197,94,.1); }

.result-name  { font-size: 13px; font-weight: 500; }
.result-meta  { font-size: 11px; color: var(--muted); }
.result-kcal  { margin-left: auto; font-size: 12px; font-weight: 600; color: var(--red); }

/* Quantity row in modal */
.qty-row { display: flex; gap: 10px; align-items: center; margin-top: 14px; }
.qty-input {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 10px; color: var(--text);
    padding: 9px 12px; width: 90px; outline: none;
    transition: border-color .2s;
}
.qty-input:focus { border-color: var(--accent); }
.meal-select {
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 10px; color: var(--text);
    padding: 9px 12px; flex:1; outline: none;
}
.meal-select:focus { border-color: var(--accent); }

/* Polling dot (Kullanıcı talebiyle tamamen gizlendi) */
#poll-dot {
    display: none !important;
}

/* Skeleton loader */
.skeleton {
    background: linear-gradient(90deg, var(--surface-2) 25%, var(--surface) 50%, var(--surface-2) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 8px;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
</style>
</head>
<body>

<?php $activePage = 'dashboard'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<!-- ═══════════════════════ MAIN ════════════════════════════ -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="hamburger btn-ghost btn-topbar" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="topbar-title">Günlük Dashboard</div>
                <div class="topbar-sub" id="dateLabel">Yükleniyor…</div>
            </div>
        </div>
        <div class="topbar-right">
            <!-- Hızlı Ekle -->
            <button class="btn-topbar btn-accent" data-bs-toggle="modal" data-bs-target="#quickAddModal">
                <i class="bi bi-plus-lg"></i>
                <span class="d-none d-sm-inline">Hızlı Ekle</span>
            </button>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="content">

        <!-- ── KPI KARTLARI (4'lü üst satır) ─────────────── -->
        <div class="row g-3 mb-4">
            <!-- Kalori -->
            <div class="col-6 col-xl-3">
                <div class="kpi-tile">
                    <div class="glow" style="background:var(--red)"></div>
                    <div class="kpi-label"><i class="bi bi-fire me-1"></i>Kalori</div>
                    <div class="kpi-value" id="kpiCalVal" style="color:var(--red)">—</div>
                    <div class="kpi-sub">/ <span id="kpiCalTarget">—</span> kcal hedef</div>
                    <div class="gyp-progress">
                        <div class="gyp-progress-fill" id="kpiCalBar" style="width:0%;background:var(--red)"></div>
                    </div>
                    <div style="font-size:11px;color:var(--muted)"><span id="kpiCalRem">—</span> kcal kaldı</div>
                </div>
            </div>
            <!-- Protein -->
            <div class="col-6 col-xl-3">
                <div class="kpi-tile">
                    <div class="glow" style="background:#60a5fa"></div>
                    <div class="kpi-label"><i class="bi bi-egg-fried me-1"></i>Protein</div>
                    <div class="kpi-value" id="kpiProtVal" style="color:#60a5fa">—</div>
                    <div class="kpi-sub">/ <span id="kpiProtTarget">—</span> g hedef</div>
                    <div class="gyp-progress">
                        <div class="gyp-progress-fill" id="kpiProtBar" style="width:0%;background:#60a5fa"></div>
                    </div>
                    <div style="font-size:11px;color:var(--muted)"><span id="kpiProtRem">—</span> g kaldı</div>
                </div>
            </div>
            <!-- Karb -->
            <div class="col-6 col-xl-3">
                <div class="kpi-tile">
                    <div class="glow" style="background:var(--yellow)"></div>
                    <div class="kpi-label"><i class="bi bi-lightning me-1"></i>Karbonhidrat</div>
                    <div class="kpi-value" id="kpiCarbVal" style="color:var(--yellow)">—</div>
                    <div class="kpi-sub">/ <span id="kpiCarbTarget">—</span> g hedef</div>
                    <div class="gyp-progress">
                        <div class="gyp-progress-fill" id="kpiCarbBar" style="width:0%;background:var(--yellow)"></div>
                    </div>
                    <div style="font-size:11px;color:var(--muted)"><span id="kpiCarbRem">—</span> g kaldı</div>
                </div>
            </div>
            <!-- Yağ -->
            <div class="col-6 col-xl-3">
                <div class="kpi-tile">
                    <div class="glow" style="background:var(--purple)"></div>
                    <div class="kpi-label"><i class="bi bi-droplet-half me-1"></i>Yağ</div>
                    <div class="kpi-value" id="kpiFatVal" style="color:var(--purple)">—</div>
                    <div class="kpi-sub">/ <span id="kpiFatTarget">—</span> g hedef</div>
                    <div class="gyp-progress">
                        <div class="gyp-progress-fill" id="kpiFatBar" style="width:0%;background:var(--purple)"></div>
                    </div>
                    <div style="font-size:11px;color:var(--muted)"><span id="kpiFatRem">—</span> g kaldı</div>
                </div>
            </div>
        </div>

        <!-- ── ALT 2'Lİ ALAN ──────────────────────────────── -->
        <div class="row g-3 mb-3">

            <!-- SOL: Günün Özeti (Donut Rings) -->
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="section-header">
                            <div class="section-title"><i class="bi bi-pie-chart-fill" style="color:var(--accent)"></i> Günün Makro Özeti</div>
                            <div id="workoutBadge" class="workout-badge off" onclick="toggleWorkout()" style="cursor:pointer" title="Antrenman / Dinlenme durumunu değiştirmek için tıklayın">
                                <i class="bi bi-moon-stars"></i> Dinlenme
                            </div>
                        </div>

                        <!-- 4 Ring -->
                        <div class="d-flex flex-wrap gap-3 justify-content-around" id="ringContainer">
                            <!-- Dinamik olarak doldurulur -->
                        </div>

                        <!-- BMR / TDEE satırı -->
                        <div class="d-flex justify-content-around mt-4 pt-3" style="border-top:1px solid var(--border)">
                            <div class="text-center">
                                <div style="font-size:11px;color:var(--muted);margin-bottom:4px">BMR</div>
                                <div style="font-size:18px;font-weight:700" id="bmrVal">—</div>
                                <div style="font-size:10px;color:var(--muted)">kcal/gün</div>
                            </div>
                            <div style="width:1px;background:var(--border)"></div>
                            <div class="text-center">
                                <div style="font-size:11px;color:var(--muted);margin-bottom:4px">TDEE</div>
                                <div style="font-size:18px;font-weight:700;color:var(--accent)" id="tdeeVal">—</div>
                                <div style="font-size:10px;color:var(--muted)">kcal/gün</div>
                            </div>
                            <div style="width:1px;background:var(--border)"></div>
                            <div class="text-center">
                                <div style="font-size:11px;color:var(--muted);margin-bottom:4px">Kalan</div>
                                <div style="font-size:18px;font-weight:700;color:var(--green)" id="remCalVal">—</div>
                                <div style="font-size:10px;color:var(--muted)">kcal</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SAĞ: Yaklaşan Alarmlar -->
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="section-header">
                            <div class="section-title"><i class="bi bi-alarm-fill" style="color:var(--yellow)"></i> Yaklaşan Alarmlar</div>
                            <a href="reminders.php" style="font-size:12px;color:var(--accent)">Tümünü Yönet →</a>
                        </div>
                        <div id="alarmList">
                            <div class="skeleton" style="height:56px;margin-bottom:8px"></div>
                            <div class="skeleton" style="height:56px;margin-bottom:8px"></div>
                            <div class="skeleton" style="height:56px"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── AKILLI HİDRASYON & SU TAKİBİ ────────────────── -->
        <div class="row g-3 mb-3">
            <div class="col-12">
                <div class="card" style="background: linear-gradient(135deg, rgba(14,165,233,0.07), rgba(2,132,199,0.02)); border: 1px solid rgba(56,189,248,0.25);">
                    <div class="card-body p-3 p-sm-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div class="section-title mb-0" style="color:var(--text); font-size:15px; font-weight:600;">
                                <i class="bi bi-droplet-fill text-info fs-5 me-1"></i>
                                <span>Akıllı Hidrasyon & Su Takibi</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span id="waterTargetBadge" class="badge" style="background:rgba(56,189,248,0.15); color:var(--accent); border:1px solid rgba(56,189,248,0.3); font-weight:600; padding:6px 12px; font-size:12px;">
                                    🎯 Günlük Hedef: <span id="waterTargetVal">2800</span> ml
                                </span>
                                <button class="btn btn-sm btn-ghost" onclick="resetWater()" title="Sıfırla" style="padding:4px 8px; border-radius:8px;">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row align-items-center g-3">
                            <!-- Sol: İlerleme & İstatistikler -->
                            <div class="col-lg-5">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="position-relative" style="width:76px; height:76px; flex-shrink:0;">
                                        <div id="waterPercentRing" style="width:76px; height:76px; border-radius:50%; background:conic-gradient(var(--accent) 0%, rgba(255,255,255,0.08) 0%); display:flex; align-items:center; justify-content:center; box-shadow:0 0 15px rgba(56,189,248,0.15); transition: background 0.5s ease;">
                                            <div style="width:62px; height:62px; border-radius:50%; background:var(--surface); display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                                <span id="waterPercentText" style="font-weight:700; font-size:15px; color:var(--accent);">0%</span>
                                                <small style="font-size:9px; color:var(--muted);">HEDEF</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-baseline gap-2">
                                            <span id="waterConsumedVal" style="font-size:26px; font-weight:700; color:var(--accent);">0</span>
                                            <span style="color:var(--muted); font-size:14px;">/ <span id="waterTargetSub">2800</span> ml</span>
                                        </div>
                                        <div id="waterStatusMsg" style="font-size:12px; color:var(--muted); margin-top:2px;">
                                            Kalan: <strong id="waterRemainingVal" style="color:var(--text);">2800 ml</strong> (yaklaşık <span id="waterGlassesVal">11</span> bardak)
                                        </div>
                                        <div id="waterBonusBadge" class="mt-1 d-none" style="font-size:11px; color:var(--green); font-weight:500;">
                                            <i class="bi bi-lightning-charge-fill me-1"></i>Antrenman desteği: +500 ml eklendi
                                        </div>
                                    </div>
                                </div>
                                <div class="gyp-progress mt-3 mb-0" style="height:10px; background:rgba(255,255,255,0.06);">
                                    <div id="waterProgressBar" class="gyp-progress-fill" style="width:0%; background:linear-gradient(90deg, #38bdf8, #0284c7);"></div>
                                </div>
                            </div>

                            <!-- Sağ: Tek Tıkla Bardak/Şişe Ekleme Butonları -->
                            <div class="col-lg-7">
                                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                                    <button class="btn btn-ghost text-light" onclick="quickAddWater(200)" style="padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;">
                                        🥛 +200 ml <small class="text-muted d-block fw-normal" style="font-size:10px">1 Bardak</small>
                                    </button>
                                    <button class="btn btn-ghost text-light" onclick="quickAddWater(330)" style="padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;">
                                        🥤 +330 ml <small class="text-muted d-block fw-normal" style="font-size:10px">Küçük Şişe</small>
                                    </button>
                                    <button class="btn btn-ghost text-light" onclick="quickAddWater(500)" style="padding:8px 14px; border-radius:10px; font-size:13px; font-weight:600; border-color:rgba(56,189,248,0.4); background:rgba(56,189,248,0.1);">
                                        🍶 +500 ml <small class="text-info d-block fw-normal" style="font-size:10px">Orta Şişe</small>
                                    </button>
                                    <button class="btn btn-ghost text-light" onclick="quickAddWater(1000)" style="padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;">
                                        🫖 +1000 ml <small class="text-muted d-block fw-normal" style="font-size:10px">Sürahi</small>
                                    </button>
                                    <button class="btn btn-ghost text-warning" onclick="quickAddWater(-200)" style="padding:8px 10px; border-radius:10px; font-size:13px;" title="Son bardağı geri al">
                                        <i class="bi bi-arrow-counterclockwise"></i> -200 ml
                                    </button>
                                    <button class="btn btn-ghost text-accent" onclick="promptCustomWater()" style="padding:8px 10px; border-radius:10px; font-size:13px;" title="Özel Miktar Gir">
                                        <i class="bi bi-pencil-square"></i> Özel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SON ÖĞÜNLER ────────────────────────────────── -->
        <div class="row g-3">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="section-header">
                            <div class="section-title"><i class="bi bi-clock-history" style="color:var(--green)"></i> Bugün Eklenenler</div>
                            <a href="nutrition.php" style="font-size:12px;color:var(--accent)">Beslenme Modülüne Git →</a>
                        </div>
                        <div id="mealList" class="row g-2">
                            <div class="col-12 skeleton" style="height:44px;border-radius:10px"></div>
                            <div class="col-12 skeleton" style="height:44px;border-radius:10px"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── HAFTANIN GÜNLÜK DÖKÜMÜ ─────────────────────────── -->
        <?php if (!empty($weeklyBreakdown)): 
            $days = $weeklyBreakdown['days'];
            $daysWithFood = $weeklyBreakdown['days_with_food'];
        ?>
        <div class="row g-3 mt-1">
            <div class="col-12">
                <div class="card p-3 p-sm-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h6 class="mb-0 text-light fw-bold">
                                <i class="bi bi-calendar-week me-2 text-info"></i>Haftanın Günlük Dökümü
                            </h6>
                            <span class="text-secondary small">Bu hafta kaydedilen besinler, kalori hedefleri ve spor aktiviteleri</span>
                        </div>
                        <span class="badge bg-secondary small"><?= $daysWithFood ?> gün veri kaydedildi</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-dark table-sm table-hover align-middle mb-0" style="background:transparent">
                            <thead>
                                <tr class="text-secondary" style="font-size: 12px; border-bottom: 1px solid var(--border);">
                                    <th>Tarih</th>
                                    <th>Spor Durumu</th>
                                    <th>Alınan / Hedef</th>
                                    <th>Fark</th>
                                    <th style="color:#60a5fa">Protein</th>
                                    <th style="color:#facc15">Karb</th>
                                    <th style="color:#c084fc">Yağ</th>
                                    <th>Öğünler</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($days as $idx => $d): 
                                $isTodayClass = $d['is_today'] ? 'border-start border-3 border-info ps-2' : '';
                                $diff = $d['diff_calories'];
                            ?>
                                <tr>
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
                                                <i class="bi bi-clock me-1"></i> Planlandı
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
                                            <span class="text-secondary">/ <?= number_format($d['target']['calories'], 0) ?> kcal</span>
                                        </div>
                                        <div class="progress mt-1" style="height: 4px; max-width: 130px; background: rgba(255,255,255,0.06);">
                                            <div class="progress-bar bg-danger" style="width: <?= min(100, $d['adherence_pct']) ?>%"></div>
                                        </div>
                                    </td>

                                    <!-- Fark -->
                                    <td>
                                        <?php if ($d['consumed']['calories'] == 0): ?>
                                            <span class="text-muted small">—</span>
                                        <?php elseif ($diff > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning small">+<?= number_format($diff, 0) ?> kcal</span>
                                        <?php elseif ($diff < 0): ?>
                                            <span class="badge bg-info-subtle text-info border border-info small"><?= number_format($diff, 0) ?> kcal</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success small">Hedefte</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Makrolar -->
                                    <td><span class="text-primary fw-semibold"><?= round($d['consumed']['protein_g'], 1) ?>g</span></td>
                                    <td><span class="text-warning fw-semibold"><?= round($d['consumed']['carbs_g'], 1) ?>g</span></td>
                                    <td><span style="color:#c084fc;" class="fw-semibold"><?= round($d['consumed']['fat_g'], 1) ?>g</span></td>

                                    <!-- Öğünler Mini Liste / Popover -->
                                    <td>
                                        <?php if (!empty($d['food_logs'])): ?>
                                            <button class="btn btn-sm btn-outline-secondary py-0 px-2 small" type="button" data-bs-toggle="collapse" data-bs-target="#dash-meals-<?= $idx ?>" aria-expanded="false">
                                                <?= count($d['food_logs']) ?> öğün <i class="bi bi-chevron-down ms-1"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">Kayıt yok</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php if (!empty($d['food_logs'])): ?>
                                <tr class="collapse" id="dash-meals-<?= $idx ?>">
                                    <td colspan="8" class="bg-dark bg-opacity-50 p-3">
                                        <div class="small fw-semibold text-secondary mb-2"><i class="bi bi-list-ul me-1"></i><?= $d['day_name'] ?> Günü Tüketilen Öğünler:</div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($d['food_logs'] as $f): ?>
                                                <div class="px-2 py-1 rounded bg-black bg-opacity-40 border border-secondary border-opacity-25 small text-light">
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
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /content -->
</div><!-- /main -->

<!-- Polling göstergesi (gizlendi) -->
<div id="poll-dot" style="display:none !important">
    <span id="pollLabel"></span>
</div>

<!-- ═══════════════════ HIZLI EKLE MODAL (Gemini AI) ══════════════════ -->
<div class="modal fade" id="quickAddModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h5 class="modal-title fw-bold">🤖 AI ile Öğün Analizi</h5>
                <div style="font-size:12px;color:var(--muted)">
                    Gemini AI · Serbest metin girin, makroları otomatik hesaplayın
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

            <!-- Sekme: Besin Analizi / Takviye -->
            <div class="result-tabs d-flex gap-2 mb-4">
                <button class="tab-btn active" id="tabBtnFood" onclick="switchModalTab('food', this)">
                    🍗 Gemini Besin Analizi
                </button>
                <button class="tab-btn" id="tabBtnSupp" onclick="switchModalTab('supp', this)">
                    💊 Lokal Takviye
                </button>
            </div>

            <!-- ── BÖLÜM 1: Gemini Serbest Metin ─────────────────── -->
            <div id="panelFood">
                <!-- Fotoğraf Çek/Yükle CTA Banner -->
                <div class="p-3 mb-3 d-flex align-items-center justify-content-between"
                     style="background:linear-gradient(135deg,rgba(236,72,153,.12),rgba(139,92,246,.12));border:1px solid rgba(236,72,153,.3);border-radius:12px;cursor:pointer;transition:transform .15s, border-color .15s"
                     onclick="openPhotoModalFromQuickAdd()">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:24px">📸</span>
                        <div>
                            <div style="font-size:13px;font-weight:600;color:#f472b6">Fotoğrafla Otomatik Tanı (Vision AI)</div>
                            <div style="font-size:11px;color:var(--muted)">Tabağınızı çekin veya yükleyin, Gemini makroları otomatik hesaplasın</div>
                        </div>
                    </div>
                    <span class="badge" style="background:rgba(236,72,153,.25);color:#f472b6;padding:6px 12px;border-radius:8px;font-weight:600">Kamera Aç →</span>
                </div>

                <!-- Metin Alanı -->
                <div style="margin-bottom:12px">
                    <label style="font-size:12px;color:var(--muted);margin-bottom:6px;display:block">
                        <i class="bi bi-pencil-square me-1"></i>Öğünü serbest olarak yazın
                    </label>
                    <textarea id="geminiInput" class="search-input" rows="3"
                        style="resize:vertical;min-height:80px;line-height:1.5"
                        placeholder="Örn: 150 gr ızgara tavuklu salata ve 1 kutu kola&#10;Örn: Kahvaltıda 2 yumurta, tam buğday ekmek, 1 bardak süt&#10;Örn: Akşam yemeği: mercimek çorbası ve 1 dilim ekmek"></textarea>
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">
                        <i class="bi bi-info-circle me-1"></i>Porsiyon, ağırlık ve içerik bilgisi verdiğinizde sonuç daha doğru olur.
                    </div>
                </div>

                <!-- Öğün Tipi -->
                <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:14px">
                    <div style="flex:1">
                        <label style="font-size:12px;color:var(--muted);margin-bottom:6px;display:block">Öğün Tipi</label>
                        <select id="geminiMealType" class="meal-select">
                            <option value="breakfast">🌅 Kahvaltı</option>
                            <option value="lunch" selected>☀️ Öğle</option>
                            <option value="dinner">🌙 Akşam</option>
                            <option value="snack">🍎 Ara Öğün</option>
                            <option value="pre_workout">⚡ Antrenman Öncesi</option>
                            <option value="post_workout">💪 Antrenman Sonrası</option>
                        </select>
                    </div>
                    <button onclick="previewGemini()" id="previewBtn"
                        style="background:rgba(56,189,248,.12);border:1px solid rgba(56,189,248,.3);
                               color:var(--accent);padding:9px 18px;border-radius:10px;font-size:13px;
                               font-weight:600;white-space:nowrap;transition:all .2s"
                        onmouseover="this.style.background='rgba(56,189,248,.2)'"
                        onmouseout="this.style.background='rgba(56,189,248,.12)'">
                        <i class="bi bi-stars me-1"></i> Analiz Et
                    </button>
                </div>

                <!-- Yükleniyor -->
                <div id="geminiLoading" class="d-none" style="text-align:center;padding:24px 0">
                    <div style="display:inline-flex;align-items:center;gap:10px;
                                background:var(--surface-2);border:1px solid var(--border);
                                padding:12px 20px;border-radius:12px">
                        <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                        <span style="font-size:13px;color:var(--muted)">Gemini AI analiz ediyor…</span>
                    </div>
                </div>

                <!-- Önizleme Sonucu -->
                <div id="geminiPreview" class="d-none" style="
                    background: linear-gradient(135deg,rgba(56,189,248,.08),rgba(99,102,241,.05));
                    border: 1px solid rgba(56,189,248,.25);
                    border-radius: 14px; padding: 18px">
                    <div style="font-size:11px;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:6px">
                        <i class="bi bi-stars text-info"></i> Gemini AI Tahmini
                        <span id="geminiModelBadge" style="background:rgba(56,189,248,.15);border:1px solid rgba(56,189,248,.2);
                              color:var(--accent);padding:2px 8px;border-radius:99px;font-size:10px"></span>
                    </div>
                    <div style="font-weight:600;margin-bottom:12px;color:var(--text)" id="geminiPreviewLabel"></div>

                    <!-- Makro Kartları -->
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px" id="geminiMacroGrid">
                        <!-- JS ile doldurulur -->
                    </div>
                    <div style="font-size:11px;color:var(--muted);margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
                        <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
                        Bu değerler yapay zeka tahminidir. Gerçek değerler farklılık gösterebilir.
                    </div>
                </div>

                <!-- Hata -->
                <div id="geminiError" class="d-none" style="
                    background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);
                    border-radius:12px;padding:14px;color:#fca5a5;font-size:13px">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    <span id="geminiErrorMsg"></span>
                </div>
            </div>

            <!-- ── BÖLÜM 2: Lokal Takviye Arama ──────────────────── -->
            <div id="panelSupp" class="d-none">
                <div style="position:relative;margin-bottom:10px">
                    <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted)"></i>
                    <input type="text" id="suppSearchInput" class="search-input" style="padding-left:38px"
                           placeholder="Takviye adı yazın (örn: D3, Whey, Magnezyum)"
                           oninput="searchLocalSupps(this.value)">
                </div>
                <div id="suppResultsModal" style="max-height:280px;overflow-y:auto"></div>
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
            <button type="button" class="btn btn-info fw-semibold" id="addBtn"
                    onclick="submitQuickAdd()" disabled>
                <i class="bi bi-plus-lg me-1"></i> Günlüğüme Kaydet
            </button>
        </div>
    </div>
  </div>
</div>

<!-- ═══════════════════ FOTOĞRAFLA ANALİZ MODAL (Gemini Vision) ══════════════════ -->
<div class="modal fade" id="photoAnalysisModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
        <div class="modal-header">
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
            <div id="photoDropArea" class="p-4 text-center mb-3" style="background:var(--surface-2);border:2px dashed rgba(236,72,153,.35);border-radius:14px;transition:border-color .2s">
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
                            <select id="photoMealType" class="meal-select">
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
                            <input type="text" id="photoUserNotes" class="search-input" placeholder="Örn: Yarısını yedim, sosu zeytinyağlı, 1 dilim ekmekle">
                            <div style="font-size:11px;color:var(--muted);margin-top:4px">
                                Porsiyon veya içerik belirtirseniz analiz çok daha hassas olur.
                            </div>
                        </div>
                        <button type="button" id="startPhotoAnalysisBtn" class="btn btn-accent w-100 py-2 fw-semibold" onclick="analyzeSelectedPhoto()">
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
                    <span class="badge" style="background:rgba(34,197,94,.2);color:var(--green);border:1px solid rgba(34,197,94,.3)">
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

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// ─── INITIAL HYDRATION & STATE ─────────────────────────────────────────
window.__INITIAL_DASHBOARD__ = <?= $initialDashboardData ? json_encode($initialDashboardData, JSON_UNESCAPED_UNICODE) : 'null' ?>;
let dashData     = null;    // Son API yanıtı
let selectedItem = null;    // Modal'da seçilen öğe ('gemini' | 'local' tipi)
let searchTimer  = null;    // Debounce
let activeTab    = 'foods';
const shownAlarms = new Set();

// ─── API HELPER & AUTH FAILURE TOLERANCE ─────────────────────────────
let authFailureStreak = 0;

async function apiPost(action, extra = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(extra)) fd.append(k, v);
    const r = await fetch(`${window.API_BASE}/dashboard.php`, {
        method: 'POST',
        body: fd,
        credentials: 'include'
    });
    return r.json();
}

// ─── DASHBOARD LOAD ───────────────────────────────────────────────────
async function loadDashboard(forceFetch = false) {
    // 1. İlk açılışta sunucudan gelen veriyi 0ms içinde anında ekrana bas
    if (!forceFetch && window.__INITIAL_DASHBOARD__ && !dashData) {
        dashData = window.__INITIAL_DASHBOARD__;
        renderDashboard(dashData);
        checkAlarms(dashData.upcoming_alarms ?? []);
        window.__INITIAL_DASHBOARD__ = null;
        return;
    }

    try {
        const data = await apiPost('load');
        if (!data.ok) {
            if (data.require_login) {
                authFailureStreak++;
                console.warn(`Oturum uyarısı (${authFailureStreak}/3)`);
                // Tek bir geçici gecikmede kullanıcıyı hemen atma; 3 kez üst üste başarısız olursa yönlendir
                if (authFailureStreak >= 3) {
                    window.location.href = 'login.php?redirect=dashboard.php';
                    return;
                }
                setTimeout(() => loadDashboard(true), 4000);
                return;
            }
            throw new Error(data.error || 'Veri yüklenemedi');
        }
        authFailureStreak = 0; // Başarılı yanıtta sayacı sıfırla
        dashData = data;
        renderDashboard(data);
        checkAlarms(data.upcoming_alarms ?? []);
        const pollLbl = document.getElementById('pollLabel');
        if (pollLbl) pollLbl.textContent = '⚠️ Yeniden deneniyor…';
        console.error('Dashboard yüklenemedi:', e);
        // Ağ gecikmesi veya soğuk başlangıçta 4 saniye sonra otomatik yeniden dene
        setTimeout(() => loadDashboard(true), 4000);
    }
}

function renderDashboard(d) {
    // ── Tarih ──
    document.getElementById('dateLabel').textContent =
        `${d.day_name} · ${formatDate(d.date)}`;

    // ── KPI Kartları ──
    setKPI('Cal',  d.consumed.calories,  d.target.calories,  d.remaining.calories,  d.progress_pct.calories,  'kcal');
    setKPI('Prot', d.consumed.protein_g, d.target.protein_g, d.remaining.protein_g, d.progress_pct.protein_g, 'g');
    setKPI('Carb', d.consumed.carbs_g,   d.target.carbs_g,   d.remaining.carbs_g,   d.progress_pct.carbs_g,   'g');
    setKPI('Fat',  d.consumed.fat_g,     d.target.fat_g,     d.remaining.fat_g,     d.progress_pct.fat_g,     'g');

    // ── BMR / TDEE ──
    document.getElementById('bmrVal').textContent  = fmt(d.bmr);
    document.getElementById('tdeeVal').textContent = fmt(d.tdee);
    document.getElementById('remCalVal').textContent =
        d.remaining.calories >= 0
            ? fmt(d.remaining.calories)
            : `+${fmt(Math.abs(d.remaining.calories))}`;
    document.getElementById('remCalVal').style.color =
        d.remaining.calories >= 0 ? 'var(--green)' : 'var(--red)';

    // ── Ring Charts ──
    renderRings(d);

    // ── Alarmlar ──
    renderAlarms(d.upcoming_alarms ?? []);

    // ── Son Öğünler ──
    renderMeals(d.recent_meals ?? []);

    // ── Akıllı Su Takibi ──
    if (d.water) renderWater(d.water);

    // ── Polling göstergesi ──
    const pollLbl = document.getElementById('pollLabel');
    if (pollLbl) pollLbl.textContent = 'Güncel · ' + now();
}

function setKPI(key, consumed, target, remaining, pct, unit) {
    setText(`kpi${key}Val`,    fmt(consumed));
    setText(`kpi${key}Target`, fmt(target));
    setText(`kpi${key}Rem`,    remaining >= 0 ? fmt(remaining) : `+${fmt(Math.abs(remaining))}`);
    const bar = document.getElementById(`kpi${key}Bar`);
    if (bar) bar.style.width = Math.min(100, pct) + '%';
}

// ── SVG Ring (donut) ─────────────────────────────────────────────────
function renderRings(d) {
    const rings = [
        { label:'Kalori',   consumed: d.consumed.calories,  target: d.target.calories,  pct: d.progress_pct.calories,  unit:'kcal', color:'#f87171' },
        { label:'Protein',  consumed: d.consumed.protein_g, target: d.target.protein_g, pct: d.progress_pct.protein_g, unit:'g',    color:'#60a5fa' },
        { label:'Karb',     consumed: d.consumed.carbs_g,   target: d.target.carbs_g,   pct: d.progress_pct.carbs_g,   unit:'g',    color:'#facc15' },
        { label:'Yağ',      consumed: d.consumed.fat_g,     target: d.target.fat_g,     pct: d.progress_pct.fat_g,     unit:'g',    color:'#c084fc' },
    ];

    const container = document.getElementById('ringContainer');
    container.innerHTML = rings.map(r => {
        const radius   = 28;
        const circ     = 2 * Math.PI * radius;
        const filled   = Math.min(100, r.pct) / 100 * circ;
        const isOver   = r.pct > 100;
        return `
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
            <div class="ring-wrap">
                <svg width="72" height="72" viewBox="0 0 72 72">
                    <circle cx="36" cy="36" r="${radius}" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="8"/>
                    <circle cx="36" cy="36" r="${radius}" fill="none"
                        stroke="${isOver ? '#fb923c' : r.color}"
                        stroke-width="8"
                        stroke-dasharray="${filled} ${circ - filled}"
                        stroke-linecap="round"
                        style="transition:stroke-dasharray .6s cubic-bezier(.34,1.56,.64,1)"/>
                </svg>
                <div class="ring-center">
                    <span style="color:${isOver?'#fb923c':r.color};font-size:11px">${Math.round(r.pct)}%</span>
                    <small>${r.unit}</small>
                </div>
            </div>
            <div style="text-align:center">
                <div style="font-size:12px;font-weight:600">${fmt(r.consumed)}</div>
                <div style="font-size:10px;color:var(--muted)">${r.label} / ${fmt(r.target)}</div>
            </div>
        </div>`;
    }).join('');
}

// ── Alarmlar ──────────────────────────────────────────────────────────
function renderAlarms(alarms) {
    const el = document.getElementById('alarmList');
    if (alarms.length === 0) {
        el.innerHTML = `
        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            Önümüzdeki 3 saatte alarm yok.<br>
            <a href="reminders.php" style="color:var(--accent);font-size:12px">Alarm ekle →</a>
        </div>`;
        return;
    }

    el.innerHTML = alarms.map(a => {
        const iconClass = a.type === 'medication' ? 'med' : (a.type === 'vitamin' ? 'vit' : 'supp');
        const icon = a.type === 'medication' ? '💊' : (a.type === 'vitamin' ? '☀️' : '💪');
        const minLeft = a.minutes_left;
        const minLabel = minLeft <= 0 ? 'Şimdi!' : (minLeft < 60 ? `${minLeft}dk` : `${Math.floor(minLeft/60)}s ${minLeft%60}dk`);

        return `
        <div class="alarm-item" id="alarm-row-${a.id}">
            <div class="alarm-time">${a.remind_at}</div>
            <div class="alarm-icon ${iconClass}">${icon}</div>
            <div style="min-width:0;flex:1">
                <div class="alarm-label">${esc(a.label)}</div>
                <div class="alarm-dose">${esc(a.dose)} · ${esc(a.form)}</div>
            </div>
            <div class="alarm-badge">${minLabel}</div>
            <button class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="dismissDashboardAlarm(${a.id})" title="Alarmı Sil / Kaldır" style="text-decoration:none;opacity:0.8;">
                <i class="bi bi-trash3"></i>
            </button>
        </div>`;
    }).join('');
}

async function dismissDashboardAlarm(alarmId) {
    const result = await Swal.fire({
        title: 'Alarmı Kaldır?',
        text: 'Bu alarmı dashboard ve hatırlatıcı listesinden silmek istiyor musunuz?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'Vazgeç',
        background: '#111827',
        color: '#f8fafc',
    });

    if (result.isConfirmed) {
        const formData = new FormData();
        formData.append('action', 'delete_alarm');
        formData.append('alarm_id', alarmId);

        const res = await fetch(`${window.API_BASE}/dashboard.php`, { method: 'POST', body: formData });
        const data = await res.json();

        if (data.ok) {
            Swal.fire({
                icon: 'success',
                title: 'Alarm Silindi',
                toast: true,
                position: 'top-end',
                timer: 2000,
                showConfirmButton: false,
                background: '#111827',
                color: '#f8fafc',
            });
            await loadDashboard();
        }
    }
}

// ── Son Öğünler ───────────────────────────────────────────────────────
const MEAL_COLORS = {
    breakfast:'#facc15', lunch:'#4ade80', dinner:'#60a5fa',
    snack:'#f87171', pre_workout:'#fb923c', post_workout:'#a78bfa'
};
const MEAL_LABELS = {
    breakfast:'Kahvaltı', lunch:'Öğle', dinner:'Akşam',
    snack:'Ara', pre_workout:'Ant. Öncesi', post_workout:'Ant. Sonrası'
};

function renderMeals(meals) {
    const el = document.getElementById('mealList');
    if (meals.length === 0) {
        el.innerHTML = `
        <div class="col-12">
            <div class="empty-state">
                <i class="bi bi-journal-x"></i>
                Bugün henüz öğün girilmedi.
                <button data-bs-toggle="modal" data-bs-target="#quickAddModal"
                    style="display:block;margin:10px auto 0;background:var(--accent-dim);border:1px solid rgba(56,189,248,.3);
                           color:var(--accent);padding:7px 18px;border-radius:10px;font-size:13px;cursor:pointer">
                    ⚡ İlk Öğünü Ekle
                </button>
            </div>
        </div>`;
        return;
    }

    el.innerHTML = meals.map(m => `
    <div class="col-md-6" id="meal-card-${m.id}">
        <div class="meal-row align-items-center">
            <div class="meal-dot" style="background:${MEAL_COLORS[m.meal_type]||'var(--muted)'}"></div>
            <div style="min-width:0;flex:1">
                <div class="meal-name text-truncate" title="${esc(m.food_label)}">${esc(m.food_label)}</div>
                <div class="meal-type">${MEAL_LABELS[m.meal_type]||m.meal_type} · <span style="font-size:11px;color:var(--muted)">${m.protein_g||0}p · ${m.carbs_g||0}k · ${m.fat_g||0}y</span></div>
            </div>
            <div class="meal-cal text-nowrap">${fmt(m.calories)} kcal</div>
            <button class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="deleteDashboardMeal(${m.id}, '${esc(m.food_label).replace(/'/g, "\\'")}')" title="Bu öğünü sil" style="text-decoration:none;opacity:0.8;font-size:14px">
                <i class="bi bi-trash3"></i>
            </button>
        </div>
    </div>`).join('');
}

async function deleteDashboardMeal(mealId, foodName) {
    const result = await Swal.fire({
        title: 'Öğünü Sil?',
        text: `"${foodName}" kaydını silmek istediğinize emin misiniz? Günlük kalori ve makro hedeflerinizden düşülecektir.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Evet, Sil',
        cancelButtonText: 'Vazgeç',
        background: '#111827',
        color: '#f8fafc',
    });

    if (result.isConfirmed) {
        const formData = new FormData();
        formData.append('action', 'delete_meal');
        formData.append('meal_id', mealId);

        try {
            const res = await fetch(`${window.API_BASE}/dashboard.php`, { method: 'POST', body: formData });
            const data = await res.json();

            if (data.ok) {
                Swal.fire({
                    icon: 'success',
                    title: 'Öğün Silindi',
                    text: 'Kalori ve makro hedefleriniz güncellendi.',
                    toast: true,
                    position: 'top-end',
                    timer: 2000,
                    showConfirmButton: false,
                    background: '#111827',
                    color: '#f8fafc',
                });
                await loadDashboard();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Hata',
                    text: data.error || 'Öğün silinemedi.',
                    background: '#111827',
                    color: '#f8fafc',
                });
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Bağlantı Hatası',
                text: 'Sunucuya ulaşılamadı.',
                background: '#111827',
                color: '#f8fafc',
            });
        }
    }
}


// ─── ALARM PUSH (setInterval polling) ────────────────────────────────
function checkAlarms(alarms) {
    const nowMin = new Date().toTimeString().substring(0, 5); // "14:25"
    for (const a of alarms) {
        // Yalnızca tam bu dakikaya denk gelen alarm çalsın (Erken uyarı yok, alarm anı beklenir)
        if (a.remind_at !== nowMin) continue;
        const key = `${a.id}-${a.remind_at}-${new Date().toDateString()}`;
        if (shownAlarms.has(key)) continue;
        shownAlarms.add(key);

        // Sesli alarmı döngüsel olarak başlat (Web Audio API)
        if (window.optiAlarmEngine) {
            window.optiAlarmEngine.start();
        }

        Swal.fire({
            icon:'warning', iconColor:'#facc15',
            title: a.type==='medication' ? '💊 İlaç Zamanı!' : '💪 Takviye Zamanı!',
            html:`<div style="text-align:center">
                      <h5 style="color:#f8fafc; font-size:1.2rem; margin-bottom:8px;">${esc(a.label)}</h5>
                      <p style="color:#94a3b8; margin-bottom:4px;">Saat: <strong style="color:var(--accent)">${a.remind_at}</strong></p>
                      <p style="color:#94a3b8; margin-bottom:12px;">Doz: <strong style="color:#f8fafc">${esc(a.dose)}</strong></p>
                      <div class="badge bg-danger px-3 py-2" style="font-size:12px; letter-spacing:0.5px;">
                          🔔 Sesli Alarm Çalıyor...
                      </div>
                  </div>`,
            confirmButtonText:'✅ Aldım / Durdur',
            cancelButtonText:'⏸ Ertele 15dk',
            showCancelButton:true,
            confirmButtonColor:'#22c55e', cancelButtonColor:'#64748b',
            background:'#1e293b', color:'#f8fafc', allowOutsideClick:false,
        }).then(r => {
            // Alarm sesini kapat
            if (window.optiAlarmEngine) {
                window.optiAlarmEngine.stop();
            }

            if (r.dismiss === Swal.DismissReason.cancel) {
                setTimeout(() => { shownAlarms.delete(key); }, 15*60*1000);
            }
        });

        // Tarayıcı bildirimi
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(a.type==='medication'?'💊 İlaç Zamanı!':'💪 Takviye!', {
                body: `${a.label} — ${a.dose}`,
                tag:'optilifesync-' + a.id, requireInteraction:true,
            });
        }
    }
}

// ─── MODAL: SEKMELER ─────────────────────────────────────────────────
function switchModalTab(tab, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('panelFood').classList.toggle('d-none', tab !== 'food');
    document.getElementById('panelSupp').classList.toggle('d-none', tab !== 'supp');
    // Sekme değişince seçimi ve butonu sıfırla
    selectedItem = null;
    document.getElementById('addBtn').disabled = true;
    if (tab === 'supp') resetGeminiPanel();
}

// ─── MODAL: GEMİNİ ÖNİZLEME ─────────────────────────────────────────
let geminiResult = null;  // Son başarılı Gemini analizi

async function previewGemini() {
    const text = document.getElementById('geminiInput').value.trim();
    if (!text) {
        Swal.fire({ icon:'warning', title:'Boş Alan', text:'Lütfen bir öğün metni girin.', background:'#1e293b', color:'#f8fafc', timer:2000, showConfirmButton:false });
        return;
    }

    // UI durumları sıfırla
    document.getElementById('geminiPreview').classList.add('d-none');
    document.getElementById('geminiError').classList.add('d-none');
    document.getElementById('geminiLoading').classList.remove('d-none');
    document.getElementById('previewBtn').disabled = true;
    document.getElementById('addBtn').disabled = true;
    selectedItem = null;
    geminiResult = null;

    try {
        const fd = new FormData();
        fd.append('action',    'analyze_only');
        fd.append('meal_text', text);

        const r    = await fetch(`${window.API_BASE}/analyze_food.php`, { method:'POST', body:fd });
        const data = await r.json();

        document.getElementById('geminiLoading').classList.add('d-none');
        document.getElementById('previewBtn').disabled = false;

        if (!data.ok) throw new Error(data.error ?? 'Bilinmeyen hata');

        const m = data.analyzed.macros;
        geminiResult = data.analyzed;

        // Önizleme kartı
        document.getElementById('geminiPreviewLabel').textContent = text.length > 80 ? text.substring(0,80)+'…' : text;
        document.getElementById('geminiModelBadge').textContent   = data.analyzed.model_used ?? 'gemini';
        document.getElementById('geminiMacroGrid').innerHTML = [
            { label:'Kalori',  val: m.kalori,  unit:'kcal', color:'#f87171', icon:'🔥' },
            { label:'Protein', val: m.protein, unit:'g',    color:'#60a5fa', icon:'💪' },
            { label:'Karb',    val: m.karb,    unit:'g',    color:'#facc15', icon:'⚡' },
            { label:'Yağ',     val: m.yag,     unit:'g',    color:'#c084fc', icon:'💧' },
        ].map(c => `
            <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);
                        border-radius:10px;padding:10px;text-align:center">
                <div style="font-size:16px">${c.icon}</div>
                <div style="font-size:18px;font-weight:700;color:${c.color};margin:2px 0">${c.val}</div>
                <div style="font-size:10px;color:var(--muted)">${c.label}<br>${c.unit}</div>
            </div>`).join('');

        document.getElementById('geminiPreview').classList.remove('d-none');

        // Kaydet butonunu aktifleştir
        selectedItem = { type:'gemini', mealText: text };
        document.getElementById('addBtn').disabled = false;

    } catch(e) {
        document.getElementById('geminiLoading').classList.add('d-none');
        document.getElementById('previewBtn').disabled = false;
        document.getElementById('geminiErrorMsg').textContent = e.message;
        document.getElementById('geminiError').classList.remove('d-none');
    }
}

function resetGeminiPanel() {
    document.getElementById('geminiPreview').classList.add('d-none');
    document.getElementById('geminiError').classList.add('d-none');
    document.getElementById('geminiLoading').classList.add('d-none');
    geminiResult = null;
}

// ─── MODAL: LOKAL TAKVİYE ARAMA ──────────────────────────────────────
let suppSearchTimer = null;
window._suppModalResults = [];

async function searchLocalSupps(query) {
    clearTimeout(suppSearchTimer);
    const q = query.trim();
    if (q.length < 2) { document.getElementById('suppResultsModal').innerHTML = ''; return; }
    suppSearchTimer = setTimeout(async () => {
        const fd = new FormData();
        fd.append('action', 'quick_search');
        fd.append('q', q);
        const r    = await fetch(`${window.API_BASE}/dashboard.php`, { method:'POST', body:fd });
        const data = await r.json();
        if (!data.ok) return;
        window._suppModalResults = data.supplements ?? [];
        renderSuppModal(window._suppModalResults);
    }, 350);
}

function renderSuppModal(supps) {
    const el = document.getElementById('suppResultsModal');
    if (supps.length === 0) {
        el.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted)"><i class="bi bi-capsule" style="font-size:24px;display:block;margin-bottom:8px"></i>Takviye bulunamadı.<br><a href="reminders.php" style="color:var(--accent)">Takviye ekle →</a></div>';
        return;
    }
    el.innerHTML = supps.map((s, i) => `
    <div class="result-item" onclick="selectSuppModal(${i})" data-midx="${i}">
        <div class="result-icon supp">💊</div>
        <div style="min-width:0">
            <div class="result-name">${esc(s.name)}</div>
            <div class="result-meta">${s.dose_amount} ${s.dose_unit} · ${s.form} · ${s.type}</div>
        </div>
        <div class="result-kcal" style="color:var(--green)">${s.calories_per_dose ?? 0} kcal/doz</div>
    </div>`).join('');
}

function selectSuppModal(idx) {
    const s = (window._suppModalResults ?? [])[idx];
    if (!s) return;
    document.querySelectorAll('#suppResultsModal .result-item').forEach(e => e.classList.remove('selected'));
    document.querySelector(`#suppResultsModal [data-midx="${idx}"]`)?.classList.add('selected');
    selectedItem = { type:'local', data:s };
    document.getElementById('addBtn').disabled = false;
}

// ─── MODAL: KAYDET ────────────────────────────────────────────────────
async function submitQuickAdd() {
    if (!selectedItem) return;
    const btn = document.getElementById('addBtn');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div> Kaydediliyor…';

    try {
        let data;

        if (selectedItem.type === 'gemini') {
            // Gemini: analyze + kaydet tek endpoint
            const fd = new FormData();
            fd.append('action',    'analyze');
            fd.append('meal_text', selectedItem.mealText);
            fd.append('meal_type', document.getElementById('geminiMealType').value);
            const r = await fetch(`${window.API_BASE}/analyze_food.php`, { method:'POST', body:fd });
            data = await r.json();

        } else {
            // Lokal takviye
            const fd = new FormData();
            fd.append('action',        'quick_add');
            fd.append('source',        'local');
            fd.append('supplement_id', selectedItem.data.id);
            const r = await fetch(`${window.API_BASE}/dashboard.php`, { method:'POST', body:fd });
            data = await r.json();
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Günlüğüme Kaydet';

        if (data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('quickAddModal')).hide();
            await loadDashboard();
            Swal.fire({ icon:'success', title:'Kaydedildi!', timer:1800, showConfirmButton:false, background:'#1e293b', color:'#f8fafc' });
        } else {
            throw new Error(data.error ?? 'Bilinmeyen hata');
        }

    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Günlüğüme Kaydet';
        Swal.fire({ icon:'error', title:'Hata', text:e.message, background:'#1e293b', color:'#f8fafc' });
    }
}

// ─── SU TAKİBİ FONKSİYONLARI ────────────────────────────────────────
function renderWater(w) {
    if (!w) return;
    setText('waterTargetVal',   fmt(w.target_ml));
    setText('waterTargetSub',   fmt(w.target_ml));
    setText('waterConsumedVal', fmt(w.consumed_ml));
    setText('waterRemainingVal', `${fmt(w.remaining_ml)} ml`);
    setText('waterGlassesVal',  fmt(Math.ceil(w.remaining_ml / 250)));
    setText('waterPercentText', `${Math.round(w.pct)}%`);

    const bar = document.getElementById('waterProgressBar');
    if (bar) bar.style.width = Math.min(100, w.pct) + '%';

    const ring = document.getElementById('waterPercentRing');
    if (ring) {
        const ringColor = w.pct >= 100 ? '#22c55e' : 'var(--accent)';
        const deg = Math.min(100, w.pct) * 3.6;
        ring.style.background = `conic-gradient(${ringColor} ${deg}deg, rgba(255,255,255,0.08) ${deg}deg)`;
    }

    const bonusBadge = document.getElementById('waterBonusBadge');
    if (bonusBadge) {
        if (w.workout_bonus > 0) {
            bonusBadge.classList.remove('d-none');
        } else {
            bonusBadge.classList.add('d-none');
        }
    }
}

async function quickAddWater(amount) {
    try {
        const data = await apiPost('add_water', { amount });
        if (!data.ok) throw new Error(data.error);

        if (dashData && dashData.water) {
            dashData.water.consumed_ml  = data.water_ml;
            dashData.water.target_ml    = data.target_ml;
            dashData.water.pct          = data.pct;
            dashData.water.remaining_ml = data.remaining_ml;
            renderWater(dashData.water);
        } else {
            await loadDashboard();
        }

        Swal.fire({
            icon: amount >= 0 ? 'success' : 'info',
            title: data.message,
            toast: true,
            position: 'top-end',
            timer: 1600,
            showConfirmButton: false,
            background: '#111827',
            color: '#f8fafc'
        });
    } catch(e) {
        Swal.fire({ icon:'error', title:'Hata', text:e.message, background:'#1e293b', color:'#f8fafc' });
    }
}

async function resetWater() {
    const res = await Swal.fire({
        title: 'Su Tüketimini Sıfırla?',
        text: 'Bugün içilen su miktarını 0 ml yapmak istiyor musunuz?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Evet, Sıfırla',
        cancelButtonText: 'Vazgeç',
        background: '#111827',
        color: '#f8fafc',
    });
    if (res.isConfirmed) {
        const data = await apiPost('reset_water');
        if (data.ok) {
            await loadDashboard();
            Swal.fire({ icon:'success', title:'Sıfırlandı', toast:true, position:'top-end', timer:1500, showConfirmButton:false, background:'#111827', color:'#f8fafc' });
        }
    }
}

async function promptCustomWater() {
    const { value: ml } = await Swal.fire({
        title: 'Özel Su Miktarı',
        input: 'number',
        inputLabel: 'Eklenecek miktar (ml):',
        inputPlaceholder: 'Örn: 400',
        showCancelButton: true,
        confirmButtonText: 'Ekle 💧',
        cancelButtonText: 'İptal',
        background: '#111827',
        color: '#f8fafc',
        inputValidator: (v) => {
            if (!v || parseInt(v) <= 0) return 'Lütfen geçerli bir mililitre girin!';
        }
    });
    if (ml) {
        quickAddWater(parseInt(ml));
    }
}

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

function openPhotoModalFromQuickAdd() {
    const quickModalEl = document.getElementById('quickAddModal');
    const quickModal = bootstrap.Modal.getInstance(quickModalEl);
    if (quickModal) quickModal.hide();
    openPhotoModal();
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
        await loadDashboard();

        Swal.fire({
            icon: 'success',
            title: 'Öğün Günlüğe Eklendi!',
            text: 'Fotoğraftaki besin değerleri bugünkü makrolarınıza işlendi.',
            timer: 2000,
            showConfirmButton: false,
            background: '#111827',
            color: '#f8fafc'
        });
    } catch(e) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Onayla ve Günlüğüme Ekle';
        Swal.fire({ icon:'error', title:'Hata', text: e.message, background:'#111827', color:'#f8fafc' });
    }
}


// ─── YARDIMCILAR ─────────────────────────────────────────────────────
const fmt = v => parseFloat(v||0).toLocaleString('tr-TR', {maximumFractionDigits:1});
const setText = (id, v) => { const e = document.getElementById(id); if(e) e.textContent = v; };
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const now = () => new Date().toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});
const formatDate = d => new Date(d+'T00:00').toLocaleDateString('tr-TR',{day:'numeric',month:'long',year:'numeric'});

// ─── BAŞLATMA ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {

    // İlk yükleme
    await loadDashboard();

    // Her 30sn veri yenile
    setInterval(() => loadDashboard(true), 30_000);

    // Bildirim izni
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    // Modal kapanınca sıfırla
    document.getElementById('quickAddModal').addEventListener('hidden.bs.modal', () => {
        selectedItem  = null;
        geminiResult  = null;
        document.getElementById('addBtn').disabled = true;
        document.getElementById('geminiInput').value = '';
        document.getElementById('suppSearchInput').value = '';
        document.getElementById('suppResultsModal').innerHTML = '';
        resetGeminiPanel();
        // Sekmeyi ilk sekmele döndür
        document.getElementById('tabBtnFood').click();
    });
});

</script>
</body>
</html>

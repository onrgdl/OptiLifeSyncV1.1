<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Antrenman Modülü (v2)
 *
 *  • Haftalık antrenman takvimi (planla, tamamla, düzenle, sil, haftalar arası gezin)
 *  • Egzersiz günlüğü: set / tekrar / ağırlık veya süre
 *  • Kişisel rekorlar (en ağır set + tahmini 1 tekrar maksimum)
 *  • Haftalık toplam hacim ve seçilen egzersizin gelişim grafiği
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/WorkoutService.php';
require_once __DIR__ . '/app/Services/ExerciseLogService.php';

use App\Services\ExerciseLogService;

$activePage = 'workout';
$muscleGroups = ExerciseLogService::MUSCLE_GROUPS;
$commonExercises = [
    'Bench Press', 'Incline Dumbbell Press', 'Şınav', 'Dips', 'Barbell Row', 'Lat Pulldown', 'Barfiks', 'Deadlift',
    'Squat', 'Leg Press', 'Romanian Deadlift', 'Lunge', 'Leg Curl', 'Leg Extension', 'Calf Raise',
    'Overhead Press', 'Lateral Raise', 'Face Pull', 'Biceps Curl', 'Hammer Curl', 'Triceps Pushdown',
    'Plank', 'Crunch', 'Koşu', 'Yürüyüş', 'Bisiklet', 'Yüzme', 'İp atlama',
];
$v = '20260929';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Antrenman · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .card-pad { padding: 20px; }
        @media (max-width: 576px) { .card-pad { padding: 16px; } }
        .wo-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; margin-top: 20px; }
        @media (max-width: 1200px) { .wo-grid { grid-template-columns: minmax(0, 1fr); } }
        .stack { display: flex; flex-direction: column; gap: 20px; min-width: 0; }

        /* KPI */
        .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
        @media (max-width: 992px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .kpi { padding: 16px; display: flex; flex-direction: row !important; gap: 12px; align-items: center; }
        .kpi .v { font-size: 20px; font-weight: 800; letter-spacing: -.02em; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi .l { font-size: 12px; color: var(--muted); font-weight: 600; }
        @media (max-width: 576px) { .kpis { gap: 10px; margin-bottom: 14px; } .kpi { padding: 12px; gap: 10px; } .kpi .v { font-size: 16px; } .kpi .icon-tile { width: 34px; height: 34px; font-size: 15px; } }

        /* Hafta takvimi */
        .week-nav { display: flex; align-items: center; gap: 6px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 10px; }
        @media (max-width: 1200px) { .calendar-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (max-width: 768px) { .calendar-grid { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 10px; margin: 0 -16px; padding: 4px 16px 8px; scrollbar-width: none; } .calendar-grid::-webkit-scrollbar { display: none; } .calendar-grid > .day-card { flex: 0 0 72%; scroll-snap-align: start; } }
        .day-card { border: 1px solid var(--border); border-radius: 16px; padding: 12px; background: var(--surface); display: flex; flex-direction: column; gap: 8px; min-height: 150px; }
        .day-card.is-today { border-color: var(--accent-bright); box-shadow: 0 0 0 3px var(--accent-ring); }
        .day-card.is-completed { background: var(--green-dim); border-color: transparent; }
        .day-head { display: flex; justify-content: space-between; align-items: center; }
        .day-name { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        .day-num { font-size: 20px; font-weight: 800; letter-spacing: -.02em; }
        .day-num small { font-size: 12px; color: var(--muted); font-weight: 600; }
        .w-item { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 10px; }
        .w-item.is-done { background: transparent; border-style: dashed; }
        .w-type { font-weight: 650; font-size: 13.5px; display: flex; gap: 6px; align-items: center; min-width: 0; }
        .w-type span:last-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .diff { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 6px; }
        .diff.kolay { background: var(--green-dim); color: var(--green); }
        .diff.orta { background: var(--yellow-dim); color: var(--yellow); }
        .diff.zor { background: var(--red-dim); color: var(--red); }
        .btn-finish { width: 100%; margin-top: 8px; border: 0; border-radius: 10px; padding: 7px; font-weight: 700; font-size: 12.5px; background: var(--brand-grad); color: #fff; }
        .done-pill { margin-top: 8px; font-size: 12px; font-weight: 700; color: var(--green); display: flex; align-items: center; gap: 6px; }
        .rest { color: var(--muted); font-size: 13px; display: flex; align-items: center; gap: 6px; margin: auto 0; }
        .add-mini { margin-top: auto; border: 1px dashed var(--border-strong); background: transparent; color: var(--muted); border-radius: 10px; padding: 6px; font-size: 12.5px; font-weight: 600; }
        .add-mini:hover { color: var(--accent); border-color: var(--accent-bright); }
        

        /* Egzersiz günlüğü */
        .ex-form .row > * { min-width: 0; }
        .ex-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .ex-row:last-child { border-bottom: 0; }
        .ex-name { font-weight: 650; }
        .ex-meta { font-size: 12.5px; color: var(--muted); }
        .ex-meta b { color: var(--text); font-variant-numeric: tabular-nums; }
        .sum-chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .pr-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); cursor: pointer; }
        .pr-row:last-child { border-bottom: 0; }
        .pr-row:hover .ex-name { color: var(--accent); }
        .pr-val { text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pr-val small { display: block; font-size: 11px; color: var(--muted); font-weight: 600; }
        .chart-box { position: relative; height: 210px; }
        .day-chip { border: 1px solid var(--border-strong); border-radius: 12px; padding: 8px 10px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 6px; user-select: none; font-size: 13px; }
        .day-chip.active { background: var(--accent-dim); border-color: var(--accent-bright); color: var(--accent); }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Antrenman</div>
                <div class="topbar-sub">Planla, kaydet, gelişimini gör</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-ghost d-mobile-none" onclick="document.getElementById('exName').focus()"><i class="bi bi-journal-plus"></i><span>Egzersiz kaydet</span></button>
            <button class="btn-topbar btn-accent" onclick="openAddModal()"><i class="bi bi-plus-lg"></i><span>Planla</span></button>
        </div>
    </header>

    <div class="content">
        <!-- KPI -->
        <div class="kpis">
            <div class="card kpi"><div class="icon-tile"><i class="bi bi-trophy"></i></div><div style="min-width:0"><div class="v" id="kpiProgressText">0/0</div><div class="l">Bu hafta tamamlanan</div></div></div>
            <div class="card kpi"><div class="icon-tile purple"><i class="bi bi-calendar2-check"></i></div><div style="min-width:0"><div class="v" id="kpiTodayStatus">—</div><div class="l" id="kpiTodaySub">Bugün</div></div></div>
            <div class="card kpi"><div class="icon-tile yellow"><i class="bi bi-bar-chart"></i></div><div style="min-width:0"><div class="v" id="kpiVolume">—</div><div class="l">Bu haftanın hacmi</div></div></div>
            <div class="card kpi"><div class="icon-tile blue"><i class="bi bi-award"></i></div><div style="min-width:0"><div class="v" id="kpiLastCompleted">—</div><div class="l" id="kpiLastCompletedSub">Son antrenman</div></div></div>
        </div>

        <!-- Takvim -->
        <section class="card card-pad">
            <div class="card-head flex-wrap">
                <div>
                    <h2 class="card-title-sm"><i class="bi bi-calendar-week"></i>Haftalık plan</h2>
                    <div class="small mt-1" style="color:var(--muted)" id="weekRangeLabel">Yükleniyor…</div>
                </div>
                <div class="week-nav">
                    <button class="icon-btn" onclick="navigateWeek(-1)" aria-label="Önceki hafta"><i class="bi bi-chevron-left"></i></button>
                    <button class="btn btn-light btn-sm" onclick="goToCurrentWeek()" id="btnCurrentWeek">Bu hafta</button>
                    <button class="icon-btn" onclick="navigateWeek(1)" aria-label="Sonraki hafta"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
            <div class="calendar-grid" id="calendarGrid">
                <div class="empty-state" style="grid-column:1/-1"><span class="spinner-border spinner-border-sm me-2"></span>Takvim yükleniyor…</div>
            </div>
        </section>

        <div class="wo-grid">
            <div class="stack">
                <!-- Egzersiz günlüğü -->
                <section class="card card-pad">
                    <div class="card-head flex-wrap">
                        <h2 class="card-title-sm"><i class="bi bi-journal-text"></i>Egzersiz günlüğü</h2>
                        <input type="date" class="form-control form-control-sm" id="exDate" style="width:auto" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                    </div>

                    <form class="ex-form mb-3" id="exForm" autocomplete="off">
                        <div class="row g-2">
                            <div class="col-12 col-md-7">
                                <label class="form-label">Egzersiz</label>
                                <input type="text" class="form-control" id="exName" list="exNames" placeholder="ör. Bench Press" required maxlength="140">
                                <datalist id="exNames"><?php foreach ($commonExercises as $e): ?><option value="<?= htmlspecialchars($e) ?>"><?php endforeach; ?></datalist>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label">Bölge</label>
                                <select class="form-select" id="exGroup">
                                    <?php foreach ($muscleGroups as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-3"><label class="form-label">Set</label><input type="number" class="form-control" id="exSets" min="0" max="50" inputmode="numeric" placeholder="4"></div>
                            <div class="col-3"><label class="form-label">Tekrar</label><input type="number" class="form-control" id="exReps" min="0" max="500" inputmode="numeric" placeholder="8"></div>
                            <div class="col-3"><label class="form-label">Kg</label><input type="number" class="form-control" id="exWeight" min="0" step="0.5" inputmode="decimal" placeholder="60"></div>
                            <div class="col-3"><label class="form-label">Dk</label><input type="number" class="form-control" id="exMin" min="0" max="1440" inputmode="numeric" placeholder="—"></div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between gap-2 mt-2">
                            <div class="small" style="color:var(--muted)" id="exLastHint"></div>
                            <button class="btn btn-primary px-4" type="submit"><i class="bi bi-plus-lg me-1"></i>Kaydet</button>
                        </div>
                    </form>

                    <div class="sum-chips mb-2" id="exSummary"></div>
                    <div id="exList"></div>
                </section>
            </div>

            <div class="stack">
                <!-- Haftalık hacim -->
                <section class="card card-pad">
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-bar-chart-line"></i>Haftalık hacim</h2><span class="chip">8 hafta</span></div>
                    <div class="chart-box"><canvas id="volumeChart"></canvas></div>
                    <div class="small mt-2" style="color:var(--muted)">Hacim = set × tekrar × ağırlık (kg)</div>
                </section>

                <!-- Rekorlar -->
                <section class="card card-pad">
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-award"></i>Kişisel rekorlar</h2></div>
                    <div id="prList"><div class="small" style="color:var(--muted)">Yükleniyor…</div></div>
                </section>

                <!-- Gelişim -->
                <section class="card card-pad" id="progressCard" hidden>
                    <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-graph-up-arrow"></i><span id="progressTitle">Gelişim</span></h2></div>
                    <div class="chart-box"><canvas id="progressChart"></canvas></div>
                    <div class="small mt-2" style="color:var(--muted)">Tahmini 1 tekrar maksimum (Epley formülü) ve günün en ağır seti.</div>
                </section>
            </div>
        </div>
    </div>
</div>

<!-- ═══════ PLANLAMA MODALI ═══════ -->
<div class="modal fade" id="workoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="workoutModalTitle">Antrenman planla</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <form id="workoutForm" onsubmit="handleWorkoutSubmit(event)">
                <input type="hidden" name="workout_id" id="modalWorkoutId" value="">
                <div class="modal-body">
                    <div class="mb-3" id="multiDaySection">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Günler</label>
                            <span class="chip" id="selectedDaysBadge">0 gün</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <button type="button" class="btn btn-light btn-sm" onclick="applyDayPreset('mwf')">Pzt · Çar · Cum</button>
                            <button type="button" class="btn btn-light btn-sm" onclick="applyDayPreset('tt')">Sal · Per</button>
                            <button type="button" class="btn btn-light btn-sm" onclick="applyDayPreset('weekdays')">Hafta içi</button>
                            <button type="button" class="btn btn-light btn-sm" onclick="applyDayPreset('all')">Her gün</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="applyDayPreset('clear')">Temizle</button>
                        </div>
                        <div class="row g-2" id="weekDayChipsContainer"></div>
                        <div class="input-group input-group-sm mt-2" style="max-width:320px">
                            <input type="date" class="form-control" id="extraDateInput">
                            <button type="button" class="btn btn-outline-primary" onclick="addCustomDate()">Tarih ekle</button>
                        </div>
                    </div>
                    <div class="mb-3" id="repeatWeeksSection">
                        <label class="form-label">Tekrar</label>
                        <select class="form-select" name="repeat_weeks" id="modalRepeatWeeks">
                            <option value="1" selected>Yalnızca bu hafta</option>
                            <option value="2">2 hafta boyunca</option>
                            <option value="3">3 hafta boyunca</option>
                            <option value="4">4 hafta boyunca</option>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="singleDateSection">
                        <label class="form-label">Tarih</label>
                        <input type="date" class="form-control" id="singleDateInput" readonly>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-7">
                            <label class="form-label">Antrenman tipi</label>
                            <select class="form-select" name="antrenman_tipi" id="modalType" required>
                                <option value="" disabled selected>Seçin…</option>
                                <option value="Ağırlık Antrenmanı">🏋️ Ağırlık antrenmanı</option>
                                <option value="Kardiyo & Koşu">🏃 Kardiyo / koşu / bisiklet</option>
                                <option value="Fonksiyonel Fitness">🤸 Fonksiyonel / CrossFit</option>
                                <option value="HIIT & Kondisyon">⚡ HIIT / kondisyon</option>
                                <option value="Pilates & Mobilite">🧘 Pilates / yoga / esneme</option>
                                <option value="Yüzme">🏊 Yüzme</option>
                                <option value="Dövüş Sporları / Boks">🥊 Dövüş sporları / boks</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Zorluk</label>
                            <select class="form-select" name="zorluk_seviyesi" id="modalDifficulty" required>
                                <option value="Kolay">Kolay</option>
                                <option value="Orta" selected>Orta</option>
                                <option value="Zor">Zor</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary px-4" id="btnSaveWorkout"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const API = () => `${window.API_BASE}/workout.php`;
const escapeHtml = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
const n0 = v => Math.round(parseFloat(v || 0)).toLocaleString('tr-TR');
const n1 = v => parseFloat(v || 0).toLocaleString('tr-TR', { maximumFractionDigits: 1 });
const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const toast = (title, icon = 'success') => Swal.fire({ toast: true, position: 'top', icon, title, showConfirmButton: false, timer: 2000 });
async function post(params) {
    const fd = new FormData();
    Object.entries(params).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(API(), { method: 'POST', body: fd, credentials: 'include' });
    return r.json();
}
function formatDateToIso(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
function getTypeIcon(type) {
    if (/ağırlık|güç|body/i.test(type)) return '🏋️';
    if (/kardiyo|koşu|bisiklet/i.test(type)) return '🏃';
    if (/hiit|tabata/i.test(type)) return '⚡';
    if (/pilates|yoga/i.test(type)) return '🧘';
    if (/yüzme/i.test(type)) return '🏊';
    if (/fonksiyonel|crossfit/i.test(type)) return '🤸';
    if (/dövüş|boks/i.test(type)) return '🥊';
    return '💪';
}

/* ═════════ HAFTALIK PLAN ═════════ */
let currentRefDate = new Date();
let workoutModalInstance = null;
let currentLoadedWeek = null;
let selectedPlanDates = new Set();
let isEditMode = false;

async function loadWeekData(dateStr) {
    const grid = document.getElementById('calendarGrid');
    try {
        const res = await fetch(`${API()}?action=get_week&date=${encodeURIComponent(dateStr)}`, { credentials: 'include' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Veri yüklenemedi');
        currentLoadedWeek = data;
        renderCalendar(data);
        updateSummaryKPIs(data);
    } catch (err) {
        grid.innerHTML = `<div class="alert alert-danger" style="grid-column:1/-1">${escapeHtml(err.message)}</div>`;
    }
}

function renderCalendar(data) {
    const s = new Date(data.week_start + 'T00:00'), e = new Date(data.week_end + 'T00:00');
    document.getElementById('weekRangeLabel').textContent =
        `${s.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long' })} – ${e.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' })}`;
    const isThisWeek = data.days.some(d => d.is_today);
    document.getElementById('btnCurrentWeek').classList.toggle('btn-primary', !isThisWeek);
    document.getElementById('btnCurrentWeek').classList.toggle('btn-light', isThisWeek);

    document.getElementById('calendarGrid').innerHTML = data.days.map(day => {
        const ws = day.workouts || (day.workout ? [day.workout] : []);
        const allDone = ws.length && ws.every(w => !!w.tamamlandi_mi);
        const items = ws.map(w => {
            const done = !!w.tamamlandi_mi;
            const diff = (w.zorluk_seviyesi || 'Orta').toLowerCase();
            return `<div class="w-item ${done ? 'is-done' : ''}">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <div class="w-type"><span>${getTypeIcon(w.antrenman_tipi)}</span><span title="${escapeHtml(w.antrenman_tipi)}">${escapeHtml(w.antrenman_tipi)}</span></div>
                    <div class="dropdown">
                        <button class="icon-btn" style="width:28px;height:28px;border:0" data-bs-toggle="dropdown" aria-label="İşlemler"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="event.preventDefault();openEditModal(${w.id}, '${escapeHtml(day.date)}', ${escapeHtml(JSON.stringify(w.antrenman_tipi))}, '${escapeHtml(w.zorluk_seviyesi)}')"><i class="bi bi-pencil me-2"></i>Düzenle</a></li>
                            <li><a class="dropdown-item" href="#" style="color:var(--red)" onclick="event.preventDefault();confirmDeleteWorkout(${w.id})"><i class="bi bi-trash3 me-2"></i>Kaldır</a></li>
                        </ul>
                    </div>
                </div>
                <span class="diff ${diff}">${escapeHtml(w.zorluk_seviyesi)}</span>
                ${done ? `<div class="done-pill"><i class="bi bi-check-circle-fill"></i>Tamamlandı ${w.tamamlanma_saati ? '· ' + w.tamamlanma_saati.slice(0, 5) : ''}</div>`
                       : `<button class="btn-finish" onclick="completeWorkout(${w.id}, this)"><i class="bi bi-check2 me-1"></i>Tamamladım</button>`}
            </div>`;
        }).join('');
        return `<div class="day-card ${day.is_today ? 'is-today' : ''} ${allDone ? 'is-completed' : ''}">
            <div class="day-head">
                <div><div class="day-name">${escapeHtml(day.day_name)}</div><div class="day-num">${day.day_number} <small>${escapeHtml(day.month_name)}</small></div></div>
                ${day.is_today ? '<span class="chip accent" style="padding:2px 8px;font-size:11px">Bugün</span>' : ''}
            </div>
            ${ws.length ? `<div class="w-list d-flex flex-column gap-2">${items}</div>` : '<div class="rest"><i class="bi bi-moon-stars"></i>Dinlenme</div>'}
            <button class="add-mini" onclick="openAddModal('${day.date}')"><i class="bi bi-plus-lg me-1"></i>Ekle</button>
        </div>`;
    }).join('');
    const todayCard = document.querySelector('.day-card.is-today');
    if (todayCard && window.innerWidth <= 768) todayCard.parentElement.scrollLeft = todayCard.offsetLeft - 16;
}

function updateSummaryKPIs(data) {
    const planned = data.total_planned || 0, completed = data.total_completed || 0;
    document.getElementById('kpiProgressText').textContent = `${completed}/${planned}`;
    const all = [];
    (data.days || []).forEach(d => (d.workouts || (d.workout ? [d.workout] : [])).forEach(w => all.push({ ...w, day_name: d.day_name })));
    const done = all.filter(w => !!w.tamamlandi_mi);
    const last = done[done.length - 1];
    document.getElementById('kpiLastCompleted').textContent = last ? last.antrenman_tipi : '—';
    document.getElementById('kpiLastCompletedSub').textContent = last ? `Son antrenman · ${last.day_name}` : 'Bu hafta henüz yok';
    const today = (data.days || []).find(d => d.is_today);
    const tw = today ? (today.workouts || (today.workout ? [today.workout] : [])) : [];
    if (tw.length) {
        const c = tw.filter(w => !!w.tamamlandi_mi).length;
        document.getElementById('kpiTodayStatus').textContent = c === tw.length ? 'Tamamlandı ✓' : `${c}/${tw.length} yapıldı`;
        document.getElementById('kpiTodaySub').textContent = tw.map(w => w.antrenman_tipi).join(', ');
    } else if (today) {
        document.getElementById('kpiTodayStatus').textContent = 'Dinlenme';
        document.getElementById('kpiTodaySub').textContent = 'Bugün plan yok';
    }
}

async function completeWorkout(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    try {
        const data = await post({ action: 'complete', workout_id: id });
        if (!data.ok) throw new Error(data.error || 'İşlem tamamlanamadı');
        Swal.fire({ icon: 'success', title: 'Tebrikler! 🏆', text: 'Antrenman tamamlandı. Yaptığın egzersizleri günlüğe eklemeyi unutma.', confirmButtonText: 'Harika' });
        loadWeekData(formatDateToIso(currentRefDate));
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Hata', text: e.message });
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Tamamladım'; }
    }
}

function renderWeekDayChips() {
    const c = document.getElementById('weekDayChipsContainer');
    if (!currentLoadedWeek?.days?.length) { c.innerHTML = ''; return; }
    const weekSet = new Set(currentLoadedWeek.days.map(d => d.date));
    const chips = currentLoadedWeek.days.map(d => [d.date, d.day_name, `${d.day_number} ${d.month_name}`]);
    selectedPlanDates.forEach(ds => { if (!weekSet.has(ds)) chips.push([ds, 'Özel', ds]); });
    c.innerHTML = chips.map(([ds, n, sub]) => `<div class="col-6 col-sm-4 col-md-3">
        <div class="day-chip ${selectedPlanDates.has(ds) ? 'active' : ''}" onclick="togglePlanDate('${ds}')">
            <div><div style="font-size:11px;font-weight:700;opacity:.8">${escapeHtml(n)}</div><div style="font-weight:700">${escapeHtml(sub)}</div></div>
            <i class="bi ${selectedPlanDates.has(ds) ? 'bi-check-circle-fill' : 'bi-circle'}"></i>
        </div></div>`).join('');
    document.getElementById('selectedDaysBadge').textContent = `${selectedPlanDates.size} gün`;
}
function togglePlanDate(ds) { selectedPlanDates.has(ds) ? selectedPlanDates.delete(ds) : selectedPlanDates.add(ds); renderWeekDayChips(); }
function applyDayPreset(p) {
    if (!currentLoadedWeek?.days) return;
    selectedPlanDates.clear();
    const d = currentLoadedWeek.days;
    const idx = { mwf: [0, 2, 4], tt: [1, 3], weekdays: [0, 1, 2, 3, 4], all: [0, 1, 2, 3, 4, 5, 6] }[p] || [];
    idx.forEach(i => d[i] && selectedPlanDates.add(d[i].date));
    renderWeekDayChips();
}
function addCustomDate() { const i = document.getElementById('extraDateInput'); if (i.value) { selectedPlanDates.add(i.value); i.value = ''; renderWeekDayChips(); } }

async function handleWorkoutSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveWorkout');
    const fd = new FormData(document.getElementById('workoutForm'));
    fd.append('action', 'save');
    if (isEditMode) {
        fd.append('tarih', document.getElementById('singleDateInput').value);
    } else {
        if (!selectedPlanDates.size) { toast('En az bir gün seçin', 'warning'); return; }
        fd.append('tarihler', [...selectedPlanDates].join(','));
    }
    btn.disabled = true;
    try {
        const res = await fetch(API(), { method: 'POST', body: fd, credentials: 'include' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Kaydedilemedi');
        workoutModalInstance.hide();
        toast(isEditMode ? 'Antrenman güncellendi' : 'Antrenman planlandı');
        loadWeekData(formatDateToIso(currentRefDate));
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Hata', text: err.message });
    } finally { btn.disabled = false; }
}

async function confirmDeleteWorkout(id) {
    const r = await Swal.fire({ icon: 'warning', title: 'Antrenman kaldırılsın mı?', showCancelButton: true, confirmButtonText: 'Kaldır', cancelButtonText: 'Vazgeç' });
    if (!r.isConfirmed) return;
    const data = await post({ action: 'delete', workout_id: id });
    if (data.ok) { toast('Kaldırıldı', 'info'); loadWeekData(formatDateToIso(currentRefDate)); }
    else Swal.fire({ icon: 'error', title: 'Hata', text: data.error || '' });
}

function openAddModal(dateStr = '') {
    isEditMode = false;
    document.getElementById('workoutModalTitle').textContent = 'Antrenman planla';
    document.getElementById('modalWorkoutId').value = '';
    document.getElementById('multiDaySection').classList.remove('d-none');
    document.getElementById('repeatWeeksSection').classList.remove('d-none');
    document.getElementById('singleDateSection').classList.add('d-none');
    document.getElementById('modalType').value = '';
    document.getElementById('modalDifficulty').value = 'Orta';
    document.getElementById('modalRepeatWeeks').value = '1';
    selectedPlanDates.clear();
    selectedPlanDates.add(dateStr || formatDateToIso(new Date()));
    renderWeekDayChips();
    workoutModalInstance.show();
}
function openEditModal(id, dateStr, type, diff) {
    isEditMode = true;
    document.getElementById('workoutModalTitle').textContent = 'Antrenmanı düzenle';
    document.getElementById('modalWorkoutId').value = id;
    document.getElementById('multiDaySection').classList.add('d-none');
    document.getElementById('repeatWeeksSection').classList.add('d-none');
    document.getElementById('singleDateSection').classList.remove('d-none');
    document.getElementById('singleDateInput').value = dateStr;
    document.getElementById('modalType').value = type;
    document.getElementById('modalDifficulty').value = diff;
    workoutModalInstance.show();
}
function navigateWeek(delta) { currentRefDate.setDate(currentRefDate.getDate() + delta * 7); loadWeekData(formatDateToIso(currentRefDate)); }
function goToCurrentWeek() { currentRefDate = new Date(); loadWeekData(formatDateToIso(currentRefDate)); }

/* ═════════ EGZERSİZ GÜNLÜĞÜ ═════════ */
const GROUP_KEYS = <?= json_encode(array_flip($muscleGroups), JSON_UNESCAPED_UNICODE) ?>;
let knownNames = {};

async function loadExercises() {
    const date = document.getElementById('exDate').value;
    const data = await post({ action: 'exercises', date });
    if (!data.ok) return;
    const s = data.summary;
    document.getElementById('exSummary').innerHTML = s.count ? `
        <span class="chip">${s.count} egzersiz</span>
        ${s.sets ? `<span class="chip">${s.sets} set</span>` : ''}
        ${s.volume ? `<span class="chip yellow">${n0(s.volume)} kg hacim</span>` : ''}
        ${s.minutes ? `<span class="chip blue">${s.minutes} dk</span>` : ''}` : '';
    document.getElementById('exList').innerHTML = data.items.length ? data.items.map(x => {
        const parts = [];
        if (x.sets || x.reps) parts.push(`<b>${x.sets || 1} × ${x.reps || '—'}</b>`);
        if (x.weight_kg) parts.push(`<b>${n1(x.weight_kg)} kg</b>`);
        if (x.duration_minutes) parts.push(`<b>${x.duration_minutes} dk</b>`);
        if (x.e1rm) parts.push(`1RM ≈ ${n1(x.e1rm)} kg`);
        return `<div class="ex-row">
            <div class="icon-tile sm"><i class="bi ${x.duration_minutes && !x.weight_kg ? 'bi-stopwatch' : 'bi-lightning-charge'}"></i></div>
            <div style="flex:1;min-width:0">
                <div class="ex-name text-truncate">${escapeHtml(x.exercise_name)}</div>
                <div class="ex-meta">${escapeHtml(x.muscle_group || '')}${x.muscle_group ? ' · ' : ''}${parts.join(' · ')}</div>
            </div>
            <button class="icon-btn" style="border:0" title="Tekrar ekle" onclick='repeatExercise(${JSON.stringify(x).replace(/&/g, "&amp;").replace(/'/g, "&#39;")})'><i class="bi bi-arrow-repeat"></i></button>
            <button class="icon-btn danger" style="border:0" title="Sil" onclick="deleteExercise(${x.id})"><i class="bi bi-trash3"></i></button>
        </div>`;
    }).join('') : '<div class="empty-state py-3"><i class="bi bi-journal"></i>Bu gün için egzersiz kaydı yok.</div>';
}

function repeatExercise(x) {
    document.getElementById('exName').value = x.exercise_name;
    if (x.muscle_group && GROUP_KEYS[x.muscle_group]) document.getElementById('exGroup').value = GROUP_KEYS[x.muscle_group];
    document.getElementById('exSets').value = x.sets || '';
    document.getElementById('exReps').value = x.reps || '';
    document.getElementById('exWeight').value = x.weight_kg || '';
    document.getElementById('exMin').value = x.duration_minutes || '';
    document.getElementById('exForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function deleteExercise(id) {
    const data = await post({ action: 'delete_exercise', id });
    if (data.ok) { toast('Silindi', 'info'); loadExercises(); loadStats(); }
}

document.getElementById('exForm').addEventListener('submit', async e => {
    e.preventDefault();
    const payload = {
        action: 'log_exercise',
        date: document.getElementById('exDate').value,
        exercise_name: document.getElementById('exName').value.trim(),
        muscle_group: document.getElementById('exGroup').value,
        sets: document.getElementById('exSets').value || 0,
        reps: document.getElementById('exReps').value || 0,
        weight_kg: document.getElementById('exWeight').value || 0,
        duration_minutes: document.getElementById('exMin').value || 0,
    };
    const data = await post(payload);
    if (!data.ok) { Swal.fire({ icon: 'error', title: 'Kaydedilemedi', text: data.error || '' }); return; }
    toast('Egzersiz kaydedildi');
    ['exSets', 'exReps', 'exWeight', 'exMin'].forEach(i => document.getElementById(i).value = '');
    document.getElementById('exLastHint').textContent = '';
    loadExercises(); loadStats();
    showProgress(payload.exercise_name);
});

// Egzersiz adı seçilince son kaydı öner
let hintTimer = null;
document.getElementById('exName').addEventListener('input', e => {
    clearTimeout(hintTimer);
    const name = e.target.value.trim();
    if (knownNames[name.toLowerCase()] && GROUP_KEYS[knownNames[name.toLowerCase()]]) document.getElementById('exGroup').value = GROUP_KEYS[knownNames[name.toLowerCase()]];
    if (name.length < 3) { document.getElementById('exLastHint').textContent = ''; return; }
    hintTimer = setTimeout(async () => {
        const d = await post({ action: 'exercise_progress', name });
        const l = d.last;
        const hint = document.getElementById('exLastHint');
        if (l) {
            hint.innerHTML = `Son: <b style="color:var(--text)">${l.sets || 1}×${l.reps || '—'}${l.weight_kg ? ' · ' + n1(l.weight_kg) + ' kg' : ''}${l.duration_minutes ? ' · ' + l.duration_minutes + ' dk' : ''}</b> <a href="#" style="color:var(--accent);font-weight:600">doldur</a>`;
            hint.querySelector('a').onclick = ev => { ev.preventDefault(); repeatExercise({ exercise_name: name, muscle_group: l.muscle_group, sets: l.sets, reps: l.reps, weight_kg: l.weight_kg ? parseFloat(l.weight_kg) : null, duration_minutes: l.duration_minutes }); };
        } else hint.textContent = '';
    }, 400);
});
document.getElementById('exDate').addEventListener('change', loadExercises);

/* ═════════ İSTATİSTİK & GRAFİKLER ═════════ */
let volChart = null, progChart = null, lastStats = null, lastProgress = null;

function chartTheme() { return { grid: cssVar('--border'), muted: cssVar('--muted'), accent: cssVar('--accent-bright'), purple: cssVar('--purple'), yellow: cssVar('--c-carb') }; }

async function loadStats() {
    const d = await post({ action: 'exercise_stats' });
    if (!d.ok) return;
    lastStats = d;
    (d.names || []).forEach(n => { knownNames[String(n.exercise_name).toLowerCase()] = n.muscle_group; });
    const dl = document.getElementById('exNames');
    const existing = new Set([...dl.options].map(o => o.value.toLowerCase()));
    (d.names || []).forEach(n => { if (!existing.has(String(n.exercise_name).toLowerCase())) { const o = document.createElement('option'); o.value = n.exercise_name; dl.prepend(o); } });

    const thisWeek = d.weekly[d.weekly.length - 1];
    document.getElementById('kpiVolume').textContent = thisWeek && thisWeek.volume ? `${n0(thisWeek.volume)} kg` : (thisWeek && thisWeek.minutes ? `${thisWeek.minutes} dk` : '—');

    document.getElementById('prList').innerHTML = d.records.length ? d.records.map(r => `
        <div class="pr-row" onclick="showProgress(${escapeHtml(JSON.stringify(r.exercise_name))})">
            <div class="icon-tile sm yellow"><i class="bi bi-trophy"></i></div>
            <div style="flex:1;min-width:0"><div class="ex-name text-truncate">${escapeHtml(r.exercise_name)}</div>
            <div class="ex-meta">${r.sessions} kayıt · ${new Date(r.date + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })}</div></div>
            <div class="pr-val">${n1(r.max_weight)} kg × ${r.max_reps_at_max || 1}<small>1RM ≈ ${n1(r.e1rm)} kg</small></div>
        </div>`).join('') : '<div class="small" style="color:var(--muted)">Ağırlıklı egzersiz kaydettikçe rekorlarınız burada görünür.</div>';
    drawVolume();
    if (!lastProgress && d.records[0]) showProgress(d.records[0].exercise_name);
}

function drawVolume() {
    if (!lastStats || typeof Chart === 'undefined') return;
    const t = chartTheme();
    if (volChart) volChart.destroy();
    volChart = new Chart(document.getElementById('volumeChart'), {
        type: 'bar',
        data: { labels: lastStats.weekly.map(w => w.label), datasets: [{ label: 'Hacim (kg)', data: lastStats.weekly.map(w => w.volume), backgroundColor: t.accent, borderRadius: 8, maxBarThickness: 26 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${n0(c.raw)} kg · ${lastStats.weekly[c.dataIndex].active_days} gün` } } },
            scales: { x: { grid: { display: false }, ticks: { color: t.muted }, border: { display: false } }, y: { beginAtZero: true, grid: { color: t.grid }, ticks: { color: t.muted, maxTicksLimit: 5 }, border: { display: false } } }
        }
    });
}

async function showProgress(name) {
    const d = await post({ action: 'exercise_progress', name });
    if (!d.ok || !d.points.length) return;
    lastProgress = d;
    document.getElementById('progressCard').hidden = false;
    document.getElementById('progressTitle').textContent = name;
    drawProgress();
}
function drawProgress() {
    if (!lastProgress || typeof Chart === 'undefined') return;
    const t = chartTheme();
    if (progChart) progChart.destroy();
    const pts = lastProgress.points;
    progChart = new Chart(document.getElementById('progressChart'), {
        type: 'line',
        data: {
            labels: pts.map(p => new Date(p.date + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' })),
            datasets: [
                { label: 'Tahmini 1RM', data: pts.map(p => p.e1rm), borderColor: t.purple, backgroundColor: t.purple, tension: .3, pointRadius: 3 },
                { label: 'En ağır set', data: pts.map(p => p.max_weight), borderColor: t.accent, backgroundColor: t.accent, tension: .3, pointRadius: 3 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: t.muted, usePointStyle: true, boxWidth: 8 } } },
            scales: { x: { grid: { display: false }, ticks: { color: t.muted }, border: { display: false } }, y: { grid: { color: t.grid }, ticks: { color: t.muted, maxTicksLimit: 5 }, border: { display: false } } }
        }
    });
}
document.addEventListener('opti:theme', () => { drawVolume(); drawProgress(); });

/* ═════════ BAŞLAT ═════════ */
workoutModalInstance = new bootstrap.Modal(document.getElementById('workoutModal'));
loadWeekData(formatDateToIso(currentRefDate));
loadExercises();
loadStats();
</script>
</body>
</html>

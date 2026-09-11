<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Spor & Antrenman Modülü (Workout Module)
 *
 * Mimarisi:
 * - Backend: PHP 8+, MySQL (PDO), WorkoutService
 * - Frontend: Bootstrap 5, Bootstrap Icons, SweetAlert2, Vanilla JS & Fetch API
 * - Özellikler:
 *   1. Haftalık 7 Günlük Antrenman Takvimi (Pazartesi - Pazar)
 *   2. Dinamik Makro Senkronizasyonu (+400 kcal, +30g protein)
 *   3. Antrenman Sonrası 15 Dk Takviye Tetikleyicisi (Whey Protein & Magnezyum)
 *   4. Sayfa yenilenmeden tam AJAX / Fetch API etkileşimi
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/WorkoutService.php';

$activePage = 'workout';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - Spor & Antrenman Planı</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>

    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- OptiLifeSync Sidebar & Layout CSS -->
    <link rel="stylesheet" href="assets/css/sidebar.css">
    
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="assets/js/alarm-engine.js"></script>

    <style>
        :root {
            --card-bg: #ffffff;
            --card-hover: #fdfbf7;
            --card-border: #e2ddd3;
            --accent-green: #16a34a;
            --accent-yellow: #d97706;
            --accent-red: #dc2626;
            --accent-blue: #0284c7;
            --accent-purple: #9333ea;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.15rem;
            height: 100%;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .stat-card:hover {
            border-color: rgba(2, 132, 199, 0.3);
            transform: translateY(-2px);
        }

        /* Haftalık Takvim Tablosu / Izgarası */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.85rem;
        }
        @media (max-width: 1200px) {
            .calendar-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        @media (max-width: 768px) {
            .calendar-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 576px) {
            .calendar-grid {
                grid-template-columns: 1fr;
            }
            #btnCurrentWeek {
                padding-left: 8px !important;
                padding-right: 8px !important;
                font-size: 11px !important;
            }
            .topbar-right .btn-group {
                margin-right: 4px !important;
            }
            .topbar-right .btn-topbar {
                padding: 6px 8px !important;
            }
        }

        .day-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 270px;
            padding: 1rem;
            position: relative;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .day-card:hover {
            background: var(--card-hover);
            border-color: rgba(2, 132, 199, 0.3);
        }
        .day-card.is-today {
            border-color: var(--accent);
            box-shadow: 0 0 16px rgba(2, 132, 199, 0.12);
            background: linear-gradient(180deg, rgba(2, 132, 199, 0.05) 0%, #ffffff 100%);
        }
        .day-card.has-workout {
            border-top: 3px solid #ea580c;
        }
        .day-card.is-completed {
            border-top: 3px solid var(--accent-green);
        }

        .day-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.6rem;
            margin-bottom: 0.75rem;
        }
        .day-title {
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .day-date {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text);
        }

        /* Dinamik Makro Rozeti */
        .macro-badge {
            background: rgba(234, 88, 12, 0.12);
            border: 1px solid rgba(234, 88, 12, 0.25);
            color: #c2410c;
            font-size: 0.73rem;
            font-weight: 700;
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin: 0.6rem 0;
            box-shadow: 0 1px 4px rgba(234, 88, 12, 0.08);
        }
        .macro-badge.inactive {
            background: var(--surface-2);
            border-color: var(--border);
            color: var(--muted);
        }

        /* Antrenman Tipi & Zorluk */
        .workout-type-tag {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .diff-tag {
            font-size: 0.68rem;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-weight: 600;
        }
        .diff-kolay  { background: rgba(22, 163, 74, 0.12); color: #16a34a; border: 1px solid rgba(22, 163, 74, 0.25); }
        .diff-orta   { background: rgba(217, 119, 6, 0.12); color: #d97706; border: 1px solid rgba(217, 119, 6, 0.25); }
        .diff-zor    { background: rgba(220, 38, 38, 0.12); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.25); }

        /* Butonlar */
        .btn-finish-workout {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: #fff;
            font-weight: 700;
            font-size: 0.8rem;
            border: none;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(22, 163, 74, 0.2);
        }
        .btn-finish-workout:hover {
            background: linear-gradient(135deg, #15803d 0%, #166534 100%);
            transform: translateY(-1px);
            color: #fff;
        }

        .completed-pill {
            background: rgba(22, 163, 74, 0.12);
            border: 1px solid rgba(22, 163, 74, 0.25);
            color: #16a34a;
            font-size: 0.76rem;
            font-weight: 700;
            border-radius: 8px;
            padding: 0.45rem 0.6rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }

        .post-workout-alert {
            background: rgba(2, 132, 199, 0.08);
            border: 1px dashed rgba(2, 132, 199, 0.3);
            color: #0369a1;
            font-size: 0.72rem;
            border-radius: 8px;
            padding: 0.4rem 0.55rem;
            margin-top: 0.5rem;
            line-height: 1.35;
        }

        .empty-day {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 120px;
            color: var(--muted);
            text-align: center;
        }

        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 16px;
        }
        .form-control, .form-select {
            background-color: #ffffff;
            border: 1px solid var(--border);
            color: var(--text);
        }
        .form-control:focus, .form-select:focus {
            background-color: #ffffff;
            border-color: var(--accent);
            color: var(--text);
            box-shadow: 0 0 0 0.25rem rgba(2, 132, 199, 0.15);
        }
        .day-chip {
            cursor: pointer;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 10px;
            background: #f8fafc;
            color: #334155;
            transition: all 0.18s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        .day-chip:hover {
            border-color: #0284c7;
            background: #f0f9ff;
        }
        .day-chip.active {
            background: #0284c7 !important;
            border-color: #0284c7 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<!-- ═══════════════════════ MAIN İÇERİK ════════════════════════════ -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Spor & Antrenman Planı</div>
            </div>
        </div>
        <div class="topbar-right">
            <!-- Hafta Navigasyonu -->
            <div class="btn-group me-2" role="group">
                <button class="btn btn-sm btn-outline-secondary text-light" onclick="navigateWeek(-1)" title="Önceki Hafta">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary text-light px-3 fw-semibold" onclick="goToCurrentWeek()" id="btnCurrentWeek">
                    Bu Hafta
                </button>
                <button class="btn btn-sm btn-outline-secondary text-light" onclick="navigateWeek(1)" title="Sonraki Hafta">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <!-- Yeni Antrenman Butonu -->
            <button class="btn-topbar btn-accent" onclick="openAddModal()">
                <i class="bi bi-plus-lg"></i>
                <span class="d-none d-sm-inline">Antrenman Ekle</span>
            </button>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="content">

        <!-- ── 1. ÖZET KPI KARTLARI ─────────────────────────────── -->
        <div class="row g-3 mb-4">
            <!-- İlerleme -->
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small fw-semibold">HAFTALIK İLERLEME</span>
                        <div class="p-2 rounded-3" style="background:rgba(56,189,248,0.1);color:#38bdf8;">
                            <i class="bi bi-trophy-fill"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-baseline gap-2">
                        <div class="fs-4 fw-bold text-light" id="kpiProgressText">0 / 0</div>
                        <span class="text-secondary small" id="kpiProgressPct">(%0)</span>
                    </div>
                    <div class="progress mt-2" style="height: 6px; background: rgba(255,255,255,0.08);">
                        <div class="progress-fill" id="kpiProgressBar" style="width: 0%; background: #38bdf8; border-radius: 6px;"></div>
                    </div>
                </div>
            </div>

            <!-- Bugünkü Durum -->
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small fw-semibold">BUGÜNÜN DURUMU</span>
                        <div class="p-2 rounded-3" style="background:rgba(16,185,129,0.1);color:#10b981;">
                            <i class="bi bi-calendar2-check"></i>
                        </div>
                    </div>
                    <div class="fs-5 fw-bold text-light" id="kpiTodayStatus">Yükleniyor…</div>
                    <small class="text-secondary" id="kpiTodaySub">Lütfen bekleyin</small>
                </div>
            </div>

            <!-- Beslenme Etkisi -->
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small fw-semibold">BESLENME ETKİSİ</span>
                        <div class="p-2 rounded-3" style="background:rgba(16,185,129,0.1);color:#10b981;">
                            <i class="bi bi-shield-check"></i>
                        </div>
                    </div>
                    <div class="fs-5 fw-bold text-success" id="kpiMacroText">Sabit Hedef</div>
                    <small class="text-secondary" id="kpiMacroSub">Kalori hedefleri sabit korunur</small>
                </div>
            </div>

            <!-- Son Tamamlanan -->
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-secondary small fw-semibold">SON TAMAMLANAN</span>
                        <div class="p-2 rounded-3" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                            <i class="bi bi-award"></i>
                        </div>
                    </div>
                    <div class="fs-5 fw-bold text-light" id="kpiLastCompleted">—</div>
                    <small class="text-secondary" id="kpiLastCompletedSub">Aktivite takibi</small>
                </div>
            </div>
        </div>

        <!-- ── 2. HAFTALIK GÖRÜNÜM KARTI ────────────────────────── -->
        <div class="card p-3 p-md-4 shadow-sm mb-4" style="background:var(--surface); border:1px solid var(--border); border-radius:18px;">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25 gap-2">
                <div>
                    <h5 class="fw-bold text-light mb-1"><i class="bi bi-calendar-week me-2 text-info"></i>Haftalık Antrenman Takvimi</h5>
                    <p class="text-secondary small mb-0" id="weekRangeLabel">Yükleniyor…</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center gap-1 small text-secondary">
                        <span class="badge rounded-circle p-1 bg-info"> </span> Planlanan Antrenman
                    </div>
                    <div class="d-flex align-items-center gap-1 small text-secondary">
                        <span class="badge rounded-circle p-1 bg-success"> </span> Tamamlandı
                    </div>
                </div>
            </div>

            <!-- 7 Günlük Responsive Izgara -->
            <div class="calendar-grid" id="calendarGrid">
                <!-- JavaScript tarafından render edilecek -->
                <div class="text-center py-5 text-secondary col-12">
                    <div class="spinner-border spinner-border-sm text-info me-2" role="status"></div>
                    Takvim verileri yükleniyor…
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<!-- ═══════════════════════ MODAL: ANTRENMAN EKLE / DÜZENLE ═══════════════════════ -->
<div class="modal fade" id="workoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg" style="background:#ffffff; color:#1e293b; border-radius:18px; border:1px solid var(--border);">
            <div class="modal-header border-bottom" style="border-color:var(--border) !important;">
                <h5 class="modal-title fw-bold" id="workoutModalTitle" style="color:#0f172a;">
                    <i class="bi bi-activity text-primary me-2"></i>Antrenman Planla
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <form id="workoutForm" onsubmit="handleWorkoutSubmit(event)">
                <div class="modal-body p-3 p-md-4">
                    
                    <!-- ÇOKLU GÜN SEÇİMİ (Planlama / Ekleme Modu) -->
                    <div class="mb-3" id="multiDaySection">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold text-dark mb-0">
                                <i class="bi bi-calendar-check text-primary me-1"></i>Antrenman Günleri
                            </label>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold" id="selectedDaysBadge">
                                0 gün seçildi
                            </span>
                        </div>
                        <p class="text-secondary small mb-2" style="font-size:12.5px;">
                            Planlamak istediğiniz günlerin üzerine dokunarak birden fazla gün seçebilirsiniz:
                        </p>

                        <!-- Hızlı Seçim Şablonları -->
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 fw-semibold" style="font-size:11.5px; border-radius:6px;" onclick="applyDayPreset('mwf')">
                                🏋️ Pzt - Çar - Cum (3 Gün)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 fw-semibold" style="font-size:11.5px; border-radius:6px;" onclick="applyDayPreset('tt')">
                                🏃 Salı - Perş (2 Gün)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 fw-semibold" style="font-size:11.5px; border-radius:6px;" onclick="applyDayPreset('weekdays')">
                                ⚡ Hafta İçi (5 Gün)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 fw-semibold" style="font-size:11.5px; border-radius:6px;" onclick="applyDayPreset('all')">
                                Tüm Hafta
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2 fw-semibold" style="font-size:11.5px; border-radius:6px;" onclick="applyDayPreset('clear')">
                                Temizle
                            </button>
                        </div>

                        <!-- 7 Gün Chip Izgarası -->
                        <div class="row g-2" id="weekDayChipsContainer">
                            <!-- JS ile render edilecek -->
                        </div>

                        <!-- Ek Tarih Ekleme -->
                        <div class="mt-3 pt-2 border-top border-light-subtle">
                            <a class="small text-decoration-none text-primary fw-semibold d-inline-flex align-items-center gap-1" data-bs-toggle="collapse" href="#collapseExtraDate" role="button" aria-expanded="false">
                                <i class="bi bi-calendar-plus"></i> Başka bir tarih daha ekle
                            </a>
                            <div class="collapse mt-2" id="collapseExtraDate">
                                <div class="input-group input-group-sm" style="max-width:320px;">
                                    <input type="date" class="form-control text-dark" id="extraDateInput">
                                    <button type="button" class="btn btn-outline-primary fw-semibold" onclick="addCustomDate()">
                                        <i class="bi bi-plus-lg me-1"></i>Listeye Ekle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TEKİL TARİH SEÇİMİ (Düzenleme Modu) -->
                    <div class="mb-3 d-none" id="singleDateSection">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="bi bi-calendar3 text-primary me-1"></i>Antrenman Tarihi
                        </label>
                        <input type="date" class="form-control text-dark" id="singleDateInput" readonly style="background:#f8fafc; font-weight:600;">
                    </div>

                    <!-- Antrenman Tipi -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="bi bi-lightning-charge-fill text-warning me-1"></i>Antrenman Tipi
                        </label>
                        <select class="form-select text-dark fw-medium" name="antrenman_tipi" id="modalType" required>
                            <option value="" disabled selected>Bir antrenman tipi seçin…</option>
                            <option value="Ağırlık Antrenmanı">🏋️ Ağırlık Antrenmanı (Hipertrofi / Güç)</option>
                            <option value="Kardiyo & Koşu">🏃 Kardiyo / Koşu / Bisiklet</option>
                            <option value="Fonksiyonel Fitness">🤸 Fonksiyonel Fitness / CrossFit</option>
                            <option value="HIIT & Kondisyon">⚡ HIIT / Tabata / Çeviklik</option>
                            <option value="Pilates & Mobilite">🧘 Pilates / Yoga / Esneme</option>
                            <option value="Yüzme">🏊 Yüzme</option>
                            <option value="Dövüş Sporları / Boks">🥊 Dövüş Sporları / Boks</option>
                        </select>
                    </div>

                    <!-- Zorluk Seviyesi -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark mb-1">
                            <i class="bi bi-speedometer2 text-info me-1"></i>Zorluk Seviyesi
                        </label>
                        <select class="form-select text-dark fw-medium" name="zorluk_seviyesi" id="modalDifficulty" required>
                            <option value="Kolay">🟢 Kolay (Düşük Yoğunluk / Toparlanma)</option>
                            <option value="Orta" selected>🟡 Orta (Standart Antrenman Şiddeti)</option>
                            <option value="Zor">🔴 Zor (Ağır / Maksimal / Tükeniş)</option>
                        </select>
                    </div>

                </div>
                <div class="modal-footer border-top" style="border-color:var(--border) !important;">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-bold px-3" id="btnSaveWorkout">
                        <i class="bi bi-check-lg me-1"></i> Antrenmanı Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ══════════════════════════════════════════════════════════════════
// OptiLifeSync Spor Modülü Frontend Mantığı (Vanilla JS + Fetch API)
// ══════════════════════════════════════════════════════════════════

let currentRefDate = new Date();
let workoutModalInstance = null;
let currentLoadedWeek = null;
let selectedPlanDates = new Set();
let isEditMode = false;

document.addEventListener('DOMContentLoaded', () => {
    workoutModalInstance = new bootstrap.Modal(document.getElementById('workoutModal'));
    
    // İlk haftayı yükle
    loadWeekData(formatDateToIso(currentRefDate));

    // Tarayıcı bildirim izni iste
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
});

/**
 * Belirli bir tarihin haftasını Fetch API ile çeker ve UI'ı render eder
 */
async function loadWeekData(dateStr) {
    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = `
        <div class="text-center py-5 text-secondary col-12" style="grid-column: 1 / -1;">
            <div class="spinner-border spinner-border-sm text-info me-2" role="status"></div>
            Haftalık program getiriliyor…
        </div>`;

    try {
        const res = await fetch(`${window.API_BASE}/workout.php?action=get_week&date=${encodeURIComponent(dateStr)}`);
        const data = await res.json();

        if (!data.ok) {
            throw new Error(data.error || 'Veri yüklenemedi');
        }

        currentLoadedWeek = data;
        renderCalendar(data);
        updateSummaryKPIs(data);
    } catch (err) {
        console.error('Haftalık veri hatası:', err);
        grid.innerHTML = `
            <div class="alert alert-danger col-12" style="grid-column: 1 / -1;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> Veriler yüklenirken hata oluştu: ${err.message}
            </div>`;
    }
}

/**
 * 7 günlük takvim kartlarını oluşturur
 */
function renderCalendar(data) {
    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';

    // Hafta başlığı güncelle
    const startObj = new Date(data.week_start);
    const endObj = new Date(data.week_end);
    document.getElementById('weekRangeLabel').textContent = 
        `${startObj.toLocaleDateString('tr-TR', {day:'numeric', month:'long'})} — ${endObj.toLocaleDateString('tr-TR', {day:'numeric', month:'long', year:'numeric'})}`;

    data.days.forEach(day => {
        const card = document.createElement('div');
        const isCompleted = day.has_workout && day.workout.tamamlandi_mi;
        
        card.className = `day-card ${day.is_today ? 'is-today' : ''} ${day.has_workout ? 'has-workout' : ''} ${isCompleted ? 'is-completed' : ''}`;

        // Kart Başlığı (Pzt, 08 Eyl)
        let headerHtml = `
            <div class="day-header">
                <div>
                    <div class="day-title">${day.day_name}</div>
                    <div class="day-date">${day.day_number} <span class="fs-6 fw-normal text-secondary">${day.month_name}</span></div>
                </div>
                ${day.is_today ? '<span class="badge bg-info text-dark fw-bold" style="font-size:0.65rem;">BUGÜN</span>' : ''}
            </div>
        `;

        // Kart Gövdesi (Antrenman bilgisi veya dinlenme)
        let bodyHtml = '';
        if (day.has_workout) {
            const w = day.workout;
            const diffClass = w.zorluk_seviyesi === 'Kolay' ? 'diff-kolay' : (w.zorluk_seviyesi === 'Zor' ? 'diff-zor' : 'diff-orta');
            const typeIcon = getTypeIcon(w.antrenman_tipi);

            bodyHtml = `
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="diff-tag ${diffClass}">${w.zorluk_seviyesi}</span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-link text-secondary p-0" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow">
                                <li><a class="dropdown-item small" href="#" onclick="openEditModal('${day.date}', '${escapeHtml(w.antrenman_tipi)}', '${w.zorluk_seviyesi}')"><i class="bi bi-pencil me-2"></i>Düzenle</a></li>
                                <li><a class="dropdown-item small text-danger" href="#" onclick="confirmDeleteWorkout(${w.id})"><i class="bi bi-trash me-2"></i>Kaldır</a></li>
                            </ul>
                        </div>
                    </div>

                    <div class="workout-type-tag mb-2">
                        <span>${typeIcon}</span>
                        <span>${escapeHtml(w.antrenman_tipi)}</span>
                    </div>

                    <div class="macro-badge text-info" style="background: rgba(56,189,248,0.08); border: 1px solid rgba(56,189,248,0.2);">
                        <i class="bi bi-activity text-info"></i>
                        <span class="text-light">Planlanan Antrenman</span>
                    </div>
                </div>
            `;

            // Kart Altı Butonları
            let footerHtml = '';
            if (isCompleted) {
                const compTime = w.tamamlanma_saati ? w.tamamlanma_saati.substring(0, 5) : '';
                footerHtml = `
                    <div class="mt-3">
                        <div class="completed-pill">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Tamamlandı ${compTime ? '(' + compTime + ')' : ''}</span>
                        </div>
                    </div>
                `;
            } else {
                footerHtml = `
                    <div class="mt-3">
                        <button class="btn btn-finish-workout w-100" onclick="completeWorkout(${w.id}, this)">
                            <i class="bi bi-check2-circle me-1"></i> Antrenmanı Bitir
                        </button>
                    </div>
                `;
            }

            card.innerHTML = headerHtml + bodyHtml + footerHtml;

        } else {
            // Antrenman yok (Dinlenme Günü)
            bodyHtml = `
                <div class="empty-day">
                    <i class="bi bi-moon-stars fs-3 mb-1 text-secondary opacity-50"></i>
                    <div class="small fw-semibold text-secondary">Dinlenme Günü</div>
                    <div class="macro-badge inactive my-2">
                        <i class="bi bi-moon-stars"></i>
                        <span>Aktivite Yok</span>
                    </div>
                </div>
                <div class="mt-auto">
                    <button class="btn btn-outline-secondary btn-sm w-100 text-light border-opacity-25" onclick="openAddModal('${day.date}')">
                        <i class="bi bi-plus me-1"></i> Antrenman Ekle
                    </button>
                </div>
            `;
            card.innerHTML = headerHtml + bodyHtml;
        }

        grid.appendChild(card);
    });
}

/**
 * KPI İstatistik kartlarını günceller
 */
function updateSummaryKPIs(data) {
    const planned = data.total_planned || 0;
    const completed = data.total_completed || 0;
    const pct = planned > 0 ? Math.round((completed / planned) * 100) : 0;

    document.getElementById('kpiProgressText').textContent = `${completed} / ${planned}`;
    document.getElementById('kpiProgressPct').textContent = `(%${pct})`;
    document.getElementById('kpiProgressBar').style.width = `${pct}%`;

    // Son Tamamlanan Antrenman
    const completedDays = data.days.filter(d => d.has_workout && d.workout.tamamlandi_mi);
    const lastComp = completedDays.length > 0 ? completedDays[completedDays.length - 1] : null;
    const lastEl = document.getElementById('kpiLastCompleted');
    const lastSub = document.getElementById('kpiLastCompletedSub');
    if (lastEl && lastSub) {
        if (lastComp) {
            lastEl.innerHTML = `<span class="text-info">${escapeHtml(lastComp.workout.antrenman_tipi)}</span>`;
            lastSub.textContent = `${lastComp.day_name} (${lastComp.workout.tamamlanma_saati ? lastComp.workout.tamamlanma_saati.substring(0,5) : 'Tamamlandı'})`;
        } else {
            lastEl.textContent = '—';
            lastSub.textContent = 'Bu hafta henüz yok';
        }
    }

    // Bugünün durumu
    const todayDay = data.days.find(d => d.is_today);
    if (todayDay && todayDay.has_workout) {
        const w = todayDay.workout;
        const isDone = w.tamamlandi_mi;
        document.getElementById('kpiTodayStatus').innerHTML = isDone 
            ? '<span class="text-success"><i class="bi bi-check-all me-1"></i>Tamamlandı</span>' 
            : `<span class="text-warning"><i class="bi bi-lightning-charge me-1"></i>${escapeHtml(w.antrenman_tipi)}</span>`;
        document.getElementById('kpiTodaySub').textContent = isDone ? 'Harika iş çıkardın!' : 'Bugün antrenman günün!';
        
        document.getElementById('kpiMacroText').textContent = 'Sabit Hedef';
        document.getElementById('kpiMacroSub').textContent = 'Diyet dengesi korunuyor';
    } else {
        document.getElementById('kpiTodayStatus').innerHTML = '<span class="text-secondary"><i class="bi bi-cup-hot me-1"></i>Dinlenme</span>';
        document.getElementById('kpiTodaySub').textContent = 'Bugün antrenman planlanmadı';
        
        document.getElementById('kpiMacroText').textContent = 'Sabit Hedef';
        document.getElementById('kpiMacroSub').textContent = 'Kalori korunuyor';
    }
}

/**
 * Antrenmanı Bitir butonu tetiklendiğinde çalışan AJAX / Fetch API fonksiyonu
 * - workouts.tamamlandi_mi = 1 yapar
 */
async function completeWorkout(workoutId, btnElem) {
    if (btnElem) {
        btnElem.disabled = true;
        btnElem.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> İşleniyor…`;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'complete');
        formData.append('workout_id', workoutId);

        const res = await fetch(`${window.API_BASE}/workout.php`, {
            method: 'POST',
            body: formData,
        });
        const data = await res.json();

        if (!data.ok) {
            throw new Error(data.error || 'İşlem tamamlanamadı.');
        }

        // Başarı Mesajı (SweetAlert2)
        Swal.fire({
            icon: 'success',
            title: 'Tebrikler! 🏆',
            text: 'Antrenman başarıyla tamamlandı olarak kaydedildi. Harika bir iş çıkardın!',
            background: '#ffffff',
            color: '#1e293b',
            confirmButtonColor: '#16a34a',
            confirmButtonText: 'Harika!',
        });

        // Takvimi canlı güncelle
        loadWeekData(formatDateToIso(currentRefDate));

    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Hata',
            text: err.message,
            background: '#ffffff',
            color: '#1e293b'
        });
        if (btnElem) {
            btnElem.disabled = false;
            btnElem.innerHTML = `<i class="bi bi-check2-circle me-1"></i> Antrenmanı Bitir`;
        }
    }
}

/**
 * Çoklu Gün Seçimi (Chip'ler)
 */
function renderWeekDayChips() {
    const container = document.getElementById('weekDayChipsContainer');
    if (!container) return;
    container.innerHTML = '';

    if (!currentLoadedWeek || !currentLoadedWeek.days || !currentLoadedWeek.days.length) {
        container.innerHTML = '<div class="col-12 text-muted small p-2">Takvim verisi bulunamadı.</div>';
        return;
    }

    currentLoadedWeek.days.forEach(day => {
        const isSelected = selectedPlanDates.has(day.date);
        const col = document.createElement('div');
        col.className = 'col-6 col-sm-4 col-md-3';
        col.innerHTML = `
            <div class="day-chip ${isSelected ? 'active' : ''}" onclick="togglePlanDate('${day.date}')">
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.4px; opacity:0.85;">${day.day_name}</div>
                    <div style="font-size:13.5px; font-weight:700;">${day.day_number} ${day.month_name}</div>
                </div>
                <i class="bi ${isSelected ? 'bi-check-circle-fill' : 'bi-circle'} fs-6"></i>
            </div>
        `;
        container.appendChild(col);
    });

    // Hafta dışından eklenmiş özel tarihler varsa onları da listele
    const weekDateSet = new Set(currentLoadedWeek.days.map(d => d.date));
    selectedPlanDates.forEach(dateStr => {
        if (!weekDateSet.has(dateStr)) {
            const col = document.createElement('div');
            col.className = 'col-6 col-sm-4 col-md-3';
            col.innerHTML = `
                <div class="day-chip active" onclick="togglePlanDate('${dateStr}')">
                    <div>
                        <div style="font-size:11px; text-transform:uppercase; opacity:0.85;">Özel Tarih</div>
                        <div style="font-size:13.5px; font-weight:700;">${dateStr}</div>
                    </div>
                    <i class="bi bi-x-circle-fill fs-6"></i>
                </div>
            `;
            container.appendChild(col);
        }
    });

    updateSelectedDaysBadge();
}

function togglePlanDate(dateStr) {
    if (selectedPlanDates.has(dateStr)) {
        selectedPlanDates.delete(dateStr);
    } else {
        selectedPlanDates.add(dateStr);
    }
    renderWeekDayChips();
}

function updateSelectedDaysBadge() {
    const badge = document.getElementById('selectedDaysBadge');
    if (badge) {
        const count = selectedPlanDates.size;
        badge.textContent = `${count} gün seçildi`;
        if (count > 0) {
            badge.className = 'badge bg-primary text-white border border-primary fw-bold';
        } else {
            badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle fw-semibold';
        }
    }
}

function applyDayPreset(preset) {
    if (!currentLoadedWeek || !currentLoadedWeek.days) return;
    selectedPlanDates.clear();

    const days = currentLoadedWeek.days;
    if (preset === 'mwf') {
        // Pazartesi (0), Çarşamba (2), Cuma (4)
        [0, 2, 4].forEach(idx => { if (days[idx]) selectedPlanDates.add(days[idx].date); });
    } else if (preset === 'tt') {
        // Salı (1), Perşembe (3)
        [1, 3].forEach(idx => { if (days[idx]) selectedPlanDates.add(days[idx].date); });
    } else if (preset === 'weekdays') {
        // Hafta İçi (0..4)
        [0, 1, 2, 3, 4].forEach(idx => { if (days[idx]) selectedPlanDates.add(days[idx].date); });
    } else if (preset === 'all') {
        days.forEach(d => selectedPlanDates.add(d.date));
    }
    // 'clear' -> already cleared

    renderWeekDayChips();
}

function addCustomDate() {
    const input = document.getElementById('extraDateInput');
    const val = input ? input.value.trim() : '';
    if (!val) return;
    selectedPlanDates.add(val);
    input.value = '';
    renderWeekDayChips();
}

/**
 * Antrenman Ekleme / Güncelleme Form Gönderimi (Fetch API)
 */
async function handleWorkoutSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveWorkout');

    const form = document.getElementById('workoutForm');
    const formData = new FormData(form);
    formData.append('action', 'save');

    if (isEditMode) {
        const dateVal = document.getElementById('singleDateInput').value;
        if (!dateVal) {
            Swal.fire({
                icon: 'warning',
                title: 'Tarih Eksik',
                text: 'Lütfen geçerli bir tarih seçin.',
                background: '#ffffff',
                color: '#1e293b'
            });
            return;
        }
        formData.append('tarih', dateVal);
    } else {
        const datesArr = Array.from(selectedPlanDates);
        if (datesArr.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Gün Seçilmedi',
                text: 'Lütfen antrenman planlamak istediğiniz en az bir günü seçin.',
                confirmButtonColor: '#0284c7',
                background: '#ffffff',
                color: '#1e293b'
            });
            return;
        }
        formData.append('tarihler', datesArr.join(','));
    }

    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Kaydediliyor…`;

    try {
        const res = await fetch(`${window.API_BASE}/workout.php`, {
            method: 'POST',
            body: formData,
        });
        const data = await res.json();

        if (!data.ok) {
            throw new Error(data.error || 'Kaydedilemedi');
        }

        workoutModalInstance.hide();

        // Başarı Toasti
        Swal.fire({
            icon: 'success',
            title: 'Antrenman Kaydedildi!',
            text: data.message,
            toast: true,
            position: 'top-end',
            timer: 3500,
            showConfirmButton: false,
            background: '#ffffff',
            color: '#1e293b',
        });

        // Takvimi güncelle
        loadWeekData(formatDateToIso(currentRefDate));

    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Hata',
            text: err.message,
            background: '#ffffff',
            color: '#1e293b',
        });
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="bi bi-check-lg me-1"></i> Antrenmanı Kaydet`;
    }
}

/**
 * Antrenmanı Silme Onayı ve Fetch API Çağrısı
 */
async function confirmDeleteWorkout(workoutId) {
    const result = await Swal.fire({
        title: 'Antrenmanı Kaldır?',
        text: 'Bu antrenmanı sildiğinizde o güne ait antrenman kaydı ve hedefleri normale dönecektir.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Evet, Kaldır',
        cancelButtonText: 'Vazgeç',
        background: '#ffffff',
        color: '#1e293b',
    });

    if (result.isConfirmed) {
        try {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('workout_id', workoutId);

            const res = await fetch(`${window.API_BASE}/workout.php`, {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();

            if (!data.ok) throw new Error(data.error || 'Silinemedi');

            Swal.fire({
                icon: 'info',
                title: 'Kaldırıldı',
                text: data.message,
                toast: true,
                position: 'top-end',
                timer: 3000,
                showConfirmButton: false,
                background: '#ffffff',
                color: '#1e293b',
            });

            loadWeekData(formatDateToIso(currentRefDate));

        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Hata',
                text: err.message,
                background: '#ffffff',
                color: '#1e293b',
            });
        }
    }
}

/**
 * Modal Açma Yardımcıları
 */
function openAddModal(dateStr = '') {
    isEditMode = false;
    document.getElementById('workoutModalTitle').innerHTML = `<i class="bi bi-activity text-primary me-2"></i>Antrenman Planla`;
    document.getElementById('multiDaySection').classList.remove('d-none');
    document.getElementById('singleDateSection').classList.add('d-none');
    document.getElementById('modalType').value = '';
    document.getElementById('modalDifficulty').value = 'Orta';

    selectedPlanDates.clear();
    if (dateStr) {
        selectedPlanDates.add(dateStr);
    } else {
        const todayIso = formatDateToIso(new Date());
        selectedPlanDates.add(todayIso);
    }

    renderWeekDayChips();
    workoutModalInstance.show();
}

function openEditModal(dateStr, type, difficulty) {
    isEditMode = true;
    document.getElementById('workoutModalTitle').innerHTML = `<i class="bi bi-pencil-square text-warning me-2"></i>Antrenmanı Düzenle`;
    document.getElementById('multiDaySection').classList.add('d-none');
    document.getElementById('singleDateSection').classList.remove('d-none');
    document.getElementById('singleDateInput').value = dateStr;
    document.getElementById('modalType').value = type;
    document.getElementById('modalDifficulty').value = difficulty;
    workoutModalInstance.show();
}

/**
 * Hafta Gezinme Fonksiyonları
 */
function navigateWeek(deltaWeeks) {
    currentRefDate.setDate(currentRefDate.getDate() + (deltaWeeks * 7));
    loadWeekData(formatDateToIso(currentRefDate));
}

function goToCurrentWeek() {
    currentRefDate = new Date();
    loadWeekData(formatDateToIso(currentRefDate));
}

/**
 * Yardımcı Araçlar
 */
function formatDateToIso(d) {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

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

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

</body>
</html>

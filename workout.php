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
            --card-bg: #111827;
            --card-hover: #162032;
            --card-border: rgba(255, 255, 255, 0.08);
            --accent-green: #10b981;
            --accent-yellow: #f59e0b;
            --accent-red: #ef4444;
            --accent-blue: #38bdf8;
            --accent-purple: #a855f7;
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
            border-color: rgba(56, 189, 248, 0.3);
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
            border-color: rgba(255, 255, 255, 0.16);
        }
        .day-card.is-today {
            border-color: var(--accent);
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.15);
            background: linear-gradient(180deg, rgba(56, 189, 248, 0.04) 0%, rgba(17, 24, 39, 1) 100%);
        }
        .day-card.has-workout {
            border-top: 3px solid #f97316;
        }
        .day-card.is-completed {
            border-top: 3px solid var(--accent-green);
        }

        .day-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding-bottom: 0.6rem;
            margin-bottom: 0.75rem;
        }
        .day-title {
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }
        .day-date {
            font-size: 1.15rem;
            font-weight: 800;
            color: #f8fafc;
        }

        /* Dinamik Makro Rozeti */
        .macro-badge {
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.18), rgba(239, 68, 68, 0.12));
            border: 1px solid rgba(249, 115, 22, 0.35);
            color: #fdba74;
            font-size: 0.73rem;
            font-weight: 700;
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin: 0.6rem 0;
            box-shadow: 0 2px 6px rgba(249, 115, 22, 0.1);
        }
        .macro-badge.inactive {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.06);
            color: #64748b;
        }

        /* Antrenman Tipi & Zorluk */
        .workout-type-tag {
            font-size: 0.92rem;
            font-weight: 700;
            color: #f1f5f9;
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
        .diff-kolay  { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .diff-orta   { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .diff-zor    { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* Butonlar */
        .btn-finish-workout {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            font-weight: 700;
            font-size: 0.8rem;
            border: none;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-finish-workout:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
            color: #fff;
        }

        .completed-pill {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
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
            background: rgba(56, 189, 248, 0.1);
            border: 1px dashed rgba(56, 189, 248, 0.4);
            color: #7dd3fc;
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
            color: #475569;
            text-align: center;
        }

        .modal-content {
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #f8fafc;
            border-radius: 16px;
        }
        .form-control, .form-select {
            background-color: #0b1120;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #f8fafc;
        }
        .form-control:focus, .form-select:focus {
            background-color: #0b1120;
            border-color: var(--accent);
            color: #f8fafc;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.2);
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
            <button class="hamburger btn-ghost btn-topbar" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="topbar-title">Spor & Antrenman Planı</div>
                <div class="topbar-sub">OptiLifeSync · Haftalık Program & Aktivite Takibi</div>
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

        <!-- ── 3. BİLGİLENDİRME BANNERI ─────────────────────────── -->
        <div class="p-3 rounded-4 border border-secondary border-opacity-25 mb-4" style="background:rgba(17,24,39,0.7);">
            <div class="d-flex align-items-start gap-3">
                <div class="fs-3 text-info">💡</div>
                <div class="small text-secondary">
                    <strong class="text-light">OptiLifeSync Spor Takip Modülü</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li><strong>Aktivite Takibi:</strong> Planladığınız antrenmanları ve günlük spor disiplininizi haftalık takvim üzerinden kolayca kaydedebilir ve takip edebilirsiniz.</li>
                        <li><strong>Sabit Beslenme Hedefleri:</strong> Antrenman kayıtları yalnızca spor takibi içindir; kalori ve makro hedefleriniz sabit kalarak diyet dengenizi korumanızı sağlar.</li>
                    </ul>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<!-- ═══════════════════════ MODAL: ANTRENMAN EKLE / DÜZENLE ═══════════════════════ -->
<div class="modal fade" id="workoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold text-light" id="workoutModalTitle">
                    <i class="bi bi-activity text-info me-2"></i>Antrenman Planla
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <form id="workoutForm" onsubmit="handleWorkoutSubmit(event)">
                <div class="modal-body">
                    
                    <!-- Tarih -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Antrenman Tarihi</label>
                        <input type="date" class="form-control" name="tarih" id="modalDate" required>
                    </div>

                    <!-- Antrenman Tipi -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Antrenman Tipi</label>
                        <select class="form-select" name="antrenman_tipi" id="modalType" required>
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
                        <label class="form-label small text-secondary fw-semibold">Zorluk Seviyesi</label>
                        <select class="form-select" name="zorluk_seviyesi" id="modalDifficulty" required>
                            <option value="Kolay">🟢 Kolay (Düşük Yoğunluk / Toparlanma)</option>
                            <option value="Orta" selected>🟡 Orta (Standart Antrenman Şiddeti)</option>
                            <option value="Zor">🔴 Zor (Ağır / Maksimal / Tükeniş)</option>
                        </select>
                    </div>

                </div>
                <div class="modal-footer border-secondary border-opacity-25">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-info btn-sm fw-bold px-3" id="btnSaveWorkout">
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
            background: '#111827',
            color: '#f8fafc',
            confirmButtonColor: '#10b981',
            confirmButtonText: 'Harika!',
            customClass: {
                popup: 'border border-secondary'
            }
        });

        // Takvimi canlı güncelle
        loadWeekData(formatDateToIso(currentRefDate));

    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Hata',
            text: err.message,
            background: '#111827',
            color: '#f8fafc'
        });
        if (btnElem) {
            btnElem.disabled = false;
            btnElem.innerHTML = `<i class="bi bi-check2-circle me-1"></i> Antrenmanı Bitir`;
        }
    }
}

/**
 * Antrenman Ekleme / Güncelleme Form Gönderimi (Fetch API)
 */
async function handleWorkoutSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveWorkout');
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Kaydediliyor…`;

    const form = document.getElementById('workoutForm');
    const formData = new FormData(form);
    formData.append('action', 'save');

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
            background: '#111827',
            color: '#f8fafc',
        });

        // Takvimi güncelle
        loadWeekData(formatDateToIso(currentRefDate));

    } catch (err) {
        Swal.fire({
            icon: 'error',
            title: 'Hata',
            text: err.message,
            background: '#111827',
            color: '#f8fafc',
        });
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="bi bi-check-lg me-1"></i> Kaydet & Hedefleri Güncelle`;
    }
}

/**
 * Antrenmanı Silme Onayı ve Fetch API Çağrısı
 */
async function confirmDeleteWorkout(workoutId) {
    const result = await Swal.fire({
        title: 'Antrenmanı Kaldır?',
        text: 'Bu antrenmanı sildiğinizde o güne ait dinamik makro hedefleri (+400 kcal, +30g protein) normale dönecektir.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Evet, Kaldır',
        cancelButtonText: 'Vazgeç',
        background: '#111827',
        color: '#f8fafc',
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
                background: '#111827',
                color: '#f8fafc',
            });

            loadWeekData(formatDateToIso(currentRefDate));

        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: 'Hata',
                text: err.message,
                background: '#111827',
                color: '#f8fafc',
            });
        }
    }
}

/**
 * Modal Açma Yardımcıları
 */
function openAddModal(dateStr = '') {
    document.getElementById('workoutModalTitle').innerHTML = `<i class="bi bi-activity text-info me-2"></i>Antrenman Planla`;
    document.getElementById('modalDate').value = dateStr || formatDateToIso(new Date());
    document.getElementById('modalType').value = '';
    document.getElementById('modalDifficulty').value = 'Orta';
    workoutModalInstance.show();
}

function openEditModal(dateStr, type, difficulty) {
    document.getElementById('workoutModalTitle').innerHTML = `<i class="bi bi-pencil-square text-warning me-2"></i>Antrenmanı Düzenle`;
    document.getElementById('modalDate').value = dateStr;
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

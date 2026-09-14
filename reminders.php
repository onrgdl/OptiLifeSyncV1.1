<?php

declare(strict_types=1);

/**
 * OptiLifeSync - İlaç ve Takviye Yönetim Modülü
 *
 * Backend: PHP + MySQL (ReminderService)
 * Frontend: Bootstrap 5 + SweetAlert2 + Web Push Notification API
 *
 * Bildirim Mekanizması:
 *   JS setInterval (her 30sn) → api/reminders.php?action=check_due
 *     ├── Zamanı gelen alarm varsa → SweetAlert2 modal göster
 *     └── Tarayıcı izni varsa   → Yerel Push Notification da gönder
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/ReminderService.php';

use App\Services\ReminderService;

$service = $pdo ? new ReminderService($pdo) : null;

// ── Sayfa yüklendiğinde supplement listesini çek ─────────────────────
$supplements = $service ? $service->getSupplementsWithSchedule($userId) : [];

// ── Form işlemleri (sayfa içi POST) ──────────────────────────────────
$flashMsg  = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service) {
    $action = $_POST['form_action'] ?? '';

    try {
        if ($action === 'add') {
            $newId = $service->addSupplement($userId, $_POST);
            $times = array_filter(
                array_map('trim', explode(',', $_POST['schedule_times'] ?? '')),
                fn($t) => $t !== ''
            );
            if ($newId && !empty($times)) {
                $service->updateSchedule($newId, $userId, array_values($times));
            }
            $flashMsg = '✅ ' . htmlspecialchars($_POST['name'] ?? '') . ' eklendi.';
        }

        if ($action === 'finish') {
            $service->finishSupplement((int)($_POST['supplement_id'] ?? 0), $userId);
            $flashMsg = '🏁 İlaç tamamlandı olarak işaretlendi ve tüm alarmları kaldırıldı.';
        }

        if ($action === 'delete') {
            $service->deleteSupplement((int)($_POST['supplement_id'] ?? 0), $userId);
            $flashMsg = '🗑️ Kayıt ve bağlı alarmlar silindi.';
        }

        if ($action === 'update_schedule') {
            $times = array_filter(
                array_map('trim', explode(',', $_POST['schedule_times'] ?? '')),
                fn($t) => $t !== ''
            );
            $service->updateSchedule((int)($_POST['supplement_id'] ?? 0), $userId, array_values($times));
            $flashMsg = '🕐 Alarm saatleri güncellendi.';
        }

        // Yeniden çek
        $supplements = $service->getSupplementsWithSchedule($userId);

    } catch (\Throwable $e) {
        $flashMsg  = '❌ Hata: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $flashType = 'danger';
    }
}

// Tür ve form etiket haritaları (görsel)
$typeLabels = [
    'medication'  => ['label' => 'İlaç',     'color' => 'danger',  'icon' => 'bi-capsule-pill'],
    'supplement'  => ['label' => 'Takviye',   'color' => 'success', 'icon' => 'bi-plus-circle'],
    'vitamin'     => ['label' => 'Vitamin',   'color' => 'warning', 'icon' => 'bi-sun'],
    'mineral'     => ['label' => 'Mineral',   'color' => 'info',    'icon' => 'bi-gem'],
    'herb'        => ['label' => 'Bitki',     'color' => 'secondary','icon'=> 'bi-flower1'],
    'other'       => ['label' => 'Diğer',     'color' => 'secondary','icon'=> 'bi-box'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - İlaç & Takviye Yönetimi</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="assets/js/alarm-engine.js"></script>
    <style>
        .card           { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; }
        .form-control,
        .form-select    { background: #ffffff; border-color: var(--border); color: var(--text); }
        .form-control:focus,
        .form-select:focus { background: #ffffff; border-color: var(--accent); color: var(--text); box-shadow: 0 0 0 .25rem rgba(2,132,199,.15); }
        .table-dark td,
        .table-dark th  { background: transparent; }

        /* Alarm badge */
        .time-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--accent-dim);
            border: 1px solid rgba(2, 132, 199, .25);
            color: var(--accent);
            border-radius: 99px;
            padding: 2px 10px;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        .time-badge:hover { background: rgba(2,132,199,.2); }
        .time-badge .remove-time {
            color: var(--red);
            font-size: .85rem;
            line-height: 1;
        }

        /* Polling göstergesi (Kullanıcı talebiyle tamamen gizlendi) */
        #polling-indicator {
            display: none !important;
        }

        .dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #16a34a;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%,100% { opacity: 1; }
            50%      { opacity: .3; }
        }

        /* Notification izin banner */
        #notif-banner {
            border-left: 4px solid #d97706;
        }

        /* Saat inputu tag-box */
        .tag-input-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 8px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            min-height: 46px;
            align-items: center;
        }
        input[type="time"] {
            color-scheme: light !important;
            color: #1e293b !important;
            font-weight: 600;
        }
        input[type="time"]::-webkit-calendar-picker-indicator {
            filter: invert(0.2);
            cursor: pointer;
        }
    </style>
</head>
<body>
<?php $activePage = 'reminders'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">İlaç & Takviye Yönetimi</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-ghost text-warning" id="notif-req-btn">
                <i class="bi bi-bell me-1"></i> Bildirimlere İzin Ver
            </button>
            <a href="dashboard.php" class="btn-topbar btn-accent"><i class="bi bi-grid-1x2-fill"></i> <span class="d-none d-sm-inline">Dashboard</span></a>
        </div>
    </header>

    <div class="content">

    <!-- Flash mesajı -->
    <?php if ($flashMsg): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
        <?= $flashMsg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Bildirim izin banner (JS ile gösterilir/gizlenir) -->
    <div id="notif-banner" class="alert alert-warning d-none mb-3">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Tarayıcı bildirimleri kapalı.</strong>
        Alarm saati geldiğinde yalnızca uygulama içi uyarı alırsınız.
        Yerel bildirim için sağ üstteki butona tıklayın.
    </div>

    <div class="row g-4">

        <!-- SOL: Yeni Ekle Formu -->
        <div class="col-lg-4">
            <div class="card p-4">
                <h5 class="mb-3"><i class="bi bi-plus-circle-fill text-success me-2"></i>Yeni İlaç / Takviye</h5>
                <form method="POST" id="addForm">
                    <input type="hidden" name="form_action" value="add">
                    <div class="mb-3">
                        <label class="form-label text-secondary small">Ürün Adı *</label>
                        <input type="text" name="name" class="form-control" placeholder="ör: D3 Vitamini, Metformin" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label text-secondary small">Tür *</label>
                            <select name="type" class="form-select">
                                <?php foreach ($typeLabels as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Form</label>
                            <select name="form" class="form-select">
                                <?php foreach (['tablet'=>'Tablet','capsule'=>'Kapsül','powder'=>'Toz','liquid'=>'Sıvı','injection'=>'Enjeksiyon','other'=>'Diğer'] as $k=>$v): ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-5">
                            <label class="form-label text-secondary small">Doz Miktarı</label>
                            <input type="number" name="dose_amount" step="0.1" class="form-control" value="1" placeholder="ör: 500">
                        </div>
                        <div class="col-4">
                            <label class="form-label text-secondary small">Birim</label>
                            <input type="text" name="dose_unit" class="form-control" value="mg" placeholder="mg / IU">
                        </div>
                        <div class="col-3">
                            <label class="form-label text-secondary small">Günde</label>
                            <input type="number" name="doses_per_day" class="form-control" value="1" min="1" max="10">
                        </div>
                    </div>

                    <!-- Saat Seçici (Ekle butonlu ve hızlı seçenekli) -->
                    <div class="mb-3">
                        <label class="form-label text-dark fw-semibold small mb-1">
                            <i class="bi bi-alarm me-1 text-primary"></i>Alarm Saatleri
                        </label>
                        
                        <!-- Saat Seçici ve Ekle Butonu -->
                        <div class="input-group mb-2">
                            <span class="input-group-text bg-light text-dark border" style="border-color:var(--border);">
                                <i class="bi bi-clock"></i>
                            </span>
                            <input type="time" id="newTimeInput" class="form-control text-dark fw-semibold" value="08:00" style="color-scheme:light;color:#1e293b;background:#ffffff;border-color:var(--border);">
                            <button type="button" class="btn btn-primary fw-semibold px-3" onclick="addCurrentTimeToForm()">
                                <i class="bi bi-plus-lg me-1"></i>Saati Ekle
                            </button>
                        </div>
                        
                        <!-- Eklenen Saatlerin Rozet Kutusu -->
                        <div class="tag-input-wrapper p-2 rounded-3" id="timeTagBox" style="background:#ffffff;border:1px solid var(--border);min-height:44px;display:flex;flex-wrap:wrap;align-items:center;gap:6px;">
                            <span id="noTimeHint" class="text-muted small fst-italic ps-1">
                                Henüz saat eklenmedi. Saati seçip "Saati Ekle" butonuna basın.
                            </span>
                        </div>
                        
                        <input type="hidden" name="schedule_times" id="scheduleTimesInput" value="">
                        
                        <!-- Hızlı Saat Seçim Önerileri -->
                        <div class="d-flex align-items-center gap-1 mt-2 flex-wrap">
                            <small class="text-secondary small me-1" style="font-size:11px;">Hızlı Ekle:</small>
                            <button type="button" class="btn btn-sm btn-light border py-0 px-2 text-dark" style="font-size:11px;" onclick="quickAddTime('08:00')">🌅 08:00</button>
                            <button type="button" class="btn btn-sm btn-light border py-0 px-2 text-dark" style="font-size:11px;" onclick="quickAddTime('13:00')">☀️ 13:00</button>
                            <button type="button" class="btn btn-sm btn-light border py-0 px-2 text-dark" style="font-size:11px;" onclick="quickAddTime('19:00')">🌙 19:00</button>
                            <button type="button" class="btn btn-sm btn-light border py-0 px-2 text-dark" style="font-size:11px;" onclick="quickAddTime('22:00')">💤 22:00</button>
                        </div>
                    </div>

                    <!-- Kullanım Süresi (Kür / Tedavi Planı) -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small">Kullanım Süresi (Gün Sayısı)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="duration_days" class="form-control" min="1" max="365" placeholder="Örn: 15 (Boş = Sürekli devam eder)">
                            <span class="input-group-text bg-light text-dark border">Gün</span>
                        </div>
                        <small class="text-secondary mt-1 d-block" style="font-size:0.75rem;">
                            <i class="bi bi-hourglass-split me-1"></i>Örn: 15 gün girerseniz, 15 gün sonra alarm otomatik olarak kapanır ve dashboarddan kalkar.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary small">Notlar</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Doktor notu, yan etkiler..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100 fw-semibold">
                        <i class="bi bi-plus-lg me-1"></i> Ekle & Alarm Kur
                    </button>
                </form>
            </div>

            <!-- ALARM & SES AYARLARI KARTI -->
            <div class="card p-4 mt-4" id="alarmSettingsCard">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="bi bi-volume-up-fill text-primary me-2"></i>Alarm &amp; Ses Ayarları
                    </h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size:11px;">
                        Canlı Ayar
                    </span>
                </div>

                <!-- Arka Plan Nöbetçisi (Ekran Kapalıyken Çalma Koruyucusu) -->
                <div class="p-3 mb-3 rounded-3" style="background:#f8fafc; border:1px solid var(--border);">
                    <div class="form-check form-switch d-flex align-items-center justify-content-between ps-0 mb-1">
                        <label class="form-check-label small fw-bold text-dark mb-0" for="sentinelToggle" style="cursor:pointer;">
                            <i class="bi bi-shield-check text-success me-1"></i>Ekran Kapalıyken Çal
                        </label>
                        <input class="form-check-input ms-2" type="checkbox" role="switch" id="sentinelToggle" checked onchange="toggleSentinelMode(this.checked)">
                    </div>
                    <small class="text-secondary d-block" style="font-size:11px; line-height:1.35;">
                        Telefon ekranı kilitlendiğinde veya tarayıcı arka plandayken alarm motorunun uyumasını engeller.
                    </small>
                    <div id="sentinelStatusBadge" class="badge bg-success-subtle text-success border border-success-subtle mt-2 px-2 py-1" style="font-size:10.5px;">
                        🟢 Nöbetçi Aktif (Ekran kapansa da çalar)
                    </div>
                </div>

                <!-- Alarm Sesi Seçimi -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">
                        <i class="bi bi-music-note-beamed me-1"></i>Alarm Melodisi
                    </label>
                    <select id="alarmSoundSelect" class="form-select form-select-sm fw-medium">
                        <option value="classic">🔔 Klasik Dijital Bip</option>
                        <option value="chime">🎵 Melodik Çan (Ding-Dong)</option>
                        <option value="marimba">🌿 Yumuşak Marimba</option>
                        <option value="urgent">🚨 Acil Uyarı Sireni</option>
                        <option value="pulse">⚡ Modern Elektronik Ritim</option>
                    </select>
                </div>

                <!-- Ses Seviyesi Slider -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary mb-0">
                            <i class="bi bi-speaker me-1"></i>Ses Düzeyi
                        </label>
                        <span id="alarmVolumeLabel" class="badge bg-light text-dark border px-2 py-1 fw-bold" style="font-size:12px;">
                            70%
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-volume-mute text-muted small"></i>
                        <input type="range" class="form-range" id="alarmVolumeSlider" min="0" max="100" step="5" value="70">
                        <i class="bi bi-volume-up text-primary small"></i>
                    </div>
                </div>

                <!-- Sesi Test Et Butonu -->
                <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-2 mb-3" id="testSoundBtn">
                    <i class="bi bi-play-fill fs-6"></i> Sesi Test Et
                </button>

                <hr class="my-2" style="border-color:var(--border);">

                <!-- Ekran & Bildirim Testi -->
                <div class="mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-2" onclick="testSystemNotification()">
                        <i class="bi bi-bell-fill text-warning"></i>
                        <span>Bildirim & Ekran Testi</span>
                    </button>
                    <small class="text-secondary d-block text-center mt-1" style="font-size:11px;">
                        Alarm çaldığında bildirim çubuğunda ve kilit ekranında görünür.
                    </small>
                </div>
            </div>
        </div>

        <!-- SAĞ: Mevcut Liste -->
        <div class="col-lg-8">
            <div class="card p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Mevcut İlaç & Takviyeler</h5>
                    <span class="badge bg-secondary"><?= count($supplements) ?> kayıt</span>
                </div>

                <?php if (empty($supplements)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-capsule fs-1 d-block mb-2"></i>
                        Henüz kayıt yok. Sol taraftan ilk ilacınızı ekleyin.
                    </div>
                <?php else: ?>
                <?php foreach ($supplements as $supp):
                    $tinfo = $typeLabels[$supp['type']] ?? $typeLabels['other'];
                    $times = $supp['schedule_times'];
                ?>
                <div class="border border-secondary border-opacity-25 rounded-3 p-3 mb-3" id="supp-<?= $supp['id'] ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="badge bg-<?= $tinfo['color'] ?> me-1">
                                <i class="bi <?= $tinfo['icon'] ?> me-1"></i><?= $tinfo['label'] ?>
                            </span>
                            <strong class="fs-6"><?= htmlspecialchars($supp['name']) ?></strong>
                            <span class="text-secondary ms-2 small"><?= $supp['dose_amount'] ?> <?= htmlspecialchars($supp['dose_unit']) ?> · <?= $supp['doses_per_day'] ?>×/gün · <?= htmlspecialchars(ucfirst($supp['form'])) ?></span>

                            <!-- Kür / Bitiş Süresi Rozeti -->
                            <?php if (!empty($supp['duration_days'])): ?>
                                <?php if ($supp['is_expired'] || !(int)$supp['is_active']): ?>
                                    <span class="badge bg-danger bg-opacity-75 ms-2">
                                        <i class="bi bi-flag-fill me-1"></i>İlaç Bitti / Tedavi Tamamlandı
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50 ms-2">
                                        <i class="bi bi-hourglass-split me-1"></i><?= $supp['duration_days'] ?> Günlük Kür (Kalan: <?= $supp['remaining_days'] ?> gün)
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2">
                            <!-- İlacı Bitir Butonu (Eğer hala aktifse) -->
                            <?php if ((int)$supp['is_active'] === 1 && !$supp['is_expired']): ?>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirmFinish(event, '<?= htmlspecialchars(addslashes($supp['name'])) ?>')">
                                <input type="hidden" name="form_action" value="finish">
                                <input type="hidden" name="supplement_id" value="<?= $supp['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="İlacı Bitir (Tüm Alarmları Kapat)">
                                    <i class="bi bi-check2-circle me-1"></i> İlacı Bitir
                                </button>
                            </form>
                            <?php endif; ?>

                            <!-- Sil butonu -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirmDelete(event, '<?= htmlspecialchars(addslashes($supp['name'])) ?>')">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="supplement_id" value="<?= $supp['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Kalıcı Olarak Sil">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Alarm Saatleri Satırı -->
                    <div class="mt-3">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <i class="bi bi-alarm text-info"></i>
                            <span class="text-secondary small me-1">Alarmlar:</span>

                            <!-- Mevcut saat badge'leri -->
                            <div class="d-flex flex-wrap gap-1" id="badges-<?= $supp['id'] ?>">
                                <?php foreach ($times as $t): ?>
                                <span class="time-badge" data-time="<?= htmlspecialchars($t) ?>" data-id="<?= $supp['id'] ?>">
                                    <i class="bi bi-clock-fill"></i>
                                    <?= htmlspecialchars($t) ?>
                                    <span class="remove-time" onclick="removeTime(<?= $supp['id'] ?>, '<?= $t ?>')">×</span>
                                </span>
                                <?php endforeach; ?>
                                <?php if (empty($times)): ?>
                                    <span class="text-secondary small fst-italic">Alarm kurulmamış</span>
                                <?php endif; ?>
                            </div>

                            <!-- Inline saat ekleme -->
                            <div class="input-group input-group-sm" style="width:170px;">
                                <input type="time" class="form-control form-control-sm text-dark fw-semibold"
                                       style="color-scheme:light;color:#1e293b;background:#ffffff;border-color:var(--border);"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault(); addTimeInline(<?= $supp['id'] ?>, this);}"
                                       id="inlineTime-<?= $supp['id'] ?>"
                                       value="08:00">
                                <button type="button" class="btn btn-outline-primary btn-sm fw-semibold"
                                        onclick="addTimeInline(<?= $supp['id'] ?>, document.getElementById('inlineTime-<?= $supp['id'] ?>'))"
                                        title="Bu saati alarma ekle">
                                    <i class="bi bi-plus-lg me-1"></i>Ekle
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if ($supp['notes']): ?>
                    <div class="mt-2 text-secondary small"><i class="bi bi-sticky me-1"></i><?= htmlspecialchars($supp['notes']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div><!-- /content -->
</div><!-- /main -->

<!-- Polling Göstergesi (gizlendi) -->
<div id="polling-indicator" style="display:none !important">
    <span id="poll-status"></span>
    <span id="poll-time"></span>
</div>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT: SweetAlert2 + Web Push + setInterval Polling
     ══════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// ─────────────────────────────────────────────────────────────────────
// BÖLÜM 1: TARAYICI BİLDİRİMİ (Web Push Notification API)
// ─────────────────────────────────────────────────────────────────────

/**
 * Tarayıcıdan bildirim izni ister.
 * Kullanıcı "İzin Ver" butonuna tıkladığında çalışır.
 */
async function requestNotificationPermission() {
    if (!('Notification' in window)) {
        Swal.fire({
            icon: 'warning',
            title: 'Desteklenmiyor',
            text: 'Bu tarayıcı yerel bildirim API\'sini desteklemiyor.',
            background: '#1e293b', color: '#f8fafc',
        });
        return;
    }

    const permission = await Notification.requestPermission();

    if (permission === 'granted') {
        document.getElementById('notif-banner').classList.add('d-none');
        Swal.fire({
            icon: 'success', title: 'Bildirimler Etkin!',
            text: 'Alarm saatlerinde masaüstü bildirimi alacaksınız.',
            timer: 2500, showConfirmButton: false,
            background: '#1e293b', color: '#f8fafc',
        });
    } else {
        document.getElementById('notif-banner').classList.remove('d-none');
    }
}

/**
 * Tarayıcı / mobil sistem push bildirimi gönderir.
 * Service Worker desteği ile Android Chrome ve kilit ekranında sorunsuz çalışır.
 */
function sendBrowserNotification(title, body, icon = '') {
    if (window.optiAlarmEngine) {
        window.optiAlarmEngine.showSystemNotification(title, { body, icon });
    }
}

// ─────────────────────────────────────────────────────────────────────
// BÖLÜM 2: SWEETALERT2 ALARM MODALI
// ─────────────────────────────────────────────────────────────────────

/**
 * Zamanı gelen bir alarm için SweetAlert2 popup gösterir ve ekran bildirimini tetikler.
 * @param {Object} reminder  check_due API veya yerel kontrolden dönen reminder nesnesi
 */
function showAlarmModal(reminder) {
    const isIlac = reminder.type === 'medication';
    const icon   = isIlac ? '💊' : '💪';
    const color  = isIlac ? '#ef4444' : '#16a34a';
    const title  = isIlac ? 'İlaç Zamanı!' : 'Takviye Zamanı!';

    // Sesli alarmı ve kilit ekranı / sistem bildirimini birlikte ateşle
    if (window.optiAlarmEngine) {
        window.optiAlarmEngine.triggerAlarm({
            title: `${icon} ${title}`,
            body: `${reminder.label} — Doz: ${reminder.dose || ''} (${reminder.form || ''}) vaktiniz geldi!`.trim(),
            label: reminder.label,
            dose: reminder.dose,
            type: reminder.type,
            id: reminder.id
        });
    }

    Swal.fire({
        icon:               'warning',
        iconColor:          color,
        title:              `${icon} ${title}`,
        html: `
            <div style="text-align:center; line-height: 1.8;">
                <h4 style="color:#0f172a; margin: 0 0 8px; font-weight:700;">
                    ${escapeHtml(reminder.label)}
                </h4>
                <p style="color:#64748b; margin:0; font-size:14px;">
                    <i class="bi bi-clock me-1"></i>
                    Alım Saati: <strong style="color:#0284c7; font-size:15px;">${reminder.remind_at}</strong>
                </p>
                <p style="color:#64748b; margin:4px 0 0; font-size:14px;">
                    Doz: <strong style="color:#0f172a">${escapeHtml(reminder.dose)}</strong>
                    — ${escapeHtml(reminder.form)}
                </p>
                <div class="badge bg-danger px-3 py-2 mt-3" style="font-size:12px; letter-spacing:0.3px;">
                    🔔 Sesli Alarm Çalıyor...
                </div>
            </div>
        `,
        confirmButtonText:  '✅ Aldım / Durdur',
        cancelButtonText:   '⏸ Ertele (15 dk)',
        showCancelButton:   true,
        confirmButtonColor: '#16a34a',
        cancelButtonColor:  '#64748b',
        background:         '#ffffff',
        color:              '#1e293b',
        backdrop:           `rgba(15, 23, 42, 0.65)`,
        allowOutsideClick:  false,
    }).then((result) => {
        // Alarm sesini durdur
        if (window.optiAlarmEngine) {
            window.optiAlarmEngine.stop();
        }

        if (result.isDismissed && result.dismiss === Swal.DismissReason.cancel) {
            // Ertele: 15 dakika sonra tekrar sor
            setTimeout(() => showAlarmModal(reminder), 15 * 60 * 1000);
            Swal.fire({
                icon: 'info', title: '15 Dakika Ertelendi',
                timer: 2000, showConfirmButton: false,
                background: '#ffffff', color: '#1e293b',
            });
        }
    });
}

// ─────────────────────────────────────────────────────────────────────
// BÖLÜM 3: POLLING — setInterval ile her 30sn API'yi sorgula
// ─────────────────────────────────────────────────────────────────────

/** Daha önce gösterilen alarm ID'lerini takip eder (çift gösterimi önler) */
const shownAlarmIds = new Set();

/**
 * API'ye sorgu atar, zamanı gelen alarmları işler.
 */
async function pollForDueReminders() {
    const indicator = document.getElementById('polling-indicator');
    const statusEl  = document.getElementById('poll-status');
    const timeEl    = document.getElementById('poll-time');

    try {
        const fd = new FormData();
        fd.append('action', 'check_due');

        const response = await fetch(`${window.API_BASE}/reminders.php`, { method: 'POST', body: fd });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();

        // Polling göstergesi güncelle (gizli değilse veya varsa)
        if (indicator) indicator.classList.add('active');
        if (timeEl) timeEl.textContent = data.server_time ?? '';
        if (statusEl) statusEl.textContent = data.count > 0
            ? `🔔 ${data.count} aktif alarm!`
            : 'Alarm takibi aktif';

        // Gelen her alarm için bildirim göster
        for (const reminder of (data.due ?? [])) {
            const key = `${reminder.id}-${reminder.remind_at}`;
            if (shownAlarmIds.has(key)) continue;  // Zaten gösterildi

            shownAlarmIds.add(key);
            showAlarmModal(reminder);

            // Tarayıcı yerel bildirimi de gönder
            sendBrowserNotification(
                reminder.type === 'medication' ? '💊 İlaç Zamanı!' : '💪 Takviye Zamanı!',
                `${reminder.label} — Doz: ${reminder.dose}`
            );
        }

        // Her yeni dakikada gösterilen alarm setini temizle (bir sonraki döneme hazırla)
        const currentMinute = new Date().toTimeString().substring(0, 5);
        if (pollForDueReminders._lastMinute !== currentMinute) {
            pollForDueReminders._lastMinute = currentMinute;
            // Geçmiş alarm ID'lerini 10 dakika sonra temizle
            setTimeout(() => shownAlarmIds.clear(), 10 * 60 * 1000);
        }

    } catch (err) {
        if (indicator) indicator.classList.remove('active');
        if (statusEl) statusEl.textContent = '⚠️ Bağlantı hatası';
        console.warn('Alarm polling hatası:', err);
    }
}

// ─────────────────────────────────────────────────────────────────────
// BÖLÜM 4: SAAT ETİKETİ (Tag Input) YÖNETİMİ
// ─────────────────────────────────────────────────────────────────────

/** Tüm inline alarm saatlerini key: suppId → Set<string> olarak tutar */
const scheduleMap = {};

<?php foreach ($supplements as $supp): ?>
scheduleMap[<?= $supp['id'] ?>] = new Set(<?= json_encode($supp['schedule_times']) ?>);
<?php endforeach; ?>

// Aktif takviyeleri yüksek hassasiyetli yerel kontrol için sakla
window.__SUPPLEMENTS_CACHE__ = <?= json_encode(array_values(array_filter($supplements, fn($s) => (int)$s['is_active'] === 1 && empty($s['is_expired'])))) ?>;

/**
 * Tarayıcı saatiyle her 2 saniyede bir eşleşen alarmları anında çalar (Sıfır gecikme).
 */
function checkLocalReminders() {
    if (!window.__SUPPLEMENTS_CACHE__ || !window.__SUPPLEMENTS_CACHE__.length) return;

    const now = new Date();
    const nowMin = now.toTimeString().substring(0, 5); // "14:30"
    const todayDate = now.toDateString();

    for (const supp of window.__SUPPLEMENTS_CACHE__) {
        const times = supp.schedule_times || [];
        for (const t of times) {
            if (t === nowMin) {
                const key = `${supp.id}-${t}-${todayDate}`;
                if (shownAlarmIds.has(key)) continue;
                shownAlarmIds.add(key);

                const reminderObj = {
                    id: supp.id,
                    type: supp.type,
                    label: supp.name,
                    remind_at: t,
                    dose: `${supp.dose_amount || ''} ${supp.dose_unit || ''}`.trim(),
                    form: supp.form || 'tablet'
                };

                showAlarmModal(reminderObj);

                sendBrowserNotification(
                    supp.type === 'medication' ? '💊 İlaç Zamanı!' : '💪 Takviye Zamanı!',
                    `${supp.name} — Doz: ${reminderObj.dose}`
                );
            }
        }
    }
}

/**
 * Inline saat input'undan yeni saat ekler ve API'ye kaydeder.
 * @param {number} suppId  Supplement ID
 * @param {HTMLInputElement} inputEl
 */
async function addTimeInline(suppId, inputEl) {
    const time = inputEl.value.trim();
    if (!time || !time.match(/^\d{2}:\d{2}$/)) return;

    if (!scheduleMap[suppId]) scheduleMap[suppId] = new Set();
    if (scheduleMap[suppId].has(time)) {
        inputEl.value = '';
        return; // Zaten var
    }

    scheduleMap[suppId].add(time);
    inputEl.value = '';

    // Yerel önbelleği güncelle
    const cached = (window.__SUPPLEMENTS_CACHE__ || []).find(s => s.id == suppId);
    if (cached) cached.schedule_times = [...scheduleMap[suppId]];

    await syncScheduleToApi(suppId);
    renderTimeBadges(suppId);
}

/**
 * Bir saati siler ve API'yi günceller.
 */
async function removeTime(suppId, time) {
    if (!scheduleMap[suppId]) return;
    scheduleMap[suppId].delete(time);

    // Yerel önbelleği güncelle
    const cached = (window.__SUPPLEMENTS_CACHE__ || []).find(s => s.id == suppId);
    if (cached) cached.schedule_times = [...scheduleMap[suppId]];

    await syncScheduleToApi(suppId);
    renderTimeBadges(suppId);
}

/**
 * scheduleMap'teki saatleri backend'e kaydeder.
 */
async function syncScheduleToApi(suppId) {
    const times = [...(scheduleMap[suppId] ?? [])].sort();
    const fd    = new FormData();
    fd.append('action',          'update_schedule');
    fd.append('supplement_id',   suppId);
    fd.append('schedule_times',  JSON.stringify(times));

    const res  = await fetch(`${window.API_BASE}/reminders.php`, { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.ok) {
        Swal.fire({ icon:'error', title:'Hata', text: data.error, background:'#ffffff', color:'#1e293b' });
    }
}

/**
 * Bir supplement kartındaki saat badge'lerini yeniden çizer.
 */
function renderTimeBadges(suppId) {
    const container = document.getElementById(`badges-${suppId}`);
    if (!container) return;

    const times = [...(scheduleMap[suppId] ?? [])].sort();

    container.innerHTML = times.length === 0
        ? '<span class="text-secondary small fst-italic">Alarm kurulmamış</span>'
        : times.map(t => `
            <span class="time-badge">
                <i class="bi bi-clock-fill"></i>
                ${escapeHtml(t)}
                <span class="remove-time" onclick="removeTime(${suppId}, '${t}')">×</span>
            </span>`
          ).join('');
}

// ─────────────────────────────────────────────────────────────────────
// BÖLÜM 5: YENİ EKLEME FORMUNUN TAG INPUT YÖNETİMİ
// ─────────────────────────────────────────────────────────────────────

const addFormTimes = new Set();

function addCurrentTimeToForm() {
    const input = document.getElementById('newTimeInput');
    if (!input) return;
    const val = input.value.trim();
    if (val) {
        addTagToForm(val);
    }
}

function quickAddTime(timeStr) {
    addTagToForm(timeStr);
}

const newTimeInputEl = document.getElementById('newTimeInput');
if (newTimeInputEl) {
    newTimeInputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addCurrentTimeToForm();
        }
    });
}

function addTagToForm(time) {
    if (!time || !time.match(/^\d{2}:\d{2}$/) || addFormTimes.has(time)) return;
    addFormTimes.add(time);
    updateFormInput();

    const hint = document.getElementById('noTimeHint');
    if (hint) hint.classList.add('d-none');

    const box = document.getElementById('timeTagBox');
    const tag = document.createElement('span');
    tag.className = 'time-badge';
    tag.dataset.time = time;
    tag.innerHTML = `<i class="bi bi-clock-fill"></i>${escapeHtml(time)}<span class="remove-time ms-1" onclick="removeFormTag('${time}')">×</span>`;
    box.appendChild(tag);
}

function removeFormTag(time) {
    addFormTimes.delete(time);
    updateFormInput();
    const box = document.getElementById('timeTagBox');
    for (const el of box.querySelectorAll('.time-badge')) {
        if (el.dataset.time === time) el.remove();
    }
    if (addFormTimes.size === 0) {
        const hint = document.getElementById('noTimeHint');
        if (hint) hint.classList.remove('d-none');
    }
}

function updateFormInput() {
    document.getElementById('scheduleTimesInput').value = [...addFormTimes].sort().join(',');
}

// ─────────────────────────────────────────────────────────────────────
// BÖLÜM 6: YARDIMCILAR & BAŞLATMA
// ─────────────────────────────────────────────────────────────────────

/** HTML escape (XSS önlemi) */
function escapeHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/** Silme onay dialogu */
function confirmDelete(event, name) {
    event.preventDefault();
    const form = event.target;
    Swal.fire({
        icon: 'warning',
        title: 'Silmek istediğinize emin misiniz?',
        html: `<strong style="color:#ef4444">${escapeHtml(name)}</strong> ve tüm alarmları silinecek.`,
        confirmButtonText: '🗑️ Evet, Sil',
        cancelButtonText:  'Vazgeç',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor:  '#64748b',
        background: '#ffffff', color: '#1e293b',
    }).then(r => { if (r.isConfirmed) form.submit(); });
    return false;
}

/** İlacı Bitirme Onay Diyaloğu */
function confirmFinish(event, name) {
    event.preventDefault();
    const form = event.target;
    Swal.fire({
        icon: 'question',
        title: 'İlaç/Tedavi Tamamlandı mı?',
        html: `<strong style="color:#0284c7">${escapeHtml(name)}</strong> için tedaviyi tamamlayıp tüm alarmlarını sonlandırmak istiyor musunuz?<br><small class="text-secondary mt-2 d-block">Dashboard ve bildirimlerden bu ilacın tüm alarmları kaldırılacaktır.</small>`,
        confirmButtonText: '🏁 Evet, İlacı Bitir',
        cancelButtonText:  'Vazgeç',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        cancelButtonColor:  '#64748b',
        background: '#ffffff', color: '#1e293b',
    }).then(r => { if (r.isConfirmed) form.submit(); });
    return false;
}

/**
 * Arka Plan Nöbetçisi Açma / Kapatma
 */
function toggleSentinelMode(enabled) {
    if (window.optiAlarmEngine) {
        window.optiAlarmEngine.toggleSentinel(enabled);
    }
    const badge = document.getElementById('sentinelStatusBadge');
    if (badge) {
        if (enabled) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle mt-2 px-2 py-1';
            badge.innerHTML = '🟢 Nöbetçi Aktif (Ekran kapansa da çalar)';
        } else {
            badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle mt-2 px-2 py-1';
            badge.innerHTML = '⏸️ Nöbetçi Kapalı (Yalnızca ekran açıkken)';
        }
    }
}

/**
 * Sistem Bildirimi ve Kilit Ekranı Görünüm Testi
 */
async function testSystemNotification() {
    if ('Notification' in window && Notification.permission !== 'granted') {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
            Swal.fire({
                icon: 'warning',
                title: 'Bildirim İzni Gerekli',
                text: 'Bildirimlerin kilit ekranında ve bildirim çubuğunda görünmesi için tarayıcı bildirim iznini onaylamanız gerekir.',
                confirmButtonColor: '#0284c7',
                background: '#ffffff',
                color: '#1e293b'
            });
            return;
        }
    }

    if (window.optiAlarmEngine) {
        window.optiAlarmEngine.showSystemNotification('🔔 Test Bildirimi', {
            body: 'Harika! OptiLifeSync alarm bildirimleri telefon ekranınızda başarıyla çalışıyor.'
        });

        Swal.fire({
            icon: 'success',
            title: 'Test Bildirimi Gönderildi',
            text: 'Telefonunuzun bildirim çubuğunu veya kilit ekranını kontrol edin.',
            timer: 3000,
            showConfirmButton: false,
            background: '#ffffff',
            color: '#1e293b'
        });
    }
}

// ── Başlatma ─────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {

    // 1. Bildirim izni butonu
    document.getElementById('notif-req-btn').addEventListener('click', requestNotificationPermission);

    // 2. Mevcut izin durumuna göre banner göster
    if ('Notification' in window && Notification.permission === 'default') {
        document.getElementById('notif-banner').classList.remove('d-none');
    }

    // 3. İlk polling hemen çalışsın
    pollForDueReminders();

    // 4. Her 30 saniyede bir server polling tekrar et
    setInterval(pollForDueReminders, 30 * 1000);

    // 5. Alarm Ses ve Düzey Ayarları
    if (window.optiAlarmEngine) {
        const soundSelect    = document.getElementById('alarmSoundSelect');
        const volSlider      = document.getElementById('alarmVolumeSlider');
        const volLabel       = document.getElementById('alarmVolumeLabel');
        const testBtn        = document.getElementById('testSoundBtn');
        const sentinelToggle = document.getElementById('sentinelToggle');

        if (soundSelect) {
            soundSelect.value = window.optiAlarmEngine.getSound();
            soundSelect.addEventListener('change', (e) => {
                window.optiAlarmEngine.setSound(e.target.value);
                window.optiAlarmEngine.testSound();
            });
        }

        if (volSlider && volLabel) {
            volSlider.value = window.optiAlarmEngine.getVolumePercent();
            volLabel.textContent = window.optiAlarmEngine.getVolumePercent() + '%';
            volSlider.addEventListener('input', (e) => {
                window.optiAlarmEngine.setVolume(e.target.value);
                volLabel.textContent = e.target.value + '%';
            });
        }

        if (testBtn) {
            testBtn.addEventListener('click', () => {
                window.optiAlarmEngine.testSound();
            });
        }

        if (sentinelToggle) {
            sentinelToggle.checked = window.optiAlarmEngine.isSentinelEnabled;
            toggleSentinelMode(sentinelToggle.checked);
        }

        // Capacitor APK ortamındaysa sistem alarmlarını senkronize et
        if (window.Capacitor) {
            const allAlarms = [];
            for (const s of (window.__SUPPLEMENTS_CACHE__ || [])) {
                for (const t of (s.schedule_times || [])) {
                    allAlarms.push({ id: s.id, type: s.type, label: s.name, dose: `${s.dose_amount||''} ${s.dose_unit||''}`.trim(), remind_at: t });
                }
            }
            window.optiAlarmEngine.syncCapacitorNotifications(allAlarms);
        }
    }

    // 6. Yüksek hassasiyetli yerel alarm kontrolü (Her 2 saniyede bir - Sıfır gecikme)
    checkLocalReminders();
    setInterval(checkLocalReminders, 2000);

    console.log('🔔 OptiLifeSync Alarm & Ses Sistemi başlatıldı.');
});
</script>

</body>
</html>

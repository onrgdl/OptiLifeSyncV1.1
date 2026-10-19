<?php

declare(strict_types=1);

/**
 * OptiLifeSync - İlaç, Takviye & Alarm Yönetimi (v2)
 *
 *  • Bugünün doz listesi: "Aldım / Atla / Geri al" + uyum oranı
 *  • İlaç / takviye ekleme, alarm saatleri, kür süresi
 *  • Özel hatırlatıcılar (su, öğün, antrenman, diğer)
 *  • Alarm güvenilirliği: Android uygulamasında telefonun alarm sistemi,
 *    tarayıcıda sayfa açıkken sesli uyarı
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/ReminderService.php';
require_once __DIR__ . '/app/Services/MedicationTracker.php';

use App\Services\ReminderService;
use App\Services\MedicationTracker;

$service = $pdo ? new ReminderService($pdo) : null;
$tracker = $pdo ? new MedicationTracker($pdo) : null;

$flashMsg  = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service) {
    $action = $_POST['form_action'] ?? '';
    try {
        if ($action === 'add') {
            if (trim((string)($_POST['name'] ?? '')) === '') {
                throw new \InvalidArgumentException('İlaç/takviye adı boş olamaz.');
            }
            $newId = $service->addSupplement($userId, $_POST);
            $times = array_filter(array_map('trim', explode(',', $_POST['schedule_times'] ?? '')), fn($t) => $t !== '');
            if ($newId && !empty($times)) {
                $service->updateSchedule($newId, $userId, array_values($times));
            }
            $flashMsg = trim((string)($_POST['name'] ?? '')) . ' eklendi' . (empty($times) ? '. Alarm saati eklemeyi unutmayın.' : ' ve alarmları kuruldu.');
        } elseif ($action === 'finish') {
            $service->finishSupplement((int)($_POST['supplement_id'] ?? 0), $userId);
            $flashMsg = 'Tedavi tamamlandı olarak işaretlendi, alarmları kaldırıldı.';
        } elseif ($action === 'delete') {
            $service->deleteSupplement((int)($_POST['supplement_id'] ?? 0), $userId);
            $flashMsg = 'Kayıt ve bağlı alarmlar silindi.';
        } elseif ($action === 'add_custom') {
            $days = array_map('intval', (array)($_POST['days'] ?? []));
            $service->addCustomReminder($userId, (string)($_POST['label'] ?? ''), (string)($_POST['type'] ?? 'custom'), (string)($_POST['time'] ?? ''), $days);
            $flashMsg = 'Hatırlatıcı eklendi.';
        } elseif ($action === 'delete_custom') {
            $service->deleteReminder((int)($_POST['reminder_id'] ?? 0), $userId);
            $flashMsg = 'Hatırlatıcı silindi.';
        } elseif ($action === 'toggle_custom') {
            $service->toggleReminder((int)($_POST['reminder_id'] ?? 0), $userId);
        }
        if ($flashMsg !== '' || $action === 'toggle_custom') {
            // Yenilemede formun tekrar gönderilmesini önle (PRG)
            if ($flashMsg !== '') {
                // Sunucusuz ortamda oturum dosyası güvenilir değil: kısa ömürlü çerez kullan
                setcookie('opti_flash', base64_encode($flashMsg), ['expires' => time() + 60, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
            }
            header('Location: reminders.php');
            exit;
        }
    } catch (\Throwable $e) {
        $flashMsg  = 'Hata: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $flashType = 'danger';
    }
}

if ($flashMsg === '' && !empty($_COOKIE['opti_flash'])) {
    $flashMsg = htmlspecialchars((string) base64_decode((string) $_COOKIE['opti_flash'], true), ENT_QUOTES, 'UTF-8');
    $flashType = 'success';
    setcookie('opti_flash', '', ['expires' => time() - 3600, 'path' => '/']);
}

$supplements = $service ? $service->getSupplementsWithSchedule($userId) : [];
$customs     = $service ? $service->getCustomReminders($userId) : [];
$todayDoses  = $tracker ? $tracker->getDosesForDate($userId) : ['doses' => [], 'summary' => ['total' => 0, 'taken' => 0, 'pct' => 0, 'missed' => 0, 'next' => null]];
$adherence   = $tracker ? $tracker->getAdherence($userId, 7) : ['pct' => null, 'series' => [], 'streak' => 0];

$activeSupps   = array_values(array_filter($supplements, fn($s) => (int)$s['is_active'] === 1 && empty($s['is_expired'])));
$finishedSupps = array_values(array_filter($supplements, fn($s) => !((int)$s['is_active'] === 1 && empty($s['is_expired']))));

$typeLabels = [
    'medication' => ['label' => 'İlaç',    'tone' => 'red',    'icon' => 'bi-capsule-pill'],
    'supplement' => ['label' => 'Takviye', 'tone' => 'accent', 'icon' => 'bi-droplet-half'],
    'vitamin'    => ['label' => 'Vitamin', 'tone' => 'yellow', 'icon' => 'bi-sun'],
    'mineral'    => ['label' => 'Mineral', 'tone' => 'blue',   'icon' => 'bi-gem'],
    'herb'       => ['label' => 'Bitkisel','tone' => 'accent', 'icon' => 'bi-flower1'],
    'other'      => ['label' => 'Diğer',   'tone' => 'purple', 'icon' => 'bi-box'],
];
$formLabels = ['tablet' => 'Tablet', 'capsule' => 'Kapsül', 'powder' => 'Toz', 'liquid' => 'Sıvı', 'injection' => 'Enjeksiyon', 'patch' => 'Bant', 'other' => 'Diğer'];
$customTypes = [
    'water'   => ['label' => 'Su',        'icon' => 'bi-droplet',          'tone' => 'cyan'],
    'meal'    => ['label' => 'Öğün',      'icon' => 'bi-egg-fried',        'tone' => 'yellow'],
    'workout' => ['label' => 'Antrenman', 'icon' => 'bi-lightning-charge', 'tone' => 'purple'],
    'custom'  => ['label' => 'Diğer',     'icon' => 'bi-bell',             'tone' => 'blue'],
];
$dayShort = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];

function fmtDose($amount, $unit): string {
    $a = rtrim(rtrim(number_format((float)$amount, 2, '.', ''), '0'), '.');
    return $a . ' ' . $unit;
}
$v = '20261019';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>İlaç & Alarmlar · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .rm-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
        @media (max-width: 1200px) { .rm-grid { grid-template-columns: minmax(0, 1fr); } }
        .stack > * { min-width: 0; }
        .stack { display: flex; flex-direction: column; gap: 20px; }
        .card-pad { padding: 20px; }
        @media (max-width: 576px) { .card-pad { padding: 16px; } }

        /* Bugün özeti */
        .today-hero { display: flex; align-items: center; gap: 20px; }
        .today-hero .ring { --size: 96px; --w: 10px; }
        .ring-label { text-align: center; line-height: 1.05; }
        .ring-label b { font-size: 22px; font-weight: 800; display: block; }
        .ring-label span { font-size: 11px; color: var(--muted); }
        .hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; flex: 1; }
        .hero-stat { background: var(--surface-2); border: 1px solid var(--border); border-radius: 14px; padding: 10px 12px; }
        .hero-stat .v { font-size: 20px; font-weight: 800; letter-spacing: -.02em; }
        .hero-stat .l { font-size: 11.5px; color: var(--muted); font-weight: 600; }
        @media (max-width: 576px) { .today-hero { gap: 14px; } .today-hero .ring { --size: 84px; } .hero-stats { grid-template-columns: 1fr 1fr; } .hero-stat:last-child { grid-column: span 2; } .hero-stat .v { font-size: 17px; } }

        .week-bars { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; margin-top: 16px; }
        .week-bar { display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 11px; color: var(--muted); font-weight: 600; }
        .week-bar .wb-col { width: 100%; max-width: 34px; height: 56px; border-radius: 10px; background: var(--surface-3); position: relative; overflow: hidden; }
        .week-bar .wb-col > i { position: absolute; left: 0; right: 0; bottom: 0; border-radius: 10px; background: var(--brand-grad); }
        .week-bar .wb-col.none { background: repeating-linear-gradient(45deg, var(--surface-2), var(--surface-2) 4px, var(--surface-3) 4px, var(--surface-3) 8px); }

        /* Doz satırları */
        .dose-row { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 14px; border: 1px solid var(--border); background: var(--surface); transition: background .2s, border-color .2s; }
        .dose-row + .dose-row { margin-top: 8px; }
        .dose-time { font-weight: 800; font-size: 15px; width: 52px; white-space: nowrap; flex-shrink: 0; font-variant-numeric: tabular-nums; }
        .dose-main { flex: 1; min-width: 0; }
        .dose-name { font-weight: 650; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dose-meta { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .dose-meta .chip { padding: 1px 8px; font-size: 11px; }
        @media (max-width: 576px) { .dose-row { padding: 10px; gap: 10px; } .dose-time { width: 46px; font-size: 14px; } .btn-take { padding: 7px 10px; } }
        .dose-row.taken { background: var(--green-dim); border-color: transparent; }
        .dose-row.taken .dose-name { text-decoration: line-through; text-decoration-color: color-mix(in srgb, var(--green) 60%, transparent); }
        .dose-row.missed { border-color: color-mix(in srgb, var(--red) 35%, transparent); }
        .dose-row.due { border-color: var(--accent-bright); box-shadow: 0 0 0 3px var(--accent-ring); }
        .dose-row.skipped { opacity: .7; }
        .dose-actions { display: flex; gap: 6px; flex-shrink: 0; }
        .btn-take { background: var(--brand-grad); color: #fff; border: 0; border-radius: 11px; padding: 7px 12px; font-weight: 700; font-size: 13px; }
        .btn-take:active { transform: scale(.96); }

        /* İlaç kartları */
        .med { border: 1px solid var(--border); border-radius: 16px; padding: 16px; background: var(--surface); }
        .med + .med { margin-top: 12px; }
        .med-head { display: flex; gap: 12px; align-items: flex-start; }
        .med-title { font-weight: 700; font-size: 15.5px; line-height: 1.25; }
        .med-sub { font-size: 12.5px; color: var(--muted); margin-top: 2px; }
        .med-times { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 12px; }
        .time-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 6px 5px 11px; border-radius: 99px; background: var(--accent-dim); color: var(--accent); font-weight: 700; font-size: 13px; font-variant-numeric: tabular-nums; }
        .time-chip button { border: 0; background: transparent; color: inherit; opacity: .6; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; padding: 0; font-size: 13px; }
        .time-chip button:hover { opacity: 1; background: color-mix(in srgb, var(--accent) 15%, transparent); }
        .add-time { display: inline-flex; align-items: center; gap: 4px; }
        .add-time input { width: 116px; padding: 4px 8px !important; border-radius: 99px !important; font-size: 13px !important; height: 32px; }
        .add-time button { height: 32px; width: 32px; border-radius: 50%; padding: 0; }
        .course { margin-top: 12px; }
        .course .bar { height: 6px; }

        /* Hatırlatıcı listesi */
        .rem-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .rem-row:last-child { border-bottom: 0; }
        .rem-row.off { opacity: .55; }
        .day-pick { display: flex; gap: 4px; flex-wrap: wrap; }
        .day-pick input { display: none; }
        .day-pick label { width: 38px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: 12px; font-weight: 700; border: 1px solid var(--border-strong); color: var(--muted); cursor: pointer; user-select: none; }
        .day-pick input:checked + label { background: var(--accent-dim); border-color: transparent; color: var(--accent); }

        /* Güvenilirlik kartı */
        .reliab-item { display: flex; align-items: center; gap: 10px; padding: 9px 0; font-size: 13.5px; }
        .reliab-item + .reliab-item { border-top: 1px dashed var(--border); }
        .dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: var(--muted-2); }
        .dot.ok { background: var(--green); box-shadow: 0 0 0 4px var(--green-dim); }
        .dot.bad { background: var(--red); box-shadow: 0 0 0 4px var(--red-dim); }
        .apk-promo { background: linear-gradient(135deg, rgba(16,185,129,.14), rgba(14,165,233,.10)); border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent); border-radius: 16px; padding: 16px; }

        /* Form saat etiketleri */
        .tag-box { display: flex; flex-wrap: wrap; gap: 6px; min-height: 42px; align-items: center; padding: 6px; border: 1px dashed var(--border-strong); border-radius: 12px; }
        .quick-times button { font-size: 12px; padding: 4px 10px; }
        details.settings summary { cursor: pointer; list-style: none; display: flex; align-items: center; justify-content: space-between; font-weight: 650; }
        details.settings summary::-webkit-details-marker { display: none; }
        details.settings[open] summary .bi-chevron-down { transform: rotate(180deg); }
        .finished-list .med { opacity: .7; }
            .stock { margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); border: 1px solid var(--border); }
        .stock .bar span { background: var(--accent); }
        .stock.low { border-color: color-mix(in srgb, var(--yellow, #f59e0b) 55%, transparent); background: color-mix(in srgb, var(--yellow, #f59e0b) 10%, var(--surface-2)); }
        .stock.low .stock-label { color: var(--yellow, #b45309); font-weight: 600; }
        .stock.low .bar span { background: var(--yellow, #f59e0b); }
        .stock.empty { border-color: color-mix(in srgb, var(--red) 55%, transparent); background: color-mix(in srgb, var(--red) 8%, var(--surface-2)); }
        .stock.empty .stock-label { color: var(--red); font-weight: 600; }
        .stock-box { padding: 12px; border-radius: 12px; background: var(--surface-2); border: 1px solid var(--border); }
        .stock-enable { color: var(--muted); text-decoration: none; }
</style>
</head>
<body>
<?php $activePage = 'reminders'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">İlaç & Alarmlar</div>
                <div class="topbar-sub">Dozlarınızı takip edin, alarmlarınızı yönetin</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-accent" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i><span class="d-mobile-none">İlaç / takviye ekle</span><span class="d-md-none">Ekle</span>
            </button>
        </div>
    </header>

    <div class="content">
        <?php if ($flashMsg): ?>
            <div class="alert alert-<?= $flashType ?> alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
                <i class="bi <?= $flashType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMsg ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="rm-grid">
            <!-- ═════════ SOL SÜTUN ═════════ -->
            <div class="stack">

                <!-- Bugün -->
                <section class="card card-pad fade-in" id="todayCard">
                    <div class="card-head">
                        <h2 class="card-title-sm"><i class="bi bi-calendar-check"></i>Bugünün dozları</h2>
                        <span class="chip" id="todayDateChip"><?= date('d.m.Y') ?></span>
                    </div>

                    <div class="today-hero">
                        <?php $s = $todayDoses['summary']; ?>
                        <div class="ring" id="todayRing" style="--p:<?= (int)$s['pct'] ?>">
                            <div class="ring-label"><b id="todayPct"><?= (int)$s['pct'] ?>%</b><span>tamamlandı</span></div>
                        </div>
                        <div class="hero-stats">
                            <div class="hero-stat"><div class="v" id="statTaken"><?= (int)$s['taken'] ?>/<?= (int)$s['total'] ?></div><div class="l">Alınan doz</div></div>
                            <div class="hero-stat"><div class="v" id="statNext"><?= $s['next'] ? htmlspecialchars($s['next']['time']) : '—' ?></div><div class="l">Sıradaki</div></div>
                            <div class="hero-stat"><div class="v" id="statWeek"><?= $adherence['pct'] === null ? '—' : $adherence['pct'] . '%' ?></div><div class="l">7 günlük uyum</div></div>
                        </div>
                    </div>

                    <div class="week-bars" id="weekBars">
                        <?php foreach ($adherence['series'] as $d): ?>
                            <div class="week-bar" title="<?= $d['label'] ?>: <?= $d['taken'] ?>/<?= $d['planned'] ?>">
                                <div class="wb-col <?= $d['pct'] === null ? 'none' : '' ?>"><i style="height:<?= (int)($d['pct'] ?? 0) ?>%"></i></div>
                                <span><?= $dayShort[(int)date('w', strtotime($d['date']))] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4" id="doseList">
                        <?php if (empty($todayDoses['doses'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-capsule"></i>
                                Bugün için planlanmış doz yok.<br>
                                <button class="btn btn-primary btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>İlk ilacını ekle</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- İlaçlarım -->
                <section class="card card-pad">
                    <div class="card-head">
                        <h2 class="card-title-sm"><i class="bi bi-capsule-pill"></i>İlaç & takviyelerim</h2>
                        <span class="chip"><?= count($activeSupps) ?> aktif</span>
                    </div>

                    <?php if (empty($activeSupps)): ?>
                        <div class="empty-state"><i class="bi bi-inboxes"></i>Aktif ilaç veya takviye yok.</div>
                    <?php endif; ?>

                    <?php foreach ($activeSupps as $supp):
                        $t = $typeLabels[$supp['type']] ?? $typeLabels['other'];
                        $times = $supp['schedule_times'];
                        $dur = (int)($supp['duration_days'] ?? 0);
                        $rem = $supp['remaining_days'];
                    ?>
                        <article class="med" id="supp-<?= (int)$supp['id'] ?>">
                            <div class="med-head">
                                <div class="icon-tile <?= $t['tone'] === 'accent' ? '' : $t['tone'] ?>"><i class="bi <?= $t['icon'] ?>"></i></div>
                                <div style="flex:1;min-width:0">
                                    <div class="med-title"><?= htmlspecialchars($supp['name']) ?></div>
                                    <div class="med-sub">
                                        <span class="chip <?= $t['tone'] ?>" style="padding:2px 8px;font-size:11px"><?= $t['label'] ?></span>
                                        <?= htmlspecialchars(fmtDose($supp['dose_amount'], $supp['dose_unit'])) ?> · <?= htmlspecialchars($formLabels[$supp['form']] ?? $supp['form']) ?>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button class="icon-btn" data-bs-toggle="dropdown" aria-label="İşlemler"><i class="bi bi-three-dots"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <form method="POST" onsubmit="return confirmFinish(event, <?= htmlspecialchars(json_encode($supp['name']), ENT_QUOTES) ?>)">
                                                <input type="hidden" name="form_action" value="finish">
                                                <input type="hidden" name="supplement_id" value="<?= (int)$supp['id'] ?>">
                                                <button class="dropdown-item" type="submit"><i class="bi bi-flag me-2"></i>Tedaviyi bitir</button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="POST" onsubmit="return confirmDelete(event, <?= htmlspecialchars(json_encode($supp['name']), ENT_QUOTES) ?>)">
                                                <input type="hidden" name="form_action" value="delete">
                                                <input type="hidden" name="supplement_id" value="<?= (int)$supp['id'] ?>">
                                                <button class="dropdown-item" type="submit" style="color:var(--red)"><i class="bi bi-trash3 me-2"></i>Sil</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="med-times">
                                <div class="d-contents" id="badges-<?= (int)$supp['id'] ?>" style="display:contents"></div>
                                <div class="add-time">
                                    <input type="time" class="form-control" id="inlineTime-<?= (int)$supp['id'] ?>" value="08:00"
                                           onkeydown="if(event.key==='Enter'){event.preventDefault();addTimeInline(<?= (int)$supp['id'] ?>, this);}">
                                    <button type="button" class="btn btn-outline-primary" title="Alarm saati ekle"
                                            onclick="addTimeInline(<?= (int)$supp['id'] ?>, document.getElementById('inlineTime-<?= (int)$supp['id'] ?>'))"><i class="bi bi-plus-lg"></i></button>
                                </div>
                            </div>

                            <?php if ($dur > 0 && $rem !== null): ?>
                                <div class="course">
                                    <div class="d-flex justify-content-between small mb-1" style="color:var(--muted)">
                                        <span><i class="bi bi-hourglass-split me-1"></i><?= $dur ?> günlük kür</span>
                                        <span><strong style="color:var(--text)"><?= (int)$rem ?></strong> gün kaldı</span>
                                    </div>
                                    <div class="bar"><span style="width:<?= max(3, min(100, round(($dur - $rem) / max(1, $dur) * 100))) ?>%"></span></div>
                                </div>
                            <?php endif; ?>

                            <?php $st = $supp['stock'] ?? null;
                                  $stockCfg = [
                                      'id' => (int)$supp['id'], 'name' => $supp['name'],
                                      'track' => (int)($supp['track_stock'] ?? 0), 'qty' => $supp['stock_quantity'] ?? null,
                                      'pack' => $supp['pack_size'] ?? null, 'upd' => $supp['units_per_dose'] ?? 1,
                                      'thr' => (int)($supp['low_stock_days'] ?? 5), 'rebuy' => (int)($supp['repurchase'] ?? 1),
                                  ]; ?>
                            <?php if ($st): ?>
                                <div class="stock <?= $st['status'] ?>" id="stock-<?= (int)$supp['id'] ?>">
                                    <div class="d-flex justify-content-between align-items-center gap-2 small mb-1">
                                        <span class="stock-label"><i class="bi <?= $st['status'] === 'ok' ? 'bi-box-seam' : 'bi-exclamation-triangle-fill' ?> me-1"></i><?= htmlspecialchars($st['label']) ?></span>
                                        <?php if ($st['final_pack']): ?><span class="chip" style="padding:1px 8px;font-size:11px">Son paket</span><?php endif; ?>
                                    </div>
                                    <?php if ($st['pct'] !== null): ?><div class="bar"><span style="width:<?= max(2, $st['pct']) ?>%"></span></div><?php endif; ?>
                                    <div class="d-flex gap-2 mt-2">
                                        <?php if (!$st['final_pack']): ?>
                                            <button type="button" class="btn btn-sm btn-light" onclick='refillStock(<?= json_encode($stockCfg, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-bag-plus me-1"></i>Yeni paket aldım</button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-light" onclick='editStock(<?= json_encode($stockCfg, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil me-1"></i>Düzenle</button>
                                    </div>
                                    <?php if ($st['status'] === 'empty' && $st['final_pack']): ?>
                                        <div class="small mt-2" style="color:var(--muted)">Paket bitti. Tedavi tamamlandıysa menüden <b>Tedaviyi bitir</b> diyebilirsiniz.</div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn btn-link btn-sm px-0 mt-1 stock-enable" onclick='editStock(<?= json_encode($stockCfg, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="bi bi-box-seam me-1"></i>Kalan adedi takip et</button>
                            <?php endif; ?>

                            <?php if (!empty($supp['notes'])): ?>
                                <div class="small mt-2" style="color:var(--muted)"><i class="bi bi-sticky me-1"></i><?= htmlspecialchars($supp['notes']) ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>

                    <?php if ($finishedSupps): ?>
                        <details class="mt-3 finished-list">
                            <summary class="small fw-semibold" style="cursor:pointer;color:var(--muted)"><i class="bi bi-archive me-1"></i>Tamamlanan tedaviler (<?= count($finishedSupps) ?>)</summary>
                            <div class="mt-2">
                                <?php foreach ($finishedSupps as $supp): ?>
                                    <div class="med d-flex align-items-center gap-2">
                                        <div style="flex:1;min-width:0">
                                            <div class="fw-semibold text-truncate"><?= htmlspecialchars($supp['name']) ?></div>
                                            <div class="small" style="color:var(--muted)">Bitiş: <?= htmlspecialchars((string)($supp['end_date'] ?? '—')) ?></div>
                                        </div>
                                        <form method="POST" onsubmit="return confirmDelete(event, <?= htmlspecialchars(json_encode($supp['name']), ENT_QUOTES) ?>)">
                                            <input type="hidden" name="form_action" value="delete">
                                            <input type="hidden" name="supplement_id" value="<?= (int)$supp['id'] ?>">
                                            <button class="icon-btn danger" type="submit" title="Sil"><i class="bi bi-trash3"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </section>
            </div>

            <!-- ═════════ SAĞ SÜTUN ═════════ -->
            <div class="stack">

                <!-- Alarm güvenilirliği -->
                <section class="card card-pad" id="reliabilityCard">
                    <div class="card-head">
                        <h2 class="card-title-sm"><i class="bi bi-alarm"></i>Alarm durumu</h2>
                        <span class="chip" id="alarmModeChip">Tarayıcı</span>
                    </div>

                    <!-- APK içinde gösterilir -->
                    <div id="nativeStatus" class="d-none">
                        <div class="reliab-item"><span class="dot" id="stNotif"></span><span class="flex-grow-1">Bildirim izni</span><button class="btn btn-link btn-sm p-0 d-none" id="fixNotif">Düzelt</button></div>
                        <div class="reliab-item"><span class="dot" id="stExact"></span><span class="flex-grow-1">Tam zamanında çalma</span><button class="btn btn-link btn-sm p-0 d-none" id="fixExact">Düzelt</button></div>
                        <div class="reliab-item"><span class="dot" id="stFull"></span><span class="flex-grow-1">Kilit ekranında tam ekran</span><button class="btn btn-link btn-sm p-0 d-none" id="fixFull">Düzelt</button></div>
                        <div class="reliab-item"><span class="dot" id="stBattery"></span><span class="flex-grow-1">Pil kısıtlaması yok</span><button class="btn btn-link btn-sm p-0 d-none" id="fixBattery">Düzelt</button></div>
                        <div class="small mt-2" style="color:var(--muted)" id="nativeNext"></div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-primary btn-sm flex-grow-1" id="btnTestAlarm"><i class="bi bi-alarm me-1"></i>5 sn sonra test alarmı</button>
                            <button class="btn btn-outline-secondary btn-sm" id="btnResync" title="Alarmları yeniden kur"><i class="bi bi-arrow-repeat"></i></button>
                        </div>
                        <div class="mt-3">
                            <label class="form-label mb-1">Erteleme süresi</label>
                            <select class="form-select form-select-sm" id="snoozeSelect">
                                <?php foreach ([5, 10, 15, 20, 30] as $m): ?><option value="<?= $m ?>"><?= $m ?> dakika</option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Tarayıcıda gösterilir -->
                    <div id="browserStatus">
                        <div class="apk-promo mb-3">
                            <div class="d-flex gap-3 align-items-start">
                                <div class="icon-tile"><i class="bi bi-android2"></i></div>
                                <div>
                                    <div class="fw-bold">Uygulama kapalıyken de çalsın</div>
                                    <div class="small mt-1" style="color:var(--text-2)">Tarayıcı kapatıldığında alarm çalamaz. Android uygulamasını kurarsanız alarmlar telefonun kendi alarm sistemiyle, ekran kilitliyken bile çalar.</div>
                                    <a href="download.php" class="btn btn-primary btn-sm mt-3"><i class="bi bi-download me-1"></i>Android uygulamasını indir</a>
                                </div>
                            </div>
                        </div>
                        <div class="reliab-item"><span class="dot" id="stWebNotif"></span><span class="flex-grow-1">Tarayıcı bildirimleri</span><button class="btn btn-link btn-sm p-0" id="btnWebNotif">İzin ver</button></div>
                        <div class="reliab-item"><span class="dot ok"></span><span class="flex-grow-1">Sayfa açıkken sesli alarm</span></div>

                        <details class="settings mt-3">
                            <summary><span><i class="bi bi-volume-up me-1"></i>Ses ayarları</span><i class="bi bi-chevron-down"></i></summary>
                            <div class="mt-3">
                                <label class="form-label mb-1">Alarm melodisi</label>
                                <select id="alarmSoundSelect" class="form-select form-select-sm mb-3">
                                    <option value="classic">Klasik dijital bip</option>
                                    <option value="chime">Melodik çan</option>
                                    <option value="marimba">Yumuşak marimba</option>
                                    <option value="urgent">Acil uyarı</option>
                                    <option value="pulse">Modern ritim</option>
                                </select>
                                <div class="d-flex justify-content-between"><label class="form-label mb-1">Ses düzeyi</label><span class="small fw-bold" id="alarmVolumeLabel">70%</span></div>
                                <input type="range" class="form-range" id="alarmVolumeSlider" min="0" max="100" step="5" value="70">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sentinelToggle" checked>
                                    <label class="form-check-label small" for="sentinelToggle">Arka planda uyanık tut</label>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1" id="testSoundBtn"><i class="bi bi-play-fill me-1"></i>Sesi dene</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm flex-grow-1" id="testNotifBtn"><i class="bi bi-bell me-1"></i>Bildirim dene</button>
                                </div>
                            </div>
                        </details>
                    </div>
                </section>

                <!-- Diğer hatırlatıcılar -->
                <section class="card card-pad">
                    <div class="card-head">
                        <h2 class="card-title-sm"><i class="bi bi-bell"></i>Diğer hatırlatıcılar</h2>
                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#customForm"><i class="bi bi-plus-lg"></i> Ekle</button>
                    </div>

                    <form method="POST" class="collapse mb-3" id="customForm">
                        <input type="hidden" name="form_action" value="add_custom">
                        <div class="mb-2">
                            <input type="text" name="label" class="form-control" placeholder="ör. 2 bardak su iç, Yürüyüş" required maxlength="190">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <select name="type" class="form-select">
                                    <?php foreach ($customTypes as $k => $ct): ?><option value="<?= $k ?>"><?= $ct['label'] ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6"><input type="time" name="time" class="form-control" value="10:00" required></div>
                        </div>
                        <div class="day-pick mb-3">
                            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                                <input type="checkbox" name="days[]" value="<?= $d ?>" id="cd<?= $d ?>" checked><label for="cd<?= $d ?>"><?= $dayShort[$d] ?></label>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Hatırlatıcıyı kaydet</button>
                    </form>

                    <?php if (empty($customs)): ?>
                        <div class="small" style="color:var(--muted)">Su içme, öğün veya antrenman için de alarm kurabilirsiniz.</div>
                    <?php endif; ?>
                    <?php foreach ($customs as $c):
                        $ct = $customTypes[$c['type']] ?? $customTypes['custom'];
                        $days = $c['days_of_week'];
                        $daysTxt = count($days) === 7 ? 'Her gün' : implode(', ', array_map(fn($d) => $dayShort[(int)$d] ?? '', $days));
                    ?>
                        <div class="rem-row <?= $c['is_active'] ? '' : 'off' ?>">
                            <div class="icon-tile sm <?= $ct['tone'] ?>"><i class="bi <?= $ct['icon'] ?>"></i></div>
                            <div style="flex:1;min-width:0">
                                <div class="fw-semibold text-truncate"><?= htmlspecialchars($c['label']) ?></div>
                                <div class="small" style="color:var(--muted)"><strong style="color:var(--text)"><?= htmlspecialchars($c['remind_at']) ?></strong> · <?= $daysTxt ?></div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="form_action" value="toggle_custom">
                                <input type="hidden" name="reminder_id" value="<?= $c['id'] ?>">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" role="switch" <?= $c['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Aç/kapat">
                                </div>
                            </form>
                            <form method="POST" onsubmit="return confirmDelete(event, <?= htmlspecialchars(json_encode($c['label']), ENT_QUOTES) ?>)">
                                <input type="hidden" name="form_action" value="delete_custom">
                                <input type="hidden" name="reminder_id" value="<?= $c['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Sil"><i class="bi bi-trash3"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </section>
            </div>
        </div>
    </div>
</div>

<!-- ═════════ YENİ İLAÇ / TAKVİYE MODALI ═════════ -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="addForm">
            <input type="hidden" name="form_action" value="add">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Yeni ilaç / takviye</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Adı</label>
                    <input type="text" name="name" id="suppName" class="form-control" placeholder="Yazmaya başlayın: D3, Magnezyum, Omega-3…" required list="suppCatalog" autocomplete="off">
                    <datalist id="suppCatalog"></datalist>
                    <div class="form-text d-none" id="suppHint"><i class="bi bi-magic me-1"></i><span></span></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">Tür</label>
                        <select name="type" class="form-select">
                            <?php foreach ($typeLabels as $k => $tl): ?><option value="<?= $k ?>"><?= $tl['label'] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Form</label>
                        <select name="form" class="form-select">
                            <?php foreach (['tablet', 'capsule', 'powder', 'liquid', 'injection', 'other'] as $k): ?><option value="<?= $k ?>"><?= $formLabels[$k] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label">Doz</label>
                        <input type="number" name="dose_amount" step="0.1" min="0" class="form-control" value="1">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Birim</label>
                        <input type="text" name="dose_unit" class="form-control" value="adet" list="unitList">
                        <datalist id="unitList"><option value="adet"><option value="mg"><option value="g"><option value="ml"><option value="IU"><option value="damla"><option value="ölçek"></datalist>
                    </div>
                    <div class="col-3">
                        <label class="form-label">Günde</label>
                        <input type="number" name="doses_per_day" class="form-control" value="1" min="1" max="10" id="dosesPerDay" readonly>
                    </div>
                </div>

                <label class="form-label">Alarm saatleri</label>
                <div class="input-group mb-2">
                    <input type="time" id="newTimeInput" class="form-control" value="08:00">
                    <button type="button" class="btn btn-primary" onclick="addCurrentTimeToForm()"><i class="bi bi-plus-lg me-1"></i>Ekle</button>
                </div>
                <div class="tag-box" id="timeTagBox">
                    <span id="noTimeHint" class="small ps-1" style="color:var(--muted)">Henüz saat eklenmedi</span>
                </div>
                <div class="d-flex gap-1 mt-2 flex-wrap quick-times">
                    <?php foreach (['08:00' => 'Sabah', '13:00' => 'Öğle', '19:00' => 'Akşam', '22:30' => 'Gece'] as $qt => $ql): ?>
                        <button type="button" class="btn btn-light btn-sm" onclick="quickAddTime('<?= $qt ?>')"><?= $ql ?> · <?= $qt ?></button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="schedule_times" id="scheduleTimesInput" value="">

                <div class="row g-2 mt-3">
                    <div class="col-6">
                        <label class="form-label">Kür süresi (gün)</label>
                        <input type="number" name="duration_days" class="form-control" min="1" max="365" placeholder="Süresiz">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Başlangıç</label>
                        <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="form-text">Kür süresi dolunca alarmlar otomatik kapanır.</div>

                <div class="stock-box mt-3">
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="track_stock" value="1" id="trackStock" onchange="document.getElementById('stockFields').hidden = !this.checked">
                        <label class="form-check-label fw-semibold" for="trackStock">Paketteki adedi takip et</label>
                    </div>
                    <div class="form-text mt-1">Her "Aldım" dediğinizde kalan adet düşer, bitmeden önce uyarılırsınız.</div>
                    <div id="stockFields" hidden>
                        <div class="row g-2 mt-1">
                            <div class="col-6">
                                <label class="form-label">Paketteki adet</label>
                                <input type="number" name="pack_size" class="form-control" min="1" max="100000" step="1" placeholder="ör. 60">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Her dozda</label>
                                <div class="input-group">
                                    <input type="number" name="units_per_dose" class="form-control" min="0.5" max="100" step="0.5" value="1">
                                    <span class="input-group-text">adet</span>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-6">
                                <label class="form-label">Elde kalan <span style="color:var(--muted);font-weight:500">(açık paket)</span></label>
                                <input type="number" name="stock_quantity" class="form-control" min="0" max="100000" step="0.5" placeholder="Yeni ise boş bırakın">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Bitmeden uyar</label>
                                <div class="input-group">
                                    <input type="number" name="low_stock_days" class="form-control" min="0" max="60" value="5">
                                    <span class="input-group-text">gün önce</span>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="repurchase" value="0">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="repurchase" value="1" id="repurchase" checked>
                            <label class="form-check-label" for="repurchase">Bitince yenisini alacağım <span style="color:var(--muted)">(düzenli kullanım)</span></label>
                        </div>
                        <div class="form-text">Kısa süreli bir ilaç kullanıyorsanız (ör. hastalıkta antibiyotik) bu kutuyu boş bırakın: yenisini alma uyarısı gelmez, sadece düzenli alımınız ve kalan adet takip edilir.</div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label">Not</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Tok karnına, doktor notu…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button>
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Kaydet & alarm kur</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
const API = `${window.API_BASE}/reminders.php`;
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
const post = (params) => fetch(API, { method: 'POST', body: new URLSearchParams(params), credentials: 'include' }).then(r => r.json());
const toast = (title, icon = 'success') => window.Swal && Swal.fire({ toast: true, position: 'top', icon, title, showConfirmButton: false, timer: 2200 });
const escapeHtml = esc;

/* ── Bugünün dozları ─────────────────────────────────────── */
let TODAY = <?= json_encode($todayDoses, JSON_UNESCAPED_UNICODE) ?>;

const STATUS = {
    taken:   { chip: '' },
    skipped: { chip: '<span class="chip">Atlandı</span>' },
    missed:  { chip: '<span class="chip red">Kaçırıldı</span>' },
    due:     { chip: '<span class="chip accent">Şimdi</span>' },
    pending: { chip: '' }
};

function renderDoses() {
    const list = document.getElementById('doseList');
    const s = TODAY.summary;
    document.getElementById('todayRing').style.setProperty('--p', s.pct);
    document.getElementById('todayPct').textContent = s.pct + '%';
    document.getElementById('statTaken').textContent = `${s.taken}/${s.total}`;
    document.getElementById('statNext').textContent = s.next ? s.next.time : '—';
    if (!TODAY.doses.length) return;
    list.innerHTML = TODAY.doses.map(d => {
        const done = d.status === 'taken' || d.status === 'skipped';
        const args = `${d.supplement_id}, '${d.time}'`;
        const actions = done
            ? `<button class="icon-btn" title="Geri al" onclick="undoDose(${args})"><i class="bi bi-arrow-counterclockwise"></i></button>`
            : `<button class="btn-take" onclick="logDose(${args}, 'taken')"><i class="bi bi-check2 me-1"></i>Aldım</button>
               <button class="icon-btn" title="Atla" onclick="logDose(${args}, 'skipped')"><i class="bi bi-skip-forward"></i></button>`;
        return `<div class="dose-row ${d.status}">
            <div class="dose-time">${esc(d.time)}</div>
            <div class="dose-main">
                <div class="dose-name">${esc(d.name)}</div>
                <div class="dose-meta"><span>${esc(d.dose)}${d.taken_at && d.status === 'taken' ? ' · ' + esc(d.taken_at) : ''}</span>${STATUS[d.status]?.chip || ''}</div>
            </div>
            <div class="dose-actions">${actions}</div>
        </div>`;
    }).join('');
}

async function refreshDoses() {
    try {
        const data = await post({ action: 'doses' });
        if (data.ok) { TODAY = data; renderDoses(); }
        const adh = await post({ action: 'adherence', days: 7 });
        if (adh.ok) document.getElementById('statWeek').textContent = adh.pct === null ? '—' : adh.pct + '%';
    } catch (_) {}
}

async function logDose(suppId, time, status) {
    const d = TODAY.doses.find(x => x.supplement_id === suppId && x.time === time);
    if (d) { d.status = status; renderDoses(); }
    const res = await post({ action: 'log_dose', supplement_id: suppId, scheduled_time: time, status });
    if (!res.ok) toast(res.error || 'Kaydedilemedi', 'error');
    else if (!stockWarn(res.stock)) toast(status === 'taken' ? 'Alındı olarak işaretlendi' : 'Atlandı');
    if (res.ok && res.stock) paintStock(suppId, res.stock);
    refreshDoses();
}

/* ── Takviye kataloğu: ad yazınca tür / form / doz önerisi ─────────── */
// Doz değerleri yaygın etiket dozlarıdır; reçeteli ilaçlar için öneri yapılmaz.
const SUPP_CATALOG = [
    ['D3 vitamini', 'vitamin', 'capsule', 1000, 'IU'], ['D3 + K2 vitamini', 'vitamin', 'capsule', 1000, 'IU'],
    ['C vitamini', 'vitamin', 'tablet', 1000, 'mg'], ['B12 vitamini', 'vitamin', 'tablet', 1000, 'mcg'],
    ['B kompleks', 'vitamin', 'tablet', 1, 'adet'], ['Multivitamin', 'vitamin', 'tablet', 1, 'adet'],
    ['E vitamini', 'vitamin', 'capsule', 400, 'IU'], ['Folik asit', 'vitamin', 'tablet', 400, 'mcg'],
    ['Biotin', 'vitamin', 'tablet', 5000, 'mcg'],
    ['Magnezyum sitrat', 'mineral', 'tablet', 200, 'mg'], ['Magnezyum glisinat', 'mineral', 'capsule', 200, 'mg'],
    ['Çinko', 'mineral', 'tablet', 15, 'mg'], ['Kalsiyum', 'mineral', 'tablet', 500, 'mg'],
    ['Demir', 'mineral', 'tablet', 1, 'adet'], ['Selenyum', 'mineral', 'tablet', 55, 'mcg'],
    ['Potasyum', 'mineral', 'tablet', 99, 'mg'], ['Elektrolit', 'mineral', 'powder', 1, 'ölçek'],
    ['Omega-3 balık yağı', 'supplement', 'capsule', 1000, 'mg'], ['Kreatin monohidrat', 'supplement', 'powder', 5, 'g'],
    ['Whey protein', 'supplement', 'powder', 30, 'g'], ['Kolajen', 'supplement', 'powder', 10, 'g'],
    ['Probiyotik', 'supplement', 'capsule', 1, 'adet'], ['Koenzim Q10', 'supplement', 'capsule', 100, 'mg'],
    ['Glutamin', 'supplement', 'powder', 5, 'g'], ['BCAA', 'supplement', 'powder', 5, 'g'],
    ['Melatonin', 'supplement', 'tablet', 3, 'mg'], ['L-karnitin', 'supplement', 'liquid', 1000, 'mg'],
    ['Ashwagandha', 'herb', 'capsule', 600, 'mg'], ['Zerdeçal (kurkumin)', 'herb', 'capsule', 500, 'mg'],
    ['Ginseng', 'herb', 'capsule', 200, 'mg'], ['Ekinezya', 'herb', 'capsule', 400, 'mg'],
];
// Daha önce eklediklerin (kendi dozlarınla) önce gelir
const MY_SUPPS = <?= json_encode(array_values(array_reduce($supplements, function ($acc, $s) {
    $k = mb_strtolower(trim((string)$s['name']));
    if ($k !== '' && !isset($acc[$k])) {
        $acc[$k] = [$s['name'], $s['type'], $s['form'], (float)$s['dose_amount'], $s['dose_unit']];
    }
    return $acc;
}, [])), JSON_UNESCAPED_UNICODE) ?>;
(function initSuppCatalog() {
    const input = document.getElementById('suppName'), dl = document.getElementById('suppCatalog'), hint = document.getElementById('suppHint');
    if (!input || !dl) return;
    const all = [...MY_SUPPS.map(x => [...x, true]), ...SUPP_CATALOG.filter(c => !MY_SUPPS.some(m => m[0].toLocaleLowerCase('tr') === c[0].toLocaleLowerCase('tr')))];
    dl.innerHTML = all.map(x => `<option value="${esc(x[0])}">${x[5] ? 'Önceki kaydın · ' : ''}${esc(x[3])} ${esc(x[4])}</option>`).join('');
    const form = document.getElementById('addForm');
    const apply = () => {
        const v = input.value.trim().toLocaleLowerCase('tr');
        const hit = all.find(x => x[0].toLocaleLowerCase('tr') === v);
        if (!hit) { hint.classList.add('d-none'); return; }
        form.querySelector('[name=type]').value = hit[1];
        form.querySelector('[name=form]').value = hit[2];
        form.querySelector('[name=dose_amount]').value = hit[3];
        form.querySelector('[name=dose_unit]').value = hit[4];
        hint.querySelector('span').textContent = hit[5] ? 'Önceki kaydındaki tür, form ve doz dolduruldu.' : 'Tür, form ve yaygın etiket dozu dolduruldu — paketteki doza göre düzeltebilirsin.';
        hint.classList.remove('d-none');
    };
    input.addEventListener('change', apply);
    input.addEventListener('input', () => { if (all.some(x => x[0].toLocaleLowerCase('tr') === input.value.trim().toLocaleLowerCase('tr'))) apply(); });
})();

/* ── Stok (kalan adet) ───────────────────────────────────── */
function stockWarn(st) {
    if (!st || !window.Swal) return false;
    if (st.status === 'low') {
        Swal.fire({ icon: 'warning', title: `${st.name} bitmek üzere`, text: `${st.label}. Yeni paket almayı unutmayın.`, confirmButtonText: 'Tamam' });
        return true;
    }
    if (st.status === 'empty') {
        Swal.fire({ icon: st.final_pack ? 'info' : 'warning', title: `${st.name}: paket bitti`,
            text: st.final_pack ? 'Tedavi tamamlandıysa menüden "Tedaviyi bitir" diyebilirsiniz.' : 'Yeni paket aldığınızda "Yeni paket aldım" düğmesine basın.', confirmButtonText: 'Tamam' });
        return true;
    }
    return false;
}

function paintStock(suppId, st) {
    const box = document.getElementById('stock-' + suppId);
    if (!box || !st) return;
    box.className = 'stock ' + st.status;
    const lbl = box.querySelector('.stock-label');
    if (lbl) lbl.innerHTML = `<i class="bi ${st.status === 'ok' ? 'bi-box-seam' : 'bi-exclamation-triangle-fill'} me-1"></i>${esc(st.label)}`;
    const bar = box.querySelector('.bar span');
    if (bar && st.pct !== null) bar.style.width = Math.max(2, st.pct) + '%';
}

async function refillStock(cfg) {
    let amount = '';
    if (!cfg.pack) {
        const r = await Swal.fire({ title: 'Yeni paket', input: 'number', inputLabel: 'Pakette kaç adet var?', inputAttributes: { min: 1, step: 1 }, showCancelButton: true, confirmButtonText: 'Ekle', cancelButtonText: 'Vazgeç' });
        if (!r.isConfirmed || !r.value) return;
        amount = r.value;
    } else {
        const r = await Swal.fire({ title: 'Yeni paket aldınız mı?', text: `${cfg.name} stoğuna ${Number(cfg.pack)} adet eklenecek.`, icon: 'question', showCancelButton: true, confirmButtonText: 'Evet, ekle', cancelButtonText: 'Vazgeç' });
        if (!r.isConfirmed) return;
    }
    const res = await post({ action: 'refill_stock', supplement_id: cfg.id, amount });
    if (res.ok) { toast('Stok güncellendi'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Kaydedilemedi', 'error');
}

async function editStock(cfg) {
    const num = v => (v === null || v === undefined || v === '') ? '' : Number(v);
    const r = await Swal.fire({
        title: cfg.name,
        html: `<div class="text-start">
            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="swTrack" ${cfg.track || !cfg.qty ? 'checked' : ''}><label class="form-check-label fw-semibold" for="swTrack">Paketteki adedi takip et</label></div>
            <div class="row g-2">
              <div class="col-6"><label class="form-label small">Elde kalan adet</label><input id="swQty" type="number" min="0" step="0.5" class="form-control" value="${num(cfg.qty)}"></div>
              <div class="col-6"><label class="form-label small">Paketteki adet</label><input id="swPack" type="number" min="1" step="1" class="form-control" value="${num(cfg.pack)}"></div>
              <div class="col-6"><label class="form-label small">Her dozda (adet)</label><input id="swUpd" type="number" min="0.5" step="0.5" class="form-control" value="${num(cfg.upd) || 1}"></div>
              <div class="col-6"><label class="form-label small">Kaç gün önce uyar</label><input id="swThr" type="number" min="0" max="60" class="form-control" value="${cfg.thr ?? 5}"></div>
            </div>
            <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="swRebuy" ${cfg.rebuy ? 'checked' : ''}><label class="form-check-label" for="swRebuy">Bitince yenisini alacağım</label></div>
            <div class="small mt-1" style="color:var(--muted)">Kısa süreli ilaçlarda boş bırakın: yenisini alma uyarısı gelmez, sadece düzenli alım takip edilir.</div>
        </div>`,
        showCancelButton: true, confirmButtonText: 'Kaydet', cancelButtonText: 'Vazgeç', focusConfirm: false,
        preConfirm: () => {
            const v = id => document.getElementById(id);
            const track = v('swTrack').checked;
            if (track && v('swQty').value === '' && v('swPack').value === '') { Swal.showValidationMessage('Elde kalan ya da paketteki adedi girin'); return false; }
            return { track_stock: track ? 1 : 0, stock_quantity: v('swQty').value, pack_size: v('swPack').value, units_per_dose: v('swUpd').value || 1, low_stock_days: v('swThr').value || 5, repurchase: v('swRebuy').checked ? 1 : 0 };
        }
    });
    if (!r.isConfirmed) return;
    const res = await post({ action: 'update_stock', supplement_id: cfg.id, ...r.value });
    if (res.ok) { toast('Kaydedildi'); setTimeout(() => location.reload(), 600); }
    else toast(res.error || 'Kaydedilemedi', 'error');
}

async function undoDose(suppId, time) {
    await post({ action: 'undo_dose', supplement_id: suppId, scheduled_time: time });
    refreshDoses();
}

document.addEventListener('opti:dose-logged', refreshDoses);
document.addEventListener('opti:doses-synced', refreshDoses);

/* ── İlaç kartlarındaki alarm saatleri ───────────────────── */
const scheduleMap = {};
<?php foreach ($activeSupps as $supp): ?>
scheduleMap[<?= (int)$supp['id'] ?>] = new Set(<?= json_encode($supp['schedule_times']) ?>);
<?php endforeach; ?>

function renderTimeBadges(suppId) {
    const box = document.getElementById(`badges-${suppId}`);
    if (!box) return;
    const times = [...(scheduleMap[suppId] ?? [])].sort();
    box.innerHTML = times.length
        ? times.map(t => `<span class="time-chip"><i class="bi bi-alarm"></i>${esc(t)}<button type="button" title="Kaldır" onclick="removeTime(${suppId}, '${t}')"><i class="bi bi-x"></i></button></span>`).join('')
        : '<span class="small" style="color:var(--muted)">Alarm saati yok →</span>';
}

async function syncScheduleToApi(suppId) {
    const times = [...(scheduleMap[suppId] ?? [])].sort();
    const data = await post({ action: 'update_schedule', supplement_id: suppId, schedule_times: JSON.stringify(times) });
    if (!data.ok) { toast(data.error || 'Kaydedilemedi', 'error'); return; }
    toast('Alarm saatleri güncellendi');
    if (window.OptiNative) OptiNative.syncFromServer(true);
    refreshDoses();
}

async function addTimeInline(suppId, input) {
    const t = (input.value || '').trim();
    if (!/^\d{2}:\d{2}$/.test(t)) return;
    if (!scheduleMap[suppId]) scheduleMap[suppId] = new Set();
    if (scheduleMap[suppId].has(t)) return;
    scheduleMap[suppId].add(t);
    renderTimeBadges(suppId);
    await syncScheduleToApi(suppId);
}

async function removeTime(suppId, t) {
    scheduleMap[suppId]?.delete(t);
    renderTimeBadges(suppId);
    await syncScheduleToApi(suppId);
}

/* ── Yeni ekleme formu saat etiketleri ───────────────────── */
const addFormTimes = new Set();
function addCurrentTimeToForm() { const v = document.getElementById('newTimeInput').value.trim(); if (v) addTagToForm(v); }
function quickAddTime(t) { addTagToForm(t); }
function addTagToForm(t) {
    if (!/^\d{2}:\d{2}$/.test(t) || addFormTimes.has(t)) return;
    addFormTimes.add(t);
    drawFormTags();
}
function removeFormTag(t) { addFormTimes.delete(t); drawFormTags(); }
function drawFormTags() {
    const box = document.getElementById('timeTagBox');
    const times = [...addFormTimes].sort();
    box.innerHTML = times.length
        ? times.map(t => `<span class="time-chip">${esc(t)}<button type="button" onclick="removeFormTag('${t}')"><i class="bi bi-x"></i></button></span>`).join('')
        : '<span class="small ps-1" style="color:var(--muted)">Henüz saat eklenmedi</span>';
    document.getElementById('scheduleTimesInput').value = times.join(',');
    document.getElementById('dosesPerDay').value = Math.max(1, times.length);
}
document.getElementById('newTimeInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addCurrentTimeToForm(); } });
document.getElementById('addForm').addEventListener('submit', e => {
    if (!addFormTimes.size) {
        const v = document.getElementById('newTimeInput').value.trim();
        if (/^\d{2}:\d{2}$/.test(v)) addTagToForm(v);
    }
});

/* ── Onay pencereleri ────────────────────────────────────── */
function confirmDelete(event, name) {
    event.preventDefault();
    const form = event.target;
    Swal.fire({
        icon: 'warning', title: 'Silinsin mi?',
        html: `<strong>${esc(name)}</strong> ve bağlı tüm alarmlar silinecek.`,
        showCancelButton: true, confirmButtonText: 'Evet, sil', cancelButtonText: 'Vazgeç',
        customClass: { confirmButton: 'swal2-deny' }
    }).then(r => { if (r.isConfirmed) form.submit(); });
    return false;
}
function confirmFinish(event, name) {
    event.preventDefault();
    const form = event.target;
    Swal.fire({
        icon: 'question', title: 'Tedavi tamamlandı mı?',
        html: `<strong>${esc(name)}</strong> için tüm alarmlar kapatılacak. Geçmiş kayıtlar raporlarda kalır.`,
        showCancelButton: true, confirmButtonText: 'Evet, bitir', cancelButtonText: 'Vazgeç'
    }).then(r => { if (r.isConfirmed) form.submit(); });
    return false;
}

/* ── Alarm durumu kartı ──────────────────────────────────── */
function setDot(id, ok) { const el = document.getElementById(id); if (el) el.className = 'dot ' + (ok ? 'ok' : 'bad'); }

async function refreshNativeStatus() {
    try {
        const s = await OptiNative.status();
        const notifOk = !!s.notifications && !s.channelBlocked; // kanal kapatıldıysa alarm sessiz kalır
        setDot('stNotif', notifOk); document.getElementById('fixNotif').classList.toggle('d-none', notifOk);
        window.__channelBlocked = !!s.channelBlocked;
        setDot('stExact', s.exactAlarm); document.getElementById('fixExact').classList.toggle('d-none', !!s.exactAlarm);
        setDot('stFull', s.fullScreen); document.getElementById('fixFull').classList.toggle('d-none', !!s.fullScreen);
        setDot('stBattery', !s.batteryOptimized); document.getElementById('fixBattery').classList.toggle('d-none', !s.batteryOptimized);
        const next = s.nextTrigger > 0 ? new Date(s.nextTrigger) : null;
        document.getElementById('nativeNext').innerHTML = `<i class="bi bi-phone me-1"></i>Telefonda <b>${s.alarmCount}</b> alarm kurulu` +
            (next ? ` · sıradaki: <b>${next.toLocaleDateString('tr-TR', { weekday: 'short' })} ${next.toTimeString().slice(0, 5)}</b>` : '');
        document.getElementById('snoozeSelect').value = String(s.snoozeMinutes || 10);
    } catch (e) { console.warn(e); }
}

function initReliability() {
    const isApp = window.OptiNative && OptiNative.isApp();
    document.getElementById('alarmModeChip').textContent = isApp ? 'Telefon alarmı' : 'Tarayıcı';
    document.getElementById('alarmModeChip').className = 'chip ' + (isApp ? 'accent' : '');
    document.getElementById('nativeStatus').classList.toggle('d-none', !isApp);
    document.getElementById('browserStatus').classList.toggle('d-none', isApp);

    if (isApp) {
        OptiNative.syncFromServer(true).then(refreshNativeStatus);
        OptiNative.flushTakenQueue();
        document.getElementById('fixNotif').onclick = () => window.__channelBlocked ? OptiNative.openNotificationSettings() : OptiNative.requestPermissions().then(refreshNativeStatus).catch(() => OptiNative.openNotificationSettings());
        document.getElementById('fixExact').onclick = () => OptiNative.openExactAlarmSettings();
        document.getElementById('fixFull').onclick = () => OptiNative.openFullScreenSettings();
        document.getElementById('fixBattery').onclick = () => OptiNative.openBatterySettings();
        document.getElementById('btnTestAlarm').onclick = async () => {
            await OptiNative.requestPermissions().catch(() => {});
            await OptiNative.testAlarm(5);
            toast('5 saniye içinde alarm çalacak. Ekranı kilitleyip deneyebilirsiniz.', 'info');
        };
        document.getElementById('btnResync').onclick = async () => { await OptiNative.syncFromServer(true); await refreshNativeStatus(); toast('Alarmlar yeniden kuruldu'); };
        document.getElementById('snoozeSelect').onchange = e => OptiNative.setSnoozeMinutes(parseInt(e.target.value, 10));
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshNativeStatus(); });
        // İlk açılışta bildirim izni iste
        OptiNative.status().then(s => { if (!s.notifications) OptiNative.requestPermissions().then(refreshNativeStatus).catch(() => {}); }).catch(() => {});
        return;
    }

    // Tarayıcı
    const webNotifOk = () => 'Notification' in window && Notification.permission === 'granted';
    const paint = () => { setDot('stWebNotif', webNotifOk()); document.getElementById('btnWebNotif').classList.toggle('d-none', webNotifOk()); };
    paint();
    document.getElementById('btnWebNotif').onclick = async () => {
        if (!('Notification' in window)) { toast('Bu tarayıcı bildirim desteklemiyor', 'warning'); return; }
        await Notification.requestPermission(); paint();
    };
    const eng = window.optiAlarmEngine;
    if (eng) {
        const sel = document.getElementById('alarmSoundSelect'), vol = document.getElementById('alarmVolumeSlider'), lbl = document.getElementById('alarmVolumeLabel'), sen = document.getElementById('sentinelToggle');
        sel.value = eng.getSound();
        sel.onchange = e => { eng.setSound(e.target.value); eng.testSound(); };
        vol.value = eng.getVolumePercent(); lbl.textContent = vol.value + '%';
        vol.oninput = e => { eng.setVolume(e.target.value); lbl.textContent = e.target.value + '%'; };
        sen.checked = eng.isSentinelEnabled; sen.onchange = e => eng.toggleSentinel(e.target.checked);
        document.getElementById('testSoundBtn').onclick = () => eng.testSound();
        document.getElementById('testNotifBtn').onclick = async () => {
            if ('Notification' in window && Notification.permission !== 'granted') await Notification.requestPermission();
            paint();
            const ok = await eng.showSystemNotification('🔔 Test bildirimi', { body: 'OptiLifeSync bildirimleri çalışıyor.' });
            toast(ok ? 'Bildirim gönderildi' : 'Bildirim izni verilmedi', ok ? 'success' : 'warning');
        };
    }
}

/* ── Başlat ──────────────────────────────────────────────── */
Object.keys(scheduleMap).forEach(id => renderTimeBadges(Number(id)));
renderDoses();
initReliability();
setInterval(() => { if (!document.hidden) refreshDoses(); }, 60000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshDoses(); });
</script>
</body>
</html>

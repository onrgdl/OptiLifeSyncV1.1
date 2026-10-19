<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Akıllı bildirim ayarları (Aşama 2)
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/NotificationPlanner.php';

use App\Services\NotificationPlanner;

$planner = new NotificationPlanner($pdo);
$p = $planner->getPrefs($userId);
$v = '20261019';
$nfEsc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$chk = fn(string $k) => !empty($p[$k]) ? 'checked' : '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Bildirim ayarları · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .nf-wrap { max-width: 760px; }
        .nf-card { padding: 18px 20px; }
        .nf-head { display: flex; align-items: flex-start; gap: 14px; }
        .nf-head .icon-tile { flex-shrink: 0; }
        .nf-head h3 { font-size: 16px; font-weight: 700; margin: 0; }
        .nf-head p { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
        .nf-head .form-switch { margin-left: auto; padding-top: 2px; }
        .nf-head .form-check-input { width: 2.6em; height: 1.4em; cursor: pointer; }
        .nf-body { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
        .nf-body.off { opacity: .45; pointer-events: none; }
        .nf-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .nf-row .form-label { font-size: 12.5px; color: var(--text-2); margin-bottom: 4px; }
        .flow { display: flex; flex-direction: column; gap: 0; margin: 0; padding: 0; list-style: none; }
        .flow li { display: flex; gap: 12px; padding: 8px 0; font-size: 13.5px; color: var(--text-2); }
        .flow li b { color: var(--text); font-variant-numeric: tabular-nums; min-width: 56px; }
        .save-bar { position: sticky; bottom: 16px; z-index: 5; display: flex; justify-content: flex-end; }
        @media (max-width: 991.98px) { .save-bar { bottom: 92px; } }
    </style>
</head>
<body>
<?php $activePage = 'notify'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Bildirim ayarları</div>
                <div class="topbar-sub">Gün boyunca seni yönlendiren akıllı hatırlatmalar</div>
            </div>
        </div>
    </header>

    <div class="content">
        <div class="nf-wrap d-flex flex-column gap-3">

            <div class="alert alert-info d-flex gap-2 align-items-start mb-0 d-none" id="browserNote">
                <i class="bi bi-android2 fs-5"></i>
                <div>Bu bildirimler <b>Android uygulamasında</b> telefon kapalıyken de gelir. Tarayıcıda yalnızca ayarlarını değiştirebilirsin.
                    <a href="download.php" style="color:inherit;font-weight:600">Uygulamayı indir →</a></div>
            </div>
            <div class="alert alert-warning d-flex gap-2 align-items-start mb-0 d-none" id="oldApkNote">
                <i class="bi bi-arrow-repeat fs-5"></i>
                <div>Telefonundaki uygulama eski sürüm. Akıllı bildirimler için <a href="download.php" style="color:inherit;font-weight:600">yeni APK'yı kur</a>.</div>
            </div>

            <!-- Ana anahtar + günün akışı -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile"><i class="bi bi-bell"></i></div>
                    <div>
                        <h3>Akıllı bildirimler</h3>
                        <p>İlaç alarmların bundan bağımsız olarak her zaman çalar.</p>
                    </div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="enabled" <?= $chk('enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="enabled">
                    <div class="small fw-semibold mb-1">Örnek bir gün</div>
                    <ul class="flow" id="flowPreview"></ul>
                </div>
            </section>

            <!-- Sabah özeti -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile yellow"><i class="bi bi-sunrise"></i></div>
                    <div><h3>Sabah özeti</h3><p>Bugün alacağın ilaçlar, su hedefin, antrenmanın ve kalori hedefin.</p></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="morning_enabled" <?= $chk('morning_enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="morning_enabled">
                    <div class="nf-row"><div><label class="form-label">Saat</label><input type="time" class="form-control" id="morning_time" value="<?= $nfEsc($p['morning_time']) ?>"></div></div>
                </div>
            </section>

            <!-- Su -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile cyan"><i class="bi bi-droplet"></i></div>
                    <div><h3>Su hatırlatmaları</h3><p>Gün içinde düzenli aralıklarla "bir bardak su iç". Bildirimden tek dokunuşla +250 ml eklenir.</p></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="water_enabled" <?= $chk('water_enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="water_enabled">
                    <div class="nf-row">
                        <div><label class="form-label">Başlangıç</label><input type="time" class="form-control" id="water_start" value="<?= $nfEsc($p['water_start']) ?>"></div>
                        <div><label class="form-label">Bitiş</label><input type="time" class="form-control" id="water_end" value="<?= $nfEsc($p['water_end']) ?>"></div>
                        <div><label class="form-label">Sıklık</label>
                            <select class="form-select" id="water_every_min">
                                <?php foreach ([45 => '45 dk', 60 => '1 saat', 90 => '1,5 saat', 120 => '2 saat', 180 => '3 saat'] as $m => $l): ?>
                                    <option value="<?= $m ?>" <?= (int)$p['water_every_min'] === $m ? 'selected' : '' ?>>Her <?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Doz takibi -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile red"><i class="bi bi-capsule-pill"></i></div>
                    <div><h3>Kaçırılan doz hatırlatması</h3><p>Alarmdan sonra hâlâ "Aldım" denmediyse tekrar sorar. Alındıysa sessiz kalır.</p></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="dose_followup_enabled" <?= $chk('dose_followup_enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="dose_followup_enabled">
                    <div class="nf-row"><div><label class="form-label">Alarmdan kaç dk sonra</label>
                        <select class="form-select" id="dose_followup_min">
                            <?php foreach ([15, 30, 45, 60, 90] as $m): ?><option value="<?= $m ?>" <?= (int)$p['dose_followup_min'] === $m ? 'selected' : '' ?>><?= $m ?> dk</option><?php endforeach; ?>
                        </select></div></div>
                </div>
            </section>

            <!-- Antrenman -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile purple"><i class="bi bi-lightning-charge"></i></div>
                    <div><h3>Antrenman</h3><p>Planladığın antrenmandan önce hatırlatır, saatinde başlatır, bitince "yaptın mı?" diye sorar.</p></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="workout_enabled" <?= $chk('workout_enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="workout_enabled">
                    <div class="nf-row">
                        <div><label class="form-label">Varsayılan saat</label><input type="time" class="form-control" id="workout_default_time" value="<?= $nfEsc($p['workout_default_time']) ?>"></div>
                        <div><label class="form-label">Kaç dk önce hatırlat</label>
                            <select class="form-select" id="workout_lead_min"><?php foreach ([15, 30, 60, 90, 120] as $m): ?><option value="<?= $m ?>" <?= (int)$p['workout_lead_min'] === $m ? 'selected' : '' ?>><?= $m ?> dk</option><?php endforeach; ?></select></div>
                        <div><label class="form-label">Varsayılan süre</label>
                            <select class="form-select" id="workout_duration_min"><?php foreach ([30, 45, 60, 75, 90, 120] as $m): ?><option value="<?= $m ?>" <?= (int)$p['workout_duration_min'] === $m ? 'selected' : '' ?>><?= $m ?> dk</option><?php endforeach; ?></select></div>
                    </div>
                    <div class="form-text">Antrenmanı planlarken saat girmezsen varsayılan saat kullanılır.</div>
                </div>
            </section>

            <!-- Makro -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile green"><i class="bi bi-pie-chart"></i></div>
                    <div><h3>Beslenme durumu</h3><p>Protein/kalori eksik ya da fazla ise öğle ve akşam haber verir.</p></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="macro_enabled" <?= $chk('macro_enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="macro_enabled">
                    <div class="nf-row">
                        <div><label class="form-label">Öğle kontrolü</label><input type="time" class="form-control" id="macro_t1" value="<?= $nfEsc($p['macro_times'][0] ?? '13:30') ?>"></div>
                        <div><label class="form-label">Akşam kontrolü</label><input type="time" class="form-control" id="macro_t2" value="<?= $nfEsc($p['macro_times'][1] ?? '19:30') ?>"></div>
                    </div>
                </div>
            </section>

            <!-- Gün sonu -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile blue"><i class="bi bi-moon-stars"></i></div>
                    <div><h3>Gün sonu kontrolü</h3><p>İşaretlenmemiş doz, antrenman, su ve öğün varsa son bir kez sorar; tek ekrandan tamamlarsın.</p></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="eod_enabled" <?= $chk('eod_enabled') ?>></div>
                </div>
                <div class="nf-body" data-dep="eod_enabled">
                    <div class="nf-row"><div><label class="form-label">Saat</label><input type="time" class="form-control" id="eod_time" value="<?= $nfEsc($p['eod_time']) ?>"></div></div>
                </div>
            </section>

            <!-- Sessiz saatler -->
            <section class="card nf-card">
                <div class="nf-head">
                    <div class="icon-tile"><i class="bi bi-volume-mute"></i></div>
                    <div><h3>Sessiz saatler</h3><p>Bu aralıkta akıllı bildirim gelmez. İlaç alarmların yine çalar.</p></div>
                </div>
                <div class="nf-body">
                    <div class="nf-row">
                        <div><label class="form-label">Başlangıç</label><input type="time" class="form-control" id="quiet_start" value="<?= $nfEsc($p['quiet_start']) ?>"></div>
                        <div><label class="form-label">Bitiş</label><input type="time" class="form-control" id="quiet_end" value="<?= $nfEsc($p['quiet_end']) ?>"></div>
                    </div>
                </div>
            </section>

            <div class="save-bar">
                <button class="btn btn-primary px-4 shadow" id="btnSave"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
const API = `${window.API_BASE || '/api'}/notify.php`;
const $ = id => document.getElementById(id);
const toast = (title, icon = 'success') => window.Swal && Swal.fire({ toast: true, position: 'top', icon, title, showConfirmButton: false, timer: 2200 });

function deps() {
    document.querySelectorAll('[data-dep]').forEach(el => {
        const on = $(el.dataset.dep).checked && (el.dataset.dep === 'enabled' || $('enabled').checked);
        el.classList.toggle('off', !on);
    });
    document.querySelectorAll('.nf-card').forEach(c => {
        if (c.querySelector('#enabled')) return;
        c.style.opacity = $('enabled').checked ? '' : '.55';
    });
    preview();
}

function collect() {
    const v = id => $(id).value;
    return {
        enabled: $('enabled').checked,
        morning_enabled: $('morning_enabled').checked, morning_time: v('morning_time'),
        water_enabled: $('water_enabled').checked, water_start: v('water_start'), water_end: v('water_end'), water_every_min: +v('water_every_min'),
        dose_followup_enabled: $('dose_followup_enabled').checked, dose_followup_min: +v('dose_followup_min'),
        workout_enabled: $('workout_enabled').checked, workout_default_time: v('workout_default_time'), workout_lead_min: +v('workout_lead_min'), workout_duration_min: +v('workout_duration_min'),
        macro_enabled: $('macro_enabled').checked, macro_times: [v('macro_t1'), v('macro_t2')].filter(Boolean),
        eod_enabled: $('eod_enabled').checked, eod_time: v('eod_time'),
        quiet_start: v('quiet_start'), quiet_end: v('quiet_end'),
    };
}

const toMin = t => { const [h, m] = (t || '0:0').split(':').map(Number); return h * 60 + m; };
const fromMin = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');

function preview() {
    const p = collect();
    const items = [];
    if (p.morning_enabled) items.push([toMin(p.morning_time), '☀️ Günaydın: bugünkü ilaçların, su hedefin, antrenmanın']);
    if (p.water_enabled) {
        const s = toMin(p.water_start), e = toMin(p.water_end);
        let n = 0; for (let m = s; m <= e; m += p.water_every_min) n++;
        items.push([s, `💧 Su hatırlatması (${p.water_start}–${p.water_end} arası ${n} kez)`]);
    }
    if (p.workout_enabled) {
        const w = toMin(p.workout_default_time);
        items.push([w - p.workout_lead_min, `🏋️ "Antrenmanın ${p.workout_default_time}'de" hatırlatması`]);
        items.push([w, '🏋️ Antrenman zamanı']);
        items.push([w + p.workout_duration_min + 15, '🏋️ "Antrenmanını yaptın mı?" — Yaptım / Yapmadım']);
    }
    if (p.macro_enabled) p.macro_times.forEach(t => items.push([toMin(t), '🍽️ Protein/kalori durumu (eksik ya da fazla)']));
    if (p.eod_enabled) items.push([toMin(p.eod_time), '🌙 Gün sonu: eksik kayıtları tamamla']);
    items.sort((a, b) => a[0] - b[0]);
    $('flowPreview').innerHTML = items.map(([m, t]) => `<li><b>${fromMin(Math.max(0, Math.min(1439, m)))}</b><span>${t}</span></li>`).join('')
        + (p.dose_followup_enabled ? `<li><b>+${p.dose_followup_min} dk</b><span>💊 İşaretlenmemiş doz varsa tekrar sorar</span></li>` : '');
}

document.querySelectorAll('input,select').forEach(el => el.addEventListener('change', deps));

$('btnSave').onclick = async () => {
    const btn = $('btnSave'); btn.disabled = true;
    try {
        const res = await fetch(API, { method: 'POST', credentials: 'include', body: new URLSearchParams({ action: 'save_prefs', prefs: JSON.stringify(collect()) }) }).then(r => r.json());
        if (!res.ok) throw new Error(res.error || 'Kaydedilemedi');
        if (window.OptiNative && OptiNative.isApp()) await OptiNative.syncFromServer(true).catch(() => {});
        toast('Bildirim ayarları kaydedildi');
    } catch (e) { toast(e.message, 'error'); }
    finally { btn.disabled = false; }
};

(async function initEnv() {
    const isApp = window.OptiNative && OptiNative.isApp();
    if (!isApp) { $('browserNote').classList.remove('d-none'); return; }
    try { const s = await OptiNative.status(); if (!s || !s.smartNotify) $('oldApkNote').classList.remove('d-none'); } catch (_) {}
})();

deps();
</script>
</body>
</html>

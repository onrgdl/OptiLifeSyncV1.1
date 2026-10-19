<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Gün sonu kontrolü (Aşama 2)
 * İşaretlenmemiş dozlar, antrenman onayı, su ve öğün kayıtları tek ekranda.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/app/Services/NotificationPlanner.php';

use App\Services\NotificationPlanner;

$date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) && $_GET['date'] <= date('Y-m-d'))
    ? (string)$_GET['date'] : date('Y-m-d');
$status = (new NotificationPlanner($pdo))->dayStatus($userId, $date);
$isToday = $date === date('Y-m-d');
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$v = '20261019';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Gün sonu · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $v ?>">
    <style>
        .ck-wrap { max-width: 720px; display: flex; flex-direction: column; gap: 16px; }
        .ck-score { display: flex; align-items: center; gap: 16px; }
        .ck-score .ring { --p: 0; width: 72px; height: 72px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center;
            background: conic-gradient(var(--accent) calc(var(--p) * 1%), var(--surface-3) 0); }
        .ck-score .ring span { width: 56px; height: 56px; border-radius: 50%; background: var(--surface); display: grid; place-items: center; font-weight: 800; font-size: 16px; }
        .ck-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .ck-row:last-child { border-bottom: 0; }
        .ck-row .t { font-weight: 800; width: 48px; font-variant-numeric: tabular-nums; }
        .ck-row .n { flex: 1; min-width: 0; }
        .ck-row .n div:first-child { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ck-row .n div:last-child { font-size: 12px; color: var(--muted); }
        .ck-row.done .n div:first-child { color: var(--muted); }
        .ck-btns { display: flex; gap: 6px; flex-shrink: 0; }
        .ck-btns .btn { padding: 5px 10px; font-size: 12.5px; font-weight: 700; border-radius: 10px; }
        .ok-mark { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; background: var(--green-dim); color: var(--green); flex-shrink: 0; }
        .ok-mark.skip { background: var(--surface-3); color: var(--muted); }
        .water-bar { height: 12px; border-radius: 99px; background: var(--surface-3); overflow: hidden; }
        .water-bar span { display: block; height: 100%; background: var(--c-water, #0ea5e9); border-radius: 99px; transition: width .3s; }
        .all-good { text-align: center; padding: 18px 8px; }
        .all-good i { font-size: 34px; color: var(--green); }
    </style>
</head>
<body>
<?php $activePage = 'checkin'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Gün sonu kontrolü</div>
                <div class="topbar-sub"><?= $isToday ? 'Bugün' : htmlspecialchars(date('d.m.Y', strtotime($date))) ?> · eksik kayıtları tamamla</div>
            </div>
        </div>
        <div class="topbar-right">
            <a class="btn-topbar" href="checkin.php?date=<?= $prev ?>" title="Önceki gün"><i class="bi bi-chevron-left"></i><span class="d-mobile-none">Önceki gün</span></a>
            <?php if (!$isToday): ?><a class="btn-topbar" href="checkin.php"><span>Bugün</span><i class="bi bi-chevron-right"></i></a><?php endif; ?>
        </div>
    </header>

    <div class="content">
        <div class="ck-wrap">
            <section class="card card-pad">
                <div class="ck-score">
                    <div class="ring" id="scoreRing"><span id="scoreTxt">—</span></div>
                    <div>
                        <div class="fw-bold" id="scoreTitle">—</div>
                        <div class="small" style="color:var(--muted)" id="scoreSub"></div>
                    </div>
                </div>
            </section>

            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-capsule-pill"></i>İlaç & takviye</h2><span class="chip" id="doseChip">—</span></div>
                <div id="doseList"></div>
            </section>

            <section class="card card-pad" id="workoutCard">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-lightning-charge"></i>Antrenman</h2></div>
                <div id="workoutList"></div>
            </section>

            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-droplet" style="color:var(--c-water)"></i>Su</h2><span class="chip" id="waterChip">—</span></div>
                <div class="water-bar"><span id="waterBar" style="width:0"></span></div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-light btn-sm flex-fill" onclick="act('water_250')">+250 ml</button>
                    <button class="btn btn-light btn-sm flex-fill" onclick="act('water_500')">+500 ml</button>
                    <?php if ($isToday): ?><button class="btn btn-light btn-sm flex-fill" onclick="customWater()">Diğer…</button><?php endif; ?>
                </div>
            </section>

            <section class="card card-pad">
                <div class="card-head"><h2 class="card-title-sm"><i class="bi bi-egg-fried"></i>Beslenme</h2><span class="chip" id="mealChip">—</span></div>
                <div id="mealInfo" class="small" style="color:var(--text-2)"></div>
                <a href="nutrition.php" class="btn btn-light btn-sm w-100 mt-3"><i class="bi bi-plus-lg me-1"></i>Öğün ekle</a>
            </section>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
const DATE = <?= json_encode($date) ?>;
let S = <?= json_encode($status, JSON_UNESCAPED_UNICODE) ?>;
const BASE = window.API_BASE || '/api';
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
const toast = (title, icon = 'success') => window.Swal && Swal.fire({ toast: true, position: 'top', icon, title, showConfirmButton: false, timer: 2000 });
const postTo = (file, params) => fetch(`${BASE}/${file}`, { method: 'POST', credentials: 'include', body: new URLSearchParams(params) }).then(r => r.json());
const L = ml => (ml / 1000).toLocaleString('tr-TR', { maximumFractionDigits: 1 }) + ' L';

function render() {
    // Dozlar
    const doses = S.doses || [];
    const open = doses.filter(d => ['pending', 'due', 'missed'].includes(d.status));
    document.getElementById('doseChip').textContent = doses.length ? `${doses.filter(d => d.status === 'taken').length}/${doses.length} alındı` : '—';
    document.getElementById('doseList').innerHTML = !doses.length
        ? '<div class="empty-state py-2"><i class="bi bi-capsule"></i>Bu gün için planlı doz yok.</div>'
        : doses.map(d => {
            const done = d.status === 'taken' || d.status === 'skipped';
            return `<div class="ck-row ${done ? 'done' : ''}">
                <div class="t">${esc(d.time)}</div>
                <div class="n"><div>${esc(d.name)}</div><div>${esc(d.dose)}${d.status === 'missed' ? ' · <span style="color:var(--red)">kaçırıldı</span>' : ''}</div></div>
                ${done ? `<div class="ok-mark ${d.status === 'skipped' ? 'skip' : ''}" title="${d.status === 'taken' ? 'Alındı' : 'Atlandı'}"><i class="bi ${d.status === 'taken' ? 'bi-check2' : 'bi-dash'}"></i></div>`
                       : `<div class="ck-btns"><button class="btn btn-primary" onclick="dose(${d.supplement_id}, '${d.time}', 'taken')">Aldım</button><button class="btn btn-light" onclick="dose(${d.supplement_id}, '${d.time}', 'skipped')">Atla</button></div>`}
            </div>`;
        }).join('');

    // Antrenman
    const ws = S.workouts || [];
    document.getElementById('workoutCard').hidden = !ws.length;
    document.getElementById('workoutList').innerHTML = ws.map(w => {
        const done = w.status !== 'open';
        return `<div class="ck-row ${done ? 'done' : ''}">
            <div class="t">${esc(w.time || '')}</div>
            <div class="n"><div>${esc(w.type)}</div><div>${esc(w.level)}${w.status === 'skipped' ? ' · yapılmadı' : ''}</div></div>
            ${done ? `<div class="ok-mark ${w.status === 'skipped' ? 'skip' : ''}"><i class="bi ${w.status === 'done' ? 'bi-check2' : 'bi-dash'}"></i></div>`
                   : `<div class="ck-btns"><button class="btn btn-primary" onclick="act('workout_done', {workout_id:${w.id}})">Yaptım</button><button class="btn btn-light" onclick="act('workout_skip', {workout_id:${w.id}})">Yapmadım</button></div>`}
        </div>`;
    }).join('');

    // Su
    const w = S.water || { consumed_ml: 0, target_ml: 0 };
    const wp = w.target_ml ? Math.min(100, Math.round(w.consumed_ml / w.target_ml * 100)) : 0;
    document.getElementById('waterChip').textContent = `${L(w.consumed_ml)} / ${L(w.target_ml)}`;
    document.getElementById('waterBar').style.width = wp + '%';

    // Beslenme
    const c = S.consumed || {}, t = S.target || {};
    document.getElementById('mealChip').textContent = S.meals_count ? `${S.meals_count} kayıt` : 'Kayıt yok';
    document.getElementById('mealInfo').innerHTML = S.meals_count
        ? `${Math.round(c.calories || 0)} / ${Math.round(t.calories || 0)} kcal · protein ${Math.round(c.protein_g || 0)} / ${Math.round(t.protein_g || 0)} g`
        : 'Bu gün için öğün girilmedi. Yediklerini eklersen makro takibin tamamlanır.';

    // Skor
    const items = [
        ...doses.map(d => d.status === 'taken' || d.status === 'skipped'),
        ...ws.map(x => x.status !== 'open'),
        wp >= 100, S.meals_count > 0,
    ];
    const ok = items.filter(Boolean).length, pct = items.length ? Math.round(ok / items.length * 100) : 100;
    document.getElementById('scoreRing').style.setProperty('--p', pct);
    document.getElementById('scoreTxt').textContent = pct + '%';
    const missing = open.length + ws.filter(x => x.status === 'open').length + (wp < 100 ? 1 : 0) + (S.meals_count ? 0 : 1);
    document.getElementById('scoreTitle').textContent = missing ? `${missing} eksik kayıt` : 'Her şey tamam 👏';
    document.getElementById('scoreSub').textContent = missing ? 'Aşağıdan tek dokunuşla tamamlayabilirsin.' : 'Bugünü eksiksiz kapattın.';
}

async function reload() {
    try { const r = await postTo('notify.php', { action: 'day_status', date: DATE }); if (r.ok) { S = r; render(); } } catch (_) {}
}

async function dose(supplementId, time, status) {
    const r = await postTo('reminders.php', { action: 'log_dose', supplement_id: supplementId, scheduled_time: time, log_date: DATE, status });
    if (!r.ok) return toast(r.error || 'Kaydedilemedi', 'error');
    toast(status === 'taken' ? 'Alındı olarak işaretlendi' : 'Atlandı');
    if (r.stock && (r.stock.status === 'low' || r.stock.status === 'empty') && window.Swal) {
        Swal.fire({ icon: 'warning', title: r.stock.status === 'empty' ? `${r.stock.name}: paket bitti` : `${r.stock.name} bitmek üzere`, text: r.stock.label });
    }
    document.dispatchEvent(new CustomEvent('opti:dose-logged'));
    reload();
}

async function act(what, ref = {}) {
    const r = await postTo('notify.php', { action: 'act', do: what, ref: JSON.stringify(ref), date: DATE });
    if (!r.ok) return toast(r.error || 'Kaydedilemedi', 'error');
    toast(r.message || 'Kaydedildi');
    if (what.startsWith('workout') && window.OptiNative && OptiNative.isApp()) OptiNative.syncFromServer(true).catch(() => {});
    reload();
}

async function customWater() {
    const r = await Swal.fire({ title: 'Kaç ml su içtin?', input: 'number', inputValue: 330, inputAttributes: { min: 50, max: 3000, step: 50 }, showCancelButton: true, confirmButtonText: 'Ekle', cancelButtonText: 'Vazgeç' });
    if (!r.isConfirmed || !r.value) return;
    const res = await postTo('dashboard.php', { action: 'add_water', amount: r.value });
    if (res.ok === false) return toast(res.error || 'Eklenemedi', 'error');
    toast(`+${r.value} ml eklendi`); reload();
}

render();
</script>
</body>
</html>

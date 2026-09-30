<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Uygulama Kabuğu (Kenar Menü + Mobil Alt Menü + "Daha Fazla" Paneli)
 * ─────────────────────────────────────────────────────────────────────────────
 * Tüm korumalı sayfalarda tek elden çağrılır, aktif sayfayı otomatik vurgular.
 * Ayrıca tema seçiciyi, PWA kurulumunu ve (APK içinde) yerel alarm
 * senkronizasyonunu başlatır.
 */

require_once __DIR__ . '/../app/Services/AuthService.php';
use App\Services\AuthService;

if (!isset($activePage)) {
    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    $activePage = match ($currentScript) {
        'dashboard.php' => 'dashboard',
        'nutrition.php' => 'nutrition',
        'reminders.php' => 'reminders',
        'workout.php'   => 'workout',
        'index.php'     => 'bmr',
        'reports.php'   => 'reports',
        'guide.php'     => 'guide',
        'creator.php'   => 'creator',
        default         => 'dashboard',
    };
}

$sbUser = AuthService::getCurrentUser();
$sbIsCreator = AuthService::isCreator();
$sbIsImpersonating = AuthService::isImpersonating();
$sbUsername = $sbUser['username'] ?? ($sbUser['name'] ?? 'Kullanıcı');
$sbDisplayName = $sbUser['name'] ?? 'Kullanıcı';
$sbInitial = mb_strtoupper(mb_substr((string)$sbDisplayName, 0, 1));
$sbLogo = file_exists(__DIR__ . '/../assets/img/logo-icon.png') ? 'assets/img/logo-icon.png' : 'assets/icons/icon-192.png';

$sbNav = [
    ['section' => 'Günlük'],
    ['key' => 'dashboard', 'href' => 'dashboard.php', 'icon' => 'bi-house-heart',     'label' => 'Özet'],
    ['key' => 'nutrition', 'href' => 'nutrition.php', 'icon' => 'bi-egg-fried',       'label' => 'Beslenme'],
    ['key' => 'reminders', 'href' => 'reminders.php', 'icon' => 'bi-capsule',         'label' => 'İlaç & Alarmlar'],
    ['key' => 'workout',   'href' => 'workout.php',   'icon' => 'bi-lightning-charge','label' => 'Antrenman'],
    ['section' => 'Analiz'],
    ['key' => 'reports',   'href' => 'reports.php',   'icon' => 'bi-bar-chart-line',  'label' => 'Raporlar'],
    ['key' => 'bmr',       'href' => 'index.php',     'icon' => 'bi-person-gear',     'label' => 'Profil & Hedefler'],
    ['section' => 'Sistem'],
    ['key' => 'guide',     'href' => 'guide.php',     'icon' => 'bi-book',            'label' => 'Kullanım Kılavuzu'],
];
if ($sbIsCreator || $sbIsImpersonating) {
    $sbNav[] = ['key' => 'creator', 'href' => 'creator.php', 'icon' => 'bi-shield-lock', 'label' => 'Creator Paneli', 'creator' => true];
}
$sbAssetV = '20260930';
// Aktif sayfada kullanılacak dolu ikon karşılıkları (Bootstrap Icons'ta mevcut olanlar)
$sbFill = [
    'bi-house-heart' => 'bi-house-heart-fill', 'bi-lightning-charge' => 'bi-lightning-charge-fill',
    'bi-bar-chart-line' => 'bi-bar-chart-line-fill', 'bi-book' => 'bi-book-fill',
    'bi-shield-lock' => 'bi-shield-lock-fill', 'bi-capsule' => 'bi-capsule-pill', 'bi-grid' => 'bi-grid-fill',
];
?>
<!-- Karartma katmanı -->
<div class="overlay" id="overlay" onclick="closeSidebar(); closeMoreSheet();"></div>

<!-- Kenar menü (masaüstü / tablet) -->
<nav class="sidebar" id="sidebar" aria-label="Ana menü">
    <a class="sidebar-logo" href="dashboard.php">
        <img src="<?= $sbLogo ?>" alt="" class="logo-img">
        <div>
            <div class="logo-text">Opti<b>Life</b>Sync</div>
            <div class="logo-sub">Kişisel sağlık asistanı</div>
        </div>
    </a>

    <?php if ($sbIsImpersonating): ?>
        <div class="impersonate-box">
            <div class="fw-bold" style="color:var(--yellow)"><i class="bi bi-eye-fill me-1"></i>Göz atma modu</div>
            <div class="text-truncate mb-2" style="color:var(--text-2)">@<?= htmlspecialchars($sbUsername) ?></div>
            <form method="POST" action="creator.php" class="d-inline">
                <input type="hidden" name="action" value="stop_impersonate">
                <button type="submit" class="btn btn-warning btn-sm py-1 px-3">Creator'a dön</button>
            </form>
        </div>
    <?php endif; ?>

    <?php foreach ($sbNav as $item): ?>
        <?php if (isset($item['section'])): ?>
            <div class="nav-section"><?= $item['section'] ?></div>
        <?php else: ?>
            <a class="nav-item <?= $activePage === $item['key'] ? 'active' : '' ?> <?= !empty($item['creator']) ? 'nav-creator' : '' ?>" href="<?= $item['href'] ?>">
                <i class="bi <?= $activePage === $item['key'] ? ($sbFill[$item['icon']] ?? $item['icon']) : $item['icon'] ?>"></i>
                <span><?= $item['label'] ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="sidebar-footer">
        <div class="theme-switch" role="group" aria-label="Tema">
            <button type="button" data-theme-set="light" title="Açık tema"><i class="bi bi-sun"></i> Açık</button>
            <button type="button" data-theme-set="auto" title="Cihaza göre"><i class="bi bi-circle-half"></i> Oto</button>
            <button type="button" data-theme-set="dark" title="Koyu tema"><i class="bi bi-moon-stars"></i> Koyu</button>
        </div>
        <div class="user-pill">
            <div class="avatar" <?= $sbIsCreator ? 'style="background:linear-gradient(135deg,#fbbf24,#d97706)"' : '' ?>><?= htmlspecialchars($sbInitial) ?></div>
            <div style="min-width:0;flex:1">
                <div class="user-name" title="<?= htmlspecialchars($sbDisplayName) ?>"><?= htmlspecialchars($sbDisplayName) ?></div>
                <div class="user-meta"><?= $sbIsCreator ? '<span style="color:var(--yellow);font-weight:600">Creator</span>' : '@' . htmlspecialchars($sbUsername) ?></div>
            </div>
            <a href="logout.php" class="icon-link" title="Çıkış yap" aria-label="Çıkış yap"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</nav>

<!-- Mobil alt menü -->
<nav class="mobile-bottom-nav" id="mobileBottomNav" aria-label="Mobil menü">
    <?php foreach ([
        ['dashboard', 'dashboard.php', 'bi-house-heart', 'Özet'],
        ['nutrition', 'nutrition.php', 'bi-egg-fried', 'Beslenme'],
        ['reminders', 'reminders.php', 'bi-capsule', 'İlaçlar'],
        ['workout',   'workout.php',   'bi-lightning-charge', 'Spor'],
    ] as [$k, $h, $ic, $lb]): ?>
        <a class="bottom-nav-item <?= $activePage === $k ? 'active' : '' ?>" href="<?= $h ?>">
            <i class="bi <?= $activePage === $k ? ($sbFill[$ic] ?? $ic) : $ic ?>"></i><span><?= $lb ?></span>
        </a>
    <?php endforeach; ?>
    <?php $sbMoreActive = in_array($activePage, ['reports', 'bmr', 'guide', 'creator'], true); ?>
    <button type="button" class="bottom-nav-item <?= $sbMoreActive ? 'active' : '' ?>" onclick="openMoreSheet()" aria-label="Daha fazla">
        <i class="bi bi-grid<?= $sbMoreActive ? '-fill' : '' ?>"></i><span>Daha</span>
    </button>
</nav>

<!-- "Daha fazla" paneli (mobil) -->
<div class="more-sheet" id="moreSheet" role="dialog" aria-label="Daha fazla">
    <div class="grabber"></div>
    <div class="d-flex align-items-center gap-3 mb-3 px-1">
        <div class="avatar"><?= htmlspecialchars($sbInitial) ?></div>
        <div style="min-width:0;flex:1">
            <div class="fw-bold text-truncate"><?= htmlspecialchars($sbDisplayName) ?></div>
            <div class="small" style="color:var(--muted)">@<?= htmlspecialchars($sbUsername) ?></div>
        </div>
        <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</a>
    </div>
    <div class="more-grid">
        <a href="reports.php" class="<?= $activePage === 'reports' ? 'active' : '' ?>"><i class="bi bi-bar-chart-line"></i>Raporlar</a>
        <a href="index.php" class="<?= $activePage === 'bmr' ? 'active' : '' ?>"><i class="bi bi-person-gear"></i>Profil &amp; Hedefler</a>
        <a href="guide.php" class="<?= $activePage === 'guide' ? 'active' : '' ?>"><i class="bi bi-book"></i>Kılavuz</a>
        <?php if ($sbIsCreator || $sbIsImpersonating): ?>
            <a href="creator.php" class="<?= $activePage === 'creator' ? 'active' : '' ?>"><i class="bi bi-shield-lock" style="color:var(--yellow)"></i>Creator</a>
        <?php endif; ?>
        <a href="download.php" id="moreDownloadApk"><i class="bi bi-android2"></i>Android Uygulaması</a>
    </div>
    <div class="eyebrow mb-2 px-1">Görünüm</div>
    <div class="theme-switch">
        <button type="button" data-theme-set="light"><i class="bi bi-sun"></i> Açık</button>
        <button type="button" data-theme-set="auto"><i class="bi bi-circle-half"></i> Otomatik</button>
        <button type="button" data-theme-set="dark"><i class="bi bi-moon-stars"></i> Koyu</button>
    </div>
</div>

<!-- PWA yükleme bandı -->
<div id="pwa-install-banner" class="d-none">
    <div class="d-flex align-items-center gap-2">
        <img src="assets/icons/icon-192.png" width="40" height="40" style="border-radius:12px" alt="">
        <div>
            <div class="fw-bold small" style="color:var(--text)">OptiLifeSync</div>
            <div style="font-size:11.5px;color:var(--muted)">Ana ekrana uygulama olarak ekleyin</div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-1">
        <button class="btn btn-sm btn-primary px-3" id="btn-pwa-install" onclick="installPWAApp()">Yükle</button>
        <button class="icon-btn" style="border:0" onclick="dismissPWAInstall()" title="Kapat"><i class="bi bi-x-lg"></i></button>
    </div>
</div>

<script src="assets/js/native-bridge.js?v=<?= $sbAssetV ?>"></script>
<script src="assets/js/alarm-engine.js?v=<?= $sbAssetV ?>"></script>
<script>
/* ── Menü kontrolleri ─────────────────────────────────────── */
function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('open');
    document.getElementById('overlay')?.classList.toggle('show');
}
function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('open');
    if (!document.getElementById('moreSheet')?.classList.contains('open')) document.getElementById('overlay')?.classList.remove('show');
}
function openMoreSheet() {
    document.getElementById('moreSheet')?.classList.add('open');
    document.getElementById('overlay')?.classList.add('show');
}
function closeMoreSheet() {
    document.getElementById('moreSheet')?.classList.remove('open');
    if (!document.getElementById('sidebar')?.classList.contains('open')) document.getElementById('overlay')?.classList.remove('show');
}

/* Tablet genişliğinde üst bara menü butonu ekle */
(function () {
    const left = document.querySelector('.topbar .topbar-left');
    if (left && !left.querySelector('.hamburger')) {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'hamburger'; b.setAttribute('aria-label', 'Menü');
        b.innerHTML = '<i class="bi bi-list"></i>';
        b.addEventListener('click', toggleSidebar);
        left.prepend(b);
    }
})();

/* ── Tema seçici ──────────────────────────────────────────── */
(function () {
    function sync() {
        const mode = window.OptiTheme ? OptiTheme.get() : 'auto';
        document.querySelectorAll('[data-theme-set]').forEach(b => b.classList.toggle('active', b.dataset.themeSet === mode));
    }
    document.querySelectorAll('[data-theme-set]').forEach(b => b.addEventListener('click', () => { OptiTheme.set(b.dataset.themeSet); sync(); }));
    document.addEventListener('opti:theme', sync);
    sync();
})();

/* ── Hızlı sayfa geçişi: anında geri bildirim + önceden yükleme ── */
(function () {
    const bar = document.createElement('div');
    bar.id = 'navProgress';
    document.body.appendChild(bar);

    function isInternalPage(a) {
        if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download')) return false;
        let u; try { u = new URL(a.href, location.href); } catch (_) { return false; }
        if (u.origin !== location.origin) return false;
        if (u.pathname === location.pathname && u.search === location.search && u.hash) return false;
        return /\.php$|\/$/.test(u.pathname) && !/logout\.php$/.test(u.pathname);
    }
    function startNav(a) {
        bar.className = 'run';
        document.documentElement.classList.add('is-navigating');
        const item = a.closest('.bottom-nav-item, .nav-item');
        if (item) {
            item.parentElement.querySelectorAll('.active').forEach(el => el.classList.remove('active'));
            item.classList.add('active');
        }
    }
    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest('a');
        if (isInternalPage(a)) { startNav(a); closeMoreSheet(); closeSidebar(); }
    });
    window.addEventListener('pageshow', () => { bar.className = ''; document.documentElement.classList.remove('is-navigating'); });

    // Parmak menüye değdiği anda sayfayı indirmeye başla (≈100-300 ms kazanç)
    const done = new Set([location.pathname]);
    function prefetch(a) {
        if (!isInternalPage(a)) return;
        const u = new URL(a.href, location.href);
        if (done.has(u.pathname + u.search)) return;
        done.add(u.pathname + u.search);
        const l = document.createElement('link');
        l.rel = 'prefetch'; l.href = u.href; l.as = 'document';
        document.head.appendChild(l);
    }
    const supportsSpec = HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules');
    if (supportsSpec) {
        const sr = document.createElement('script');
        sr.type = 'speculationrules';
        sr.textContent = JSON.stringify({ prefetch: [{
            source: 'document',
            where: { and: [
                { href_matches: '/*.php' },
                { not: { href_matches: '/logout.php' } },
                { not: { href_matches: '/login.php*' } },
                { not: { href_matches: '/download.php' } }
            ] },
            eagerness: 'moderate'
        }] });
        document.head.appendChild(sr);
    } else {
        ['touchstart', 'mouseover'].forEach(ev => document.addEventListener(ev, (e) => {
            const a = e.target.closest && e.target.closest('.bottom-nav-item, .nav-item, .more-sheet a');
            if (a) prefetch(a);
        }, { passive: true }));
    }
})();

/* ── Service Worker & PWA ─────────────────────────────────── */
if ('serviceWorker' in navigator && !(window.OptiNative && OptiNative.isApp())) {
    const regSW = () => navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' })
        .then(reg => { try { reg.update(); } catch (_) {} })
        .catch(err => console.warn('SW kayıt hatası:', err));
    if (document.readyState === 'complete') regSW(); else window.addEventListener('load', regSW);
}

let deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredInstallPrompt = e;
    let dismissed = false;
    try { dismissed = !!sessionStorage.getItem('pwa_banner_dismissed'); } catch (_) {}
    if (!dismissed) document.getElementById('pwa-install-banner')?.classList.remove('d-none');
});

async function installPWAApp() {
    if (deferredInstallPrompt) {
        deferredInstallPrompt.prompt();
        await deferredInstallPrompt.userChoice;
        deferredInstallPrompt = null;
        document.getElementById('pwa-install-banner')?.classList.add('d-none');
    } else if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'info',
            title: 'Ana ekrana ekle',
            html: `<div class="text-start small">
                <p class="mb-1"><strong>Android:</strong> En güvenilir alarm için <a href="download.php">Android uygulamasını</a> kurun. Alternatif olarak Chrome menüsü (⋮) → <em>Uygulamayı yükle</em>.</p>
                <p class="mb-0"><strong>iPhone:</strong> Safari'de Paylaş → <em>Ana Ekrana Ekle</em>.</p></div>`,
            confirmButtonText: 'Tamam'
        });
    }
}
function dismissPWAInstall() {
    try { sessionStorage.setItem('pwa_banner_dismissed', '1'); } catch (_) {}
    document.getElementById('pwa-install-banner')?.classList.add('d-none');
}
window.addEventListener('appinstalled', () => document.getElementById('pwa-install-banner')?.classList.add('d-none'));

/* ── Tarayıcıda: sayfa açıkken zamanı gelen alarmları yakala (APK'de telefon alarmı çalar) ── */
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => window.OptiAlarmWatcher && OptiAlarmWatcher.start());
else window.OptiAlarmWatcher && OptiAlarmWatcher.start();

/* ── APK içinde: alarmları telefonun alarm sistemine senkronize et ── */
if (window.OptiNative && OptiNative.isApp()) {
    document.getElementById('moreDownloadApk')?.remove();
    window.addEventListener('load', () => { OptiNative.syncFromServer(); OptiNative.flushTakenQueue(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) OptiNative.flushTakenQueue(); });
}
</script>

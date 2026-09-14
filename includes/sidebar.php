<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Sabit Sol Navigasyon Menüsü (Sidebar Bileşeni)
 * ──────────────────────────────────────────────────
 * Tüm sayfalarda tek elden çağrılır ve aktif sayfayı
 * otomatik olarak vurgular.
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
$sbInitial = strtoupper(substr($sbUsername, 0, 1));
?>
<!-- Karartma Katmanı (Mobilde menü açıldığında) -->
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- Sabit Sol Menü -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <?php if (file_exists(__DIR__ . '/../assets/img/logo-icon.png')): ?>
            <img src="assets/img/logo-icon.png?v=<?= filemtime(__DIR__ . '/../assets/img/logo-icon.png') ?>" alt="OptiLifeSync" class="logo-img" style="width:36px;height:36px;border-radius:10px;object-fit:contain;flex-shrink:0;">
        <?php elseif (file_exists(__DIR__ . '/../assets/img/logo.png')): ?>
            <img src="assets/img/logo.png?v=<?= filemtime(__DIR__ . '/../assets/img/logo.png') ?>" alt="OptiLifeSync" class="logo-img" style="width:36px;height:36px;border-radius:10px;object-fit:contain;flex-shrink:0;">
        <?php else: ?>
            <div class="logo-icon">💚</div>
        <?php endif; ?>
        <div>
            <div class="logo-text">OptiLifeSync</div>
            <div class="logo-sub">KİŞİSEL SAĞLIK SİSTEMİ</div>
        </div>
    </div>

    <?php if ($sbIsImpersonating): ?>
        <!-- Göz Atma Modu Uyarısı -->
        <div class="p-2 mx-3 mb-2 rounded-3 text-center" style="background: rgba(234, 179, 8, 0.15); border: 1px solid rgba(234, 179, 8, 0.4); font-size: 11px;">
            <div class="text-warning fw-bold"><i class="bi bi-eye-fill me-1"></i> Göz Atma Modu</div>
            <div class="text-light text-truncate mb-1">@<?= htmlspecialchars($sbUsername) ?></div>
            <form method="POST" action="creator.php" class="d-inline">
                <input type="hidden" name="action" value="stop_impersonate">
                <button type="submit" class="btn btn-warning btn-sm py-0 px-2 fw-bold" style="font-size: 10px;">
                    Creator'a Dön
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="nav-section">Ana Menü</div>
    <a class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
        <i class="bi bi-grid-1x2-fill"></i>
        <span>Dashboard</span>
    </a>
    <a class="nav-item <?= $activePage === 'nutrition' ? 'active' : '' ?>" href="nutrition.php">
        <i class="bi bi-egg-fried"></i>
        <span>Beslenme</span>
    </a>
    <a class="nav-item <?= $activePage === 'reminders' ? 'active' : '' ?>" href="reminders.php">
        <i class="bi bi-bell-fill"></i>
        <span>İlaç & Takviyeler</span>
    </a>
    <a class="nav-item <?= $activePage === 'workout' ? 'active' : '' ?>" href="workout.php">
        <i class="bi bi-activity"></i>
        <span>Spor Planı</span>
    </a>

    <div class="nav-section">Analiz</div>
    <a class="nav-item <?= $activePage === 'bmr' ? 'active' : '' ?>" href="index.php">
        <i class="bi bi-calculator"></i>
        <span>BMR / TDEE</span>
    </a>
    <a class="nav-item <?= $activePage === 'reports' ? 'active' : '' ?>" href="reports.php">
        <i class="bi bi-graph-up-arrow"></i>
        <span>Haftalık Rapor</span>
    </a>

    <div class="nav-section">Sistem</div>
    <?php if ($sbIsCreator || $sbIsImpersonating): ?>
        <a class="nav-item <?= $activePage === 'creator' ? 'active' : '' ?>" href="creator.php" style="color: #facc15;">
            <i class="bi bi-shield-lock-fill text-warning"></i>
            <span class="fw-bold">👑 Creator Paneli</span>
        </a>
    <?php endif; ?>
    <a class="nav-item <?= $activePage === 'guide' ? 'active' : '' ?>" href="guide.php">
        <i class="bi bi-book-half text-warning"></i>
        <span>Kullanım Kılavuzu</span>
    </a>

    <div class="sidebar-footer">
        <div class="user-pill d-flex align-items-center justify-content-between w-100">
            <div class="d-flex align-items-center gap-2" style="min-width:0; overflow:hidden">
                <div class="avatar" style="background: <?= $sbIsCreator ? 'linear-gradient(135deg, #eab308, #ca8a04)' : 'linear-gradient(135deg, #0ea5e9, #22c55e)' ?>; color:#fff; font-weight:bold;">
                    <?= $sbInitial ?>
                </div>
                <div style="min-width:0; overflow:hidden">
                    <div style="font-size:13px; font-weight:600; text-overflow:ellipsis; overflow:hidden; white-space:nowrap" title="<?= htmlspecialchars($sbDisplayName) ?>">
                        <?= htmlspecialchars($sbDisplayName) ?>
                    </div>
                    <div style="font-size:11px; color:var(--muted)">
                        <?php if ($sbIsCreator): ?>
                            <span class="text-warning fw-bold">👑 Creator</span>
                        <?php else: ?>
                            @<?= htmlspecialchars($sbUsername) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="logout.php" class="btn btn-sm btn-link text-secondary p-1 ms-1" title="Çıkış Yap" style="text-decoration:none; font-size:16px;">
                <i class="bi bi-box-arrow-right text-danger"></i>
            </a>
        </div>
    </div>
</nav>

<!-- 📱 Mobil Telefon Alt Navigasyon Çubuğu (Native App Bottom Bar) -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
    <a class="bottom-nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
        <i class="bi bi-grid-1x2<?= $activePage === 'dashboard' ? '-fill' : '' ?>"></i>
        <span>Özet</span>
    </a>
    <a class="bottom-nav-item <?= $activePage === 'nutrition' ? 'active' : '' ?>" href="nutrition.php">
        <i class="bi bi-egg-fried"></i>
        <span>Beslenme</span>
    </a>
    <a class="bottom-nav-item <?= $activePage === 'workout' ? 'active' : '' ?>" href="workout.php">
        <i class="bi bi-activity"></i>
        <span>Spor</span>
    </a>
    <a class="bottom-nav-item <?= $activePage === 'reminders' ? 'active' : '' ?>" href="reminders.php">
        <i class="bi bi-bell<?= $activePage === 'reminders' ? '-fill' : '' ?>"></i>
        <span>İlaçlar</span>
    </a>
    <a class="bottom-nav-item <?= $activePage === 'bmr' ? 'active' : '' ?>" href="index.php">
        <i class="bi bi-calculator"></i>
        <span>BMR</span>
    </a>
</nav>

<!-- 📱 PWA Telefona Yükle Bildirim Çubuğu -->
<div id="pwa-install-banner" class="d-none">
    <div class="d-flex align-items-center gap-2">
        <img src="assets/icons/icon-192.png" width="38" height="38" class="rounded-3 shadow-sm" alt="OptiLifeSync">
        <div>
            <div class="fw-bold text-light small">OptiLifeSync</div>
            <div class="text-secondary" style="font-size:11px">Ana ekrana uygulama olarak ekleyin</div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-info fw-bold px-3 py-1" id="btn-pwa-install" onclick="installPWAApp()">
            <i class="bi bi-download me-1"></i> Yükle
        </button>
        <button class="btn btn-sm btn-link text-secondary p-1" onclick="dismissPWAInstall()" title="Kapat">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>

<script>
/** Mobil çekmece kontrolü */
function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('overlay');
    if (sb) sb.classList.toggle('open');
    if (ov) ov.classList.toggle('show');
}

function closeSidebar() {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('overlay');
    if (sb) sb.classList.remove('open');
    if (ov) ov.classList.remove('show');
}

/* ═══════════════════════════════════════════════════════════
   PWA & Service Worker Yönetimi
   ═══════════════════════════════════════════════════════════ */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
            .then(reg => {
                console.log('📱 OptiLifeSync PWA Aktif (Service Worker):', reg.scope);
            })
            .catch(err => {
                console.warn('PWA SW Kayıt Hatası:', err);
            });
    });
}

// Android / Chrome / Edge için Yükleme Olayı
let deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredInstallPrompt = e;
    
    // Daha önce kapatılmadıysa yükleme banner'ını göster
    if (!sessionStorage.getItem('pwa_banner_dismissed')) {
        const banner = document.getElementById('pwa-install-banner');
        if (banner) banner.classList.remove('d-none');
    }
});

async function installPWAApp() {
    if (deferredInstallPrompt) {
        deferredInstallPrompt.prompt();
        const { outcome } = await deferredInstallPrompt.userChoice;
        console.log('Kullanıcı PWA yükleme tercihi:', outcome);
        deferredInstallPrompt = null;
        document.getElementById('pwa-install-banner')?.classList.add('d-none');
    } else {
        // iOS Safari veya halihazırda yüklü/desteklenmeyen tarayıcılar için rehber modalı
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'info',
                title: 'Telefona Uygulama Olarak Ekle 📲',
                html: `
                    <div class="text-start small">
                        <p class="mb-2"><strong>iPhone / iPad (Safari) için:</strong></p>
                        <ol class="ps-3 mb-3">
                            <li>Ekranın altındaki <strong>Paylaş <i class="bi bi-box-arrow-up text-info"></i></strong> butonuna dokunun.</li>
                            <li>Açılan menüden <strong>"Ana Ekrana Ekle <i class="bi bi-plus-square text-success"></i>"</strong> seçeneğini seçin.</li>
                        </ol>
                        <p class="mb-2"><strong>Android (Chrome) için:</strong></p>
                        <ol class="ps-3 mb-0">
                            <li>Sağ üstteki <strong>üç nokta (⋮)</strong> menüsüne dokunun.</li>
                            <li><strong>"Uygulamayı Yükle"</strong> veya <strong>"Ana ekrana ekle"</strong> deyin.</li>
                        </ol>
                    </div>
                `,
                background: '#111827',
                color: '#f8fafc',
                confirmButtonColor: '#38bdf8',
                confirmButtonText: 'Tamam'
            });
        }
    }
}

function dismissPWAInstall() {
    sessionStorage.setItem('pwa_banner_dismissed', '1');
    document.getElementById('pwa-install-banner')?.classList.add('d-none');
}

// Uygulama yüklendiğinde tetiklenir
window.addEventListener('appinstalled', () => {
    console.log('🎉 OptiLifeSync PWA başarıyla telefona kuruldu!');
    document.getElementById('pwa-install-banner')?.classList.add('d-none');
});
</script>

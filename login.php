<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app/Services/AuthService.php';

use App\Services\AuthService;

$authService = new AuthService($pdo);

// Zaten giriş yapılmışsa doğrudan Dashboard'a yönlendir
if (AuthService::isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$error = null;
$success = null;
$newRecoveryCode = null;
$action = $_GET['action'] ?? 'login'; // login, register, reset
$redirect = $_GET['redirect'] ?? 'dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['auth_action'] ?? 'login';

    if ($postAction === 'login') {
        $username = (string)($_POST['username'] ?? '');
        $pin = (string)($_POST['pin'] ?? '');

        $result = $authService->login($username, $pin);
        if ($result['ok']) {
            $rawDest = (string)($_POST['redirect'] ?? 'dashboard.php');
            $dest = 'dashboard.php';
            // Sadece yerel dosyalara izin ver (açık yönlendirme engeli)
            if ($rawDest !== '' && !preg_match('#^(https?:|//|javascript:)#i', $rawDest)) {
                $clean = ltrim($rawDest, '/');
                if (preg_match('#^[a-zA-Z0-9_\-./]+\.php(\?[a-zA-Z0-9_=&%-]*)?$#', $clean) && !str_contains($clean, '..')) {
                    $dest = $clean;
                }
            }
            header("Location: {$dest}");
            exit;
        } else {
            $error = $result['error'];
        }
    } elseif ($postAction === 'register') {
        $username = (string)($_POST['reg_username'] ?? '');
        $name = (string)($_POST['reg_name'] ?? '');
        $pin = (string)($_POST['reg_pin'] ?? '');

        $result = $authService->register($username, $pin, $name);
        if ($result['ok']) {
            $newRecoveryCode = $result['recovery_code'];
            $success = "Hesabınız başarıyla oluşturuldu! Lütfen aşağıdaki Kurtarma Kodunu güvenli bir yere kaydedin.";
            $action = 'registered_success';
        } else {
            $error = $result['error'];
            $action = 'register';
        }
    } elseif ($postAction === 'reset') {
        $username = (string)($_POST['reset_username'] ?? '');
        $recoveryCode = (string)($_POST['reset_recovery'] ?? '');
        $newPin = (string)($_POST['reset_pin'] ?? '');

        $result = $authService->resetPinWithRecoveryCode($username, $recoveryCode, $newPin);
        if ($result['ok']) {
            $success = $result['message'];
            $newRecoveryCode = $result['new_recovery_code'] ?? null;
            $action = 'login';
        } else {
            $error = $result['error'];
            $action = 'reset';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Giriş · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=20260930">
    <style>
        body { display: grid !important; grid-template-columns: 1.05fr 1fr; min-height: 100vh; }
        @media (max-width: 991.98px) { body { grid-template-columns: 1fr; } .auth-hero { display: none !important; } }
        .auth-hero {
            position: relative; overflow: hidden; padding: 48px; display: flex; flex-direction: column; justify-content: space-between;
            background: radial-gradient(900px 500px at 10% 0%, rgba(52,211,153,.35), transparent 60%),
                        radial-gradient(700px 500px at 100% 100%, rgba(14,165,233,.30), transparent 60%), #0b1220;
            color: #e8edf6;
        }
        .auth-hero h1 { color: #fff; font-size: 40px; line-height: 1.12; letter-spacing: -.035em; max-width: 480px; }
        .auth-hero h1 span { background: linear-gradient(135deg, #6ee7b7, #38bdf8); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .auth-hero p { color: #aab6c8; max-width: 440px; font-size: 15px; }
        .feat { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; max-width: 520px; }
        .feat div { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 14px; font-size: 13px; color: #c3cddc; }
        .feat i { display: block; font-size: 20px; color: #6ee7b7; margin-bottom: 6px; }
        .feat b { color: #fff; display: block; font-size: 14px; }
        .auth-side { display: flex; align-items: center; justify-content: center; padding: 32px 18px; }
        .auth-card { width: 100%; max-width: 420px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
        .brand img { width: 46px; height: 46px; border-radius: 14px; }
        .brand .t { font-size: 20px; font-weight: 800; letter-spacing: -.02em; }
        .brand .t b { background: var(--brand-grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .brand .s { font-size: 12px; color: var(--muted); }
        .auth-title { font-size: 26px; font-weight: 800; letter-spacing: -.03em; margin-bottom: 4px; }
        .auth-sub { color: var(--muted); margin-bottom: 22px; }
        .seg { width: 100%; margin-bottom: 22px; }
        .seg button { flex: 1; padding: 9px; }
        .pin-wrap { position: relative; }
        .pin-wrap .eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: var(--muted); width: 36px; height: 36px; border-radius: 10px; }
        .pin-wrap input { padding-right: 48px !important; }
        .btn-auth { width: 100%; padding: 12px; font-size: 15px; }
        .recovery-box { background: var(--yellow-dim); border-radius: 14px; padding: 14px; }
        .recovery-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 22px; font-weight: 800; letter-spacing: .06em; color: var(--text); }
        .auth-foot { margin-top: 26px; font-size: 12px; color: var(--muted); text-align: center; }
        .theme-mini { position: fixed; top: 14px; right: 14px; z-index: 10; }
        .form-control { padding: .7rem .9rem; font-size: 15px; }
    </style>
</head>
<body>

<aside class="auth-hero">
    <div class="d-flex align-items-center gap-2">
        <img src="assets/img/logo-icon.png" alt="" style="width:40px;height:40px;border-radius:12px">
        <b style="font-size:18px;letter-spacing:-.02em">OptiLifeSync</b>
    </div>
    <div>
        <h1>Sağlığını <span>tek yerden</span> yönet.</h1>
        <p class="mt-3 mb-4">Beslenme, su, antrenman ve ilaç takibini birleştiren kişisel sağlık asistanın. Alarmların, uygulama kapalıyken bile telefonunda çalar.</p>
        <div class="feat">
            <div><i class="bi bi-stars"></i><b>Yapay zeka</b>Fotoğraftan kalori hesabı</div>
            <div><i class="bi bi-alarm"></i><b>Gerçek alarm</b>İlaç saatini kaçırma</div>
            <div><i class="bi bi-graph-up-arrow"></i><b>Gelişim</b>Kilo, rekor ve uyum grafikleri</div>
            <div><i class="bi bi-shield-lock"></i><b>Gizli</b>Veriler yalnızca sana ait</div>
        </div>
    </div>
    <div style="font-size:12px;color:#7d8aa0">© <?= date('Y') ?> OptiLifeSync</div>
</aside>

<main class="auth-side">
    <div class="auth-card fade-in">
        <div class="brand d-lg-none">
            <img src="assets/img/logo-icon.png" alt="">
            <div><div class="t">Opti<b>Life</b>Sync</div><div class="s">Kişisel sağlık asistanı</div></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex gap-2 align-items-start"><i class="bi bi-exclamation-triangle-fill mt-1"></i><div><?= htmlspecialchars($error) ?></div></div>
        <?php endif; ?>
        <?php if ($success && $action !== 'registered_success'): ?>
            <div class="alert alert-success d-flex gap-2 align-items-start"><i class="bi bi-check-circle-fill mt-1"></i><div><?= htmlspecialchars($success) ?></div></div>
            <?php if (!empty($newRecoveryCode)): ?>
                <div class="recovery-box mb-3 text-center"><div class="small fw-bold mb-1">Yeni kurtarma kodunuz</div><div class="recovery-code"><?= htmlspecialchars($newRecoveryCode) ?></div></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($action === 'registered_success' && $newRecoveryCode): ?>
            <div class="text-center">
                <div style="font-size:44px">🎉</div>
                <div class="auth-title">Hoş geldiniz!</div>
                <div class="auth-sub">Hesabınız oluşturuldu.</div>
                <div class="recovery-box text-start">
                    <div class="small fw-bold mb-1"><i class="bi bi-shield-lock-fill me-1"></i>Kurtarma kodunuz</div>
                    <div class="recovery-code my-2" id="copyTarget"><?= htmlspecialchars($newRecoveryCode) ?></div>
                    <button class="btn btn-sm btn-outline-warning w-100" onclick="copyRecoveryCode(this)"><i class="bi bi-clipboard me-1"></i>Kodu kopyala</button>
                    <div class="small mt-2" style="color:var(--text-2)">PIN'inizi unutursanız hesabınızı bu kodla kurtarırsınız. Güvenli bir yere not edin.</div>
                </div>
                <a href="index.php" class="btn btn-primary btn-auth mt-3">Profilimi oluştur <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
        <?php else: ?>
            <div class="auth-title" id="authTitle">Tekrar hoş geldin</div>
            <div class="auth-sub" id="authSub">Hesabına giriş yap</div>

            <div class="seg" role="tablist">
                <button type="button" class="<?= $action === 'login' ? 'active' : '' ?>" id="tab-login-btn" onclick="switchTab('login')">Giriş</button>
                <button type="button" class="<?= $action === 'register' ? 'active' : '' ?>" id="tab-reg-btn" onclick="switchTab('register')">Yeni hesap</button>
                <button type="button" class="<?= $action === 'reset' ? 'active' : '' ?>" id="tab-reset-btn" onclick="switchTab('reset')">PIN sıfırla</button>
            </div>

            <div id="panel-login" class="<?= $action === 'login' ? '' : 'd-none' ?>">
                <form method="POST" action="login.php">
                    <input type="hidden" name="auth_action" value="login">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı adı</label>
                        <input type="text" name="username" class="form-control" placeholder="kullanıcı adınız" required autofocus autocomplete="username" autocapitalize="none">
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between"><label class="form-label">PIN</label><a href="javascript:switchTab('reset')" class="small fw-semibold" style="color:var(--accent)">Unuttum</a></div>
                        <div class="pin-wrap">
                            <input type="password" name="pin" id="login_pin" class="form-control" placeholder="••••••" required autocomplete="current-password">
                            <button class="eye" type="button" onclick="togglePinVisibility('login_pin', this)" aria-label="Göster"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-auth mt-2">Giriş yap</button>
                </form>
            </div>

            <div id="panel-register" class="<?= $action === 'register' ? '' : 'd-none' ?>">
                <form method="POST" action="login.php">
                    <input type="hidden" name="auth_action" value="register">
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı adı</label>
                        <input type="text" name="reg_username" class="form-control" placeholder="ör. ahmet_fit" required pattern="^[a-zA-Z0-9_.-]{3,30}$" title="3–30 karakter: harf, rakam, _ . -" autocapitalize="none">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Adınız <span style="color:var(--muted);font-weight:500">(isteğe bağlı)</span></label>
                        <input type="text" name="reg_name" class="form-control" placeholder="ör. Ahmet">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">PIN / parola</label>
                        <div class="pin-wrap">
                            <input type="password" name="reg_pin" id="reg_pin" class="form-control" placeholder="En az 6 karakter" minlength="6" required autocomplete="new-password">
                            <button class="eye" type="button" onclick="togglePinVisibility('reg_pin', this)" aria-label="Göster"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-auth mt-2">Hesap oluştur</button>
                </form>
            </div>

            <div id="panel-reset" class="<?= $action === 'reset' ? '' : 'd-none' ?>">
                <form method="POST" action="login.php">
                    <input type="hidden" name="auth_action" value="reset">
                    <div class="mb-3"><label class="form-label">Kullanıcı adı</label><input type="text" name="reset_username" class="form-control" required autocapitalize="none"></div>
                    <div class="mb-3"><label class="form-label">Kurtarma kodu</label><input type="text" name="reset_recovery" class="form-control" placeholder="REC-XXXX-XXXX" required autocapitalize="characters"></div>
                    <div class="mb-3">
                        <label class="form-label">Yeni PIN</label>
                        <div class="pin-wrap">
                            <input type="password" name="reset_pin" id="reset_pin" class="form-control" placeholder="En az 6 karakter" minlength="6" required autocomplete="new-password">
                            <button class="eye" type="button" onclick="togglePinVisibility('reset_pin', this)" aria-label="Göster"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-auth mt-2">PIN'i sıfırla</button>
                    <div class="small mt-3" style="color:var(--muted)">Kurtarma kodunuzu da kaybettiyseniz yönetici (<b>onrgdl</b>) PIN'inizi geçici bir PIN ile sıfırlayabilir.</div>
                </form>
            </div>
        <?php endif; ?>

        <div class="auth-foot">
            <div class="theme-switch d-inline-grid mb-3" style="width:240px">
                <button type="button" data-theme-set="light"><i class="bi bi-sun"></i> Açık</button>
                <button type="button" data-theme-set="auto"><i class="bi bi-circle-half"></i> Oto</button>
                <button type="button" data-theme-set="dark"><i class="bi bi-moon-stars"></i> Koyu</button>
            </div>
            <div>Verileriniz hesabınıza özeldir.</div>
        </div>
    </div>
</main>

<script>
const TITLES = { login: ['Tekrar hoş geldin', 'Hesabına giriş yap'], register: ['Hesap oluştur', 'Bir dakikadan kısa sürer'], reset: ['PIN sıfırla', 'Kurtarma kodunla yeni PIN belirle'] };
function switchTab(tab) {
    ['login', 'register', 'reset'].forEach(t => document.getElementById('panel-' + t)?.classList.toggle('d-none', t !== tab));
    document.getElementById('tab-login-btn')?.classList.toggle('active', tab === 'login');
    document.getElementById('tab-reg-btn')?.classList.toggle('active', tab === 'register');
    document.getElementById('tab-reset-btn')?.classList.toggle('active', tab === 'reset');
    const t = TITLES[tab];
    if (t && document.getElementById('authTitle')) { document.getElementById('authTitle').textContent = t[0]; document.getElementById('authSub').textContent = t[1]; }
}
function togglePinVisibility(id, btn) {
    const i = document.getElementById(id);
    if (!i) return;
    i.type = i.type === 'password' ? 'text' : 'password';
    if (btn) btn.innerHTML = `<i class="bi ${i.type === 'password' ? 'bi-eye' : 'bi-eye-slash'}"></i>`;
}
function copyRecoveryCode(btn) {
    const code = document.getElementById('copyTarget')?.innerText?.trim();
    if (code && navigator.clipboard) navigator.clipboard.writeText(code).then(() => { if (btn) btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Kopyalandı'; });
}
(function () {
    const sync = () => document.querySelectorAll('[data-theme-set]').forEach(b => b.classList.toggle('active', b.dataset.themeSet === OptiTheme.get()));
    document.querySelectorAll('[data-theme-set]').forEach(b => b.addEventListener('click', () => { OptiTheme.set(b.dataset.themeSet); sync(); }));
    sync();
    switchTab(<?= json_encode(in_array($action, ['login', 'register', 'reset'], true) ? $action : 'login') ?>);
})();
</script>
</body>
</html>

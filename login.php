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
                if (preg_match('/^[a-zA-Z0-9_\-\./]+\.php(\?[a-zA-Z0-9_=&%-]*)?$/', $clean)) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap & Kayıt Ol — OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">

    <style>
        /* Renk token'ları artık merkezi assets/css/theme.css içinde */

        body {
            background: radial-gradient(circle at 50% 20%, #ffffff 0%, var(--bg) 80%);
            min-height: 100vh;
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.08);
        }

        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0ea5e9, #22c55e);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 16px rgba(14, 165, 233, 0.3);
        }

        .logo-text {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(90deg, #0284c7, #16a34a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-tabs {
            border-bottom: 1px solid var(--border);
            margin-bottom: 24px;
            gap: 8px;
        }

        .nav-link {
            color: var(--muted);
            border: none;
            padding: 10px 18px;
            border-radius: 12px !important;
            font-weight: 600;
            font-size: 14px;
            transition: all .2s;
        }

        .nav-link:hover {
            color: var(--text);
            background: var(--surface-2);
        }

        .nav-link.active {
            color: #0284c7 !important;
            background: rgba(2, 132, 199, 0.1) !important;
            border: 1px solid rgba(2, 132, 199, 0.25) !important;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            padding: 12px 16px;
            font-size: 14px;
            transition: all .2s;
        }

        .form-control:focus {
            background: #ffffff;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.2);
            color: var(--text);
        }

        .btn-auth {
            background: linear-gradient(135deg, #0284c7, #0ea5e9);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 13px;
            font-weight: 700;
            font-size: 15px;
            width: 100%;
            transition: all .2s;
            margin-top: 12px;
            box-shadow: 0 4px 14px rgba(14, 165, 233, 0.25);
        }

        .btn-auth:hover {
            background: linear-gradient(135deg, #0369a1, #0284c7);
            color: #fff;
            transform: translateY(-1px);
        }

        .recovery-box {
            background: rgba(217, 119, 6, 0.08);
            border: 1px dashed rgba(217, 119, 6, 0.4);
            border-radius: 14px;
            padding: 16px;
            margin: 16px 0;
            text-align: center;
        }

        .recovery-code {
            font-family: monospace;
            font-size: 20px;
            font-weight: 800;
            color: #d97706;
            letter-spacing: 2px;
            user-select: all;
        }

        .auth-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="auth-logo">
        <?php if (file_exists(__DIR__ . '/assets/img/logo-icon.png')): ?>
            <img src="assets/img/logo-icon.png?v=<?= filemtime(__DIR__ . '/assets/img/logo-icon.png') ?>" alt="OptiLifeSync" style="width:42px;height:42px;border-radius:12px;object-fit:contain;flex-shrink:0;">
        <?php elseif (file_exists(__DIR__ . '/assets/img/logo.png')): ?>
            <img src="assets/img/logo.png?v=<?= filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt="OptiLifeSync" style="width:42px;height:42px;border-radius:12px;object-fit:contain;flex-shrink:0;">
        <?php else: ?>
            <div class="logo-icon">💚</div>
        <?php endif; ?>
        <div>
            <div class="logo-text">OptiLifeSync</div>
            <div style="font-size: 10px; color: var(--muted); font-weight: 600; letter-spacing: 1px;">KİŞİSEL SAĞLIK & PERFORMANS</div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center py-2 px-3 mb-3" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; font-size: 13px; border-radius: 12px;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success && $action !== 'registered_success'): ?>
        <div class="alert alert-success d-flex align-items-center py-2 px-3 mb-3" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #86efac; font-size: 13px; border-radius: 12px;">
            <i class="bi bi-check-circle-fill me-2 fs-5"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($action === 'registered_success' && $newRecoveryCode): ?>
        <!-- Kayıt Başarılı & Kurtarma Kodu Kartı -->
        <div class="text-center">
            <div class="fs-1 text-success mb-2">🎉</div>
            <h5 class="fw-bold mb-1">Hoş Geldiniz!</h5>
            <p class="text-muted small mb-3">Hesabınız başarıyla oluşturuldu. Kimse sizin planınızı göremez.</p>

            <div class="recovery-box">
                <div class="small fw-bold text-warning mb-1">
                    <i class="bi bi-shield-lock-fill me-1"></i> KURTARMA KODUNUZ:
                </div>
                <div class="recovery-code my-2" id="copyTarget"><?= htmlspecialchars($newRecoveryCode) ?></div>
                <button class="btn btn-sm btn-outline-warning w-100 mt-2" onclick="copyRecoveryCode()">
                    <i class="bi bi-clipboard me-1"></i> Kodu Kopyala
                </button>
                <div style="font-size: 11px; color: #cbd5e1; margin-top: 8px;">
                    ⚠️ PIN kodunuzu unutursanız hesabınızı kurtarmak için bu koda ihtiyacınız olacak. Lütfen bir yere not edin!
                </div>
            </div>

            <a href="dashboard.php" class="btn btn-auth mt-2">
                <i class="bi bi-arrow-right-circle me-1"></i> Dashboard'a Git
            </a>
        </div>

    <?php else: ?>

        <!-- Nav Sekmeleri -->
        <ul class="nav nav-tabs nav-fill" id="authTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link <?= $action === 'login' ? 'active' : '' ?>" id="tab-login-btn" onclick="switchTab('login')">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Giriş Yap
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= $action === 'register' ? 'active' : '' ?>" id="tab-reg-btn" onclick="switchTab('register')">
                    <i class="bi bi-person-plus me-1"></i> Yeni Hesap
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= $action === 'reset' ? 'active' : '' ?>" id="tab-reset-btn" onclick="switchTab('reset')">
                    <i class="bi bi-key me-1"></i> Sıfırla
                </button>
            </li>
        </ul>

        <!-- 1. GİRİŞ FORMU -->
        <div id="panel-login" class="<?= $action === 'login' ? '' : 'd-none' ?>">
            <form method="POST" action="login.php">
                <input type="hidden" name="auth_action" value="login">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                <div class="mb-3">
                    <label class="form-label">Kullanıcı Adı</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Örn: onrgdl" required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-label">PIN Kodu</label>
                        <a href="javascript:switchTab('reset')" class="text-decoration-none" style="font-size: 12px; color: var(--accent);">PIN Unuttum?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-shield-lock"></i></span>
                        <input type="password" name="pin" id="login_pin" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePinVisibility('login_pin')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-auth">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Giriş Yap
                </button>
            </form>
        </div>

        <!-- 2. HIZLI KAYIT FORMU -->
        <div id="panel-register" class="<?= $action === 'register' ? '' : 'd-none' ?>">
            <form method="POST" action="login.php">
                <input type="hidden" name="auth_action" value="register">

                <div class="mb-3">
                    <label class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-at"></i></span>
                        <input type="text" name="reg_username" class="form-control" placeholder="Örn: ahmet_fitness" required pattern="^[a-zA-Z0-9_.-]{3,30}$" title="En az 3 karakter, harf ve rakam">
                    </div>
                    <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Kullanıcı oluşturmak için sadece kullanıcı adı girmeniz yeterlidir.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Ad Soyad (Opsiyonel)</label>
                    <input type="text" name="reg_name" class="form-control" placeholder="Örn: Ahmet Yılmaz">
                </div>

                <div class="mb-3">
                    <label class="form-label">PIN / Parola Belirleyin <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-key-fill"></i></span>
                        <input type="password" name="reg_pin" id="reg_pin" class="form-control" placeholder="En az 6 haneli PIN / Parola" minlength="6" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePinVisibility('reg_pin')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-auth">
                    <i class="bi bi-person-check-fill me-1"></i> Anında Hesap Aç
                </button>
            </form>
        </div>

        <!-- 3. PIN SIFIRLAMA FORMU -->
        <div id="panel-reset" class="<?= $action === 'reset' ? '' : 'd-none' ?>">
            <form method="POST" action="login.php">
                <input type="hidden" name="auth_action" value="reset">

                <div class="mb-3">
                    <label class="form-label">Kullanıcı Adınız</label>
                    <input type="text" name="reset_username" class="form-control" placeholder="Kullanıcı adınızı girin" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Kurtarma Kodu (Recovery Code)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-shield-check"></i></span>
                        <input type="text" name="reset_recovery" class="form-control" placeholder="Örn: REC-XXXX-XXXX" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Yeni PIN Kodu</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-key"></i></span>
                        <input type="password" name="reset_pin" id="reset_pin" class="form-control" placeholder="Yeni en az 6 haneli PIN" minlength="6" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePinVisibility('reset_pin')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-auth">
                    <i class="bi bi-check2-circle me-1"></i> PIN'i Sıfırla ve Kaydet
                </button>

                <div class="recovery-box mt-3 text-start small" style="font-size: 11px; line-height: 1.5;">
                    <strong>Kurtarma kodunuzu da unuttuysanız:</strong><br>
                    Uygulamanın Creator'ı (Yöneticisi) <strong>onrgdl</strong>, Creator Yönetici Paneli üzerinden hesabınızın PIN'ini tek tıkla geçici bir PIN ile sıfırlayabilir.
                </div>
            </form>
        </div>

    <?php endif; ?>

    <div class="auth-footer">
        <div>OptiLifeSync &copy; <?= date('Y') ?> · Bütün kullanıcı verileri şifreli ve izoledir.</div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.getElementById('panel-login').classList.toggle('d-none', tab !== 'login');
    document.getElementById('panel-register').classList.toggle('d-none', tab !== 'register');
    document.getElementById('panel-reset').classList.toggle('d-none', tab !== 'reset');

    document.getElementById('tab-login-btn').classList.toggle('active', tab === 'login');
    document.getElementById('tab-reg-btn').classList.toggle('active', tab === 'register');
    document.getElementById('tab-reset-btn').classList.toggle('active', tab === 'reset');
}

function togglePinVisibility(id) {
    const input = document.getElementById(id);
    if (input) {
        input.type = input.type === 'password' ? 'text' : 'password';
    }
}

function copyRecoveryCode() {
    const code = document.getElementById('copyTarget')?.innerText?.trim();
    if (code && navigator.clipboard) {
        navigator.clipboard.writeText(code).then(() => {
            alert('Kurtarma kodu panoya kopyalandı: ' + code);
        });
    }
}
</script>

</body>
</html>

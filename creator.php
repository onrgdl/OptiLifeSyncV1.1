<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app/Services/AuthService.php';

use App\Services\AuthService;

$authService = new AuthService($pdo);

// Eğer kullanıcı Creator değil ama göz atma modundaysa ve geri dönmek istiyorsa
if (isset($_GET['action']) && $_GET['action'] === 'stop_impersonate') {
    $authService->stopImpersonating();
    header("Location: creator.php");
    exit;
}

// Sadece Creator erişebilir
AuthService::requireCreator();

$currentUser = AuthService::getCurrentUser();
$error = null;
$success = null;

// POST İşlemleri (PIN Sıfırlama, Silme, Impersonate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['target_user_id'] ?? 0);

    if ($action === 'reset_pin' && $targetId > 0) {
        $newPin = (string)($_POST['new_pin'] ?? '');
        $res = $authService->creatorResetPin($targetId, $newPin);
        if ($res['ok']) {
            $success = $res['message'];
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'delete_user' && $targetId > 0) {
        $res = $authService->creatorDeleteUser($targetId);
        if ($res['ok']) {
            $success = $res['message'];
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'impersonate' && $targetId > 0) {
        $res = $authService->impersonateUser($targetId);
        if ($res['ok']) {
            header("Location: dashboard.php");
            exit;
        } else {
            $error = $res['error'];
        }
    } elseif ($action === 'purge_data') {
        try {
            $driver = $pdo ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : '';
            if ($driver === 'sqlite') {
                $pdo->exec("DELETE FROM food_logs; DELETE FROM supplement_logs; DELETE FROM daily_logs; DELETE FROM reminders; DELETE FROM workouts; DELETE FROM supplements;");
            } elseif ($driver === 'pgsql') {
                $pdo->exec("TRUNCATE TABLE food_logs, supplement_logs, daily_logs, reminders, workouts, supplements CASCADE;");
            } else {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $pdo->exec("TRUNCATE TABLE food_logs;");
                $pdo->exec("TRUNCATE TABLE supplement_logs;");
                $pdo->exec("TRUNCATE TABLE daily_logs;");
                $pdo->exec("TRUNCATE TABLE reminders;");
                $pdo->exec("TRUNCATE TABLE workouts;");
                $pdo->exec("TRUNCATE TABLE supplements;");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            }

            // Reset profile baseline to 70kg, 170cm, 30yo (1996-01-01), male
            if ($pdo) {
                $pdo->exec("UPDATE users SET weight_kg = 70.00, height_cm = 170.00, birth_date = '1996-01-01', gender = 'male', activity_level = 'moderately_active', goal = 'maintain'");
            }
            $success = "Tüm aktivite ve log verileri başarıyla temizlendi. Mevcut kullanıcılar korundu ve profil başlangıç değerleri (70 kg, 170 cm, 30 yaş, Erkek) olarak eşitlendi.";
        } catch (\Throwable $e) {
            $error = "Veri temizleme sırasında hata oluştu: " . $e->getMessage();
        }
    }
}

$allUsers = $authService->creatorGetAllUsers();
$activePage = 'creator';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync — Creator Yönetici Paneli</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-2: #283548;
            --border: rgba(255,255,255,.09);
            --accent: #38bdf8;
            --green: #22c55e;
            --yellow: #facc15;
            --red: #f87171;
            --purple: #c084fc;
            --text: #f8fafc;
            --muted: #94a3b8;
            --sidebar-w: 240px;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 14px;
            display: flex;
        }

        .main-wrapper {
            margin-left: var(--sidebar-w);
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        @media (max-width: 991.98px) {
            .main-wrapper {
                margin-left: 0;
                padding-bottom: 72px;
            }
        }

        .top-bar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .page-content {
            padding: 28px;
        }

        .creator-hero {
            background: linear-gradient(135deg, rgba(234, 179, 8, 0.12), rgba(56, 189, 248, 0.12));
            border: 1px solid rgba(234, 179, 8, 0.3);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .card-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
        }

        .table-custom {
            color: var(--text);
            margin-bottom: 0;
        }

        .table-custom th {
            background: var(--surface-2);
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }

        .table-custom td {
            background: var(--surface);
            padding: 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
            font-size: 13.5px;
        }

        .badge-creator {
            background: linear-gradient(135deg, #eab308, #ca8a04);
            color: #000;
            font-weight: 700;
            border-radius: 99px;
            padding: 4px 10px;
            font-size: 11px;
        }

        .badge-user {
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: var(--accent);
            border-radius: 99px;
            padding: 4px 10px;
            font-size: 11px;
        }

        .btn-action {
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            transition: all .2s;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-wrapper">
    <div class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                <span>👑</span> Creator Yönetici Paneli
            </h5>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-sm btn-outline-info">
                <i class="bi bi-arrow-left me-1"></i> Dashboard'a Dön
            </a>
        </div>
    </div>

    <div class="page-content">
        <?php if ($error): ?>
            <div class="alert alert-danger py-2 px-3 mb-3 d-flex align-items-center gap-2" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;border-radius:12px">
                <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success py-2 px-3 mb-3 d-flex align-items-center gap-2" style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#86efac;border-radius:12px">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <div class="creator-hero">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="fs-4">👑</span>
                    <h4 class="mb-0 fw-bold text-warning">Süper Yönetici: <?= htmlspecialchars($currentUser['username'] ?? 'onrgdl') ?></h4>
                </div>
                <div class="text-secondary small">
                    Uygulamanın tüm yetkilerine sahipsiniz. Kullanıcıların verileri birbirine kapalıdır (izole), ancak yönetici olarak tüm hesapları yönetebilir, PIN'lerini sıfırlayabilir veya panellerine göz atabilirsiniz.
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="bg-dark px-3 py-2 rounded-3 border border-secondary text-center">
                    <div class="fs-4 fw-bold text-info"><?= count($allUsers) ?></div>
                    <div class="small text-secondary" style="font-size:11px">Toplam Kullanıcı</div>
                </div>
                <form method="POST" action="creator.php" id="purgeDataForm" class="d-inline" onsubmit="return confirm('DİKKAT! Mevcut kullanıcı hesapları KORUNACAK, ancak sisteme bugüne kadar girilmiş tüm öğün, takviye, alarm ve antrenman verileri tamamen silinecektir. Ayrıca profiller 70 kg, 170 cm, 30 yaş, erkek olarak sıfırlanacaktır. Bu işlemi onaylıyor musunuz?');">
                    <input type="hidden" name="action" value="purge_data">
                    <button type="submit" class="btn btn-danger btn-sm px-3 py-2 fw-semibold d-flex align-items-center gap-2 shadow-sm rounded-3">
                        <i class="bi bi-trash3-fill"></i>
                        <span>Tüm Log Verilerini Sıfırla</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="card-panel">
            <div class="p-3 border-bottom border-secondary d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="fw-bold fs-6">
                    <i class="bi bi-people-fill text-info me-2"></i>Kayıtlı Kullanıcı Listesi
                </div>
                <div class="text-muted small">
                    Toplam <strong><?= count($allUsers) ?></strong> hesap kayıtlı
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>ID & Kullanıcı</th>
                            <th>Rol</th>
                            <th>Fiziksel Profil</th>
                            <th>Aktivite & Veriler</th>
                            <th>Kurtarma Kodu</th>
                            <th>Kayıt Tarihi</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allUsers as $u): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width:36px; height:36px; border-radius:10px; background:rgba(56,189,248,.15); color:#38bdf8; display:flex; align-items:center; justify-content:center; font-weight:bold;">
                                            <?= strtoupper(substr($u['username'] ?? $u['name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-light">@<?= htmlspecialchars($u['username'] ?? '') ?></div>
                                            <div class="small text-secondary"><?= htmlspecialchars($u['name'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($u['role'] === 'creator' || (int)$u['id'] === 1): ?>
                                        <span class="badge-creator"><i class="bi bi-star-fill me-1"></i> Creator</span>
                                    <?php else: ?>
                                        <span class="badge-user">Üye</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <span class="text-light"><?= $u['weight_kg'] ?> kg</span> · 
                                        <span class="text-light"><?= $u['height_cm'] ?> cm</span>
                                    </div>
                                    <div class="text-muted" style="font-size:11px">
                                        Hedef: <strong><?= match($u['goal']) { 'lose' => 'Kilo Ver', 'gain' => 'Kilo Al', default => 'Koru' } ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <div class="small text-secondary">
                                        🍽️ <strong><?= $u['total_meals'] ?></strong> öğün · 
                                        💪 <strong><?= $u['total_workouts'] ?></strong> spor · 
                                        💊 <strong><?= $u['total_supplements'] ?></strong> ilaç
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-dark border border-secondary text-warning font-monospace" style="user-select:all;">
                                        <?= htmlspecialchars($u['recovery_code'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="text-secondary small">
                                    <?= date('d.m.Y H:i', strtotime($u['created_at'])) ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <!-- Impersonate (Paneline Gözat) -->
                                        <form method="POST" action="creator.php" class="d-inline">
                                            <input type="hidden" name="action" value="impersonate">
                                            <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-outline-info btn-action" title="Bu kullanıcının paneline geçiş yap">
                                                <i class="bi bi-eye"></i> Gözat
                                            </button>
                                        </form>

                                        <!-- PIN Sıfırla Modal Tetikleyici -->
                                        <button type="button" class="btn btn-outline-warning btn-action" onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'] ?? $u['name']) ?>')">
                                            <i class="bi bi-key"></i> PIN Sıfırla
                                        </button>

                                        <!-- Kullanıcı Sil (Creator Kendisini Silemez) -->
                                        <?php if ((int)$u['id'] !== 1 && $u['role'] !== 'creator'): ?>
                                            <form method="POST" action="creator.php" class="d-inline" onsubmit="return confirm('Bu kullanıcıyı ve TÜM verilerini kalıcı olarak silmek istediğinize emin misiniz?');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-action" title="Kullanıcıyı sil">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- PIN Sıfırlama Modalı -->
<div class="modal fade" id="resetPinModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border border-secondary">
            <form method="POST" action="creator.php">
                <input type="hidden" name="action" value="reset_pin">
                <input type="hidden" name="target_user_id" id="modal_target_user_id" value="">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-key-fill text-warning me-2"></i>PIN Sıfırla — <span id="modal_target_name"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary small">
                        Kullanıcı PIN kodunu unuttuysa ve kurtarma koduna erişemiyorsa, yönetici olarak yeni bir PIN belirleyebilirsiniz.
                    </p>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Yeni PIN Kodu</label>
                        <input type="text" name="new_pin" class="form-control bg-secondary bg-opacity-25 text-light border-secondary" placeholder="Örn: 1234" minlength="4" required autofocus>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-warning btn-sm fw-bold">PIN'i Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openResetModal(userId, username) {
    document.getElementById('modal_target_user_id').value = userId;
    document.getElementById('modal_target_name').textContent = '@' + username;
    const modal = new bootstrap.Modal(document.getElementById('resetPinModal'));
    modal.show();
}
</script>

</body>
</html>

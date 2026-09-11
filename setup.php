<?php
declare(strict_types=1);

/**
 * OptiLifeSync - Veritabanı Kurulumu
 * Bu araç ilk kurulum tamamlandığı için güvenlik ve veri koruma amacıyla devre dışı bırakılmıştır.
 */
http_response_code(403);
die('OptiLifeSync veritabanı kurulumu zaten tamamlanmıştır. Güvenlik ve veri koruma amacıyla bu sayfa devre dışı bırakılmıştır.');



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    try {
        // Önce veritabanı olmadan bağlan
        $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $sqlFile = __DIR__ . '/database/schema.sql';
        if (!file_exists($sqlFile)) {
            throw new Exception("schema.sql dosyası bulunamadı!");
        }

        $sqlContent = file_get_contents($sqlFile);
        
        // SQL komutlarını çalıştır
        $pdo->exec($sqlContent);

        $statusMessage = "✅ Veritabanı ve tüm tablolar başarıyla kuruldu!";
        $statusType = "success";
    } catch (\Throwable $e) {
        $statusMessage = "❌ Kurulum Hatası: " . $e->getMessage();
        $statusType = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - Veritabanı Kurulumu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <style>
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; }
    </style>
</head>
<body>
<?php $activePage = 'setup'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Veritabanı Yönetimi</div>
                <div class="topbar-sub">Tek Tıkla Tablo ve Şema Kurulumu</div>
            </div>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php" class="btn-topbar btn-accent"><i class="bi bi-grid-1x2-fill"></i> <span class="d-none d-sm-inline">Dashboard</span></a>
        </div>
    </header>

    <div class="content d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 60px);">
        <div class="container" style="max-width: 540px;">
            <div class="card p-4 shadow">
                <h3 class="text-info text-center mb-3">🛠️ OptiLifeSync Tablo Kurulumu</h3>
                <p class="text-secondary text-center small mb-4">
                    Bu sayfa hiçbir terminal veya task çalıştırmadan <code>database/schema.sql</code> dosyasını doğrudan MySQL üzerinde yürütür.
                </p>

                <?php if ($statusMessage): ?>
                    <div class="alert alert-<?= $statusType ?> mb-4" role="alert">
                        <?= htmlspecialchars($statusMessage) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="d-grid gap-2">
                        <button type="submit" name="install" value="1" class="btn btn-info py-2 fw-semibold">
                            🚀 Tabloları Otomatik Kur / Güncelle
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-secondary py-2">
                            ← Dashboard'a Dön
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>

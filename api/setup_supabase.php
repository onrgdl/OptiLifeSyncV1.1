<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Supabase Veritabanı Kurulum ve Durum Doğrulama Aracı
 *
 * Bu araç:
 * 1. Supabase PostgreSQL bağlantısını test eder.
 * 2. Tabloların varlığını ve kayıt sayılarını kontrol eder.
 * 3. İstenirse tek tıkla schema_supabase.sql dosyasını çalıştırarak tüm tabloları ve demo verilerini oluşturur.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Services\AuthService;

$authService = $pdo ? new AuthService($pdo) : null;
$isCreator = AuthService::isCreator();

$driver = $pdo ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'none';
$isPgsql = ($driver === 'pgsql');

$requiredTables = [
    'users',
    'macro_targets',
    'workout_plans',
    'workout_plan_days',
    'workouts',
    'supplements',
    'daily_logs',
    'food_logs',
    'supplement_logs',
    'exercise_logs',
    'reminders',
];

$existingTables = [];
$tableCounts = [];
$pgVersion = '';

if ($pdo && $isPgsql) {
    try {
        $pgVersion = (string)$pdo->query("SELECT version()")->fetchColumn();
        $stmt = $pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public'
        ");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($requiredTables as $t) {
            if (in_array($t, $existingTables, true)) {
                try {
                    $tableCounts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
                } catch (\Throwable) {
                    $tableCounts[$t] = 0;
                }
            }
        }
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$installMessage = null;
$installSuccess = false;

$hasUsersTable = in_array('users', $existingTables, true);
$hasUsersCount = $hasUsersTable ? ($tableCounts['users'] ?? 0) : 0;

if (($action === 'install' || $action === 'migrate') && $pdo && $isPgsql) {
    if ($hasUsersTable && $hasUsersCount > 0 && !$isCreator) {
        http_response_code(403);
        $installSuccess = false;
        $installMessage = "⛔ GÜVENLİK ENGELİ: Veritabanında aktif kullanıcılar bulunmaktadır. Yeniden kurulum veya şema güncellemesi yalnızca Creator (onrgdl) oturumu açıkken yapılabilir.";
    } else {
        try {
        if ($action === 'migrate') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(50) UNIQUE;");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'user';");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255);");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS recovery_code VARCHAR(64);");
            $pdo->exec("ALTER TABLE users ALTER COLUMN email DROP NOT NULL;");
            $pdo->exec("ALTER TABLE users ALTER COLUMN password_hash DROP NOT NULL;");
            $pdo->exec("ALTER TABLE users ALTER COLUMN birth_date SET DEFAULT '2000-01-01';");
            $pdo->exec("ALTER TABLE users ALTER COLUMN height_cm SET DEFAULT 175.00;");
            $pdo->exec("ALTER TABLE users ALTER COLUMN weight_kg SET DEFAULT 75.00;");

            // Güvenlik: PIN ve recovery_code artık sabit değerle ayarlanmıyor.
            // Creator rolü yalnızca role alanı güncellenerek atanır.
            $update = $pdo->prepare("
                UPDATE users 
                SET username = COALESCE(username, 'onrgdl'),
                    name = COALESCE(name, 'Creator'),
                    role = 'creator'
                WHERE id = 1
            ");
            $update->execute();
            $installSuccess = true;
            $installMessage = "Supabase users tablosu başarıyla güncellendi (id=1 Creator yetkisi tanımlandı). Lütfen PIN ve kurtarma kodunu uygulamadan güncelleyin.";
        } else {
            $sqlPath = dirname(__DIR__) . '/database/schema_supabase.sql';
            if (!file_exists($sqlPath)) {
                throw new RuntimeException("schema_supabase.sql dosyası bulunamadı.");
            }
            $sql = file_get_contents($sqlPath);
            $pdo->exec($sql);
            $installSuccess = true;
            $installMessage = "Supabase PostgreSQL tabloları ve başlangıç verileri başarıyla kuruldu! 🎉";
        }

        // Tabloları yeniden tara
        $stmt = $pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public'
        ");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($requiredTables as $t) {
            if (in_array($t, $existingTables, true)) {
                $tableCounts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            }
        }
    } catch (\Throwable $e) {
        $installSuccess = false;
        $installMessage = "İşlem sırasında hata oluştu: " . $e->getMessage();
    }
    }
}

$isJson = isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'              => ($pdo !== null && $isPgsql),
        'driver'          => $driver,
        'is_supabase'     => $isPgsql,
        'pg_version'      => $pgVersion,
        'existing_tables' => $existingTables,
        'table_counts'    => $tableCounts,
        'missing_tables'  => array_values(array_diff($requiredTables, $existingTables)),
        'db_error'        => $dbError,
        'install_message' => $installMessage,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync — Supabase Bağlantı & Kurulum Aracı</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #080f1e; color: #f1f5f9; font-family: 'Inter', system-ui, sans-serif; }
        .card { background: #111827; border: 1px solid rgba(255,255,255,.1); border-radius: 12px; }
        .badge-pg { background: #3ecf8e; color: #022c22; font-weight: 600; }
        .badge-mysql { background: #38bdf8; color: #082f49; font-weight: 600; }
        code { color: #38bdf8; }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 800px;">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-database-check me-2 text-info"></i>Supabase Entegrasyon Durumu</h2>
            <div class="text-secondary small">Vercel & Supabase Cloud PostgreSQL Sağlık Denetimi</div>
        </div>
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house me-1"></i> Dashboard</a>
    </div>

    <?php if ($installMessage): ?>
        <div class="alert alert-<?= $installSuccess ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-<?= $installSuccess ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($installMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-hdd-network me-2 text-primary"></i>Bağlantı Özeti</h5>
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="p-3 rounded bg-dark border border-secondary border-opacity-25">
                    <div class="text-secondary small">Aktif Sürücü</div>
                    <div class="fs-5 fw-bold">
                        <?php if ($isPgsql): ?>
                            <span class="badge badge-pg"><i class="bi bi-check-circle me-1"></i>PostgreSQL / Supabase</span>
                        <?php elseif ($driver === 'mysql'): ?>
                            <span class="badge badge-mysql"><i class="bi bi-info-circle me-1"></i>MySQL (Yerel)</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Bağlantı Yok</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="p-3 rounded bg-dark border border-secondary border-opacity-25">
                    <div class="text-secondary small">Bağlantı Durumu</div>
                    <div class="fs-5 fw-bold">
                        <?php if ($pdo): ?>
                            <span class="text-success"><i class="bi bi-check-lg me-1"></i>Bağlandı</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="bi bi-x-lg me-1"></i>Başarısız</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if (!empty($pgVersion)): ?>
            <div class="col-12">
                <div class="p-2 rounded bg-dark border border-secondary border-opacity-25 small text-secondary">
                    <strong class="text-light">Sürüm:</strong> <?= htmlspecialchars($pgVersion) ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($dbError)): ?>
            <div class="col-12">
                <div class="alert alert-danger mb-0 small">
                    <strong>Hata Detayı:</strong> <?= htmlspecialchars($dbError) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isPgsql): ?>
        <div class="card p-4 mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold mb-0"><i class="bi bi-table me-2 text-warning"></i>Supabase Tabloları</h5>
                <?php
                $missing = array_diff($requiredTables, $existingTables);
                $allReady = empty($missing);
                ?>
                <span class="badge bg-<?= $allReady ? 'success' : 'warning' ?>">
                    <?= count($existingTables) ?> / <?= count($requiredTables) ?> Tablo Hazır
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-sm table-borderless align-middle mb-0">
                    <thead>
                        <tr class="text-secondary border-bottom border-secondary border-opacity-25">
                            <th>Tablo Adı</th>
                            <th>Durum</th>
                            <th>Kayıt Sayısı</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requiredTables as $tbl): ?>
                            <?php $exists = in_array($tbl, $existingTables, true); ?>
                            <tr>
                                <td><code><?= $tbl ?></code></td>
                                <td>
                                    <?php if ($exists): ?>
                                        <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Mevcut</span>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Eksik</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $exists ? ($tableCounts[$tbl] ?? 0) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$allReady): ?>
                <div class="mt-4 p-3 rounded bg-warning bg-opacity-10 border border-warning border-opacity-25">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-bold text-warning"><i class="bi bi-exclamation-circle me-1"></i>Tablolar henüz kurulmamış</div>
                            <small class="text-secondary">Tek tıkla Supabase şemasını ve başlangıç verilerini oluşturabilirsiniz.</small>
                        </div>
                        <form method="POST" action="?action=install">
                            <button type="submit" class="btn btn-warning btn-sm fw-bold">
                                <i class="bi bi-play-fill me-1"></i> Tabloları Otomatik Kur
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-3 text-end">
                    <form method="POST" action="?action=install" onsubmit="return confirm('Mevcut şemayı yeniden çalıştırmak istiyor musunuz?')">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i> Şemayı Yeniden Çalıştır
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card p-4">
            <h5 class="fw-bold mb-2 text-info"><i class="bi bi-info-circle me-2"></i>Supabase Bağlantısı Nasıl Yapılır?</h5>
            <p class="text-secondary small mb-3">
                Şu anda sistem yerel MySQL veritabanına bağlıdır. Projeyi Vercel ve Supabase üzerinde çalıştırmak için aşağıdaki adımları izleyin:
            </p>
            <ol class="small text-secondary ps-3 mb-4">
                <li class="mb-2"><a href="https://supabase.com" target="_blank" class="text-info">Supabase</a> üzerinde ücretsiz bir proje oluşturun.</li>
                <li class="mb-2">Proje panelinde <strong>Project Settings → Database → Connection String</strong> bölümüne gidin.</li>
                <li class="mb-2"><strong>Transaction Pooler (Port 6543)</strong> veya <strong>Session Mode (Port 5432)</strong> URI adresinizi kopyalayın.</li>
                <li class="mb-2">Vercel panelinizde <strong>Settings → Environment Variables</strong> bölümüne <code>DATABASE_URL</code> olarak ekleyin.</li>
            </ol>
            <div class="bg-dark p-3 rounded border border-secondary border-opacity-25">
                <div class="text-secondary small mb-1">Örnek <code>DATABASE_URL</code>:</div>
                <code class="user-select-all">postgresql://postgres.[REF]:[PAROLA]@aws-0-[BOLGE].pooler.supabase.com:6543/postgres?sslmode=require</code>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

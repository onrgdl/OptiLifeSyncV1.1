<?php
declare(strict_types=1);

/**
 * OptiLifeSync - Android uygulaması indirme ve kurulum sayfası
 * APK, GitHub Actions tarafından otomatik derlenip "Releases" bölümünde yayınlanır.
 */
$repo = 'onrgdl/OptiLifeSyncV1.1';
$apkUrl = "https://github.com/{$repo}/releases/latest/download/OptiLifeSync.apk";
$releasesUrl = "https://github.com/{$repo}/releases";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Android uygulaması · OptiLifeSync</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=20260929">
    <style>
        body { display: block !important; }
        .wrap { max-width: 620px; margin: 0 auto; padding: 28px 16px 60px; }
        .hero { text-align: center; padding: 20px 0 8px; }
        .hero img { width: 84px; height: 84px; border-radius: 22px; box-shadow: var(--shadow-lg); }
        .hero h1 { font-size: 28px; margin: 18px 0 6px; letter-spacing: -.03em; }
        .hero p { color: var(--muted); }
        .step { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .step:last-child { border-bottom: 0; }
        .step .n { width: 30px; height: 30px; border-radius: 50%; background: var(--accent-dim); color: var(--accent); font-weight: 800; display: grid; place-items: center; flex-shrink: 0; }
        .step b { display: block; }
        .step span { color: var(--muted); font-size: 13.5px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <img src="assets/icons/icon-192.png" alt="">
        <h1>OptiLifeSync Android</h1>
        <p>İlaç ve hatırlatıcı alarmlarınız, uygulama kapalıyken ve ekran kilitliyken bile telefonun kendi alarm sistemiyle çalar.</p>
        <a href="<?= htmlspecialchars($apkUrl) ?>" class="btn btn-primary btn-lg px-4 mt-2"><i class="bi bi-download me-2"></i>APK'yı indir</a>
        <div class="small mt-2"><a href="<?= htmlspecialchars($releasesUrl) ?>" style="color:var(--accent)">Tüm sürümler</a></div>
    </div>

    <div class="card p-3 p-sm-4 mt-4">
        <h2 class="card-title-sm mb-2"><i class="bi bi-list-check"></i>Kurulum</h2>
        <div class="step"><div class="n">1</div><div><b>APK'yı indirin</b><span>Yukarıdaki butona telefonunuzdan dokunun.</span></div></div>
        <div class="step"><div class="n">2</div><div><b>Kuruluma izin verin</b><span>Android "bilinmeyen kaynak" uyarısı gösterirse tarayıcınıza bu dosya için izin verin ve "Yükle"ye dokunun.</span></div></div>
        <div class="step"><div class="n">3</div><div><b>Giriş yapın</b><span>Uygulamayı açıp her zamanki kullanıcı adı ve PIN'inizle giriş yapın. Verileriniz aynıdır.</span></div></div>
        <div class="step"><div class="n">4</div><div><b>İzinleri açın</b><span>"İlaç & Alarmlar" sayfasındaki <em>Alarm durumu</em> kartında kırmızı görünen maddelere "Düzelt" deyin, sonra "5 sn sonra test alarmı" ile deneyin.</span></div></div>
    </div>

    <div class="card p-3 p-sm-4 mt-3">
        <h2 class="card-title-sm mb-2"><i class="bi bi-battery-charging"></i>Samsung / Xiaomi / Huawei kullanıyorsanız</h2>
        <div class="small" style="color:var(--text-2);line-height:1.7">
            Bu markalar arka plandaki uygulamaları agresif şekilde kapatabilir. En güvenilir sonuç için:
            <ul class="mb-0 mt-2">
                <li>Ayarlar → Uygulamalar → OptiLifeSync → Pil → <b>Kısıtlama yok</b></li>
                <li>Xiaomi: <b>Otomatik başlatma</b> iznini açın</li>
                <li>Samsung: "Uyku modundaki uygulamalar" listesinden çıkarın</li>
            </ul>
        </div>
    </div>

    <div class="text-center mt-4"><a href="dashboard.php" style="color:var(--accent);font-weight:600">← Uygulamaya dön</a></div>
</div>
</body>
</html>

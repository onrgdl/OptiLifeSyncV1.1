<?php

declare(strict_types=1);

/**
 * OptiLifeSync - Kullanım Kılavuzu & Sistem Rehberi
 *
 * Ana menü, analiz ve sistem modüllerinin ayrıntılı açıklamalarını,
 * işlevlerini, nasıl çalıştıklarını ve ipuçlarını içeren interaktif rehber.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OptiLifeSync - Kullanım Kılavuzu</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">

    <style>
        :root {
            --surface-hover: #1e293b;
        }

        .guide-hero {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.12), rgba(99, 102, 241, 0.08));
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 18px;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .guide-hero::after {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, transparent 70%);
            pointer-events: none;
        }

        .nav-pill-btn {
            background: var(--surface);
            border: 1px solid var(--border);
            color: #94a3b8;
            padding: .5rem 1rem;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .2s ease;
        }
        .nav-pill-btn:hover {
            background: var(--surface-hover);
            color: #f8fafc;
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .module-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.75rem;
            margin-bottom: 2rem;
            transition: border-color .2s ease;
        }
        .module-section:hover {
            border-color: rgba(255, 255, 255, 0.15);
        }

        .module-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.25rem;
        }

        .module-icon-box {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .feature-card {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 1.25rem;
            height: 100%;
        }

        .feature-title {
            font-size: 14px;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: .4rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .feature-desc {
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.55;
            margin: 0;
        }

        .tip-box {
            background: rgba(56, 189, 248, 0.07);
            border-left: 4px solid var(--accent);
            border-radius: 0 12px 12px 0;
            padding: .9rem 1.2rem;
            font-size: 13px;
            color: #cbd5e1;
            margin-top: 1.25rem;
        }

        .search-bar {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: .65rem 1.2rem;
            color: #f8fafc;
            font-size: 14px;
            width: 100%;
            max-width: 380px;
        }
        .search-bar:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        @media print {
            .sidebar, .topbar, .btn-no-print, .nav-pills-row, .search-bar {
                display: none !important;
            }
            body, .main, .content {
                background: #fff !important;
                color: #000 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .module-section, .guide-hero, .feature-card {
                background: #fff !important;
                color: #000 !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
            .text-light { color: #000 !important; }
            .text-secondary, .text-muted, .feature-desc { color: #444 !important; }
        }
    </style>
</head>
<body>
<?php $activePage = 'guide'; require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="hamburger btn-ghost btn-topbar" onclick="toggleSidebar()">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="topbar-title">Sistem Kullanım Kılavuzu</div>
                <div class="topbar-sub">OptiLifeSync · Tüm Modüller ve Fonksiyonlar Rehberi</div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar btn-ghost btn-no-print" onclick="window.print()" title="Yazdır veya PDF Kaydet">
                <i class="bi bi-printer"></i>
                <span class="d-none d-sm-inline">Yazdır / PDF</span>
            </button>
            <a href="dashboard.php" class="btn-topbar btn-accent"><i class="bi bi-grid-1x2-fill"></i> <span class="d-none d-sm-inline">Dashboard</span></a>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="content">

        <!-- ── HERO BÖLÜMÜ ────────────────────────────────────────────── -->
        <div class="guide-hero mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <span class="badge bg-info text-dark fw-semibold mb-2">Resmi Kullanıcı Rehberi</span>
                    <h2 class="text-light fw-bold mb-2">OptiLifeSync Nasıl Kullanılır?</h2>
                    <p class="text-secondary mb-0" style="max-width: 650px; font-size: 14px;">
                        OptiLifeSync; yapay zeka destekli besin analizi, dinamik antrenman makroları, ilaç alarmları ve haftalık klinik koçluk raporlamasını tek bir merkezde birleştiren kişisel sağlık yönetim sisteminizdir.
                    </p>
                </div>
                <div class="btn-no-print">
                    <input type="text" id="guideSearchInput" class="search-bar" placeholder="🔍 Modül veya özellik ara..." oninput="filterGuide()">
                </div>
            </div>

            <!-- Hızlı Atlama Butonları -->
            <div class="d-flex flex-wrap gap-2 mt-4 nav-pills-row btn-no-print">
                <a href="#mod-dashboard" class="nav-pill-btn"><i class="bi bi-grid-1x2 text-info"></i> Dashboard</a>
                <a href="#mod-nutrition" class="nav-pill-btn"><i class="bi bi-egg-fried text-warning"></i> Beslenme & AI</a>
                <a href="#mod-reminders" class="nav-pill-btn"><i class="bi bi-bell text-danger"></i> İlaç & Takviye</a>
                <a href="#mod-workout" class="nav-pill-btn"><i class="bi bi-activity text-success"></i> Spor Planı</a>
                <a href="#mod-bmr" class="nav-pill-btn"><i class="bi bi-calculator text-primary"></i> BMR / TDEE</a>
                <a href="#mod-reports" class="nav-pill-btn"><i class="bi bi-graph-up-arrow text-info"></i> Haftalık Rapor</a>
                <a href="#mod-mobile" class="nav-pill-btn"><i class="bi bi-phone text-success"></i> Mobil PWA</a>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- 1. BÖLÜM: ANA MENÜ MODÜLLERİ                                   -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <h5 class="text-secondary text-uppercase fw-bold mb-3 small" style="letter-spacing: .08em;">
            <i class="bi bi-menu-button-wide me-1"></i> 1. Ana Menü Modülleri
        </h5>

        <!-- 1A. GÜNLÜK DASHBOARD -->
        <div class="module-section guide-item" id="mod-dashboard">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
                        <i class="bi bi-grid-1x2-fill"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Günlük Dashboard</h4>
                        <span class="text-secondary small">Dosya: <code>dashboard.php</code> · Sistemin kalbi ve anlık takip kokpiti</span>
                    </div>
                </div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-info px-3 btn-no-print">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-speedometer2 text-danger"></i> 4'lü Canlı KPI Kartları</div>
                        <p class="feature-desc">
                            Günün <strong>Kalori, Protein, Karbonhidrat ve Yağ</strong> sayaçlarıdır. O an tüketilen miktarı, günlük hedefi ve kalan/aşılan gramajı renkli ilerleme çubuklarıyla anlık gösterir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-pie-chart text-warning"></i> SVG Makro Halkaları (Rings)</div>
                        <p class="feature-desc">
                            4 ana besin öğesinin hedef tamamlama yüzdelerini dairesel grafiklerle sunar. Hedef aşıldığında halka rengi otomatik olarak turuncuya dönerek uyarır.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-lightning-charge text-success"></i> Hızlı Antrenman Toggle</div>
                        <p class="feature-desc">
                            Üst bardaki <strong>"Antrenman"</strong> butonuna tek tıkla bastığınızda o gün spor günü kabul edilir; günlük hedefinize anında <strong>+400 kcal ve +30g protein</strong> ilave edilir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bell-fill text-info"></i> Yaklaşan Alarmlar (3 Saat)</div>
                        <p class="feature-desc">
                            Önümüzdeki 3 saat içinde saati gelen ilaç ve takviyeleri dakikasıyla listeler. Çöp kutusu ikonuyla alarmları doğrudan dashboard üzerinden silebilir veya kapatabilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-journal-text text-primary"></i> Son Öğünler & Silme Desteği</div>
                        <p class="feature-desc">
                            Günün son kaydedilen öğünlerini ve kalorilerini listeler. Yanlış girilen bir yemek olduğunda yanındaki kırmızı <strong>çöp kutusu</strong> ile onay verip silebilirsiniz; makrolar otomatik güncellenir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-plus-circle text-info"></i> Hızlı Ekle Modalı</div>
                        <p class="feature-desc">
                            Dashboard'dan çıkmadan hızlıca yemek kalorisi yazabilir veya sistemdeki kayıtlı vitamin/takviyeleri tek tuşla bugünün kaydına işleyebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-droplet-fill text-info"></i> Akıllı Hidrasyon & Su Takibi</div>
                        <p class="feature-desc">
                            Kilonuza göre dinamik hesaplanan ($kilo \times 35\text{ ml}$) su takip sistemidir. Antrenman günlerinde otomatik <strong>+500 ml</strong> eklenir. Tek tıkla bardak (+200ml), küçük şişe (+330ml), orta şişe (+500ml) veya sürahi (+1000ml) ekleyebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-camera-fill" style="color:#f472b6"></i> 📸 Fotoğrafla Analiz (Gemini Vision)</div>
                        <p class="feature-desc">
                            Üst bardaki veya hızlı ekle panelindeki kamera butonuna tıklayarak tabağınızın fotoğrafını çekin; Gemini Vision görseli analiz edip yemekleri tanısın ve makroları çıkarsın.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                <strong>Püf Noktası:</strong> Dashboard arka planda her 30 saniyede bir otomatik senkronize olur. Telefonunuzdan veya bilgisayarınızdan veri girdiğinizde sayfayı yenilemenize gerek kalmadan veriler güncellenir.
            </div>
        </div>

        <!-- 1B. BESLENME & GEMINI AI -->
        <div class="module-section guide-item" id="mod-nutrition">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(250, 204, 21, 0.15); color: #facc15;">
                        <i class="bi bi-egg-fried"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Beslenme & Takviye Modülü</h4>
                        <span class="text-secondary small">Dosya: <code>nutrition.php</code> · Google Gemini AI destekli serbest metin analizi</span>
                    </div>
                </div>
                <a href="nutrition.php" class="btn btn-sm btn-outline-warning px-3 btn-no-print">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-stars text-info"></i> Yapay Zeka ile Serbest Metin Analizi</div>
                        <p class="feature-desc">
                            Sabit veritabanlarıyla sınırlı kalmazsınız. <em>"150 gr ızgara tavuk, 1 porsiyon pirinç pilavı ve ayran"</em> gibi günlük dilde yazdığınız yemekleri Gemini AI diyetisyen rolüyle analiz eder.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bullseye text-danger"></i> Dinamik Eksik / Fazla Durumu</div>
                        <p class="feature-desc">
                            Günün tüketilen toplamını hedeflenen dinamik makrolarla kıyaslar. Hangi öğeden kaç gram eksik kaldığınızı veya aştığınızı renkli sayaçlarla anında raporlar.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-capsule text-success"></i> Lokal Takviye Kataloğu Arama</div>
                        <p class="feature-desc">
                            Kullanılan takviyeleri (Whey protein, multivitamin, kreatin vb.) arayarak dozajına göre kalori ve protein katkısını doğrudan günlük listenize ekleyebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-6">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-table text-primary"></i> Gün İçi Tüketim Listesi</div>
                        <p class="feature-desc">
                            Bugün girilen tüm yemekler; saat, öğün kategorisi (Kahvaltı, Öğle, Akşam, Ara, Ant. Öncesi/Sonrası), kalori ve makrolarıyla birlikte tablo halinde alt kısımda listelenir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-6">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-trash3 text-danger"></i> Besin Kaydı Silme & Geri Alma</div>
                        <p class="feature-desc">
                            Her yemeğin yanındaki kırmızı <strong>"Sil"</strong> butonu sayesinde yanlış girilen öğünler SweetAlert2 onayı ile anında kaldırılır; hedefler ve grafikler baştan hesaplanır.
                        </p>
                    </div>
                </div>
                <div class="col-12">
                    <div class="feature-card" style="border-color: rgba(236,72,153,.35); background: linear-gradient(135deg, rgba(236,72,153,.07), rgba(139,92,246,.04));">
                        <div class="feature-title" style="color:#f472b6"><i class="bi bi-camera-fill me-1"></i> 📸 Gemini Vision ile Fotoğraftan Besin Analizi</div>
                        <p class="feature-desc">
                            Artık yemeklerin gramajını veya adını tek tek yazmanıza gerek yok! Tabağınızın fotoğrafını çekin veya galeri/dosyalardan yükleyin. Gemini Vision yapay zekası tabaktaki malzemeleri, porsiyonları tanır ve kalori, protein, karbonhidrat ve yağ değerlerini otomatik hesaplar. Sonuç ekranında isterseniz değerleri elle düzenleyebilir ve tek tıkla günlüğünüze kaydedebilirsiniz.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                <strong>Püf Noktası:</strong> "Veritabanına Kaydet" kutucuğunun işaretini kaldırırsanız, yemek analizini veritabanına işlemeden sadece anlık kalori/makro önizlemesi olarak görebilirsiniz.
            </div>
        </div>

        <!-- 1C. İLAÇ & TAKVİYE ALARMLARI -->
        <div class="module-section guide-item" id="mod-reminders">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(248, 113, 113, 0.15); color: #f87171;">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">İlaç & Takviye Alarmları</h4>
                        <span class="text-secondary small">Dosya: <code>reminders.php</code> · Çoklu alarm saatleri, kür takibi ve sesli uyarılar</span>
                    </div>
                </div>
                <a href="reminders.php" class="btn btn-sm btn-outline-danger px-3 btn-no-print">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-capsule-pill text-primary"></i> İlaç & Takviye Kataloğu</div>
                        <p class="feature-desc">
                            İlaç veya takviyenin adını, dozajını (mg, IU, ml), formunu (tablet, kapsül, toz, sıvı) ve günlük kullanım sayısını sisteme kolayca kaydedebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-clock-history text-warning"></i> Çoklu Alarm Saatleri</div>
                        <p class="feature-desc">
                            Günde 1, 2 veya 3 defa alınacak ürünler için birden fazla saat tanımlanabilir (ör: 09:00, 14:00, 21:00). Sistem her saat dilimi için bağımsız alarm üretir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-calendar-check text-success"></i> Süreli Tedavi & Kür Takibi</div>
                        <p class="feature-desc">
                            Örneğin 15 günlük bir antibiyotik veya kür için gün sayısı girildiğinde bitiş tarihi hesaplanır; ilaç süresi bittiğinde sistem sizi bilgilendirir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-6">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-check2-circle text-info"></i> İlacı / Kürü Bitir Butonu</div>
                        <p class="feature-desc">
                            İlaç bittiğinde tek tuşla <strong>"İlacı Bitir"</strong> yapabilirsiniz. Bu işlem ilacı tamamlandı olarak arşivler ve ona bağlı tüm alarmları dashboard'dan otomatik temizler.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-6">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-volume-up text-warning"></i> Sesli & Görsel Bildirimler</div>
                        <p class="feature-desc">
                            Alarm saati geldiğinde tarayıcı bildirim sesi çalar ve ekranda dikkat çekici modal açılır. Alındı olarak onaylayabilir veya erteleyebilirsiniz.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                <strong>Püf Noktası:</strong> Tek bir alarm saatini geçici olarak kapatmak isterseniz kartın üzerindeki saat rozetine tıklayarak o saati aktif veya pasif yapabilirsiniz.
            </div>
        </div>

        <!-- 1D. SPOR PROGRAMI -->
        <div class="module-section guide-item" id="mod-workout">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(34, 197, 94, 0.15); color: #22c55e;">
                        <i class="bi bi-activity"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Spor Modülü (Workout)</h4>
                        <span class="text-secondary small">Dosya: <code>workout.php</code> · Haftalık takvim, dinamik makro tetikleyicisi ve antrenman takibi</span>
                    </div>
                </div>
                <a href="workout.php" class="btn btn-sm btn-outline-success px-3 btn-no-print">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-calendar3-week text-success"></i> 7 Günlük Haftalık Ajanda</div>
                        <p class="feature-desc">
                            Pazartesi'den Pazar'a haftanın tüm günlerini kartlar halinde gösterir. Hangi gün antrenman yapılacağını, hangi günün dinlenme olduğunu net şekilde planlarsınız.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-fire text-danger"></i> Otomatik Dinamik Makro (+400 kcal)</div>
                        <p class="feature-desc">
                            Herhangi bir güne antrenman eklediğiniz anda backend PHP mantığı devreye girer; o günün hedefine otomatik <strong>+400 kcal ve +30g protein</strong> eklenir. Antrenman silinirse hedef normale döner.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-stopwatch text-info"></i> 15 Dk Sonra Takviye Tetikleyicisi</div>
                        <p class="feature-desc">
                            Antrenmanı bitirip <strong>"Tamamlandı"</strong> yaptığınızda, sistem bitiş saatinden tam 15 dakika sonrasına otomatik <em>"Whey Protein ve Magnezyum Al"</em> alarmı kurar!
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                <strong>Püf Noktası:</strong> Ağırlık, Kardiyo, HIIT, Fonksiyonel veya Yoga gibi farklı antrenman tipleri seçebilir; Kolay, Orta ve Zor seviyeleri belirleyebilirsiniz.
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- 2. BÖLÜM: ANALİZ MODÜLLERİ                                     -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <h5 class="text-secondary text-uppercase fw-bold mb-3 mt-4 small" style="letter-spacing: .08em;">
            <i class="bi bi-graph-up me-1"></i> 2. Analiz & Raporlama Modülleri
        </h5>

        <!-- 2A. BMR / TDEE HESAPLAYICI -->
        <div class="module-section guide-item" id="mod-bmr">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(96, 165, 250, 0.15); color: #60a5fa;">
                        <i class="bi bi-calculator"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">BMR / TDEE Metabolizma Analizi</h4>
                        <span class="text-secondary small">Dosya: <code>index.php</code> · Mifflin-St Jeor bilimsel enerji formülleri</span>
                    </div>
                </div>
                <a href="index.php" class="btn btn-sm btn-outline-primary px-3 btn-no-print">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-heart-pulse text-danger"></i> BMR (Bazal Metabolizma)</div>
                        <p class="feature-desc">
                            Vücudunuzun hiçbir hareket yapmadan sadece hayati fonksiyonlarını sürdürmek için yaktığı kalori tabanıdır (Mifflin-St Jeor denklemi).
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-lightning text-warning"></i> TDEE (Günlük Enerji Tüketimi)</div>
                        <p class="feature-desc">
                            BMR değerinizin haftalık aktivite düzeyinizle (hareketsizden profesyonel sporcuya 1.2 – 1.9 katsayısı) çarpılmasıyla bulunan gerçek günlük enerji ihtiyacınızdır.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-flag text-success"></i> Hedef Stratejisi (Kilo Ver/Al/Koru)</div>
                        <p class="feature-desc">
                            Kilo vermek için -500 kcal açık, kilo almak ve kas kütlesi inşa etmek için +500 kcal fazlalık otomatik planlanır; makro gramajları hesaplanır.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2B. HAFTALIK RAPOR -->
        <div class="module-section guide-item" id="mod-reports">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Haftalık Rapor & Performans Analizi</h4>
                        <span class="text-secondary small">Dosya: <code>reports.php</code> · İlerleme grafikleri ve Yapay Zeka Koçluk Değerlendirmesi</span>
                    </div>
                </div>
                <a href="reports.php" class="btn btn-sm btn-outline-info px-3 btn-no-print">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bar-chart-line text-danger"></i> Kalori & Dinamik Hedef Grafiği</div>
                        <p class="feature-desc">
                            Haftanın 7 günü için tüketilen kalori barlarını ve antrenman günlerinde +400 kcal yükselen kesikli hedef çizgisini Chart.js ile karşılaştırmalı çizer.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-pie-chart-fill text-warning"></i> Makro Enerji Payı Halkası</div>
                        <p class="feature-desc">
                            Haftalık alınan enerjinin yüzde kaçının Protein, Karbonhidrat ve Yağdan geldiğini gösterir; makro dengenizi optimize etmenize yardımcı olur.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-stars text-info"></i> Gemini AI Haftalık Koçluk Raporu</div>
                        <p class="feature-desc">
                            Yapay zeka haftalık kalori açığınızı, protein tutarlılığınızı ve antrenman katılımınızı analiz ederek <strong>güçlü yönler, dikkat edilecekler ve 3 somut tavsiye</strong> sunar.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-6">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-award text-success"></i> Spor & İlaç Uyum Karnesi</div>
                        <p class="feature-desc">
                            Planlanan kaç antrenmanı tamamladığınızı (% uyum) ve ilaç/takviye alarmlarınıza sadakat oranınızı haftalık özet kartlarında sunar.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-6">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-printer text-light"></i> Yazdır / PDF İndir Desteği</div>
                        <p class="feature-desc">
                            Diyetisyeninizle paylaşmak veya arşivlemek için tek tıkla menülerden arındırılmış temiz bir A4 formatında PDF olarak kaydedebilirsiniz.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- 3. BÖLÜM: MOBİL UYGULAMA VE ERİŞİM                             -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <h5 class="text-secondary text-uppercase fw-bold mb-3 mt-4 small" style="letter-spacing: .08em;">
            <i class="bi bi-phone me-1"></i> 3. Mobil Uygulama ve Erişim
        </h5>

        <!-- 3A. MOBİL UYGULAMA (PWA) -->
        <div class="module-section guide-item" id="mod-mobile">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(34, 197, 94, 0.15); color: #22c55e;">
                        <i class="bi bi-phone"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Mobil PWA (Ana Ekrana Ekle)</h4>
                        <span class="text-secondary small">Tarayıcı üzerinden sıfır kurulumla yerel uygulama deneyimi</span>
                    </div>
                </div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-success px-3 btn-no-print">
                    Dashboard'a Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-patch-check text-success"></i> Güvenlik Uyarısı Yok</div>
                        <p class="feature-desc">
                            Harici APK indirmeye gerek kalmadan, doğrudan telefonunuzun güvenli tarayıcısı üzerinden yerel uygulama gibi çalışır.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-phone text-info"></i> Tam Ekran Mobil Deneyim</div>
                        <p class="feature-desc">
                            Telefonunuzun tarayıcı menüsünden <em>"Ana Ekrana Ekle"</em> butonuna bastığınızda telefonunuzda tam ekran bir uygulama simgesi oluşur.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bell text-warning"></i> Sesli Alarmlar & Bildirimler</div>
                        <p class="feature-desc">
                            Web Audio API tabanlı sentetik alarm motoru sayesinde takviye ve ilaç hatırlatıcılarınız tarayıcınızdan sesli olarak çalar.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Arama filtresi
function filterGuide() {
    const q = document.getElementById('guideSearchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('.guide-item');

    items.forEach(item => {
        const text = item.innerText.toLowerCase();
        if (!q || text.includes(q)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>
</body>
</html>

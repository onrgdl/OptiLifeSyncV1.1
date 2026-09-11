<?php

declare(strict_types=1);

/**
 * OptiLifeSync V1.1 - Kullanım Kılavuzu & Sistem Rehberi
 * ───────────────────────────────────────────────────────────
 * Bilimsel metabolizma yönetimi, yapay zeka destekli besin analizi,
 * ilaç & takviye takibi, spor planlaması ve analitik raporlama modüllerinin
 * ayrıntılı kullanım rehberi.
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$activePage = 'guide';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>OptiLifeSync — Sistem Kullanım Kılavuzu</title>
    <?php require_once __DIR__ . '/includes/pwa-meta.php'; ?>

    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">

    <style>
        :root {
            --surface-hover: #1e293b;
        }

        .guide-hero {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.12) 0%, rgba(99, 102, 241, 0.08) 50%, rgba(16, 185, 129, 0.06) 100%);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 20px;
            padding: 2.2rem;
            position: relative;
            overflow: hidden;
        }

        .guide-hero::after {
            content: '';
            position: absolute;
            top: -40%;
            right: -10%;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.15) 0%, transparent 70%);
            pointer-events: none;
        }

        .nav-pill-btn {
            background: var(--surface);
            border: 1px solid var(--border);
            color: #94a3b8;
            padding: 0.55rem 1.1rem;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            white-space: nowrap;
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
            padding: 1.8rem;
            margin-bottom: 2rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            scroll-margin-top: 80px;
        }
        .module-section:hover {
            border-color: rgba(255, 255, 255, 0.16);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }

        .module-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            padding-bottom: 1.1rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.4rem;
        }

        .module-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .feature-card {
            background: rgba(0, 0, 0, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 1.25rem;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .feature-title {
            font-size: 14px;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 0.45rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .feature-desc {
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.6;
            margin: 0;
        }

        .tip-box {
            background: rgba(56, 189, 248, 0.08);
            border-left: 4px solid var(--accent);
            border-radius: 0 12px 12px 0;
            padding: 0.9rem 1.2rem;
            font-size: 13px;
            color: #cbd5e1;
            margin-top: 1.3rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .search-bar-wrap {
            position: relative;
            width: 100%;
            max-width: 420px;
        }

        .search-bar-wrap input {
            background: #0b1329;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.7rem 1.1rem 0.7rem 2.6rem;
            color: #f8fafc;
            font-size: 14px;
            width: 100%;
            outline: none;
            transition: all 0.2s ease;
        }

        .search-bar-wrap input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .search-bar-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 15px;
        }

        @media (max-width: 768px) {
            .guide-hero {
                padding: 1.4rem;
            }
            .module-section {
                padding: 1.2rem;
            }
            .search-bar-wrap {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<!-- ═══════════════════════ MAIN İÇERİK ════════════════════════════ -->
<div class="main">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-left">
            <div>
                <div class="topbar-title">Sistem Kullanım Kılavuzu</div>
                <div class="topbar-sub">OptiLifeSync V1.1 · Modül Rehberi ve Bilimsel Açıklamalar</div>
            </div>
        </div>
        <div class="topbar-right">
            <a href="dashboard.php" class="btn-topbar btn-accent">
                <i class="bi bi-grid-1x2-fill"></i>
                <span class="d-none d-sm-inline">Dashboard'a Dön</span>
            </a>
        </div>
    </header>

    <!-- CONTENT -->
    <div class="content">

        <!-- ── HERO BÖLÜMÜ ────────────────────────────────────── -->
        <div class="guide-hero mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div style="max-width: 650px;">
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill small fw-bold mb-3" style="background:rgba(56,189,248,0.15); color:var(--accent); border:1px solid rgba(56,189,248,0.3);">
                        <i class="bi bi-shield-check"></i> Sürüm 1.1 · Güncel Sistem Dokümantasyonu
                    </div>
                    <h2 class="text-light fw-bold mb-2">OptiLifeSync Nasıl Çalışır?</h2>
                    <p class="text-secondary mb-0" style="font-size: 14px; line-height: 1.65;">
                        OptiLifeSync; Mifflin-St Jeor metabolizma formülasyonu, Google Gemini 2.5 Flash yapay zeka gıda analiz motoru, akıllı ilaç ve takviye hatırlatıcıları ile haftalık spor takip sistemini tek çatı altında birleştiren yeni nesil kişisel sağlık yönetim platformudur.
                    </p>
                </div>
                <!-- Canlı Arama Kutusu -->
                <div class="search-bar-wrap w-100 w-md-auto">
                    <i class="bi bi-search"></i>
                    <input type="text" id="guideSearchInput" placeholder="Kılavuzda ara (örn: Gemini, TDEE, Alarm, Makro)..." oninput="filterGuide()">
                </div>
            </div>
        </div>

        <!-- ── HIZLI GEZİNME BUTONLARI (PILLS) ───────────────── -->
        <div class="d-flex flex-wrap gap-2 mb-4 pb-2 border-bottom border-secondary border-opacity-25" id="pillsContainer">
            <a href="#mod-dashboard" class="nav-pill-btn"><i class="bi bi-grid-1x2-fill text-info"></i> Dashboard</a>
            <a href="#mod-nutrition" class="nav-pill-btn"><i class="bi bi-egg-fried text-warning"></i> Beslenme & AI</a>
            <a href="#mod-reminders" class="nav-pill-btn"><i class="bi bi-bell-fill text-danger"></i> İlaç & Takviye</a>
            <a href="#mod-workout" class="nav-pill-btn"><i class="bi bi-activity text-success"></i> Spor Planı</a>
            <a href="#mod-bmr" class="nav-pill-btn"><i class="bi bi-calculator text-primary"></i> BMR / TDEE</a>
            <a href="#mod-reports" class="nav-pill-btn"><i class="bi bi-graph-up-arrow text-info"></i> Haftalık Rapor</a>
            <a href="#mod-creator" class="nav-pill-btn"><i class="bi bi-shield-lock-fill text-warning"></i> Creator Paneli</a>
            <a href="#mod-security" class="nav-pill-btn"><i class="bi bi-key-fill text-success"></i> Güvenlik & Mimari</a>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- 1. BÖLÜM: ANA MODÜLLER                                         -->
        <!-- ══════════════════════════════════════════════════════════════ -->

        <!-- 1A. DASHBOARD -->
        <div class="module-section guide-item" id="mod-dashboard">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
                        <i class="bi bi-grid-1x2-fill"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Dashboard (Akıllı Günlük Kontrol Paneli)</h4>
                        <span class="text-secondary small">Sayfa: <code>dashboard.php</code> · Günün tüm beslenme, makro, su ve ilaç verilerini anlık özetler</span>
                    </div>
                </div>
                <a href="dashboard.php" class="btn btn-sm btn-outline-info px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-fire text-danger"></i> Anlık Makro & Kalori</div>
                        <p class="feature-desc">
                            Günün tüketilen kalorisi, proteini, karbonhidratı ve yağı hedefinizle kıyaslanır. Kalan miktarlar ve doluluk yüzdeleri renkli ilerleme çubuklarında gösterilir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-pie-chart text-info"></i> Halka Göstergeler</div>
                        <p class="feature-desc">
                            4 ana besin öğesi için dairesel SVG halka grafikleri günün hedefine ne kadar yaklaştığınızı görselleştirir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-droplet-fill text-primary"></i> Akıllı Hidrasyon Takibi</div>
                        <p class="feature-desc">
                            Vücut ağırlığınızın her kilogramı için 35 ml baz su ihtiyacı hesaplanır. Spor yapılan günlerde otomatik +500 ml eklenerek su hedefiniz dinamik belirlenir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bell-fill text-warning"></i> Yaklaşan Alarmlar</div>
                        <p class="feature-desc">
                            Günün sonraki 3 saati içindeki tüm ilaç ve takviye bildirimleri listelenir; zamanı gelen alarmlar sesli ve görsel olarak sizi uyarır.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-lightning-charge-fill text-warning fs-5"></i>
                <div>
                    <strong>Sıfır Gecikmeli Açılış (SSR Hydration):</strong> Dashboard sayfası sunucu tarafında verileri anında hazırlayıp sayfaya gömer. Sayfa açılır açılmaz bekleme ve spinner olmadan tüm değerleriniz anında ekranda belirir.
                </div>
            </div>
        </div>

        <!-- 1B. BESLENME & GEMINI AI -->
        <div class="module-section guide-item" id="mod-nutrition">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(234, 179, 8, 0.15); color: #eab308;">
                        <i class="bi bi-egg-fried"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Beslenme & Gemini AI Kalori Tarayıcı</h4>
                        <span class="text-secondary small">Sayfa: <code>nutrition.php</code> · Serbest metin veya fotoğrafla yapay zeka destekli besin analizi</span>
                    </div>
                </div>
                <a href="nutrition.php" class="btn btn-sm btn-outline-warning px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-chat-text text-info"></i> Serbest Metinle Besin Analizi</div>
                        <p class="feature-desc">
                            Örn: <em>"2 haşlanmış yumurta, 50g lor peyniri ve 1 dilim çavdar ekmeği"</em> yazdığınızda Gemini 2.5 Flash gıdaları ayrıştırır, kalori ve makro gramajlarını hesaplar.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-camera-fill text-danger"></i> Fotoğrafla Tabak Analizi</div>
                        <p class="feature-desc">
                            Yediğiniz yemeğin fotoğrafını yükleyerek yapay zekanın porsiyonları, besin çeşitlerini ve tahmini enerji içeriğini saniyeler içinde tanımasını sağlayabilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-clock-history text-success"></i> 6 Farklı Öğün Kategorisi</div>
                        <p class="feature-desc">
                            Kahvaltı, Öğle, Akşam, Ara Öğün, Antrenman Öncesi ve Antrenman Sonrası olmak üzere 6 ayrı zaman dilimine göre öğünlerinizi organize edebilirsiniz.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-info-circle-fill text-info fs-5"></i>
                <div>
                    <strong>Diyet Tutarlılığı:</strong> OptiLifeSync'te beslenme hedefleri bilimsel BMR/TDEE temelinde sabittir. Spor yapsanız dahi kalori hedefleri şişirilmez; böylece yağ yakımı veya kas inşası hedefinize sadık kalırsınız.
                </div>
            </div>
        </div>

        <!-- 1C. İLAÇ VE TAKVİYELER -->
        <div class="module-section guide-item" id="mod-reminders">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(248, 113, 113, 0.15); color: #f87171;">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">İlaç & Takviye Yönetim Modülü</h4>
                        <span class="text-secondary small">Sayfa: <code>reminders.php</code> · Çoklu saatli alarmlar, dozaj takibi ve etkileşim denetimi</span>
                    </div>
                </div>
                <a href="reminders.php" class="btn btn-sm btn-outline-danger px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-alarm text-warning"></i> Çoklu Alarm & Saat Planı</div>
                        <p class="feature-desc">
                            Günde birden fazla kez alınması gereken ilaçlar için tek kayıtta birden fazla saat (örn: 08:30, 14:00, 21:00) ve haftanın belirli günlerini seçebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-volume-up-fill text-info"></i> Web Audio API Sesli İkaz</div>
                        <p class="feature-desc">
                            Harici ses dosyalarına veya internet bağlantısına ihtiyaç duymadan, tarayıcının yerel ses motoruyla net sentetik bip uyarıları üretir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-shield-exclamation text-danger"></i> Etkileşim Kontrol Motoru</div>
                        <p class="feature-desc">
                            Birlikte alındığında birbirinin emilimini bozan maddeler (örn: Demir ve Kalsiyum, Magnezyum ve Çinko) için akıllı güvenlik uyarıları sunar.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <div>
                    <strong>Tedavi Bitirme:</strong> Süreli kullanılan bir antibiyotik veya takviye bittiğinde <em>"Tamamlandı"</em> butonuna basarak alarmları durdurabilir, kayıt geçmişini saklayabilirsiniz.
                </div>
            </div>
        </div>

        <!-- 1D. SPOR VE ANTRENMAN PLANI -->
        <div class="module-section guide-item" id="mod-workout">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <i class="bi bi-activity"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Spor & Aktivite Takip Modülü</h4>
                        <span class="text-secondary small">Sayfa: <code>workout.php</code> · Haftalık aktivite programı, spor branşları ve tutarlılık takibi</span>
                    </div>
                </div>
                <a href="workout.php" class="btn btn-sm btn-outline-success px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-calendar3 text-info"></i> 7 Günlük Haftalık Takvim</div>
                        <p class="feature-desc">
                            Pazartesi'den Pazar'a haftanın tüm günlerini tek ekranda görün. Önceki ve sonraki haftalara oklarla kolayca geçiş yapabilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-trophy text-warning"></i> 7 Farklı Spor Branşı</div>
                        <p class="feature-desc">
                            Ağırlık Antrenmanı, Kardiyo/Koşu, Fonksiyonel Fitness, HIIT/Kondisyon, Pilates/Yoga, Yüzme ve Boks seçeneklerinden dilediğinizi seçin.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-shield-check text-success"></i> Sabit Diyet Felsefesi</div>
                        <p class="feature-desc">
                            Antrenman eklemeniz beslenme hedeflerinizi bozmaz. Spor kayıtları kişisel disiplin ve aktivite takibi içindir; kalori açığınız korunur.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-check2-circle text-success fs-5"></i>
                <div>
                    <strong>Antrenmanı Tamamla:</strong> Antrenmanınızı bitirdiğinizde <em>"Antrenmanı Bitir"</em> butonuna dokunarak günün sporunu tamamlandı olarak işaretleyin. İlerleme grafiğinize anında yansır.
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- 2. BÖLÜM: ANALİZ VE RAPORLAMA                                  -->
        <!-- ══════════════════════════════════════════════════════════════ -->

        <!-- 2A. BMR / TDEE HESAPLAYICI -->
        <div class="module-section guide-item" id="mod-bmr">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(96, 165, 250, 0.15); color: #60a5fa;">
                        <i class="bi bi-calculator"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">BMR / TDEE Metabolizma Analizi</h4>
                        <span class="text-secondary small">Sayfa: <code>index.php</code> · Bilimsel Mifflin-St Jeor enerji ve makro denklemleri</span>
                    </div>
                </div>
                <a href="index.php" class="btn btn-sm btn-outline-primary px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-heart-pulse text-danger"></i> BMR (Bazal Metabolizma Hızı)</div>
                        <p class="feature-desc">
                            Vücudunuzun hiçbir hareket yapmadan sadece hayati fonksiyonlarını (organ çalışması, nefes, hücre yenilenmesi) sürdürmek için harcadığı temel enerjidir.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-lightning text-warning"></i> TDEE (Günlük Toplam Enerji)</div>
                        <p class="feature-desc">
                            BMR değerinizin haftalık aktivite düzeyinizle (1.2 Hareketsiz – 1.9 Atlet katsayıları) çarpılmasıyla hesaplanan gerçek günlük harcamanızdır.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bullseye text-success"></i> Sürdürülebilir Kalori Açığı</div>
                        <p class="feature-desc">
                            Sağlıklı yağ yakımı için TDEE'den 500 kcal açık oluşturulur (haftalık ~0.5 kg saf yağ kaybı). Kas kaybını önlemek için kilo başına 2g protein hedeflenir.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-mortarboard-fill text-info fs-5"></i>
                <div>
                    <strong>Mifflin-St Jeor Formülü:</strong> Erkekler için <code>10 x kilo + 6.25 x boy - 5 x yaş + 5</code>; Kadınlar için <code>10 x kilo + 6.25 x boy - 5 x yaş - 161</code> olarak tıp standartlarında hesaplanır.
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
                        <span class="text-secondary small">Sayfa: <code>reports.php</code> · Karşılaştırmalı grafikler ve Gemini AI haftalık koçluk raporu</span>
                    </div>
                </div>
                <a href="reports.php" class="btn btn-sm btn-outline-info px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-bar-chart-line text-danger"></i> 7 Günlük Kalori Karşılaştırması</div>
                        <p class="feature-desc">
                            Haftanın her günü için tüketilen kalori barları ile hedef kalori çizginizi Chart.js destekli yüksek çözünürlüklü grafikte kıyaslar.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-pie-chart-fill text-warning"></i> Makro Enerji Payı Halkası</div>
                        <p class="feature-desc">
                            Haftalık aldığınız kalorinin yüzde kaçının Protein (%30), Karbonhidrat (%45) ve Yağdan (%25) geldiğini göstererek besin kalitesini analiz eder.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-stars text-info"></i> Gemini AI Haftalık Koçluk</div>
                        <p class="feature-desc">
                            Yapay zeka haftalık kalori açığınızı, protein sürekliliğinizi ve spor katılımınızı inceleyerek: <strong>Güçlü Yönler, İyileştirme Alanları ve 3 Somut Tavsiye</strong> üretir.
                        </p>
                    </div>
                </div>
            </div>

            <div class="tip-box">
                <i class="bi bi-printer-fill text-light fs-5"></i>
                <div>
                    <strong>PDF ve Yazdırma:</strong> Rapor sayfasında sağ üstteki <em>"Yazdır / PDF İndir"</em> butonuyla menülerden arındırılmış temiz bir A4 rapor çıktısı alabilir, diyetisyeninizle paylaşabilirsiniz.
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- 3. BÖLÜM: YÖNETİM VE GÜVENLİK                                  -->
        <!-- ══════════════════════════════════════════════════════════════ -->

        <!-- 3A. CREATOR YÖNETİCİ PANELİ -->
        <div class="module-section guide-item" id="mod-creator">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(250, 204, 21, 0.15); color: #facc15;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Creator Yönetici Paneli</h4>
                        <span class="text-secondary small">Sayfa: <code>creator.php</code> · Kullanıcı yönetimi, sistem sağlığı ve Göz Atma Modu (Impersonation)</span>
                    </div>
                </div>
                <a href="creator.php" class="btn btn-sm btn-outline-warning px-3">
                    Modüle Git <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-people-fill text-info"></i> Kullanıcı Yönetimi</div>
                        <p class="feature-desc">
                            Sistemde kayıtlı tüm kullanıcıların rollerini (creator/user), e-posta adreslerini ve kayıt tarihlerini güvenle görüntüleyebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-eye-fill text-warning"></i> Göz Atma Modu (Impersonation)</div>
                        <p class="feature-desc">
                            Kullanıcılara teknik destek vermek için şifre bilmeden hesaplarına tek tıkla geçebilir, sistemdeki hataları kullanıcının gözünden inceleyebilirsiniz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-database-check text-success"></i> Veritabanı Teşhisi</div>
                        <p class="feature-desc">
                            Aktif veritabanı sürücüsü (MySQL veya Supabase PostgreSQL), bağlantı durumu ve tablo kayıt sayıları doğrudan panelden denetlenir.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3B. GÜVENLİK VE MİMARİ -->
        <div class="module-section guide-item" id="mod-security">
            <div class="module-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="module-icon-box" style="background: rgba(34, 197, 94, 0.15); color: #22c55e;">
                        <i class="bi bi-key-fill"></i>
                    </div>
                    <div>
                        <h4 class="text-light fw-bold mb-1">Güvenlik, Oturum & Bulut Mimarisi</h4>
                        <span class="text-secondary small">Kriptografik HMAC-SHA256 oturum yönetimi ve bulut veri yedekliliği</span>
                    </div>
                </div>
                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-3 py-2">
                    <i class="bi bi-shield-check me-1"></i> Aktif Koruma
                </span>
            </div>

            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-fingerprint text-success"></i> HMAC-SHA256 Durumsuz Çerez</div>
                        <p class="feature-desc">
                            30 günlük kriptografik <code>optilife_auth</code> çerezi sayesinde hem telefondan hem bilgisayardan eşzamanlı giriş yapılsa bile sistemden atma yaşanmaz.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-cloud-check-fill text-info"></i> Çift Yönlü Veritabanı Desteği</div>
                        <p class="feature-desc">
                            Tek bir kod tabanıyla hem yerel XAMPP (MySQL) ortamında hem de Vercel + Supabase (PostgreSQL) bulutunda tam uyumlulukla çalışır.
                        </p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-title"><i class="bi bi-shield-shaded text-warning"></i> Enjeksiyon & XSS Koruması</div>
                        <p class="feature-desc">
                            Tüm veritabanı sorguları PDO Prepared Statements ile parametrize edilir; kullanıcıdan gelen her veri XSS süzgecinden geçirilir.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Canlı Arama Filtresi
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

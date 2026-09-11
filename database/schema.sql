-- ============================================================
-- OptiLifeSync - Kişisel Sağlık Takip Sistemi - Veritabanı Şeması
-- Motor: MySQL 5.7+ / MariaDB 10.3+
-- Karakter Seti: utf8mb4 (emoji ve Türkçe karakterler için)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES 'utf8mb4';

CREATE DATABASE IF NOT EXISTS `gyp_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `gyp_db`;

-- ============================================================
-- 1. KULLANICI TABLOSU
--    Boy, kilo, yaş, cinsiyet ve hedef bilgilerini tutar.
--    Her kullanıcının benzersiz bir kaydı olur; ileride
--    çok kullanıcılı (aile/danışman) yapıya hazır şekilde tasarlandı.
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(100)    NOT NULL,
    `username`          VARCHAR(50)     NULL,
    `role`              VARCHAR(20)     NOT NULL DEFAULT 'user' COMMENT 'creator, user',
    `email`             VARCHAR(191)    NULL,
    `password_hash`     VARCHAR(255)    NULL,
    `pin_hash`          VARCHAR(255)    NULL COMMENT '4-6 haneli PIN hash',
    `recovery_code`     VARCHAR(64)     NULL COMMENT 'PIN unutulursa sıfırlama kodu',
    -- Fiziksel Profil
    `gender`            ENUM('male','female','other') NOT NULL DEFAULT 'male',
    `birth_date`        DATE            NOT NULL DEFAULT '1996-01-01' COMMENT 'Yaşı dinamik hesaplamak için tarih tutulur',
    `height_cm`         DECIMAL(5,2)    NOT NULL DEFAULT 170.00 COMMENT 'Santimetre cinsinden boy',
    `weight_kg`         DECIMAL(5,2)    NOT NULL DEFAULT 70.00 COMMENT 'Kilogram cinsinden güncel kilo',
    -- Hedef
    `goal`              ENUM('lose','gain','maintain') NOT NULL DEFAULT 'maintain'
                        COMMENT 'lose=kilo ver, gain=kilo al, maintain=koru',
    `activity_level`    ENUM(
                            'sedentary',        -- Hareketsiz (masa başı iş)
                            'lightly_active',   -- Hafif aktif (haftada 1-3 gün)
                            'moderately_active',-- Orta aktif (haftada 3-5 gün)
                            'very_active',      -- Çok aktif (haftada 6-7 gün)
                            'extra_active'      -- Profesyonel sporcu
                        ) NOT NULL DEFAULT 'sedentary',
    -- Meta
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ana kullanıcı profil tablosu';


-- ============================================================
-- 2. TAKVİYE VE İLAÇ TABLOSU
--    Lokal takviye ve ilaç öğelerini tutar:
--      - Vitamin / Mineral takviyeleri
--      - Reçeteli veya OTC ilaçlar
--    Her satır bir ürünü temsil eder (günlük log ayrı tabloda).
-- ============================================================

CREATE TABLE IF NOT EXISTS `supplements` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `name`              VARCHAR(150)    NOT NULL            COMMENT 'Ürün adı (ör: D3 Vitamini, Metformin)',
    `type`              ENUM('supplement','medication','vitamin','mineral','herb','other')
                        NOT NULL DEFAULT 'supplement'       COMMENT 'Ürün kategorisi',
    `form`              ENUM('tablet','capsule','powder','liquid','injection','patch','other')
                        NOT NULL DEFAULT 'tablet'           COMMENT 'Kullanım formu',
    -- Doz Bilgisi
    `dose_amount`       DECIMAL(8,2)    NOT NULL            COMMENT 'Tek seferlik doz miktarı',
    `dose_unit`         VARCHAR(20)     NOT NULL DEFAULT 'mg'
                        COMMENT 'Birim: mg, mcg, g, IU, ml, vb.',
    `doses_per_day`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Günlük kaç kez alınacak',
    -- Zamanlama (ilaç alarmları için)
    `schedule_times`    JSON            NULL
                        COMMENT 'Alarm saatleri: ["08:00","14:00","21:00"] formatında JSON',
    -- Kullanım Süresi (Kür / Tedavi Planı)
    `start_date`        DATE            NOT NULL DEFAULT (CURRENT_DATE),
    `end_date`          DATE            NULL COMMENT 'Kullanım bitiş tarihi (NULL=sürekli)',
    `duration_days`     INT UNSIGNED    NULL COMMENT 'Kaç günlük kür/tedavi (örn: 15)',
    -- Besin Değeri (takviyeler için opsiyonel)
    `calories_per_dose` DECIMAL(6,2)    NULL DEFAULT 0,
    `protein_g`         DECIMAL(6,2)    NULL DEFAULT 0,
    `carbs_g`           DECIMAL(6,2)    NULL DEFAULT 0,
    `fat_g`             DECIMAL(6,2)    NULL DEFAULT 0,
    -- Notlar
    `notes`             TEXT            NULL                COMMENT 'Doktor notu, yan etkiler vb.',
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1=aktif kullanımda, 0=bırakıldı',
    -- Meta
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_supplements_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kullanıcıya ait takviye ve ilaç kataloğu';


-- ============================================================
-- 3A. SPOR PROGRAMI TABLOSU
--     Haftalık tekrarlayan antrenman planlarını tutar.
--     Örn: "Pazartesi - Göğüs/Triceps", "Salı - Dinlenme"
-- ============================================================

CREATE TABLE IF NOT EXISTS `workout_plans` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `name`              VARCHAR(100)    NOT NULL            COMMENT 'Plan adı (ör: Bulk Dönemi Planı)',
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_workout_plans_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kullanıcının antrenman plan başlıkları';


-- ============================================================
-- 3B. HAFTALIK ANTRENMAN GÜNLERİ TABLOSU
--     workout_plans'a bağlı, gün bazlı ayrıntı.
-- ============================================================

CREATE TABLE IF NOT EXISTS `workout_plan_days` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `plan_id`           INT UNSIGNED    NOT NULL,
    `day_of_week`       TINYINT UNSIGNED NOT NULL           COMMENT '0=Pazar...6=Cumartesi (PHP date("w") ile uyumlu)',
    `day_type`          ENUM('rest','training') NOT NULL DEFAULT 'rest'
                        COMMENT 'rest=Dinlenme, training=Antrenman',
    `training_label`    VARCHAR(100)    NULL                COMMENT 'Antrenman etiketi (ör: Göğüs & Triceps)',
    `notes`             TEXT            NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_plan_day` (`plan_id`, `day_of_week`),
    CONSTRAINT `fk_plan_days_plan`
        FOREIGN KEY (`plan_id`) REFERENCES `workout_plans` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Haftalık plan için gün-tip eşleşmeleri';


-- ============================================================
-- 3C. GÜNLÜK SPOR VE ANTRENMAN TABLOSU (Workouts)
--     Kullanıcının tarih bazlı antrenman kayıtlarını tutar.
-- ============================================================

CREATE TABLE IF NOT EXISTS `workouts` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `tarih`             DATE            NOT NULL,
    `antrenman_tipi`    VARCHAR(50)     NOT NULL COMMENT 'Ağırlık, Kardiyo, Fonksiyonel, HIIT, Pilates/Yoga vb.',
    `zorluk_seviyesi`   VARCHAR(20)     NOT NULL DEFAULT 'Orta' COMMENT 'Kolay, Orta, Zor',
    `tamamlandi_mi`     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '0=Planlandı, 1=Tamamlandı',
    `tamamlanma_saati`  TIME            NULL COMMENT 'Bitirildiği saat',
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_workouts_user_date` (`user_id`, `tarih`),
    CONSTRAINT `fk_workouts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kullanıcı günlük spor ve antrenman planları';


-- ============================================================
-- 3D. DİNAMİK MAKRO HEDEFLERİ TABLOSU
--     Dinlenme ve antrenman günleri için farklı kalori/makro
--     hedeflerini tutar. BMR hesabından türetilir, elle de girilebilir.
-- ============================================================

CREATE TABLE IF NOT EXISTS `macro_targets` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `day_type`          ENUM('rest','training') NOT NULL    COMMENT 'Hangi gün tipi için geçerli',
    -- Kalori
    `calories`          SMALLINT UNSIGNED NOT NULL          COMMENT 'Hedef kalori (kcal)',
    -- Makrolar (gram cinsinden)
    `protein_g`         DECIMAL(6,2)    NOT NULL,
    `carbs_g`           DECIMAL(6,2)    NOT NULL,
    `fat_g`             DECIMAL(6,2)    NOT NULL,
    -- Antrenman gününe ek değerler (PHP sınıfı tarafından hesaplanır)
    `extra_calories`    SMALLINT        NOT NULL DEFAULT 0  COMMENT 'Antrenmana ek kalori (varsayılan +400)',
    `extra_protein_g`   DECIMAL(6,2)    NOT NULL DEFAULT 0  COMMENT 'Antrenmana ek protein (varsayılan +30g)',
    -- Kaynak
    `source`            ENUM('auto','manual') NOT NULL DEFAULT 'auto'
                        COMMENT 'auto=PHP sınıfı hesapladı, manual=kullanıcı girdi',
    -- Meta
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_macro_user_daytype` (`user_id`, `day_type`),
    CONSTRAINT `fk_macro_targets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dinlenme ve antrenman günleri için dinamik makro hedefleri';


-- ============================================================
-- 4. GÜNLÜK LOG TABLOSU
--    Her gün için tek bir başlık satırı tutar.
--    Tüm detay loglar bu tabloya FK ile bağlanır.
-- ============================================================

CREATE TABLE IF NOT EXISTS `daily_logs` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `log_date`          DATE            NOT NULL,
    -- O günün gerçekleşen özeti (dashboard için hesaplanıp güncellenir)
    `total_calories`    DECIMAL(8,2)    NOT NULL DEFAULT 0,
    `total_protein_g`   DECIMAL(7,2)    NOT NULL DEFAULT 0,
    `total_carbs_g`     DECIMAL(7,2)    NOT NULL DEFAULT 0,
    `total_fat_g`       DECIMAL(7,2)    NOT NULL DEFAULT 0,
    -- Spor durumu
    `workout_done`      TINYINT(1)      NOT NULL DEFAULT 0  COMMENT '1=antrenman yapıldı',
    `workout_notes`     TEXT            NULL,
    -- Su Tüketimi (ml)
    `water_ml`          INT UNSIGNED    NOT NULL DEFAULT 0  COMMENT 'O gün içilen toplam su (ml)',
    -- Günlük ağırlık takibi
    `weight_kg`         DECIMAL(5,2)    NULL                COMMENT 'O günkü sabah kilosu',
    -- Notlar
    `notes`             TEXT            NULL,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_daily_log_user_date` (`user_id`, `log_date`),
    CONSTRAINT `fk_daily_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Günlük log başlığı; dashboard için özet bilgileri tutar';


-- ============================================================
-- 4A. BESIN LOG TABLOSU
--     Gemini AI veya kullanıcı tarafından eklenen yiyeceklerin loglanması.
--     Hesaplanan makro verileri saklanır, tekrar sorgulanmaz.
-- ============================================================

CREATE TABLE IF NOT EXISTS `food_logs` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `daily_log_id`      INT UNSIGNED    NOT NULL,
    `meal_type`         ENUM('breakfast','lunch','dinner','snack','pre_workout','post_workout')
                        NOT NULL DEFAULT 'snack',
    -- Besin kimliği
    `food_id`           VARCHAR(50)     NULL                COMMENT 'Besin veya AI ID',
    `food_label`        VARCHAR(200)    NOT NULL            COMMENT 'Yiyecek adı',
    `quantity`          DECIMAL(8,2)    NOT NULL DEFAULT 1,
    `unit`              VARCHAR(50)     NOT NULL DEFAULT 'gram',
    -- Besin değerleri (analizden alınıp saklanır)
    `calories`          DECIMAL(8,2)    NOT NULL DEFAULT 0,
    `protein_g`         DECIMAL(7,2)    NOT NULL DEFAULT 0,
    `carbs_g`           DECIMAL(7,2)    NOT NULL DEFAULT 0,
    `fat_g`             DECIMAL(7,2)    NOT NULL DEFAULT 0,
    `fiber_g`           DECIMAL(7,2)    NULL DEFAULT 0,
    `sugar_g`           DECIMAL(7,2)    NULL DEFAULT 0,
    `sodium_mg`         DECIMAL(8,2)    NULL DEFAULT 0,
    -- Meta
    `logged_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_food_logs_daily`
        FOREIGN KEY (`daily_log_id`) REFERENCES `daily_logs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Günlük besin tüketim logları (Gemini AI destekli)';


-- ============================================================
-- 4B. TAKVİYE / İLAÇ LOG TABLOSU
--     supplements tablosundaki öğelerin günlük alım kaydı.
-- ============================================================

CREATE TABLE IF NOT EXISTS `supplement_logs` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `daily_log_id`      INT UNSIGNED    NOT NULL,
    `supplement_id`     INT UNSIGNED    NOT NULL,
    `taken_at`          TIME            NOT NULL            COMMENT 'Alım saati',
    `dose_taken`        DECIMAL(8,2)    NOT NULL            COMMENT 'Alınan doz miktarı',
    `dose_unit`         VARCHAR(20)     NOT NULL DEFAULT 'mg',
    `is_taken`          TINYINT(1)      NOT NULL DEFAULT 1  COMMENT '1=alındı, 0=atlandı',
    `notes`             TEXT            NULL,
    `logged_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_supplement_logs_daily`
        FOREIGN KEY (`daily_log_id`) REFERENCES `daily_logs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_supplement_logs_supp`
        FOREIGN KEY (`supplement_id`) REFERENCES `supplements` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Günlük takviye ve ilaç alım logları';


-- ============================================================
-- 4C. ANTRENMAN EGZERSİZ LOG TABLOSU
--     O günkü yapılan egzersizlerin set/tekrar/ağırlık detayı.
-- ============================================================

CREATE TABLE IF NOT EXISTS `exercise_logs` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `daily_log_id`      INT UNSIGNED    NOT NULL,
    `exercise_name`     VARCHAR(150)    NOT NULL            COMMENT 'Egzersiz adı (ör: Bench Press)',
    `muscle_group`      VARCHAR(100)    NULL                COMMENT 'Çalışılan kas grubu',
    `sets`              TINYINT UNSIGNED NULL,
    `reps`              TINYINT UNSIGNED NULL,
    `weight_kg`         DECIMAL(6,2)    NULL,
    `duration_minutes`  SMALLINT UNSIGNED NULL              COMMENT 'Kardiyo egzersizleri için süre',
    `notes`             TEXT            NULL,
    `logged_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_exercise_logs_daily`
        FOREIGN KEY (`daily_log_id`) REFERENCES `daily_logs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Günlük egzersiz detay logları';


-- ============================================================
-- 5. ALARM / HATIRLATİCİ TABLOSU
--    İlaç, su içme, öğün ve antrenman alarmları.
-- ============================================================

CREATE TABLE IF NOT EXISTS `reminders` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED    NOT NULL,
    `supplement_id`     INT UNSIGNED    NULL                COMMENT 'İlaç/takviye alarmıysa bağlantı',
    `type`              ENUM('medication','supplement','meal','workout','water','custom')
                        NOT NULL DEFAULT 'custom',
    `label`             VARCHAR(200)    NOT NULL,
    `remind_at`         TIME            NOT NULL,
    `start_date`        DATE            NULL DEFAULT (CURRENT_DATE),
    `end_date`          DATE            NULL COMMENT 'Alarmın son geçerlilik tarihi',
    `days_of_week`      JSON            NOT NULL            COMMENT '[0,1,2,3,4,5,6] — tekrar günleri',
    `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_reminders_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reminders_supplement`
        FOREIGN KEY (`supplement_id`) REFERENCES `supplements` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kullanıcı alarm ve hatırlatıcıları';


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Örnek Faaliyet Katsayıları (Referans)
-- sedentary        => BMR x 1.2
-- lightly_active   => BMR x 1.375
-- moderately_active=> BMR x 1.55
-- very_active      => BMR x 1.725
-- extra_active     => BMR x 1.9
-- ============================================================

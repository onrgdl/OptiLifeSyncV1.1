-- ============================================================
-- OptiLifeSync - Supabase PostgreSQL Veritabanı Şeması
-- Motor: PostgreSQL 14+ / Supabase
-- Karakter Seti: UTF8
-- ============================================================

-- 1. KULLANICILAR (users)
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE,
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('creator', 'user', 'admin')),
    email VARCHAR(191) NULL,
    password_hash VARCHAR(255) NULL,
    pin_hash VARCHAR(255) NULL,
    recovery_code VARCHAR(64) NULL,
    gender VARCHAR(20) NOT NULL DEFAULT 'male' CHECK (gender IN ('male', 'female', 'other')),
    birth_date DATE NOT NULL DEFAULT '1996-01-01',
    height_cm NUMERIC(5,2) NOT NULL DEFAULT 170.00,
    weight_kg NUMERIC(5,2) NOT NULL DEFAULT 70.00,
    goal VARCHAR(20) NOT NULL DEFAULT 'maintain' CHECK (goal IN ('lose', 'gain', 'maintain')),
    activity_level VARCHAR(30) NOT NULL DEFAULT 'sedentary' CHECK (activity_level IN ('sedentary', 'lightly_active', 'moderately_active', 'very_active', 'extra_active')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. TAKVİYE VE İLAÇLAR (supplements)
CREATE TABLE IF NOT EXISTS supplements (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    name VARCHAR(150) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'supplement' CHECK (type IN ('supplement', 'medication', 'vitamin', 'mineral', 'herb', 'other')),
    form VARCHAR(30) NOT NULL DEFAULT 'tablet' CHECK (form IN ('tablet', 'capsule', 'powder', 'liquid', 'injection', 'patch', 'other')),
    dose_amount NUMERIC(8,2) NOT NULL,
    dose_unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    doses_per_day SMALLINT NOT NULL DEFAULT 1,
    schedule_times JSONB NULL,
    start_date DATE NOT NULL DEFAULT CURRENT_DATE,
    end_date DATE NULL,
    duration_days INT NULL,
    calories_per_dose NUMERIC(6,2) NULL DEFAULT 0,
    protein_g NUMERIC(6,2) NULL DEFAULT 0,
    carbs_g NUMERIC(6,2) NULL DEFAULT 0,
    fat_g NUMERIC(6,2) NULL DEFAULT 0,
    notes TEXT NULL,
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3A. ANTRENMAN PROGRAMLARI (workout_plans)
CREATE TABLE IF NOT EXISTS workout_plans (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    name VARCHAR(100) NOT NULL,
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3B. HAFTALIK ANTRENMAN GÜNLERİ (workout_plan_days)
CREATE TABLE IF NOT EXISTS workout_plan_days (
    id BIGSERIAL PRIMARY KEY,
    plan_id BIGINT NOT NULL REFERENCES workout_plans(id) ON DELETE CASCADE ON UPDATE CASCADE,
    day_of_week SMALLINT NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
    day_type VARCHAR(20) NOT NULL DEFAULT 'rest' CHECK (day_type IN ('rest', 'training')),
    training_label VARCHAR(100) NULL,
    notes TEXT NULL,
    CONSTRAINT uk_plan_day UNIQUE (plan_id, day_of_week)
);

-- 3C. GÜNLÜK ANTRENMAN KAYITLARI (workouts)
CREATE TABLE IF NOT EXISTS workouts (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    tarih DATE NOT NULL,
    antrenman_tipi VARCHAR(50) NOT NULL,
    zorluk_seviyesi VARCHAR(20) NOT NULL DEFAULT 'Orta',
    tamamlandi_mi SMALLINT NOT NULL DEFAULT 0 CHECK (tamamlandi_mi IN (0, 1)),
    tamamlanma_saati TIME NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_workouts_user_date ON workouts(user_id, tarih);

-- 3D. DİNAMİK MAKRO HEDEFLERİ (macro_targets)
CREATE TABLE IF NOT EXISTS macro_targets (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    day_type VARCHAR(20) NOT NULL CHECK (day_type IN ('rest', 'training')),
    calories INT NOT NULL,
    protein_g NUMERIC(6,2) NOT NULL,
    carbs_g NUMERIC(6,2) NOT NULL,
    fat_g NUMERIC(6,2) NOT NULL,
    extra_calories INT NOT NULL DEFAULT 0,
    extra_protein_g NUMERIC(6,2) NOT NULL DEFAULT 0,
    source VARCHAR(20) NOT NULL DEFAULT 'auto' CHECK (source IN ('auto', 'manual')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_macro_user_daytype UNIQUE (user_id, day_type)
);

-- 4. GÜNLÜK ÖZET LOGLARI (daily_logs)
CREATE TABLE IF NOT EXISTS daily_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    log_date DATE NOT NULL,
    total_calories NUMERIC(8,2) NOT NULL DEFAULT 0,
    total_protein_g NUMERIC(7,2) NOT NULL DEFAULT 0,
    total_carbs_g NUMERIC(7,2) NOT NULL DEFAULT 0,
    total_fat_g NUMERIC(7,2) NOT NULL DEFAULT 0,
    workout_done SMALLINT NOT NULL DEFAULT 0 CHECK (workout_done IN (0, 1)),
    workout_notes TEXT NULL,
    water_ml INT NOT NULL DEFAULT 0,
    weight_kg NUMERIC(5,2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_daily_log_user_date UNIQUE (user_id, log_date)
);

-- 4A. BESİN LOGLARI (food_logs)
CREATE TABLE IF NOT EXISTS food_logs (
    id BIGSERIAL PRIMARY KEY,
    daily_log_id BIGINT NOT NULL REFERENCES daily_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
    meal_type VARCHAR(30) NOT NULL DEFAULT 'snack' CHECK (meal_type IN ('breakfast', 'lunch', 'dinner', 'snack', 'pre_workout', 'post_workout')),
    food_id VARCHAR(50) NULL,
    food_label VARCHAR(200) NOT NULL,
    quantity NUMERIC(8,2) NOT NULL DEFAULT 1,
    unit VARCHAR(50) NOT NULL DEFAULT 'gram',
    calories NUMERIC(8,2) NOT NULL DEFAULT 0,
    protein_g NUMERIC(7,2) NOT NULL DEFAULT 0,
    carbs_g NUMERIC(7,2) NOT NULL DEFAULT 0,
    fat_g NUMERIC(7,2) NOT NULL DEFAULT 0,
    fiber_g NUMERIC(7,2) NULL DEFAULT 0,
    sugar_g NUMERIC(7,2) NULL DEFAULT 0,
    sodium_mg NUMERIC(8,2) NULL DEFAULT 0,
    logged_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4B. TAKVİYE / İLAÇ LOGLARI (supplement_logs)
CREATE TABLE IF NOT EXISTS supplement_logs (
    id BIGSERIAL PRIMARY KEY,
    daily_log_id BIGINT NOT NULL REFERENCES daily_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
    supplement_id BIGINT NOT NULL REFERENCES supplements(id) ON DELETE CASCADE ON UPDATE CASCADE,
    taken_at TIME NOT NULL,
    dose_taken NUMERIC(8,2) NOT NULL,
    dose_unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    is_taken SMALLINT NOT NULL DEFAULT 1 CHECK (is_taken IN (0, 1)),
    notes TEXT NULL,
    logged_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4C. EGZERSİZ DETAY LOGLARI (exercise_logs)
CREATE TABLE IF NOT EXISTS exercise_logs (
    id BIGSERIAL PRIMARY KEY,
    daily_log_id BIGINT NOT NULL REFERENCES daily_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
    exercise_name VARCHAR(150) NOT NULL,
    muscle_group VARCHAR(100) NULL,
    sets SMALLINT NULL,
    reps SMALLINT NULL,
    weight_kg NUMERIC(6,2) NULL,
    duration_minutes INT NULL,
    notes TEXT NULL,
    logged_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. ALARMLAR VE HATIRLATICILAR (reminders)
CREATE TABLE IF NOT EXISTS reminders (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    supplement_id BIGINT NULL REFERENCES supplements(id) ON DELETE CASCADE ON UPDATE CASCADE,
    type VARCHAR(30) NOT NULL DEFAULT 'custom' CHECK (type IN ('medication', 'supplement', 'meal', 'workout', 'water', 'custom')),
    label VARCHAR(200) NOT NULL,
    remind_at TIME NOT NULL,
    start_date DATE NULL DEFAULT CURRENT_DATE,
    end_date DATE NULL,
    days_of_week JSONB NOT NULL,
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- OTOMATİK updated_at TETİKLEYİCİSİ (TRIGGER FUNCTION)
-- ============================================================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_users_updated_at ON users;
CREATE TRIGGER trg_users_updated_at
BEFORE UPDATE ON users
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DROP TRIGGER IF EXISTS trg_supplements_updated_at ON supplements;
CREATE TRIGGER trg_supplements_updated_at
BEFORE UPDATE ON supplements
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DROP TRIGGER IF EXISTS trg_workout_plans_updated_at ON workout_plans;
CREATE TRIGGER trg_workout_plans_updated_at
BEFORE UPDATE ON workout_plans
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DROP TRIGGER IF EXISTS trg_workouts_updated_at ON workouts;
CREATE TRIGGER trg_workouts_updated_at
BEFORE UPDATE ON workouts
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DROP TRIGGER IF EXISTS trg_macro_targets_updated_at ON macro_targets;
CREATE TRIGGER trg_macro_targets_updated_at
BEFORE UPDATE ON macro_targets
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DROP TRIGGER IF EXISTS trg_daily_logs_updated_at ON daily_logs;
CREATE TRIGGER trg_daily_logs_updated_at
BEFORE UPDATE ON daily_logs
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================
-- BAŞLANGIÇ VERİLERİ — GÜVENLIK NOTU
-- ============================================================
-- Creator hesabı artık şemada sabit PIN/recovery_code ile OLUŞTURULMAZ.
-- Kurulum sonrası Creator hesabını elle oluşturun:
--   1. Uygulamada /login → Kayıt Ol ile 'onrgdl' kullanıcısını kaydedin
--   2. Ardından aşağıdaki SQL ile role'ü creator yapın:
--      UPDATE users SET role = 'creator' WHERE username = 'onrgdl';
-- Alternatif (Supabase SQL Editor):
--   INSERT INTO users (name, username, role, email, pin_hash, recovery_code, gender, birth_date, height_cm, weight_kg, goal, activity_level)
--   VALUES ('Creator', 'onrgdl', 'creator', 'onrgdl@optilifesync.local',
--           crypt('YENİ_PIN_BURAYA', gen_salt('bf')),
--           'REC-' || upper(encode(gen_random_bytes(4),'hex')) || '-' || upper(encode(gen_random_bytes(4),'hex')),
--           'female', '1995-01-01', 165.00, 61.00, 'lose', 'sedentary')
--   ON CONFLICT (username) DO NOTHING;


-- 2. Dinamik Makro Hedefleri
INSERT INTO macro_targets (user_id, day_type, calories, protein_g, carbs_g, fat_g, extra_calories, extra_protein_g, source)
VALUES 
    (1, 'rest', 2300, 160.00, 260.00, 65.00, 0, 0, 'auto'),
    (1, 'training', 2700, 190.00, 310.00, 75.00, 400, 30.00, 'auto')
ON CONFLICT (user_id, day_type) DO NOTHING;

-- 3. Varsayılan Haftalık Antrenman Programı
INSERT INTO workout_plans (id, user_id, name, is_active)
VALUES (1, 1, 'Standart Hipertrofi Programı (4 Günlük Split)', 1)
ON CONFLICT (id) DO NOTHING;

INSERT INTO workout_plan_days (plan_id, day_of_week, day_type, training_label, notes)
VALUES
    (1, 1, 'training', 'Göğüs & Arka Kol (Triceps)', 'Bench Press, Incline Dumbbell, Dips'),
    (1, 2, 'training', 'Sırt & Ön Kol (Biceps)', 'Barbell Row, Lat Pulldown, Hammer Curl'),
    (1, 3, 'rest', 'Dinlenme & Aktif Toparlanma', 'Hafif yürüyüş ve esneme'),
    (1, 4, 'training', 'Omuz & Karın', 'Overhead Press, Lateral Raise, Plank'),
    (1, 5, 'training', 'Bacak & Kalf', 'Squat, Leg Press, Romanian Deadlift'),
    (1, 6, 'rest', 'Hafif Kardiyo / Dinlenme', 'Zone 2 kardiyo veya yürüyüş'),
    (1, 0, 'rest', 'Haftalık Dinlenme', 'Beslenme ve uykuya odaklanma')
ON CONFLICT (plan_id, day_of_week) DO NOTHING;

-- 4. Başlangıç Takviye & İlaç Listesi
INSERT INTO supplements (id, user_id, name, type, form, dose_amount, dose_unit, doses_per_day, schedule_times, start_date, is_active, calories_per_dose, protein_g, carbs_g, fat_g)
VALUES
    (1, 1, 'Whey Protein İzolat', 'supplement', 'powder', 30.00, 'g', 1, '["09:30"]'::jsonb, CURRENT_DATE, 1, 120.00, 25.00, 2.00, 1.00),
    (2, 1, 'Kreatin Monohidrat', 'supplement', 'powder', 5.00, 'g', 1, '["12:30"]'::jsonb, CURRENT_DATE, 1, 0.00, 0.00, 0.00, 0.00),
    (3, 1, 'Omega-3 Balık Yağı (EPA/DHA)', 'vitamin', 'capsule', 1000.00, 'mg', 1, '["13:00"]'::jsonb, CURRENT_DATE, 1, 10.00, 0.00, 0.00, 1.00),
    (4, 1, 'D3 + K2 Vitamini', 'vitamin', 'liquid', 2000.00, 'IU', 1, '["10:00"]'::jsonb, CURRENT_DATE, 1, 0.00, 0.00, 0.00, 0.00),
    (5, 1, 'Magnezyum Glisinat', 'mineral', 'tablet', 200.00, 'mg', 1, '["22:30"]'::jsonb, CURRENT_DATE, 1, 0.00, 0.00, 0.00, 0.00)
ON CONFLICT (id) DO NOTHING;

-- 5. Başlangıç Alarmları & Hatırlatıcılar
INSERT INTO reminders (user_id, supplement_id, type, label, remind_at, days_of_week, is_active)
VALUES
    (1, 1, 'supplement', 'Whey Protein İzolat — 09:30', '09:30:00', '[0,1,2,3,4,5,6]'::jsonb, 1),
    (1, 2, 'supplement', 'Kreatin Monohidrat — 12:30', '12:30:00', '[0,1,2,3,4,5,6]'::jsonb, 1),
    (1, 3, 'supplement', 'Omega-3 Balık Yağı — 13:00', '13:00:00', '[0,1,2,3,4,5,6]'::jsonb, 1),
    (1, 5, 'supplement', 'Magnezyum Glisinat — 22:30', '22:30:00', '[0,1,2,3,4,5,6]'::jsonb, 1)
ON CONFLICT DO NOTHING;

-- ============================================================
-- SEQUENCE SENKRONİZASYONU (ID çakışmalarını %100 engeller)
-- ============================================================
SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE(MAX(id), 1)) FROM users;
SELECT setval(pg_get_serial_sequence('macro_targets', 'id'), COALESCE(MAX(id), 1)) FROM macro_targets;
SELECT setval(pg_get_serial_sequence('workout_plans', 'id'), COALESCE(MAX(id), 1)) FROM workout_plans;
SELECT setval(pg_get_serial_sequence('workout_plan_days', 'id'), COALESCE(MAX(id), 1)) FROM workout_plan_days;
SELECT setval(pg_get_serial_sequence('supplements', 'id'), COALESCE(MAX(id), 1)) FROM supplements;
SELECT setval(pg_get_serial_sequence('daily_logs', 'id'), COALESCE(MAX(id), 1)) FROM daily_logs;
SELECT setval(pg_get_serial_sequence('reminders', 'id'), COALESCE(MAX(id), 1)) FROM reminders;
SELECT setval(pg_get_serial_sequence('food_logs', 'id'), COALESCE(MAX(id), 1)) FROM food_logs;
SELECT setval(pg_get_serial_sequence('supplement_logs', 'id'), COALESCE(MAX(id), 1)) FROM supplement_logs;
SELECT setval(pg_get_serial_sequence('exercise_logs', 'id'), COALESCE(MAX(id), 1)) FROM exercise_logs;
SELECT setval(pg_get_serial_sequence('workouts', 'id'), COALESCE(MAX(id), 1)) FROM workouts;

-- OptiLifeSync 2026-10 sertleştirme + performans migrasyonu (idempotent)
-- Uygulama Supabase'e doğrudan 'postgres' rolüyle bağlanır (RLS'i atlar);
-- Data API (anon/authenticated) kullanılmadığı için bu rollerin yetkileri kaldırılır.

-- 1) Tablolar: ai_usage_logs (artık kodda CREATE edilmiyor) + giriş denemeleri
CREATE TABLE IF NOT EXISTS ai_usage_logs (
    user_id     INTEGER NOT NULL,
    usage_date  DATE    NOT NULL,
    photo_count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, usage_date)
);
ALTER TABLE ai_usage_logs ADD COLUMN IF NOT EXISTS text_count INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGSERIAL PRIMARY KEY,
    throttle_key VARCHAR(128) NOT NULL,
    attempted_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_key_time ON login_attempts (throttle_key, attempted_at);

-- 2) RLS + Data API yetkilerini kapat
DO $$
DECLARE t text;
BEGIN
  FOR t IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP
    EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t);
  END LOOP;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='anon') THEN
    REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM anon;
    REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon;
    REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM anon;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES    FROM anon;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='authenticated') THEN
    REVOKE ALL ON ALL TABLES    IN SCHEMA public FROM authenticated;
    REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM authenticated;
    REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM authenticated;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES    FROM authenticated;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM authenticated;
  END IF;
END $$;

-- 3) Trigger fonksiyonu search_path sabitle
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
             WHERE n.nspname='public' AND p.proname='update_updated_at_column') THEN
    ALTER FUNCTION public.update_updated_at_column() SET search_path = public, pg_temp;
  END IF;
END $$;

-- 4) İndeksler
-- (İsteğe bağlı, elle çalıştırın) gereksiz kopya indeks: DROP INDEX IF EXISTS idx_daily_logs_user_date;
CREATE INDEX IF NOT EXISTS idx_food_logs_daily        ON food_logs (daily_log_id);
CREATE INDEX IF NOT EXISTS idx_exercise_logs_daily    ON exercise_logs (daily_log_id);
CREATE INDEX IF NOT EXISTS idx_supplements_user_active ON supplements (user_id) WHERE is_active = 1;
CREATE INDEX IF NOT EXISTS idx_reminders_supplement   ON reminders (supplement_id);
CREATE INDEX IF NOT EXISTS idx_daily_logs_workout     ON daily_logs (user_id, log_date) WHERE workout_done = 1;

-- OptiLifeSync — Aşama 2: akıllı bildirimler (idempotent)
-- workouts.planlanan_saat : antrenmanın planlanan başlangıç saati (bildirimler için)
-- workouts.sure_dk        : planlanan süre (dakika) — "yaptın mı?" sorusu bitişten sonra gelir
-- workouts.atlandi_mi     : kullanıcı "yapmadım" dedi (gün sonu eksik listesinde çıkmaz)
ALTER TABLE workouts ADD COLUMN IF NOT EXISTS planlanan_saat TIME     NULL;
ALTER TABLE workouts ADD COLUMN IF NOT EXISTS sure_dk        SMALLINT NULL;
ALTER TABLE workouts ADD COLUMN IF NOT EXISTS atlandi_mi     SMALLINT NOT NULL DEFAULT 0;

-- Kullanıcı başına bildirim tercihleri (JSON)
CREATE TABLE IF NOT EXISTS notification_prefs (
    user_id    BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    prefs      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE notification_prefs ENABLE ROW LEVEL SECURITY;

-- OptiLifeSync v2 — İlaç uyum takibi için doz saat bilgisi
-- supplement_logs.scheduled_time: dozun hangi alarm saatine ait olduğu (NULL = saatsiz/hızlı ekleme)
-- Güvenli ve geri alınabilir: yalnızca yeni, boş olabilen bir sütun ve indeks ekler.

ALTER TABLE supplement_logs ADD COLUMN IF NOT EXISTS scheduled_time TIME NULL;
CREATE INDEX IF NOT EXISTS idx_supplement_logs_daily_supp ON supplement_logs (daily_log_id, supplement_id);
CREATE INDEX IF NOT EXISTS idx_daily_logs_user_date ON daily_logs (user_id, log_date);
CREATE INDEX IF NOT EXISTS idx_reminders_user_active ON reminders (user_id, is_active);

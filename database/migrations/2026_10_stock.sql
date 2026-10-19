-- OptiLifeSync — Takviye/ilaç stok (kalan adet) takibi (idempotent)
-- track_stock    : 1 = kalan adedi takip et
-- stock_quantity : elde kalan adet (tablet, kapsül, ölçek…)
-- pack_size      : bir paketteki adet ("Yeni paket aldım" bu kadar ekler)
-- units_per_dose : her dozda kaç adet alınıyor
-- low_stock_days : bitmeden kaç gün önce uyarılsın
-- repurchase     : 1 = paket bitince yenisi alınacak (uyar), 0 = bu paketle bitecek (sadece düzenli alım takibi)
ALTER TABLE supplements ADD COLUMN IF NOT EXISTS track_stock    SMALLINT     NOT NULL DEFAULT 0;
ALTER TABLE supplements ADD COLUMN IF NOT EXISTS stock_quantity NUMERIC(8,2) NULL;
ALTER TABLE supplements ADD COLUMN IF NOT EXISTS pack_size      NUMERIC(8,2) NULL;
ALTER TABLE supplements ADD COLUMN IF NOT EXISTS units_per_dose NUMERIC(6,2) NOT NULL DEFAULT 1;
ALTER TABLE supplements ADD COLUMN IF NOT EXISTS low_stock_days SMALLINT     NOT NULL DEFAULT 5;
ALTER TABLE supplements ADD COLUMN IF NOT EXISTS repurchase     SMALLINT     NOT NULL DEFAULT 1;

# OptiLifeSync — Ekim 2026 sertleştirme + performans güncellemesi

## Güvenlik
- Giriş / PIN sıfırlama / davet kodu denemeleri artık **veritabanında** (`login_attempts`) kullanıcı adı + IP bazında sınırlanıyor (5 deneme / 5 dk kullanıcı, 20 / IP). Çerez silerek aşılamaz.
- PIN sıfırlama: genel hata mesajı (kullanıcı adı taraması engellendi), boş kurtarma kodu reddi, sınırlama.
- Oturum çerezi geri yüklenirken kullanıcı ve rol DB'den doğrulanır (silinen/rolü değişen hesap).
- **Kayıt varsayılan olarak KAPALI.** Açmak için Vercel ortam değişkeni: `REGISTRATION_OPEN=1` (herkese açık) veya `INVITE_CODE=<kod>` (davet kodlu).
- `api/setup_supabase.php` yalnızca Creator oturumuyla açılır (aksi halde 404); canlıda tablo/satır sayısı sızıntısı kapandı.
- Router: `/includes/*`, `/config/*`, `example_usage`, `setup` artık URL ile çalıştırılamaz.
- Güvenlik başlıkları (CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy) route düzeyinde — önceki `headers` bloğu `routes` ile birlikte yok sayılıyordu.
- Yazma işlemleri (`api/dashboard.php`) yalnızca POST. Hata yanıtlarında iç hata metni sızdırılmıyor.
- AI kotası: foto 8/gün, metin 60/gün, atomik; tüm AI eylemlerinde zorunlu; çalışma anında `CREATE TABLE` kaldırıldı.
- Supabase: tüm tablolarda RLS açık, `anon`/`authenticated` yetkileri kaldırıldı (uygulama `postgres` rolüyle bağlanır, etkilenmez).

## Performans
- Dashboard SQL sayısı 13 → 11 (profil önbelleği); yükleme çağrısında tek-istek koruması + üstel geri çekilme.
- İlaç sayfası yoklaması sekme gizliyken durur.
- Yeni indeksler (food_logs, exercise_logs, supplements, reminders, daily_logs).
- Service Worker v5, varlık sürümü 20261009.

## Android / alarm
- Erteleme (snooze) cihaz yeniden başlasa da kalıcı; alarm tetiklenince bir sonraki alarm önce kurulur.
- Doz bildirimi önce diske yazılır (çevrimdışıyken kaybolmaz); bildirim kanalı kapalıysa ilaç sayfasında uyarı.
- `allowBackup=false`; CI: imzalı sürüm desteği (`capacitor-app/SIGNING.md`).

## Keep-alive
- `api/ping.php?db=1` veritabanına SELECT 1 atar; `vercel.json` günlük cron + `.github/workflows/keepalive.yml` (2 günde bir) Supabase'in 7 gün hareketsizlikte duraklamasını önler.

## Veritabanı
`database/migrations/2026_10_hardening.sql` — **Supabase'e zaten uygulandı** (idempotent, tekrar çalıştırılabilir).

# OptiLifeSync 2.1 — Hız ve Mobil Düzeltmesi

## Yükleme (2 dakika)

1. **github.com/onrgdl/OptiLifeSyncV1.1** → **Add file → Upload files**
2. Bu klasörün **içindeki her şeyi** (app, assets, config, includes klasörleri ve dosyalar) sürükleyip bırakın. Eski dosyaların üzerine yazılır.
3. **Commit changes**. Vercel 1–2 dakikada yayına alır.
4. Telefonda ana ekrandaki uygulamayı **tamamen kapatıp** (son uygulamalardan kaydırın) yeniden açın. İlk açılışta yeni sürüm kendini kurar; ikinci açılıştan itibaren hızlanma tam hissedilir.

## Ne düzeldi?

- **Sunucu artık Frankfurt'ta.** Uygulama ABD'de (Washington) çalışıp her sorguda Almanya'daki veritabanına gidip geliyordu. Artık ikisi aynı şehirde (`vercel.json` → `regions: fra1`).
- **Sorgu başına 3 yerine 1 ağ turu.** Veritabanı bağlantısı sadeleştirildi (`config/db.php`). Özet sayfası ~39 gidiş-dönüşten ~13'e indi.
- **Antrenman sayfasının donması giderildi.** Her istekte tabloyu kilitleyen eski bir veritabanı komutu (`ALTER TABLE workouts…`) kaldırıldı. Antrenman sayfası aynı anda 3–4 istek attığı için bunlar birbirini bekliyor, sayfa açılmıyor gibi görünüyordu.
- **Tüm API'ler tek, sıcak sunucu fonksiyonunda.** Her modülün ayrı "soğuk başlatma" beklemesi kalktı.
- **Yeni Service Worker (v4):** Bootstrap, ikonlar, yazı tipi, grafik kütüphanesi ve uygulama dosyaları telefonda saklanıyor; modül geçişinde yalnızca sayfanın kendisi iniyor. İnternet yoksa "Bağlantı yok" ekranı çıkıyor ve bağlantı gelince kendiliğinden yenileniyor.
- **Anında geri bildirim:** Alt menüye dokunduğunuz an sekme seçili görünür, üstte ilerleme çubuğu akar; parmağınız menüye değdiği anda sayfa arka planda inmeye başlar.
- Eski iPhone'larda (iOS 14 öncesi) İlaçlar sayfasındaki bir JavaScript sözdizimi uyumlu hale getirildi.

## Hâlâ açılmayan sayfa olursa

Telefonda o sayfayı açıp ekran görüntüsü atın ya da bilgisayarda Claude'un tarayıcı panelinde bir kez giriş yapın; canlı sitede doğrudan kontrol edebilirim.

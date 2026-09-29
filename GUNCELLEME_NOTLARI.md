# OptiLifeSync 2.0 — Güncelleme Notları

## GitHub'a yükleme (bilgisayarda kurulum gerekmez)

1. **github.com/onrgdl/OptiLifeSyncV1.1** deposunu açın.
2. **Add file → Upload files** seçin.
3. Bu klasörün **içindeki her şeyi** (`android-apk.yml` hariç: api, app, assets, capacitor-app, database, includes klasörleri ve diğer dosyalar) sürükleyip bırakın. Klasör yapısı korunur, eski dosyaların üzerine yazılır.
4. Aşağıda **Commit changes** butonuna basın.
5. Vercel birkaç dakika içinde siteyi kendiliğinden günceller.

### Android derleme dosyası (tek seferlik, 1 dakika)

APK'yı GitHub'ın derlemesi için bir ayar dosyası gerekiyor. Nokta ile başlayan klasörler bu pakete konamadığı için bu dosyayı elle oluşturun:

1. Depoda **Add file → Create new file** seçin.
2. Dosya adı kutusuna tam olarak şunu yazın: `.github/workflows/android-apk.yml`
3. Bu klasördeki **`android-apk.yml`** dosyasını Not Defteri ile açın, tüm içeriğini kopyalayıp GitHub'daki metin alanına yapıştırın.
4. **Commit changes** deyin. (Bu `android-apk.yml` dosyasını ayrıca depoya yüklemenize gerek yok.)

## Android uygulaması (APK)

- Dosyalar yüklenince GitHub **Actions** sekmesinde "Android APK" işi otomatik başlar (5–8 dakika).
- Bitince APK, deponun **Releases** bölümünde "OptiLifeSync Android (son sürüm)" başlığıyla yayınlanır. Siteye telefonla girip menüde **Daha → Android Uygulaması** sayfasından da indirebilirsiniz.
- Depo gizliyse (private) indirme bağlantısı yalnızca GitHub'a giriş yapmışken çalışır; bu durumda APK'yı Actions → ilgili çalışma → **Artifacts** bölümünden indirin.
- İlk açılışta **İlaç & Alarmlar → Alarm durumu** kartındaki kırmızı maddelere "Düzelt" deyin ve "5 sn sonra test alarmı" ile deneyin.

## Veritabanı

`supplement_logs` tablosuna `scheduled_time` sütunu eklendi (dozun hangi alarm saatine ait olduğu). Bu değişiklik Supabase'e **zaten uygulandı**; ayrıca bir şey yapmanıza gerek yok. SQL'i `database/migrations/2026_09_add_scheduled_time.sql` dosyasında bulabilirsiniz.

## Yenilikler

- **Yeni arayüz:** Açık / koyu / otomatik tema, yeni kenar menü, telefonda alt menü ve "Daha" paneli, tüm sayfalar yeniden tasarlandı.
- **Gerçek alarm (Android):** Alarmlar telefonun alarm sistemine kurulur; uygulama kapalıyken, ekran kilitliyken ve telefon yeniden başladıktan sonra çalar, susturana kadar devam eder. Kilit ekranından **Aldım / Ertele / Atla**.
- **İlaç takibi:** Bugünün doz listesi, alındı/kaçırıldı durumu, 7 günlük uyum oranı, su/öğün/antrenman için özel hatırlatıcılar, tamamlanan tedavi arşivi.
- **Beslenme:** Gün gün geçmiş, öğünlere göre gruplama, enerji dağılımı, "Sık yenenler" ile tek dokunuşla ekleme, yapay zeka olmadan elle ekleme, yapay zeka sonuçlarını kaydetmeden önce düzeltme.
- **Özet:** Kalori halkası, makro çubukları, doz listesi, su şişesi, günlük kilo kaydı ve trendi, haftalık görünüm.
- **Antrenman:** Egzersiz günlüğü (set/tekrar/kg/dakika), kişisel rekorlar, tahmini 1RM, haftalık hacim ve gelişim grafikleri.
- **Raporlar:** Kalori, protein, su, kilo ve ilaç uyumu grafikleri; gün gün döküm; yapay zeka koç değerlendirmesi.
- **Düzeltmeler:** Girişten sonra istenen sayfaya yönlendirme hatası, öğün türü boş gelince kaydın başarısız olması, Gemini anahtarı yokken elle kaydın engellenmesi, 1,1 MB'lık ikonların her sayfada yüklenmesi.

# 🚀 OptiLifeSync — Vercel & Supabase Canlı Yayınlama Rehberi

Bu rehber, **OptiLifeSync** sağlık ve beslenme takip sisteminizi **Vercel (`.vercel.app`)** üzerinde sıfır maliyetle yayınlamanızı ve **Supabase (Cloud PostgreSQL)** veritabanına bağlayarak yüksek performanslı, kalıcı ve sınırsız bir bulut altyapısına kavuşturmanızı sağlar.

---

## 🌟 Neden Vercel + Supabase?

| Özellik | Yerel / Tek Başına Vercel | Vercel + Supabase |
| :--- | :--- | :--- |
| **Alan Adı** | `localhost` (sadece yerel) | **`https://proje-adiniz.vercel.app`** (ücretsiz SSL dahil) |
| **Veri Kalıcılığı** | Vercel'in kendi diski salt-okunurdur (geçicidir) | **%100 Kalıcı Bulut Veritabanı** (Supabase PostgreSQL) |
| **Bağlantı Havuzu** | Standart veritabanlarında bağlantı tükenmesi olabilir | **Supabase Transaction Pooler (Port 6543)** ile binlerce anlık istek |
| **Erişilebilirlik** | Sadece kendi bilgisayarınız | **Tüm telefon, tablet ve bilgisayarlardan 7/24 erişim** |
| **Maliyet** | — | **%100 Ücretsiz Planlar (Free Tier)** |

---

## 📋 4 Kolay Adımda Kurulum

### 1. Adım: Supabase Projesi Oluşturun ve Bağlantı Bilgisini Alın

1. [supabase.com](https://supabase.com) adresine gidin ve ücretsiz bir hesap açın / giriş yapın.
2. **"New Project"** butonuna tıklayın:
   - **Name:** `optilifesync` (veya istediğiniz bir isim)
   - **Database Password:** Güçlü bir şifre belirleyin (bu şifreyi unutmayın).
   - **Region:** `Central EU (Frankfurt)` veya Türkiye'ye en yakın bölgeyi seçin.
3. Projeniz oluştuktan sonra sol menüden **Project Settings** (Dişli çark) $\rightarrow$ **Database** bölümüne gidin.
4. **Connection string** alanına inin ve **URI** sekmesini seçin:
   - Modu **Transaction** (Port: `6543`) olarak seçin.
   - Kopyalayacağınız bağlantı dizesi şu formattadır:
     ```text
     postgresql://postgres.[PROJECT-REF]:[ŞİFRENİZ]@aws-0-[BÖLGE].pooler.supabase.com:6543/postgres?sslmode=require
     ```
   - `[ŞİFRENİZ]` kısmına 2. adımda belirlediğiniz veritabanı şifrenizi yazın.

---

### 2. Adım: Supabase Veritabanı Şemasını ve Tabloları Yükleyin

Projenizde hazır olarak bulunan `database/schema_supabase.sql` dosyası; 11 tablonun tamamını, otomatik `updated_at` tetikleyicilerini, demo kullanıcıyı, takviyeleri, antrenman programını ve alarmları tek seferde kurar.

1. Supabase panelinde sol menüden **SQL Editor** simgesine (`>_`) tıklayın.
2. **"New query"** butonuna basın.
3. Projenizdeki `database/schema_supabase.sql` dosyasının **tüm içeriğini** kopyalayıp buraya yapıştırın.
4. Sağ alttaki yeşil **"Run"** butonuna basın.
5. *"Success. No rows returned"* mesajını gördüğünüzde veritabanınız tüm başlangıç verileriyle birlikte hazır hale gelmiştir!

> 💡 **Alternatif Otomatik Kurulum:** İsterseniz bu adımı atlayıp Vercel'e deploy ettikten sonra doğrudan `https://proje-adiniz.vercel.app/setup` adresini tarayıcınızda açıp **"Tabloları Otomatik Kur"** butonuna da basabilirsiniz!

---

### 3. Adım: Projeyi Vercel'e Dağıtın (Deploy Edin)

#### Yöntem A: GitHub ile (Önerilen & En Kolay)

1. Proje klasörünüzde hazır bulunan **`push_to_github.bat`** dosyasına çift tıklayın (veya terminalden çalıştırın).
   - Sizden GitHub repository URL'nizi isteyecektir (Örn: `https://github.com/KULLANICI_ADINIZ/optilifesync.git`).
   - URL'yi yapıştırıp `Enter`'a bastığınızda tüm dosyalar (`main` dalı) otomatik olarak GitHub'a yüklenecektir.
2. [vercel.com](https://vercel.com) adresine gidin ve GitHub hesabınızla giriş yapın.
3. **"Add New..."** $\rightarrow$ **"Project"** butonuna tıklayın ve GitHub deponuzu seçin (**Import**).
4. **Environment Variables** (Ortam Değişkenleri) açılır kutusunu genişletin ve şu 2 değişkeni ekleyin:
   - **`DATABASE_URL`**: 1. Adımda kopyaladığınız Supabase bağlantı dizesi.
   - **`GEMINI_API_KEY`**: Google AI Studio'dan aldığınız Gemini API anahtarınız.
5. **"Deploy"** butonuna tıklayın.
6. Yaklaşık 30-45 saniye içinde projeniz derlenecek ve size özel bir `https://optilifesync-xxx.vercel.app` bağlantısı verilecektir!

#### Yöntem B: Vercel CLI ile (Komut Satırından)

Bilgisayarınızda Node.js yüklüyse terminalden:
```bash
npm install -g vercel
vercel login
vercel
```
Sorulan sorulara `Enter` ile varsayılan yanıtları verin. Ardından ortam değişkenlerini tanımlayın:
```bash
vercel env add DATABASE_URL
vercel env add GEMINI_API_KEY
vercel --prod
```

---

### 4. Adım: Doğrulama ve İlk Kullanım

1. Tarayıcınızda `https://proje-adiniz.vercel.app` adresine gidin.
   - Sistem sizi doğrudan modern **Dashboard** ekranına yönlendirecektir.
2. Bağlantı durumunu kontrol etmek için:
   - `https://proje-adiniz.vercel.app/setup` adresine girin.
   - **PostgreSQL / Supabase** yeşil rozetini ve 11 tablonun tamamının **"Mevcut"** olduğunu göreceksiniz.
3. Tebrikler! Artık:
   - BMR/TDEE hesaplayıcınız,
   - Beslenme ve Gemini Vision fotoğrafla yemek analiziniz,
   - Akıllı dinamik su takipçiniz,
   - İlaç/takviye alarmlarınız ve antrenman modülünüz,
   Vercel üzerinde dünya standartlarında bir bulut altyapısıyla çalışmaktadır.

---

## 🔧 Sorun Giderme (FAQ)

### S: Vercel'de "Erişim Reddedildi (403)" hatası alıyorum, neden?
**C:** Projede yerel ağ güvenliği Vercel ortamını otomatik tanıyacak şekilde ayarlanmıştır (`VERCEL`, `VERCEL_ENV` ve `HTTP_X_VERCEL_ID`). Vercel üzerinde hiçbir IP engeliyle karşılaşmazsınız.

### S: Şifremde özel karakterler (`@`, `#`, `%`) var, bağlantı hatası alıyorum?
**C:** Şifrenizde `@` gibi karakterler varsa URI formatında URL-encode edilmelidir (örneğin `@` yerine `%40`). Veya Supabase ayarlarından sadece harf ve rakamlardan oluşan güçlü bir şifre belirleyebilirsiniz.

### S: Supabase bağlantı portu 5432 mi 6543 mü olmalı?
**C:** Vercel serverless (sunucusuz) fonksiyonlar kullandığı için **6543 (Transaction Pooler)** modu önerilir. Bu mod, Vercel'in anlık çok sayıda istek attığı durumlarda veritabanı bağlantı limitine takılmasını önler.

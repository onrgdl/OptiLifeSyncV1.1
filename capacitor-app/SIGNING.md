# Özel imza anahtarı (isteğe bağlı)

> **Bu adım tamamen isteğe bağlıdır.** Hiçbir şey yapmazsanız APK, depodaki
> `native/debug.keystore` ile imzalanmaya devam eder ve her şey şimdiki gibi çalışır.
> Bu anahtar herkese açık depoda durduğu için, yalnızca kendi anahtarınızla imzalamak
> isterseniz aşağıdakileri yapın.

## 1) Anahtarı bir kez oluşturun

Bilgisayarınızda Java (JDK) kuruluysa bir terminal / PowerShell açıp çalıştırın:

```
keytool -genkeypair -v -keystore optilifesync-release.keystore -alias optilifesync -keyalg RSA -keysize 2048 -validity 10000
```

Sizden bir şifre ve ad/soyad gibi bilgiler ister. Girdiğiniz **şifreyi not alın**.
Oluşan `optilifesync-release.keystore` dosyasını **kaybetmeyin ve kimseyle paylaşmayın**
(depoya yüklemeyin). Kaybederseniz uygulamayı güncelleyemezsiniz.

## 2) Dosyayı base64 metnine çevirin

**Windows PowerShell:**

```
[Convert]::ToBase64String([IO.File]::ReadAllBytes("optilifesync-release.keystore")) | Set-Content keystore.b64.txt
```

**macOS / Linux:**

```
base64 -w0 optilifesync-release.keystore > keystore.b64.txt     # Linux
base64 -i optilifesync-release.keystore -o keystore.b64.txt     # macOS
```

`keystore.b64.txt` dosyasının içindeki tüm metni kopyalayın (bir sonraki adımda yapıştıracaksınız).
İş bitince bu metin dosyasını silin.

## 3) GitHub'a 4 gizli bilgi (secret) ekleyin

GitHub'da depo sayfası → **Settings** → soldan **Secrets and variables** → **Actions**
→ **New repository secret**. Aşağıdaki 4 adı birebir böyle yazın:

| Ad | Değer |
|----|-------|
| `ANDROID_KEYSTORE_B64` | `keystore.b64.txt` içindeki uzun metin |
| `ANDROID_KEYSTORE_PASSWORD` | 1. adımda belirlediğiniz keystore şifresi |
| `ANDROID_KEY_ALIAS` | `optilifesync` (komuttaki `-alias` değeri) |
| `ANDROID_KEY_PASSWORD` | anahtar şifresi (soru sorulduğunda aynısını verdiyseniz keystore şifresiyle aynıdır) |

4'ü de dolu olursa bir sonraki derleme (Actions → **Android APK** → **Run workflow**)
APK'yı sizin anahtarınızla imzalar. Biri eksikse eski debug anahtarı kullanılır.

## Önemli uyarı

İmza anahtarı değişince Android bunu "farklı uygulama" sayar: telefonda **debug anahtarıyla
imzalı eski uygulamayı bir kez kaldırmanız (uninstall)** ve yeni APK'yı sıfırdan kurmanız gerekir,
üstüne güncelleme yapılamaz. Endişelenmeyin: alarmlar ve veriler sunucuda durur; giriş
yaptığınızda alarmlar sunucudan yeniden yüklenir, hiçbir şey kaybolmaz.
Bu geçişten sonra gelecek tüm sürümler yeni anahtarla imzalanır ve normal şekilde üstüne güncellenir.

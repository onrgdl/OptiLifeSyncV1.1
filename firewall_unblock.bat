@echo off
chcp 65001 >nul
echo ====================================================================
echo   OptiLifeSync - Apache Güvenlik Duvarı Engeli Kaldırma Aracı
echo ====================================================================
echo.
echo Bu araç, Windows Güvenlik Duvarı'ndaki Apache engellemesini kaldırır
echo ve telefonunuzun (10.10.18.50) adresine sorunsuz bağlanmasını sağlar.
echo.

:: Yönetici yetkisi kontrolü
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [UYARI] Bu dosya Yönetici olarak çalıştırılmalıdır!
    echo Lütfen bu dosyaya SAĞ TIKLAYIP "Yönetici olarak çalıştır" seçeneğini seçin.
    echo.
    pause
    exit /b
)

echo [*] Eski engelleyici kurallar temizleniyor...
netsh advfirewall firewall delete rule name="Apache HTTP Server" >nul 2>&1
netsh advfirewall firewall delete rule name="OptiLifeSync Port 80" >nul 2>&1

echo [*] Apache HTTP Server için gelen bağlantı izni ekleniyor...
netsh advfirewall firewall add rule name="Apache HTTP Server (OptiLifeSync)" dir=in action=allow program="D:\webs\apache\bin\httpd.exe" enable=yes profile=any

echo [*] Port 80 için gelen TCP izni ekleniyor...
netsh advfirewall firewall add rule name="OptiLifeSync Port 80 (HTTP)" dir=in action=allow protocol=TCP localport=80 enable=yes profile=any

echo.
echo ====================================================================
echo [BAŞARILI] Güvenlik duvarı engeli kaldırıldı!
echo Artık telefonunuzdan http://10.10.18.50/Gyp/dashboard.php adresine
echo sorunsuz ve kesintisiz şekilde erişebilirsiniz.
echo ====================================================================
echo.
pause

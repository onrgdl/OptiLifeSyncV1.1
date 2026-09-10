@echo off
chcp 65001 >nul
title OptiLifeSync - GitHub Senkronizasyon Araci

echo ======================================================
echo    OptiLifeSync - GitHub Senkronizasyon Araci
echo ======================================================
echo.

set "GIT_CMD=C:\Users\onurgudul\AppData\Local\Programs\Git\cmd\git.exe"

if not exist "%GIT_CMD%" (
    where git >nul 2>&1
    if %errorlevel% equ 0 (
        set "GIT_CMD=git"
    ) else (
        echo [HATA] Git bulunamadi! Lutfen Git'in kurulu oldugundan emin olun.
        pause
        exit /b 1
    )
)

"%GIT_CMD%" remote get-url origin >nul 2>&1
if %errorlevel% neq 0 (
    echo [BILGI] Henuz bir GitHub deposu baglanmamis.
    echo.
    echo Lutfen GitHub'da olusturdugunuz deponun adresini yapistirin:
    echo (Ornek: https://github.com/KULLANICI_ADINIZ/optilifesync.git)
    echo.
    set /p REPO_URL="GitHub Repo URL: "
    if "%REPO_URL%"=="" (
        echo [HATA] Gecerli bir repo URL'si girmediniz.
        pause
        exit /b 1
    )
    "%GIT_CMD%" remote add origin "%REPO_URL%"
    echo [BASARILI] GitHub baglantisi eklendi: %REPO_URL%
)

echo.
echo [1/3] Degisiklikler taranip hazirlaniyor...
"%GIT_CMD%" add .

echo [2/3] Commit kaydediliyor...
"%GIT_CMD%" commit -m "update: OptiLifeSync latest release" >nul 2>&1

echo [3/3] GitHub'a gonderiliyor (Push)...
"%GIT_CMD%" push -u origin main --force

if %errorlevel% equ 0 (
    echo.
    echo ======================================================
    echo    TEBRIKLER! Tum kodlar GitHub'a basariyla gonderildi!
    echo    Vercel otomatik olarak yeni surumu dagitmaya basladi.
    echo ======================================================
) else (
    echo.
    echo [UYARI] Gonderim sirasinda bir sorun olustu.
    echo GitHub yetkilendirme penceresi acildiysa lutfen giris yapin.
)

echo.
pause

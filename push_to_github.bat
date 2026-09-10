@echo off
chcp 65001 >nul
title OptiLifeSync - GitHub Senkronizasyon Araci

:: Calisma dizinini bat dosyasinin bulundugu klasore sabitle
cd /d "%~dp0"

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

:: Git repository kontrolu
if not exist ".git" (
    echo [BILGI] Git deposu baslatiliyor...
    "%GIT_CMD%" init
    "%GIT_CMD%" branch -M main
)

:: Remote URL kontrolu ve otomatik tanimlama
"%GIT_CMD%" remote get-url origin >nul 2>&1
if %errorlevel% neq 0 (
    echo [BILGI] GitHub deposu baglaniyor...
    "%GIT_CMD%" remote add origin "https://github.com/onrgdl/OptiLifeSyncV1.1.git"
)

echo Bagli GitHub Deposu:
"%GIT_CMD%" remote get-url origin
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

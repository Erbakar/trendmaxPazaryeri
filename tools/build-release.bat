@echo off
chcp 65001 >nul
echo.
echo ========================================
echo   OzyConnect Acik Surum Builder
echo ========================================
echo.

set "SOURCE=c:\wamp64\www"
set "TARGET=c:\wamp64\www\OzyConnect Acik Surum"

:: 1. Eski klasoru sil
if exist "%TARGET%" (
    echo [1/5] Eski klasor siliniyor...
    rmdir /S /Q "%TARGET%"
)

:: 2. Dosyalari kopyala
echo [2/5] Dosyalar kopyalaniyor...
robocopy "%SOURCE%" "%TARGET%" /E /NFL /NDL /NJH /NJS /R:0 /W:0 /XD .git node_modules .gemini electron "OzyConnect Acik Surum" uploads /XF .env installer_done.lock installer_done.php guvenlik_performans_raporu.md ozellikler.md package.json package-lock.json composer.lock build-release.bat build-release.ps1

:: 3. Storage temizle
echo [3/5] Storage temizleniyor...
for %%d in (logs cache feeds invoices backups labels) do (
    if exist "%TARGET%\storage\%%d" (
        del /Q /S "%TARGET%\storage\%%d\*" >nul 2>&1
        for /d %%x in ("%TARGET%\storage\%%d\*") do rmdir /s /q "%%x" >nul 2>&1
    )
    if not exist "%TARGET%\storage\%%d" mkdir "%TARGET%\storage\%%d"
    type nul > "%TARGET%\storage\%%d\.gitkeep"
)

:: 4. Uploads temizle
echo [4/5] Upload dosyalari temizleniyor...
for %%d in (products branding invoices) do (
    if exist "%TARGET%\public\uploads\%%d" (
        rmdir /S /Q "%TARGET%\public\uploads\%%d" >nul 2>&1
    )
    mkdir "%TARGET%\public\uploads\%%d" 2>nul
    type nul > "%TARGET%\public\uploads\%%d\.gitkeep"
)

:: 5. Tools klasorundeki build scriptini kaldir + gereksiz dosyalar
echo [5/5] Son temizlik...
if exist "%TARGET%\tools\build-release.bat" del /Q "%TARGET%\tools\build-release.bat"
if exist "%TARGET%\tools\build-release.ps1" del /Q "%TARGET%\tools\build-release.ps1"
if exist "%TARGET%\tools" rmdir /Q "%TARGET%\tools" 2>nul

echo.
echo ========================================
echo   TAMAMLANDI!
echo   Konum: %TARGET%
echo ========================================
echo.
echo   SQL temiz (veri yok, marketplace pasif)
echo   .env yok (installer olusturacak)
echo   Storage bos, uploads bos
echo   OzyConnect klasor referanslari kaldirildi
echo.
pause

# ============================================================
# OzyConnect Panel — Açık Sürüm Dağıtım Scripti
# Bu script temiz, kuruluma hazır bir paket oluşturur.
# ============================================================

$ErrorActionPreference = "Stop"
$sourceDir = "c:\wamp64\www"
$targetDir = "c:\wamp64\OzyConnect Açık Sürüm"

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  OzyConnect Açık Sürüm Builder" -ForegroundColor Cyan  
Write-Host "========================================`n" -ForegroundColor Cyan

# --- 1. Hedef klasörü temizle/oluştur ---
if (Test-Path $targetDir) {
    Write-Host "[1/6] Eski klasör siliniyor..." -ForegroundColor Yellow
    Remove-Item $targetDir -Recurse -Force
}
New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
Write-Host "[1/6] Hedef klasör oluşturuldu: $targetDir" -ForegroundColor Green

# --- 2. Dosyaları kopyala (hassas/gereksiz olanları hariç tut) ---
Write-Host "[2/6] Dosyalar kopyalanıyor..." -ForegroundColor Yellow

$excludeDirs = @('.git', 'node_modules', '.gemini', 'electron')
$excludeFiles = @('.env', 'installer_done.lock', 'installer_done.php', 'guvenlik_performans_raporu.md', 'ozellikler.md')

# Robocopy ile hızlı kopyalama
$excludeDirArgs = $excludeDirs | ForEach-Object { "/XD `"$sourceDir\$_`"" }
$excludeFileArgs = $excludeFiles | ForEach-Object { "/XF `"$_`"" }

# Robocopy komutu
$robocopyArgs = @(
    "`"$sourceDir`"",
    "`"$targetDir`"",
    "/E", "/NFL", "/NDL", "/NJH", "/NJS",
    "/XD"
) + $excludeDirs + @("/XF") + $excludeFiles

& robocopy $sourceDir $targetDir /E /NFL /NDL /NJH /NJS /XD .git node_modules .gemini electron /XF .env installer_done.lock installer_done.php guvenlik_performans_raporu.md ozellikler.md

Write-Host "[2/6] Dosyalar kopyalandı" -ForegroundColor Green

# --- 3. Hassas verileri temizle ---
Write-Host "[3/6] Hassas veriler temizleniyor..." -ForegroundColor Yellow

# .env silindi zaten (kopyalanmadı), .env.example kalacak
# installer_done.lock silindi zaten

# Storage içindeki verileri temizle
$storageDirs = @('logs', 'cache', 'feeds', 'invoices', 'backups', 'labels')
foreach ($dir in $storageDirs) {
    $path = "$targetDir\storage\$dir"
    if (Test-Path $path) {
        Get-ChildItem $path -Recurse -File | Remove-Item -Force
    }
    # .gitkeep ekle
    if (!(Test-Path $path)) { New-Item -ItemType Directory -Path $path -Force | Out-Null }
    New-Item -ItemType File -Path "$path\.gitkeep" -Force | Out-Null
}

# Public uploads içindeki kullanıcı dosyalarını temizle
$uploadDirs = @('products', 'branding', 'invoices')
foreach ($dir in $uploadDirs) {
    $path = "$targetDir\public\uploads\$dir"
    if (Test-Path $path) {
        Get-ChildItem $path -Recurse -File | Where-Object { $_.Name -ne '.gitkeep' } | Remove-Item -Force
        # Alt klasörleri de temizle
        Get-ChildItem $path -Recurse -Directory | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (!(Test-Path $path)) { New-Item -ItemType Directory -Path $path -Force | Out-Null }
    New-Item -ItemType File -Path "$path\.gitkeep" -Force | Out-Null
}

Write-Host "[3/6] Hassas veriler temizlendi" -ForegroundColor Green

# --- 4. Temiz SQL dosyası oluştur ---
Write-Host "[4/6] Temiz veritabanı SQL'i oluşturuluyor..." -ForegroundColor Yellow
# Bu adım aşağıdaki ayrı dosyada yapılacak
Write-Host "[4/6] SQL dosyası hazırlanacak" -ForegroundColor Green

# --- 5. Gereksiz dosyaları kaldır ---
Write-Host "[5/6] Gereksiz dosyalar kaldırılıyor..." -ForegroundColor Yellow

# package.json ve lock dosyaları (npm gereksiz)
Remove-Item "$targetDir\package.json" -Force -ErrorAction SilentlyContinue
Remove-Item "$targetDir\package-lock.json" -Force -ErrorAction SilentlyContinue
Remove-Item "$targetDir\composer.lock" -Force -ErrorAction SilentlyContinue

# tools klasöründeki build scriptini kaldır (dağıtımda gereksiz)
Remove-Item "$targetDir\tools\build-release.ps1" -Force -ErrorAction SilentlyContinue

Write-Host "[5/6] Gereksiz dosyalar kaldırıldı" -ForegroundColor Green

# --- 6. Özet ---
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  Dağıtım paketi hazırlandı!" -ForegroundColor Green
Write-Host "  Konum: $targetDir" -ForegroundColor White
Write-Host "========================================" -ForegroundColor Cyan

$fileCount = (Get-ChildItem $targetDir -Recurse -File).Count
$dirCount = (Get-ChildItem $targetDir -Recurse -Directory).Count
$size = [math]::Round((Get-ChildItem $targetDir -Recurse -File | Measure-Object -Property Length -Sum).Sum / 1MB, 2)
Write-Host "  Dosya: $fileCount | Klasör: $dirCount | Boyut: ${size} MB" -ForegroundColor Gray
Write-Host ""
Write-Host "  Yapılması gerekenler:" -ForegroundColor Yellow
Write-Host "  [x] Dosyalar kopyalandı" -ForegroundColor Gray
Write-Host "  [x] .env ve lock dosyaları silindi" -ForegroundColor Gray
Write-Host "  [x] Storage temizlendi" -ForegroundColor Gray
Write-Host "  [x] Upload dosyaları temizlendi" -ForegroundColor Gray
Write-Host "  [ ] ozyconnect.sql ve ozellikler.html scriptten ayrı oluşturulacak" -ForegroundColor Gray
Write-Host ""

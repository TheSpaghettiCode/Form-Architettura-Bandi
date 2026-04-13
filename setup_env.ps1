$ErrorActionPreference = "Stop"

Write-Host "1. Creazione directory per PHP portatile locale..."
$phpDir = "$PSScriptRoot\php_portable"
if (-not (Test-Path $phpDir)) {
    New-Item -ItemType Directory -Path $phpDir | Out-Null
}

Write-Host "2. Download di PHP 8.3 per Windows..."
$phpZip = "$phpDir\php.zip"
if (-not (Test-Path $phpZip)) {
    # Download Thread Safe PHP 8.3 x64
    Invoke-WebRequest -Uri "https://windows.php.net/downloads/releases/php-8.3.4-Win32-vs16-x64.zip" -OutFile $phpZip
}

Write-Host "3. Estrazione PHP in corso..."
if (-not (Test-Path "$phpDir\php.exe")) {
    Expand-Archive -Path $phpZip -DestinationPath $phpDir -Force
}

Write-Host "4. Configurazione php.ini..."
$phpIni = "$phpDir\php.ini"
if (-not (Test-Path $phpIni)) {
    Copy-Item "$phpDir\php.ini-development" $phpIni
    # Abilitiamo le estensioni necessarie (gd, mbstring, zip per PhpSpreadsheet)
    (Get-Content $phpIni) | ForEach-Object {
        $_ -replace "^;extension_dir = `"ext`"", "extension_dir = `"ext`"" `
           -replace "^;extension=gd", "extension=gd" `
           -replace "^;extension=mbstring", "extension=mbstring" `
           -replace "^;extension=zip", "extension=zip" `
           -replace "^;extension=fileinfo", "extension=fileinfo"
    } | Set-Content $phpIni
}

Write-Host "5. Download di Composer..."
$composerPhar = "$PSScriptRoot\composer.phar"
if (-not (Test-Path $composerPhar)) {
    Invoke-WebRequest -Uri "https://getcomposer.org/download/latest-stable/composer.phar" -OutFile $composerPhar
}

Write-Host "6. Installazione libreria PhpSpreadsheet..."
Set-Location $PSScriptRoot
& "$phpDir\php.exe" $composerPhar require phpoffice/phpspreadsheet

Write-Host "`n=== TUTTO PRONTO! ==="
Write-Host "Per avviare il server, esegui il file avvia_server.bat che sto per creare."

$batContent = "@echo off`n$phpDir\php.exe -S localhost:8000"
Set-Content -Path "$PSScriptRoot\avvia_server.bat" -Value $batContent

Write-Host "Ora puoi avviare il server!"

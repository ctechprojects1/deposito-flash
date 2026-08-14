# =============================================================================
#  deploy-build.ps1
#  Compila o React e integra o build ao Laravel:
#    - frontend/dist/assets      -> public/assets
#    - frontend/dist/index.html  -> resources/views/spa.blade.php
#
#  Rode na RAIZ do projeto:   .\deploy-build.ps1
#  Depois suba o projeto para a HostGator (ver DEPLOY.md).
# =============================================================================

$ErrorActionPreference = "Stop"
$root         = $PSScriptRoot
$frontend     = Join-Path $root "frontend"
$dist         = Join-Path $frontend "dist"
$publicAssets = Join-Path $root "public\assets"
$spaView      = Join-Path $root "resources\views\spa.blade.php"

Write-Host "==> Compilando o frontend (Vite)..." -ForegroundColor Cyan
Push-Location $frontend
npm install
npm run build
Pop-Location

if (-not (Test-Path (Join-Path $dist "index.html"))) {
    throw "Build nao gerou index.html. Verifique erros do 'npm run build'."
}

Write-Host "==> Limpando assets antigos em public\assets..." -ForegroundColor Cyan
if (Test-Path $publicAssets) { Remove-Item $publicAssets -Recurse -Force }
New-Item -ItemType Directory -Force -Path $publicAssets | Out-Null

Write-Host "==> Copiando assets do build..." -ForegroundColor Cyan
Copy-Item (Join-Path $dist "assets\*") $publicAssets -Recurse -Force

# Copia quaisquer arquivos estaticos soltos do build (favicon, etc.), menos o index.html
Get-ChildItem $dist -File | Where-Object { $_.Name -ne "index.html" } | ForEach-Object {
    Copy-Item $_.FullName (Join-Path $root "public") -Force
}

Write-Host "==> Publicando index.html como resources\views\spa.blade.php..." -ForegroundColor Cyan
Copy-Item (Join-Path $dist "index.html") $spaView -Force

Write-Host ""
Write-Host "OK! Build integrado ao Laravel." -ForegroundColor Green
Write-Host "Proximo passo: subir os arquivos para a HostGator (veja DEPLOY.md)." -ForegroundColor Green

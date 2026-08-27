# Збирає архів для заливання на хостинг без SSH.
#
# Запуск із кореня проєкту:
#   powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1
#
# Результат: release/school-food-РРРР-ММ-ДД.zip — його заливаєте
# у файловий менеджер хостингу й розпаковуєте там.

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$php = 'C:\php83\php.exe'
$composer = 'C:\php83\composer.phar'
$stamp = Get-Date -Format 'yyyy-MM-dd'
$releaseDir = Join-Path $root 'release'
$staging = Join-Path $releaseDir 'staging'
$archive = Join-Path $releaseDir "school-food-$stamp.zip"

Set-Location $root

Write-Host '== 1/5 Збірка фронтенду ==' -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) { throw 'npm run build завершився помилкою' }

Write-Host '== 2/5 Залежності без dev-пакетів ==' -ForegroundColor Cyan
# --no-dev прибирає phpunit та інструменти розробки: на бойовому вони не потрібні
# і саме вони дають більшу частину ваги.
& $php $composer install --no-dev --optimize-autoloader --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'composer install завершився помилкою' }

Write-Host '== 3/5 Підготовка файлів ==' -ForegroundColor Cyan

if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Path $staging -Force | Out-Null

# Те, що їде на сервер. node_modules, tests і .git не потрібні.
$include = @(
    'app', 'bootstrap', 'config', 'database', 'lang',
    'public_html', 'resources', 'routes', 'storage', 'vendor', 'scripts',
    'artisan', 'composer.json', 'composer.lock'
)

foreach ($item in $include) {
    $source = Join-Path $root $item
    if (Test-Path $source) {
        Copy-Item $source -Destination $staging -Recurse -Force
    }
}

# Локальні кеші й логи в архів не потрапляють.
foreach ($junk in @(
    'storage\logs\*.log',
    'storage\framework\cache\data\*',
    'storage\framework\sessions\*',
    'storage\framework\views\*',
    'bootstrap\cache\*.php',
    'public_html\storage',
    'public_html\hot'
)) {
    $path = Join-Path $staging $junk
    Remove-Item $path -Recurse -Force -ErrorAction SilentlyContinue
}

# Полегшуємо vendor: документація й тести пакетів займають десятки мегабайтів
# і на роботу не впливають.
Write-Host '   чищу vendor від документації й тестів...' -ForegroundColor DarkGray
$vendorPath = Join-Path $staging 'vendor'
foreach ($pattern in @('tests', 'test', 'docs', 'doc', 'examples', '.github')) {
    Get-ChildItem $vendorPath -Directory -Recurse -Filter $pattern -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch 'src' } |
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
}
Get-ChildItem $vendorPath -File -Recurse -Include '*.md', '*.dist', '*.xml.dist', 'phpunit.xml' -ErrorAction SilentlyContinue |
    Remove-Item -Force -ErrorAction SilentlyContinue

Write-Host '== 4/5 Архівування ==' -ForegroundColor Cyan
if (Test-Path $archive) { Remove-Item $archive -Force }
Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $archive -CompressionLevel Optimal

Remove-Item $staging -Recurse -Force

Write-Host '== 5/5 Повертаю dev-залежності для локальної роботи ==' -ForegroundColor Cyan
& $php $composer install --no-interaction | Out-Null

$size = [math]::Round((Get-Item $archive).Length / 1MB, 1)

Write-Host ''
Write-Host "Готово: $archive ($size МБ)" -ForegroundColor Green
Write-Host ''
Write-Host 'Далі:' -ForegroundColor Yellow
Write-Host '  1. Залийте архів у файловий менеджер хостингу'
Write-Host '  2. Розпакуйте його там'
Write-Host '  3. Створіть .env за зразком .env.production.example'
Write-Host '  4. Відкрийте /maintenance/ВАШ_ТОКЕН/migrate'
Write-Host ''
Write-Host 'Докладно — у docs/deploy-without-ssh.md'

[CmdletBinding()]
param([string]$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path)
$ErrorActionPreference = 'Stop'
foreach ($item in @(@{Source='public/static/css/booking.css'; Pattern='public/static/css/booking.*.css'},@{Source='public/static/js/booking-calendar.js'; Pattern='public/static/js/booking-calendar.*.js'})) {
    $source = Join-Path $Root $item.Source
    $hash = (Get-FileHash $source -Algorithm SHA256).Hash.ToLowerInvariant().Substring(0,12)
    $directory = Split-Path (Join-Path $Root $item.Source)
    Get-ChildItem $directory -Filter ([IO.Path]::GetFileName($item.Pattern)) | Remove-Item -Force -ErrorAction SilentlyContinue
    $extension = [IO.Path]::GetExtension($source)
    $target = Join-Path $directory (([IO.Path]::GetFileNameWithoutExtension($source)) + '.' + $hash + $extension)
    Copy-Item $source $target -Force
    $relativeTarget = ('/' + $target.Substring((Join-Path $Root 'public').Length).TrimStart('\','/') -replace '\\','/')
    if ($item.Source -like '*booking.css') { $cssPath = $relativeTarget } else { $jsPath = $relativeTarget }
}
$configPath = Join-Path $Root 'config/static-assets.php'
$config = Get-Content -LiteralPath $configPath -Raw
$config = [regex]::Replace($config, "'booking_css'\s*=>\s*'[^']+'", "'booking_css' => '$cssPath'")
$config = [regex]::Replace($config, "'booking_js'\s*=>\s*'[^']+'", "'booking_js' => '$jsPath'")
Set-Content -LiteralPath $configPath -Value $config -Encoding UTF8
Write-Output $cssPath
Write-Output $jsPath

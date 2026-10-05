[CmdletBinding()]
param([string]$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path)
$ErrorActionPreference = 'Stop'
$sources = [ordered]@{
    booking_css = 'public/static/css/booking.css'
    booking_js = 'public/static/js/booking-calendar.js'
    admin_css = 'public/static/css/admin.css'
    admin_js = 'public/static/js/admin-auth.js'
}
$configPath = Join-Path $Root 'config/static-assets.php'
$config = Get-Content -LiteralPath $configPath -Raw
foreach ($key in $sources.Keys) {
    $source = Join-Path $Root $sources[$key]
    $hash = (Get-FileHash $source -Algorithm SHA256).Hash.ToLowerInvariant().Substring(0,12)
    $directory = Split-Path $source
    $extension = [IO.Path]::GetExtension($source)
    $stem = [IO.Path]::GetFileNameWithoutExtension($source)
    Get-ChildItem -LiteralPath $directory -File | Where-Object {
        $_.Name -match ('^' + [regex]::Escape($stem) + '\.[0-9a-f]{12}' + [regex]::Escape($extension) + '$')
    } | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
    $target = Join-Path $directory (([IO.Path]::GetFileNameWithoutExtension($source)) + '.' + $hash + $extension)
    Copy-Item $source $target -Force
    $relativeTarget = ('/' + $target.Substring((Join-Path $Root 'public').Length).TrimStart('\','/') -replace '\\','/')
    $pattern = "'$key'\s*=>\s*'[^']+'"
    if ([regex]::Matches($config, $pattern).Count -ne 1) { throw "Missing or duplicate static mapping: $key" }
    $config = [regex]::Replace($config, $pattern, "'$key' => '$relativeTarget'")
    Write-Output $relativeTarget
}
[IO.File]::WriteAllText($configPath, $config, [Text.UTF8Encoding]::new($false))

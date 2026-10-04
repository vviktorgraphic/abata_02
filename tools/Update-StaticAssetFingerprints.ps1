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
    Write-Output $target
}

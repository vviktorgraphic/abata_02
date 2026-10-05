[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ArchivePath
)

$ErrorActionPreference = 'Stop'
$archive = (Resolve-Path -LiteralPath $ArchivePath).Path
$entries = @(& tar -tzf $archive)
if ($LASTEXITCODE -ne 0) { throw 'Unable to list tar.gz archive.' }
$normalized = $entries | ForEach-Object { $_ -replace '^\./', '' }
if ($normalized | Where-Object { $_ -eq '.env' -or $_ -like '.env.*' -or $_ -like '.git/*' }) { throw 'Archive contains a secret or .git path.' }
if (-not $normalized.Contains('public/static/css/admin.css')) { throw 'Archive is missing public/static/css/admin.css.' }
if ($normalized | Where-Object { $_ -like 'public/assets/*' }) { throw 'Archive contains runtime public/assets.' }
if ($normalized | Where-Object { $_ -like 'tests/*' }) { throw 'Archive contains tests.' }

$stage = Join-Path ([IO.Path]::GetTempPath()) ('foglalo-verify-' + [guid]::NewGuid().ToString('N'))
try {
    New-Item -ItemType Directory -Path $stage | Out-Null
    & tar -xzf $archive -C $stage
    if ($LASTEXITCODE -ne 0) { throw 'Unable to extract archive.' }
    $mapping = Get-Content (Join-Path $stage 'config/static-assets.php') -Raw
    $cssMatch = [regex]::Match($mapping, "'booking_css'\s*=>\s*'(/static/css/booking\.[0-9a-f]{12}\.css)'")
    $jsMatch = [regex]::Match($mapping, "'booking_js'\s*=>\s*'(/static/js/booking-calendar\.[0-9a-f]{12}\.js)'")
    if (-not $cssMatch.Success -or -not $jsMatch.Success) { throw 'Runtime static asset mapping is missing valid fingerprinted paths.' }
    foreach ($pair in @(@{Mapped=$cssMatch.Groups[1].Value; Canonical='public/static/css/booking.css'}, @{Mapped=$jsMatch.Groups[1].Value; Canonical='public/static/js/booking-calendar.js'})) {
        $mappedRelative = $pair.Mapped.TrimStart('/')
        $mappedPath = Join-Path (Join-Path $stage 'public') $mappedRelative
        $canonicalPath = Join-Path $stage $pair.Canonical
        if (-not (Test-Path -LiteralPath $mappedPath)) { throw "Mapped asset is missing: $mappedRelative" }
        $hash = (Get-FileHash -LiteralPath $mappedPath -Algorithm SHA256).Hash.ToLowerInvariant().Substring(0,12)
        if ([IO.Path]::GetFileName($mappedPath) -notmatch "\.$hash\.") { throw "Asset filename fingerprint does not match bytes: $mappedRelative" }
        if (-not ((Get-FileHash $mappedPath).Hash -eq (Get-FileHash $canonicalPath).Hash)) { throw "Fingerprint copy differs from canonical source: $mappedRelative" }
    }
    if ($IsLinux -or $IsMacOS) {
        $badDirectories = @(& find $stage -type d ! -perm -0555)
        if ($badDirectories.Count -gt 0) { throw 'Archive contains a directory without traversal permissions.' }
    }
    Write-Output 'Release package verification passed.'
}
finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue }
}

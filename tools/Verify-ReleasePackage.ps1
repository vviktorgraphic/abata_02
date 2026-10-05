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
    $requiredAssets = [ordered]@{
        booking_css = '/static/css/booking.css'
        booking_js = '/static/js/booking-calendar.js'
        admin_css = '/static/css/admin.css'
        admin_js = '/static/js/admin-auth.js'
    }
    foreach ($key in $requiredAssets.Keys) {
        $canonical = $requiredAssets[$key]
        $extension = [IO.Path]::GetExtension($canonical)
        $stem = $canonical.Substring(0, $canonical.Length - $extension.Length)
        $pathPattern = [regex]::Escape($stem) + '\.[0-9a-f]{12}' + [regex]::Escape($extension)
        $matches = [regex]::Matches($mapping, "'$key'\s*=>\s*'($pathPattern)'")
        if ($matches.Count -ne 1 -or [regex]::Matches($mapping, "'$key'\s*=>").Count -ne 1) { throw "Runtime static asset mapping is missing valid fingerprinted paths: $key" }
        $mappedRelative = $matches[0].Groups[1].Value.TrimStart('/')
        $mappedPath = Join-Path (Join-Path $stage 'public') $mappedRelative
        $canonicalPath = Join-Path $stage ('public' + $canonical)
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

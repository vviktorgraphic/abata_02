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
if (-not ($normalized | Where-Object { $_ -match '^public/static/css/booking\.[0-9a-f]{12}\.css$' })) { throw 'Archive is missing fingerprinted booking CSS.' }
if (-not ($normalized | Where-Object { $_ -match '^public/static/js/booking-calendar\.[0-9a-f]{12}\.js$' })) { throw 'Archive is missing fingerprinted booking JS.' }
if (-not ($normalized | Where-Object { $_ -match '^public/static/css/booking\.[0-9a-f]{12}\.css$' })) { throw 'Archive is missing fingerprinted booking CSS.' }
if (-not ($normalized | Where-Object { $_ -match '^public/static/js/booking-calendar\.[0-9a-f]{12}\.js$' })) { throw 'Archive is missing fingerprinted booking JS.' }
if ($normalized | Where-Object { $_ -like 'public/assets/*' }) { throw 'Archive contains runtime public/assets.' }
if ($normalized | Where-Object { $_ -like 'tests/*' }) { throw 'Archive contains tests.' }

$stage = Join-Path ([IO.Path]::GetTempPath()) ('foglalo-verify-' + [guid]::NewGuid().ToString('N'))
try {
    New-Item -ItemType Directory -Path $stage | Out-Null
    & tar -xzf $archive -C $stage
    if ($LASTEXITCODE -ne 0) { throw 'Unable to extract archive.' }
    if ($IsLinux -or $IsMacOS) {
        $badDirectories = @(& find $stage -type d ! -perm -0555)
        if ($badDirectories.Count -gt 0) { throw 'Archive contains a directory without traversal permissions.' }
    }
    Write-Output 'Release package verification passed.'
}
finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue }
}

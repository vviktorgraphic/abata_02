[CmdletBinding()]
param(
    [string]$Commit = '',
    [string]$OutputDirectory = '',
    [switch]$IncludeTests
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repo 'release-packages'
}
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)

$gitExit = 0
if ([string]::IsNullOrWhiteSpace($Commit)) {
    $Commit = (& git -C $repo rev-parse HEAD).Trim()
    $gitExit = $LASTEXITCODE
}
if ($gitExit -ne 0 -or $Commit -notmatch '^[0-9a-fA-F]{7,40}$') {
    throw 'Commit must be a valid commit SHA.'
}

$tracked = @(& git -C $repo ls-tree -r --name-only $Commit)
if ($LASTEXITCODE -ne 0) { throw "Commit not found: $Commit" }
if ($tracked -contains '.env') {
    throw 'Refusing to package a commit that tracks an environment file.'
}

$releaseId = (& git -C $repo rev-parse --short $Commit).Trim()
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ('foglalo-release-' + [guid]::NewGuid().ToString('N'))
$payload = Join-Path $stage 'release'
$archive = Join-Path $stage 'source.tar'
$tarGz = Join-Path $OutputDirectory ('foglalo-' + $releaseId + '.tar.gz')
$manifest = Join-Path $OutputDirectory ('foglalo-' + $releaseId + '.manifest.txt')

try {
    New-Item -ItemType Directory -Force -Path $OutputDirectory, $payload | Out-Null
    & git -C $repo archive --format=tar --output=$archive $Commit
    if ($LASTEXITCODE -ne 0) { throw 'git archive failed.' }
    tar -xf $archive -C $payload
    Remove-Item $archive -Force

    if (-not $IncludeTests) {
        $tests = Join-Path $payload 'tests'
        if (Test-Path -LiteralPath $tests) { Remove-Item $tests -Recurse -Force }
    }
    Get-ChildItem -LiteralPath $payload -Force -Recurse | Where-Object {
        $_.Name -eq '.env' -or $_.Name -like '.env.*'
    } | Remove-Item -Force

    # tar preserves the POSIX mode bits from git archive; PowerShell ZIP extraction
    # does not reliably preserve traversable directory modes on shared Linux hosts.
    if (Test-Path -LiteralPath $tarGz) { Remove-Item $tarGz -Force }
    & tar -czf $tarGz -C $payload .
    if ($LASTEXITCODE -ne 0) { throw 'tar.gz packaging failed.' }

    $files = Get-ChildItem -LiteralPath $payload -File -Recurse | Sort-Object FullName
    $lines = @(
        'release_id=' + $releaseId
        'commit=' + $Commit
        'include_tests=' + $IncludeTests.IsPresent.ToString().ToLowerInvariant()
        'created_utc=' + [DateTime]::UtcNow.ToString('o')
        'files=' + $files.Count
        ''
        'sha256  path'
    )
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($payload.Length).TrimStart('\','/') -replace '\\','/'
        $lines += ((Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + $relative)
    }
    Set-Content -LiteralPath $manifest -Value $lines -Encoding UTF8
    Write-Output ('Package: ' + $tarGz)
    Write-Output ('Manifest: ' + $manifest)
}
finally {
    if (Test-Path -LiteralPath $stage) { Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue }
}

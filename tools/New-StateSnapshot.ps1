[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9._-]+(?:@[A-Za-z0-9.-]+)?$')]
    [string] $SshTarget,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^/[A-Za-z0-9._/-]+$')]
    [string] $ReleaseRoot,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^/[A-Za-z0-9._/-]+$')]
    [string] $SnapshotRoot,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^/[A-Za-z0-9._/-]+$')]
    [string] $DatabaseBackupFile,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^/[A-Za-z0-9._/-]+$')]
    [string] $DatabaseChecksumFile,

    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._-]{0,100}$')]
    [string] $SnapshotId = '',

    [ValidateRange(1, 65535)]
    [int] $SshPort = 22,

    [string] $IdentityFile = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

foreach ($remotePath in @($ReleaseRoot, $SnapshotRoot, $DatabaseBackupFile, $DatabaseChecksumFile)) {
    if ($remotePath -match '(^|/)\.\.(/|$)') {
        throw 'Remote paths must not contain parent-directory segments.'
    }
}

$core = Join-Path $PSScriptRoot 'New-StateSnapshot.Core.sh'
if (-not (Test-Path -LiteralPath $core -PathType Leaf)) {
    throw 'State snapshot core script is missing.'
}

$sshArguments = @(
    '-o', 'BatchMode=yes',
    '-o', 'PasswordAuthentication=no',
    '-p', $SshPort.ToString()
)
if (-not [string]::IsNullOrWhiteSpace($IdentityFile)) {
    if (-not (Test-Path -LiteralPath $IdentityFile -PathType Leaf)) {
        throw 'Identity file is missing.'
    }
    $sshArguments += @('-i', (Resolve-Path -LiteralPath $IdentityFile).Path)
}
$sshArguments += $SshTarget

$remoteArguments = @(
    'bash', '-s', '--',
    '--release-root', $ReleaseRoot,
    '--snapshot-root', $SnapshotRoot,
    '--database-backup', $DatabaseBackupFile,
    '--database-checksum', $DatabaseChecksumFile
)
if (-not [string]::IsNullOrWhiteSpace($SnapshotId)) {
    $remoteArguments += @('--snapshot-id', $SnapshotId)
}

Get-Content -LiteralPath $core -Raw | & ssh @sshArguments @remoteArguments
if ($LASTEXITCODE -ne 0) {
    throw 'Remote state snapshot failed; no final snapshot should be present.'
}

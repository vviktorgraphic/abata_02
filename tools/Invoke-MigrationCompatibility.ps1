[CmdletBinding()]
param(
    [switch] $KeepContainers,
    [switch] $UpgradeFrom010
)

$ErrorActionPreference = 'Stop'
$password = [guid]::NewGuid().ToString('N')
$engines = @(
    @{ Name = 'mysql'; Image = 'mysql:8.0'; Port = 33307 },
    @{ Name = 'mariadb'; Image = 'mariadb:10.6.28'; Port = 33306 }
)
$partialDirectory = Join-Path $PWD 'database/migrations-010-smoke'

try {
    New-Item -ItemType Directory -Force -Path $partialDirectory | Out-Null
    Get-ChildItem database/migrations -Filter '*.sql' | Where-Object { $_.BaseName -match '^(00[1-9]|010)_' } |
        Copy-Item -Destination $partialDirectory

    foreach ($engine in $engines) {
        $container = "rc2-migration-$($engine.Name)"
        try { docker rm -f $container 2>$null | Out-Null } catch { }
        docker run -d --name $container -e "MYSQL_ROOT_PASSWORD=$password" -e "MARIADB_ROOT_PASSWORD=$password" `
            -e MYSQL_DATABASE=booking_system -e MYSQL_USER=booking_user -e "MYSQL_PASSWORD=$password" `
            -e MARIADB_DATABASE=booking_system -e MARIADB_USER=booking_user -e "MARIADB_PASSWORD=$password" `
            -p "$($engine.Port):3306" $engine.Image | Out-Null

        Start-Sleep -Seconds 15

        $common = @('-e', 'DB_HOST=host.docker.internal', '-e', "DB_PORT=$($engine.Port)", '-e', 'DB_DATABASE=booking_system', '-e', 'DB_USERNAME=booking_user', "-e", "DB_PASSWORD=$password")
        if ($UpgradeFrom010) {
            docker compose run --rm --no-deps @common -e MIGRATION_DIRECTORY=/var/www/html/database/migrations-010-smoke app php bin/migrate.php
        }
        docker compose run --rm --no-deps @common app php bin/migrate.php
    }
}
finally {
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $partialDirectory
    if (-not $KeepContainers) {
        try { docker rm -f rc2-migration-mysql rc2-migration-mariadb 2>$null | Out-Null } catch { }
    }
}

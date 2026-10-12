[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$BaseUrl,

    [ValidateRange(1, 120)]
    [int]$TimeoutSeconds = 10
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

[Uri]$baseUri = $null
$validBaseUri = [Uri]::TryCreate($BaseUrl, [UriKind]::Absolute, [ref]$baseUri)
if (-not $validBaseUri -or
    $baseUri.Scheme -notin @('http', 'https') -or
    -not [string]::IsNullOrEmpty($baseUri.UserInfo) -or
    -not [string]::IsNullOrEmpty($baseUri.Query) -or
    -not [string]::IsNullOrEmpty($baseUri.Fragment)) {
    throw 'BaseUrl must be an absolute HTTP(S) URL without credentials, query or fragment.'
}

$root = $BaseUrl.TrimEnd('/')

function Get-HeaderValue {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Name
    )

    $value = $Response.Headers[$Name]
    if ($null -eq $value) {
        return ''
    }

    return (($value | ForEach-Object { [string]$_ }) -join ', ')
}

function Invoke-RequiredGet {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$ContentTypePrefix
    )

    try {
        $response = Invoke-WebRequest -Uri ($root + $Path) -Method Get -UseBasicParsing -TimeoutSec $TimeoutSeconds
    } catch {
        throw "GET $Path did not complete successfully."
    }

    if ($response.StatusCode -ne 200) {
        throw "GET $Path returned an unexpected status."
    }
    if ((Get-HeaderValue -Response $response -Name 'Content-Type') -notlike ($ContentTypePrefix + '*')) {
        throw "GET $Path returned an unexpected content type."
    }
    if ((Get-HeaderValue -Response $response -Name 'X-Content-Type-Options') -ne 'nosniff') {
        throw "GET $Path is missing the required nosniff header."
    }
    if ((Get-HeaderValue -Response $response -Name 'X-Frame-Options') -ne 'DENY') {
        throw "GET $Path is missing the required frame protection."
    }

    return $response
}

try {
    $health = Invoke-RequiredGet -Path '/health' -ContentTypePrefix 'application/json'
    try {
        $healthPayload = $health.Content | ConvertFrom-Json
    } catch {
        throw 'GET /health did not return valid JSON.'
    }
    if ($healthPayload.status -ne 'ok') {
        throw 'GET /health did not report status ok.'
    }
    Write-Output '[PASS] GET /health'

    $null = Invoke-RequiredGet -Path '/' -ContentTypePrefix 'text/html'
    Write-Output '[PASS] GET /'
    $login = Invoke-RequiredGet -Path '/admin/login' -ContentTypePrefix 'text/html'
    if ((Get-HeaderValue -Response $login -Name 'Cache-Control') -notmatch '(^|,\s*)no-store(,|$)') {
        throw 'GET /admin/login is missing the required no-store directive.'
    }
    if ((Get-HeaderValue -Response $login -Name 'Content-Security-Policy') -notmatch "frame-ancestors 'none'") {
        throw 'GET /admin/login is missing the required CSP frame protection.'
    }
    Write-Output '[PASS] GET /admin/login'

    Write-Output '[PASS] RC2 read-only smoke completed.'
    exit 0
} catch {
    Write-Error ('RC2 read-only smoke failed: ' + $_.Exception.Message)
    exit 1
}

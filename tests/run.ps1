<#
.SYNOPSIS
  Site Bloat Auditor test runner (Local by Flywheel on Windows).

.EXAMPLE
  .\tests\run.ps1                      # lint + security + regression + snapshot compare
  .\tests\run.ps1 -Suite lint
  .\tests\run.ps1 -Suite snapshot -Capture
  .\tests\run.ps1 -Fixtures create
  .\tests\run.ps1 -Fixtures cleanup            # dry run
  .\tests\run.ps1 -Fixtures cleanup -Confirm   # really remove fixtures

  Override environment detection with $env:SBA_PHP, $env:SBA_PHP_INI, $env:SBA_DB_PORT,
  $env:SBA_LOCAL_SITE (Local site name; default: the Local site whose folder contains
  this plugin).
#>
param(
    [ValidateSet('all', 'lint', 'security', 'regression', 'snapshot')] [string] $Suite = 'all',
    [ValidateSet('', 'create', 'cleanup')] [string] $Fixtures = '',
    [switch] $Capture,
    [switch] $Confirm,
    [string] $Report = ''
)
$ErrorActionPreference = 'Stop'
$PluginDir = Split-Path -Parent $PSScriptRoot
$TestsDir  = $PSScriptRoot
$LocalRoot = Join-Path $env:APPDATA 'Local'
$SiteName  = $env:SBA_LOCAL_SITE

# --- Environment detection (Local by Flywheel) ---------------------------------
# The site is $env:SBA_LOCAL_SITE if set, otherwise the Local site whose folder
# contains this plugin (no site name is hard-coded).
$site = $null
$sitesJson = Join-Path $LocalRoot 'sites.json'
if (Test-Path $sitesJson) {
    $sites = Get-Content $sitesJson -Raw | ConvertFrom-Json
    foreach ($p in $sites.PSObject.Properties) {
        $sitePath = [string]$p.Value.path
        if ($sitePath.StartsWith('~')) { $sitePath = Join-Path $HOME $sitePath.Substring(1).TrimStart('\', '/') }
        $sitePath = $sitePath.TrimEnd('\', '/') + '\'
        $match = if ($SiteName) { $p.Value.name -eq $SiteName } else { $PluginDir.StartsWith($sitePath, [StringComparison]::OrdinalIgnoreCase) }
        if ($match) { $site = $p.Value; $siteId = $p.Name }
    }
}
if (-not $site -and -not $env:SBA_PHP) { throw "Local site not found in sites.json for $PluginDir. Set `$env:SBA_LOCAL_SITE or `$env:SBA_PHP." }
$Php = $env:SBA_PHP
if (-not $Php -and $site) { $Php = Join-Path $LocalRoot ("lightning-services\php-{0}+0\bin\win64\php.exe" -f $site.services.php.version) }
$PhpIni = $env:SBA_PHP_INI
if (-not $PhpIni -and $site) { $PhpIni = Join-Path $LocalRoot "run\$siteId\conf\php\php.ini" }
$DbPort = $env:SBA_DB_PORT
if (-not $DbPort -and $site) { $DbPort = [string]$site.services.mysql.ports.MYSQL[0] }
if (-not (Test-Path $Php)) { throw "PHP not found ($Php). Set `$env:SBA_PHP." }

function Invoke-Php([string[]] $PhpArgs) {
    $base = @()
    if ($PhpIni) { $base += @('-c', $PhpIni) }
    if ($DbPort) { $base += @('-d', "mysqli.default_port=$DbPort") }
    $base += @('-d', 'display_startup_errors=0', '-d', 'display_errors=stderr')
    & $Php @base @PhpArgs 2>&1 | Where-Object { $_ -notmatch "php_imagick\.dll" } | ForEach-Object { "$_" }
}

$failures = @()

if ($Fixtures -eq 'create') {
    Invoke-Php @("$TestsDir\fixtures\create-fixtures.php"); if ($LASTEXITCODE) { exit $LASTEXITCODE }; exit 0
}
if ($Fixtures -eq 'cleanup') {
    $a = @("$TestsDir\fixtures\cleanup-fixtures.php"); if ($Confirm) { $a += '--confirm' }
    Invoke-Php $a; exit $LASTEXITCODE
}

if ($Suite -in 'all', 'lint') {
    Write-Host "`n=== PHP lint ===" -ForegroundColor Cyan
    $phps = @($Php) + (Get-ChildItem (Join-Path $LocalRoot 'lightning-services') -Directory -Filter 'php-*' -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' } | Where-Object { (Test-Path $_) -and ($_ -ne $Php) })
    foreach ($bin in $phps) {
        $ver = (& $bin -n -r 'echo PHP_VERSION;')
        $bad = 0; $n = 0
        Get-ChildItem $PluginDir -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch '\\(\.git|node_modules|vendor)\\' } | ForEach-Object {
            $n++
            $out = & $bin -n -l $_.FullName 2>&1
            if ($LASTEXITCODE -ne 0) { $bad++; Write-Host "  $out" -ForegroundColor Red }
        }
        Write-Host ("  PHP {0}: {1}/{2} files OK" -f $ver, ($n - $bad), $n)
        if ($bad) { $failures += "lint PHP $ver" }
    }
    $node = (Get-Command node -ErrorAction SilentlyContinue).Source
    if ($node) {
        Get-ChildItem (Join-Path $PluginDir 'assets\js') -Recurse -Filter *.js | ForEach-Object {
            & $node --check $_.FullName 2>&1 | Out-Null
            if ($LASTEXITCODE) { $failures += "node --check $($_.Name)"; Write-Host "  JS syntax error: $($_.Name)" -ForegroundColor Red }
            else { Write-Host "  JS OK: $($_.Name)" }
        }
    }
}

if ($Suite -in 'all', 'security') {
    Write-Host "`n=== Security ===" -ForegroundColor Cyan
    Invoke-Php @("$TestsDir\security\test-ajax-security.php") | Select-Object -Last 400 | ForEach-Object { if ($_ -match '\[FAIL\]|==|NOTE|read-only') { $_ } }
    if ($LASTEXITCODE) { $failures += 'security' }
}

if ($Suite -in 'all', 'regression') {
    Write-Host "`n=== Regression: media audit ===" -ForegroundColor Cyan
    $a = @("$TestsDir\regression\test-media-audit.php"); if ($Report) { $a += "--report=$Report" }
    Invoke-Php $a
    if ($LASTEXITCODE) { $failures += 'regression' }
}

if ($Suite -in 'all', 'snapshot') {
    Write-Host "`n=== Snapshots ===" -ForegroundColor Cyan
    $mode = if ($Capture) { 'capture' } else { 'compare' }
    Invoke-Php @("$TestsDir\snapshots\snapshot.php", $mode)
    if ($LASTEXITCODE) { $failures += 'snapshot' }
}

Write-Host ""
if ($failures.Count) { Write-Host ("FAILED: " + ($failures -join ', ')) -ForegroundColor Red; exit 1 }
Write-Host "ALL SELECTED SUITES PASSED" -ForegroundColor Green
exit 0

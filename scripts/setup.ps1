[CmdletBinding()]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $Options = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Show-Usage {
    Write-Host 'usage: npm run setup -- [--fresh] [--reinstall] [--skip-docker] [--skip-build] [--skip-e2e]'
    Write-Host '  --fresh        drop all data (public, rag, worker schemas) and re-seed the demo workspace'
    Write-Host '  --reinstall    run npm ci even when node_modules already match package.json + lockfile'
    Write-Host '  --skip-docker  do not start docker compose (infra already running elsewhere)'
    Write-Host '  --skip-build   do not build web assets / worker bundle'
    Write-Host '  --skip-e2e     do not install the Playwright suite and browser'
}

$Fresh = $false; $Reinstall = $false; $SkipDocker = $false; $SkipBuild = $false; $SkipE2E = $false
foreach ($opt in $Options) {
    switch -Regex ($opt) {
        '^-{1,2}fresh$' { $Fresh = $true; continue }
        '^-{1,2}reinstall$' { $Reinstall = $true; continue }
        '^-{1,2}skip-?docker$' { $SkipDocker = $true; continue }
        '^-{1,2}skip-?build$' { $SkipBuild = $true; continue }
        '^-{1,2}skip-?e2e$' { $SkipE2E = $true; continue }
        '^(-h|--help|-\?)$' { Show-Usage; exit 0 }
        default { Write-Host "unknown option: $opt (see --help)" -ForegroundColor Red; exit 2 }
    }
}

function Write-Step([string] $Title) { Write-Host ''; Write-Host '==> ' -ForegroundColor Cyan -NoNewline; Write-Host $Title -ForegroundColor White }
function Write-Ok([string] $Message) { Write-Host '  [ok] ' -ForegroundColor Green -NoNewline; Write-Host $Message }
function Write-Warn([string] $Message) { Write-Host '  [!]  ' -ForegroundColor Yellow -NoNewline; Write-Host $Message }
function Stop-Setup([string] $Message) {
    Write-Host '  [x]  ' -ForegroundColor Red -NoNewline
    Write-Host $Message
    Write-Host '       fix the error above and re-run; every step is idempotent' -ForegroundColor DarkGray
    exit 1
}

function Test-Command([string] $Name) { return [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

function Invoke-Native {
    param([string] $Directory, [string] $Exe, [string[]] $Arguments = @())
    Push-Location $Directory
    try {
        & $Exe @Arguments
        $code = $LASTEXITCODE
    } finally {
        Pop-Location
    }
    if ($code -ne 0) { throw "'$Exe $($Arguments -join ' ')' failed in $Directory (exit $code)" }
}

function Test-Native {
    param([string] $Directory, [string] $Exe, [string[]] $Arguments = @())
    Push-Location $Directory
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $Exe @Arguments *> $null
        return ($LASTEXITCODE -eq 0)
    } catch {
        return $false
    } finally {
        $ErrorActionPreference = $previous
        Pop-Location
    }
}

function Invoke-Retry {
    param([int] $Attempts, [scriptblock] $Action, [string] $Label)
    for ($i = 1; $i -le $Attempts; $i++) {
        try { & $Action; return } catch {
            if ($i -eq $Attempts) { throw }
            Write-Warn "attempt $i/$Attempts failed: $Label - retrying in $($i * 5)s ($($_.Exception.Message))"
            Start-Sleep -Seconds ($i * 5)
        }
    }
}

function Install-NpmDeps([string] $Directory) {
    $stamp = Join-Path $Root 'scripts/deps-stamp.ts'
    if (-not $Reinstall -and (Test-Native $Root 'node' @($stamp, 'check', $Directory))) {
        Write-Ok "$(Split-Path -Leaf $Directory): node_modules up to date (npm ci skipped; --reinstall forces it)"
        return
    }
    if (Test-Path (Join-Path $Directory 'package-lock.json')) {
        Invoke-Native $Directory 'npm' @('ci', '--no-audit', '--no-fund')
    } else {
        Invoke-Native $Directory 'npm' @('install', '--no-audit', '--no-fund')
    }
    Invoke-Native $Root 'node' @($stamp, 'write', $Directory)
}

function Test-NpmScript([string] $Directory, [string] $Name) {
    $manifest = Join-Path $Directory 'package.json'
    if (-not (Test-Path $manifest)) { return $false }
    $pkg = Get-Content $manifest -Raw -Encoding UTF8 | ConvertFrom-Json
    if (-not ($pkg.PSObject.Properties.Name -contains 'scripts')) { return $false }
    return [bool]($pkg.scripts.PSObject.Properties.Name -contains $Name)
}

function Read-EnvFile([string] $Path) {
    $values = @{}
    if (-not (Test-Path $Path)) { return $values }
    foreach ($line in Get-Content $Path -Encoding UTF8) {
        if ($line -match '^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') {
            $key = $Matches[1]
            $value = $Matches[2]
            if ($value -match '^"(.*)"$' -or $value -match "^'(.*)'$") { $value = $Matches[1] }
            elseif ($value -match '^(.*?)\s+#') { $value = $Matches[1] }
            $values[$key] = $value
        }
    }
    return $values
}

$ProjectEnv = Join-Path $Root 'scripts/project.env'
$Project = Read-EnvFile $ProjectEnv
$Web = Join-Path $Root 'apps/web'
$Worker = Join-Path $Root 'apps/worker'
$E2E = Join-Path $Root 'e2e'
$Timer = [System.Diagnostics.Stopwatch]::StartNew()

try {
    Write-Step 'Preflight'
    foreach ($tool in @('node', 'npm', 'php', 'composer')) {
        if (-not (Test-Command $tool)) { Stop-Setup "$tool not found on PATH (see README -> Prerequisites)" }
    }
    if (-not $SkipDocker -and -not (Test-Command 'docker')) { Stop-Setup 'docker not found - install Docker Desktop or pass --skip-docker' }
    $nodeMajor = [int]((& node -p "process.versions.node.split('.')[0]") | Out-String).Trim()
    if ($nodeMajor -lt 24) { Stop-Setup "Node $nodeMajor found, Node 24+ required" }
    if (-not (Test-Native $Root 'php' @('-r', "exit(version_compare(PHP_VERSION, '8.4.0', '>=') ? 0 : 1);"))) { Stop-Setup 'PHP 8.4+ required' }
    Write-Ok "node $((& node -v | Out-String).Trim()), php $((& php -r 'echo PHP_VERSION;' | Out-String).Trim())"

    Write-Step 'Root tooling'
    Install-NpmDeps $Root
    Write-Ok 'root dependencies installed'

    if ($SkipDocker) {
        Write-Warn 'skipping docker compose (--skip-docker)'
    } else {
        Write-Step "Infrastructure: postgres(pgvector) :$($Project.PG_PORT), redis :$($Project.REDIS_PORT), mailpit :$($Project.MAIL_UI_PORT)"
        if (-not (Test-Native $Root 'docker' @('info'))) { Stop-Setup 'Docker daemon is not running - start Docker Desktop' }
        Invoke-Retry -Attempts 3 -Label 'docker compose up' -Action {
            Invoke-Native $Root 'docker' @('compose', '--env-file', $ProjectEnv, 'up', '-d', '--wait', '--wait-timeout', '180')
        }
        Write-Ok 'containers healthy'
        Invoke-Native $Root 'docker' @('compose', '--env-file', $ProjectEnv, 'exec', '-T', 'postgres', 'sh', '/docker-entrypoint-initdb.d/01-init.sh')
        Write-Ok "databases $($Project.DB_NAME), $($Project.DB_NAME)_test, $($Project.DB_NAME)_e2e ready"
    }

    Write-Step 'Environment files & shared secrets'
    Invoke-Native $Root 'node' @('scripts/env-sync.ts')

    if (Test-Path (Join-Path $Web 'composer.json')) {
        Write-Step 'web: composer + npm'
        Invoke-Native $Web 'composer' @('install', '--no-interaction', '--prefer-dist', '--no-progress')
        Install-NpmDeps $Web
        $vitePlus = Join-Path $Web 'node_modules/vite-plus/package.json'
        $binding = Join-Path $Web 'node_modules/@voidzero-dev/vite-plus-win32-x64-msvc'
        if ((Test-Path $vitePlus) -and -not (Test-Path $binding)) {
            $vpVersion = (Get-Content $vitePlus -Raw -Encoding UTF8 | ConvertFrom-Json).version
            Write-Warn "vite-plus Windows binding missing - installing @voidzero-dev/vite-plus-win32-x64-msvc@$vpVersion (no-save)"
            Invoke-Native $Web 'npm' @('install', '--no-save', '--no-audit', '--no-fund', "@voidzero-dev/vite-plus-win32-x64-msvc@$vpVersion")
        }
        Write-Ok 'web dependencies installed'
    } else { Write-Warn 'apps/web not present - skipped' }

    if (Test-Path (Join-Path $Worker 'package.json')) {
        Write-Step 'worker: npm'
        Install-NpmDeps $Worker
        Write-Ok 'worker dependencies installed'
    } else { Write-Warn 'apps/worker not present - skipped' }

    if ($SkipE2E) {
        Write-Warn 'skipping e2e install (--skip-e2e)'
    } elseif (Test-Path (Join-Path $E2E 'package.json')) {
        Write-Step 'e2e: Playwright'
        Install-NpmDeps $E2E
        try { Invoke-Native $E2E 'npm' @('run', 'browsers') } catch { Write-Warn 'Playwright browser download failed - run `npm run e2e:install` later' }
        Write-Ok 'e2e suite ready'
    }

    Write-Step 'Database migrations'
    if ($Fresh) {
        if ($SkipDocker) { Stop-Setup '--fresh needs the docker postgres (drop schemas via docker compose exec)' }
        Write-Warn '--fresh: dropping schemas rag and worker, then migrate:fresh --seed'
        $user = $Project.DB_USER
        $sql = "DROP SCHEMA IF EXISTS rag CASCADE; DROP SCHEMA IF EXISTS worker CASCADE; CREATE SCHEMA rag AUTHORIZATION $user; CREATE SCHEMA worker AUTHORIZATION $user;"
        Invoke-Native $Root 'docker' @('compose', '--env-file', $ProjectEnv, 'exec', '-T', 'postgres', 'psql', '-v', 'ON_ERROR_STOP=1', '-U', $user, '-d', $Project.DB_NAME, '-c', $sql)
    }
    if (Test-Path (Join-Path $Web 'artisan')) {
        if ($Fresh) {
            Invoke-Native $Web 'php' @('artisan', 'migrate:fresh', '--seed', '--force')
        } elseif (Test-Native $Web 'php' @('artisan', 'migrate:status')) {
            Invoke-Native $Web 'php' @('artisan', 'migrate', '--force')
        } else {
            Invoke-Native $Web 'php' @('artisan', 'migrate', '--seed', '--force')
        }
        Write-Ok 'web (public schema) migrated'
    }
    if (Test-NpmScript $Worker 'migrate') {
        Invoke-Native $Worker 'npm' @('run', 'migrate')
        Write-Ok 'worker (worker schema) migrated'
    } elseif (Test-Path (Join-Path $Worker 'package.json')) {
        Write-Warn 'apps/worker has no "migrate" script - the worker migrates its schema on boot'
    }

    if ($SkipBuild) {
        Write-Warn 'skipping builds (--skip-build)'
    } else {
        Write-Step 'Build'
        if (Test-NpmScript $Web 'build') {
            Invoke-Native $Web 'npm' @('run', 'build')
            Write-Ok 'web assets built (apps/web/public/build)'
        }
        if (Test-NpmScript $Worker 'build') {
            Invoke-Native $Worker 'npm' @('run', 'build')
            Write-Ok 'worker compiled (apps/worker/dist)'
        }
    }
} catch {
    Stop-Setup $_.Exception.Message
}

$webEnv = Read-EnvFile (Join-Path $Web '.env')
$slug = if ($webEnv.ContainsKey('PRODUCT_SLUG') -and $webEnv.PRODUCT_SLUG) { $webEnv.PRODUCT_SLUG } else { $Project.PRODUCT_SLUG }
Write-Step "Done in $([int]$Timer.Elapsed.TotalSeconds)s"
Write-Host "  $($Project.PRODUCT_NAME) is ready. Start everything with:  npm run dev"
Write-Host ''
Write-Host "  web       http://localhost:$($Project.WEB_PORT)"
Write-Host "  worker    http://localhost:$($Project.WORKER_PORT)/healthz   (queues: /admin/queues)"
Write-Host "  mailpit   http://localhost:$($Project.MAIL_UI_PORT)"
Write-Host ''
Write-Host "  demo login     demo@$slug.test / password"
Write-Host "  platform admin admin@$slug.test / password"

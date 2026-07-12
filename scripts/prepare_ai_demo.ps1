#Requires -Version 5.1
<#
.SYNOPSIS
  Prepare AI demo data: seed reservations, curated scenarios, train models, verify .pkl files.

.EXAMPLE
  .\scripts\prepare_ai_demo.ps1
  .\scripts\prepare_ai_demo.ps1 -SkipBulkSeed
#>
param(
    [switch]$SkipBulkSeed,
    [switch]$SkipPipInstall,
    [int]$BulkCount = 80,
    [double]$ApprovedRatio = 0.65
)

$ErrorActionPreference = 'Stop'
$env:PYTHONIOENCODING = 'utf-8'
$Root = Split-Path -Parent $PSScriptRoot
$AiDir = Join-Path $Root 'ai'
$ModelsDir = Join-Path $AiDir 'models'

function Get-FrsPythonExe {
    foreach ($name in @('python', 'python3')) {
        $cmd = Get-Command $name -ErrorAction SilentlyContinue
        if (-not $cmd) { continue }
        $exe = $cmd.Source
        if ($exe -like '*\WindowsApps\python*.exe') { continue }
        & $exe --version *> $null
        if ($LASTEXITCODE -eq 0) { return $exe }
    }
    $installed = Get-ChildItem "$env:LOCALAPPDATA\Programs\Python\Python*\python.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending
    if ($installed) {
        return $installed[0].FullName
    }
    return $null
}

$PythonExe = Get-FrsPythonExe
if (-not $PythonExe) {
    Write-Error 'Python not found. Install Python 3 from python.org and ensure it is on PATH (disable the Microsoft Store alias if needed).'
}

Set-Location $AiDir

Write-Host ''
Write-Host '========================================' -ForegroundColor Cyan
Write-Host ' CPRF AI Demo Package - Prepare' -ForegroundColor Cyan
Write-Host '========================================' -ForegroundColor Cyan
Write-Host ('Using Python: {0}' -f $PythonExe) -ForegroundColor DarkGray
Write-Host ''

if (-not $SkipPipInstall) {
    Write-Host '[0/4] Installing Python dependencies (pip)...' -ForegroundColor Yellow
    & $PythonExe -m pip install -r requirements.txt bcrypt --quiet
    if ($LASTEXITCODE -ne 0) {
        throw ('pip install failed (exit {0}). Try: python -m pip install -r ai/requirements.txt bcrypt' -f $LASTEXITCODE)
    }
}

if (-not $SkipBulkSeed) {
    Write-Host ('[1/4] Seeding bulk sample reservations ({0})...' -f $BulkCount) -ForegroundColor Yellow
    & $PythonExe scripts/seed_sample_reservations.py --count $BulkCount --approved-ratio $ApprovedRatio --yes
    if ($LASTEXITCODE -ne 0) { throw ('Bulk seed failed (exit {0})' -f $LASTEXITCODE) }
} else {
    Write-Host '[1/4] Skipping bulk seed (-SkipBulkSeed)' -ForegroundColor DarkGray
}

Write-Host '[2/4] Seeding curated demo scenarios...' -ForegroundColor Yellow
& $PythonExe scripts/seed_demo_scenarios.py --yes
if ($LASTEXITCODE -ne 0) { throw ('Demo scenario seed failed (exit {0})' -f $LASTEXITCODE) }

Write-Host '[3/4] Training all ML models...' -ForegroundColor Yellow
$trainScripts = @(
    'train_conflict_detection.py',
    'train_auto_approval_risk.py',
    'train_chatbot_intents.py',
    'train_purpose_analysis.py',
    'train_facility_recommendation.py',
    'train_demand_forecasting.py'
)
$trainFailed = @()
foreach ($script in $trainScripts) {
    Write-Host ('  -> {0}' -f $script) -ForegroundColor DarkGray
    & $PythonExe ('scripts/' + $script)
    if ($LASTEXITCODE -ne 0) {
        $trainFailed += $script
        Write-Warning ('  Training failed: {0} (exit {1})' -f $script, $LASTEXITCODE)
    }
}

Write-Host '[4/4] Verifying model files...' -ForegroundColor Yellow
$expectedModels = @(
    'conflict_detection.pkl',
    'conflict_detection_features.pkl',
    'auto_approval_risk_model.pkl',
    'auto_approval_risk_encoders.pkl',
    'chatbot_intent_model.pkl',
    'chatbot_intent_vectorizer.pkl',
    'purpose_category_model.pkl',
    'purpose_category_vectorizer.pkl',
    'purpose_unclear_model.pkl',
    'purpose_unclear_vectorizer.pkl',
    'facility_recommendation_model.pkl',
    'facility_recommendation_encoders.pkl',
    'demand_forecasting_model.pkl'
)
$missing = @()
foreach ($model in $expectedModels) {
    $path = Join-Path $ModelsDir $model
    if (Test-Path $path) {
        $kb = [math]::Round((Get-Item $path).Length / 1KB, 1)
        Write-Host ('  OK  {0} ({1} KB)' -f $model, $kb) -ForegroundColor Green
    } else {
        $missing += $model
        Write-Host ('  --  {0} (missing)' -f $model) -ForegroundColor Red
    }
}

Write-Host ''
Write-Host 'Done.' -ForegroundColor Cyan
if ($trainFailed.Count -gt 0) {
    Write-Warning ('Some train scripts failed: {0}' -f ($trainFailed -join ', '))
}
if ($missing.Count -gt 0) {
    Write-Warning ('Missing model files: {0}' -f ($missing -join ', '))
}
Write-Host ''
Write-Host 'Next steps:' -ForegroundColor Cyan
Write-Host '  1. Open AI Model Lab: /dashboard/ai-model-lab (Admin)'
Write-Host '  2. Book a Facility -> Load demo scenario (Admin/Staff)'
Write-Host '  3. Demo logins: demo.low@culiat.test / Demo2026!'
Write-Host ''

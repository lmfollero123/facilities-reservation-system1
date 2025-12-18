<#
Windows Task Scheduler setup for Facilities Reservation System (XAMPP/local).

This creates scheduled tasks that run PHP scripts using C:\PHP\php.exe.

Run in PowerShell (preferably as Administrator):
  Set-ExecutionPolicy -Scope Process Bypass -Force
  .\scripts\windows_task_scheduler_setup.ps1

You can also remove tasks:
  .\scripts\windows_task_scheduler_setup.ps1 -Remove
#>

param(
    [switch]$Remove
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot  # ...\facilities_reservation_system
   $PhpExe = "C:\xampp\php\php.exe"

$ArchiveScript = Join-Path $ProjectRoot "scripts\archive_documents.php"
$AutoDeclineScript = Join-Path $ProjectRoot "scripts\auto_decline_expired.php"
$CleanupScript = Join-Path $ProjectRoot "scripts\cleanup_old_data.php"
$OptimizeDbScript = Join-Path $ProjectRoot "scripts\optimize_database.php"

function Assert-FileExists($path) {
    if (-not (Test-Path $path)) {
        throw "Missing file: $path"
    }
}

Assert-FileExists $PhpExe
Assert-FileExists $ArchiveScript
Assert-FileExists $AutoDeclineScript
Assert-FileExists $CleanupScript
Assert-FileExists $OptimizeDbScript

$Tasks = @(
    @{
        Name = "FRS - Archive Documents (Daily)"
        Schedule = "/SC DAILY /ST 02:00"
        Script = $ArchiveScript
        Args = ""   # add: --verbose or --dry-run for testing
    },
    @{
        Name = "FRS - Auto-Decline Expired Reservations (Daily)"
        Schedule = "/SC DAILY /ST 03:00"
        Script = $AutoDeclineScript
        Args = ""
    },
    @{
        Name = "FRS - Cleanup Old Data (Weekly)"
        Schedule = "/SC WEEKLY /D SUN /ST 04:00"
        Script = $CleanupScript
        Args = ""
    },
    @{
        Name = "FRS - Optimize Database (Weekly)"
        Schedule = "/SC WEEKLY /D SUN /ST 06:00"
        Script = $OptimizeDbScript
        Args = ""
    }
)

if ($Remove) {
    foreach ($t in $Tasks) {
        Write-Host "Deleting task: $($t.Name)"
        schtasks /Delete /TN "$($t.Name)" /F | Out-Null
    }
    Write-Host "Done."
    exit 0
}

foreach ($t in $Tasks) {
    $taskName = $t.Name
    $scriptPath = $t.Script
    $args = $t.Args

    # Create a .cmd wrapper per task to avoid schtasks quoting issues on Windows.
    $safeTask = ($taskName -replace '[^a-zA-Z0-9_-]', '_')
    $logDir = Join-Path $ProjectRoot "storage\task_logs"
    if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

    $cmdDir = Join-Path $ProjectRoot "storage\task_cmd"
    if (-not (Test-Path $cmdDir)) { New-Item -ItemType Directory -Path $cmdDir -Force | Out-Null }

    $logFile = Join-Path $logDir ($safeTask + ".log")
    $cmdFile = Join-Path $cmdDir ($safeTask + ".cmd")

    $cmdContents = @"
@echo off
cd /d "$ProjectRoot"
"$PhpExe" "$scriptPath" $args >> "$logFile" 2>&1
"@
    Set-Content -Path $cmdFile -Value $cmdContents -Encoding ASCII

    # /TR becomes simple: cmd.exe /c "<cmdFile>"
    $tr = "cmd.exe /c `"$cmdFile`""

    Write-Host "Creating/updating task: $taskName"
    # Build one argument string so /TN and /TR values with spaces are properly quoted.
    $argString = "/Create /F $($t.Schedule) /TN `"$taskName`" /TR `"$tr`""
    $p = Start-Process -FilePath "schtasks.exe" -ArgumentList $argString -NoNewWindow -Wait -PassThru
    if ($p.ExitCode -ne 0) {
        throw "Failed to create task: $taskName (ExitCode=$($p.ExitCode))"
    }
}

Write-Host ""
Write-Host "✅ Scheduled tasks created."
Write-Host "Logs will be written to: $ProjectRoot\storage\task_logs\"



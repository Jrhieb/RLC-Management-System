param(
    [ValidateSet('Start', 'Stop', 'Status', 'Inspect')]
    [string] $Action = 'Status'
)

$ErrorActionPreference = 'Stop'
$queueWorkspace = Split-Path -Parent $PSScriptRoot
$queueRuntime = Join-Path $env:TEMP 'rlcp-registry-php84'
$queuePhp = Join-Path $queueRuntime 'php.exe'
$queueIni = Join-Path $queueRuntime 'registry.ini'
$queueScript = Join-Path $PSScriptRoot 'lan-mail-queue.php'
$queueDirectory = Join-Path $queueWorkspace 'storage\framework\lan-mail-queue'
$queueState = Join-Path $queueDirectory 'state.json'
$queueStop = Join-Path $queueDirectory 'stop'

function Get-QueueProcesses {
    @(Get-CimInstance Win32_Process | Where-Object {
        $_.Name -match '^php(?:-cgi)?\.exe$' -and (
            $_.CommandLine -match 'artisan\s+(?:queue:(?:work|listen)|horizon)(?:\s|$)' -or
            $_.CommandLine -match 'lan-mail-queue\.php.*--work'
        )
    })
}

function Show-QueueStatus {
    $queueProcesses = @(Get-QueueProcesses)
    $queueSnapshot = Read-QueueState
    [pscustomobject]@{
        worker_status = if ($queueProcesses.Count -gt 0) { 'running' } else { 'stopped' }
        running_worker_count = $queueProcesses.Count
        process_ids = @($queueProcesses | ForEach-Object { $_.ProcessId })
        last_recorded_state = $queueSnapshot
    } | ConvertTo-Json -Depth 7
}

function Read-QueueState {
    for ($queueRead = 0; $queueRead -lt 5; $queueRead++) {
        if (-not (Test-Path -LiteralPath $queueState)) { return $null }
        try { return Get-Content -LiteralPath $queueState -Raw | ConvertFrom-Json }
        catch { Start-Sleep -Milliseconds 50 }
    }
    return $null
}

if ($Action -eq 'Status') { Show-QueueStatus; exit 0 }
if ($Action -eq 'Stop') {
    if (-not (Test-Path -LiteralPath $queueDirectory)) { Show-QueueStatus; exit 0 }
    [IO.File]::WriteAllText($queueStop, 'graceful stop requested')
    for ($queueWait = 0; $queueWait -lt 40; $queueWait++) {
        $queueOwnWorkers = @(Get-QueueProcesses | Where-Object { $_.CommandLine -match 'lan-mail-queue\.php.*--work' })
        if ($queueOwnWorkers.Count -eq 0) { break }
        Start-Sleep -Milliseconds 250
    }
    Show-QueueStatus
    exit 0
}
if (-not (Test-Path -LiteralPath $queuePhp) -or -not (Test-Path -LiteralPath $queueIni)) {
    throw 'The verified PHP runtime or registry.ini is missing. No worker was started.'
}

$queueMutex = [Threading.Mutex]::new($false, 'Local\RLCP_LAN_MAIL_QUEUE_START')
$queueOwnMutex = $false
try {
    $queueOwnMutex = $queueMutex.WaitOne(10000)
    if (-not $queueOwnMutex) { throw 'Another worker startup is in progress.' }
    if ($Action -eq 'Start' -and @(Get-QueueProcesses).Count -gt 0) {
        Write-Output 'A queue worker already exists; no duplicate was started.'
        Show-QueueStatus
        exit 0
    }
    $queueRaw = & $queuePhp -c $queueIni $queueScript --inspect
    if ($LASTEXITCODE -ne 0) { throw 'Safe queue inspection failed; no worker was started.' }
    $queueInventory = $queueRaw | ConvertFrom-Json
    if ($Action -eq 'Inspect' -or -not $queueInventory.start_allowed) {
        $queueInventory | ConvertTo-Json -Depth 7
        if (-not $queueInventory.start_allowed) { throw 'Unrelated, reserved, or previously attempted jobs require review. No worker was started.' }
        exit 0
    }
    if (-not (Test-Path -LiteralPath $queueDirectory)) { New-Item -Path $queueDirectory -ItemType Directory | Out-Null }
    if (Test-Path -LiteralPath $queueStop) { Remove-Item -LiteralPath $queueStop }
    $queueStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $queueArguments = @('-c', ('"' + $queueIni + '"'), ('"' + $queueScript + '"'), '--work')
    $queueChild = Start-Process -FilePath $queuePhp -ArgumentList $queueArguments -WorkingDirectory $queueWorkspace -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $queueDirectory ('worker-' + $queueStamp + '.out.log')) `
        -RedirectStandardError (Join-Path $queueDirectory ('worker-' + $queueStamp + '.err.log'))
    for ($queueWait = 0; $queueWait -lt 40; $queueWait++) {
        $queueChild.Refresh()
        if ($queueChild.HasExited) { throw 'Worker exited during startup. Inspect the sanitized local status.' }
        if (Test-Path -LiteralPath $queueState) {
            $queueSnapshot = Read-QueueState
            if ($queueSnapshot.pid -eq $queueChild.Id -and $queueSnapshot.status -eq 'running') { Show-QueueStatus; exit 0 }
        }
        Start-Sleep -Milliseconds 250
    }
    throw 'Worker startup is not yet confirmed; use Status before starting again.'
} finally {
    if ($queueOwnMutex) { $queueMutex.ReleaseMutex() }
    $queueMutex.Dispose()
}

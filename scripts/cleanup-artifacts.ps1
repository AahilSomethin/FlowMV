param([string[]]$Select = @(), [switch]$Delete, [string]$ConfirmDeletion = '')
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
Set-Location -LiteralPath $repoRoot
# Intentionally closed list: no storage, secrets, databases, logs, binaries or volumes.
$known = @('.next-hygiene-check','.next-integration-build','.next-phase85','.next-phase85-build','.next-phase85-employee','.next-phase85-manager','.next-phase9c','.next-phase9c-build','.next-phase9c-final','.next-phase9d','.next-phase9d-final','.next-phase10-native','.next-phase10-native-production','.next-phase11-native-production') | ForEach-Object { 'frontend/' + $_ }
$known += @('frontend/tsconfig.tsbuildinfo','backend/.phpunit.result.cache')
foreach ($entry in $Select) { if ($entry -cnotin $known) { throw "Unrecognized or protected selection: $entry" } }
if ($Delete -and (!$Select.Count -or $ConfirmDeletion -cne 'DELETE SELECTED GENERATED ARTIFACTS')) { throw 'Deletion requires explicit -Select paths and -ConfirmDeletion "DELETE SELECTED GENERATED ARTIFACTS".' }
function Assert-Path([string]$relative) {
    if ($relative -cnotin $known) { throw 'Path is outside the closed artifact allowlist' }
    $absolute = [IO.Path]::GetFullPath((Join-Path $repoRoot $relative))
    if (!$absolute.StartsWith($repoRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Path escapes repository' }
    $current = $absolute
    while ($current -ne $repoRoot) {
        if ((Get-Item -LiteralPath $current -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked paths refused' }
        $current = Split-Path -Parent $current
    }
    $children = @(Get-ChildItem -LiteralPath $absolute -Force -Recurse)
    if ($children | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }) { throw 'Nested links refused' }
    if ($children | Where-Object { $_.Name -match '^\.env|\.(pem|key|sqlite|dump)$' }) { throw 'Protected material found inside output' }
    $tracked = @(git ls-files -- $relative)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count) { throw 'Tracked paths refused' }
    git check-ignore --quiet -- $relative
    if ($LASTEXITCODE -ne 0) { throw 'Unignored paths refused' }
    if ((Get-Item -LiteralPath $absolute).PSIsContainer) {
        if (!(Test-Path -LiteralPath (Join-Path $absolute 'server')) -and !(Test-Path -LiteralPath (Join-Path $absolute 'dev/server'))) { throw 'Not a recognized generated Next output' }
    }
    return $absolute
}
function Assert-Inactive([string]$relative) {
    # Fail closed if process inventory is unavailable or a Next process is unfamiliar.
    $processes = @(Get-CimInstance Win32_Process)
    $nodes = @($processes | Where-Object { $_.Name -eq 'node.exe' })
    if ($nodes | Where-Object { !$_.CommandLine }) { throw 'Cannot identify all Node processes' }
    foreach ($proc in $nodes) {
        if ($proc.CommandLine -match [regex]::Escape($relative.Split('/')[-1])) { throw 'Artifact referenced by a running process' }
        if ($proc.CommandLine -match 'next[/\\]dist[/\\]bin[/\\]next.+(start|dev)') {
            $parent = $processes | Where-Object ProcessId -eq $proc.ParentProcessId
            if ($proc.CommandLine -notmatch '--port\s+3010\b' -or $parent.CommandLine -notmatch 'scripts[/\\]demo-run\.mjs') { throw 'Unknown active Next service; stop it or review its output before cleanup' }
            $html = (Invoke-WebRequest 'http://localhost:3010/login' -TimeoutSec 15).Content
            $assets = @([regex]::Matches($html, 'src="(/_next/static/[^"?]+\.js)') | ForEach-Object { $_.Groups[1].Value })
            if (!$assets.Count) { throw 'Cannot identify active native build assets' }
            if ($relative -like 'frontend/.next*') {
                $matches = @($assets | Where-Object { Test-Path -LiteralPath (Join-Path (Join-Path $repoRoot $relative) $_.Substring(7)) })
                if ($matches.Count -eq $assets.Count) {
                    # Identical source builds can share every chunk. Require positive live build-ID evidence.
                    $liveIds = @('.next','.next-phase12-native-production','.next-phase12-native-production-final') | ForEach-Object {
                        $idFile = Join-Path (Join-Path $repoRoot ('frontend/' + $_)) 'BUILD_ID'
                        if (Test-Path -LiteralPath $idFile) {
                            $id = ([IO.File]::ReadAllText($idFile)).Trim()
                            if ($id -match '^[a-zA-Z0-9_-]{10,64}$' -and $html.Contains($id)) { $id }
                        }
                    }
                    $candidateIdFile = Join-Path (Join-Path $repoRoot $relative) 'BUILD_ID'
                    if (@($liveIds).Count -ne 1 -or !(Test-Path -LiteralPath $candidateIdFile) -or ([IO.File]::ReadAllText($candidateIdFile)).Trim() -eq $liveIds[0]) { throw 'Output may supply the running native service; retained' }
                }
            }
        }
    }
    $refs = @(Get-ChildItem -LiteralPath (Join-Path $repoRoot 'scripts'),(Join-Path $repoRoot 'docker') -Recurse -File | Where-Object { $_.FullName -ne $PSCommandPath -and $_.Extension -in '.mjs','.ps1','.yaml' -or $_.Name -like '*.Dockerfile' })
    $refs += @(Get-ChildItem -LiteralPath $repoRoot -File -Filter 'compose*.yaml')
    foreach ($config in @('frontend/package.json','frontend/next.config.ts','.vscode/launch.json','.vscode/tasks.json')) {
        if (Test-Path -LiteralPath (Join-Path $repoRoot $config)) { $refs += Get-Item -LiteralPath (Join-Path $repoRoot $config) }
    }
    foreach ($file in $refs) { if ([IO.File]::ReadAllText($file.FullName).Contains($relative.Split('/')[-1])) { throw 'Output referenced by a current launcher/container source' } }
}
$inventory = @()
foreach ($relative in $known) {
    if (!(Test-Path -LiteralPath (Join-Path $repoRoot $relative))) { continue }
    try {
        $absolute = Assert-Path $relative
        Assert-Inactive $relative
        $bytes = if ((Get-Item -LiteralPath $absolute).PSIsContainer) { (Get-ChildItem -LiteralPath $absolute -Force -Recurse -File | Measure-Object Length -Sum).Sum } else { (Get-Item -LiteralPath $absolute).Length }
        $inventory += [pscustomobject]@{path=$relative; bytes=[long]$bytes; status='candidate'}
    } catch { $inventory += [pscustomobject]@{path=$relative; bytes=0; status='retained: '+$_.Exception.Message} }
}
$inventory | ConvertTo-Json | Write-Output
if (!$Delete) { Write-Output 'DRY RUN: nothing deleted. Unlisted .next*, all Phase 12 outputs and protected trees are retained.'; exit 0 }
foreach ($relative in $Select) { if (!(($inventory | Where-Object path -eq $relative).status -eq 'candidate')) { throw "Selection is absent or unsafe: $relative" } }
foreach ($relative in $Select) {
    $absolute = Assert-Path $relative
    Assert-Inactive $relative
    # Same shell performs validation and deletion; no string-built cross-shell command.
    Remove-Item -LiteralPath $absolute -Recurse -Force
    Write-Output "Removed $relative"
}

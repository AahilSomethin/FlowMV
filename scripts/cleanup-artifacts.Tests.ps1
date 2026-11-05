$ErrorActionPreference='Stop'
$script=Join-Path $PSScriptRoot 'cleanup-artifacts.ps1'
$cases=@(
 @('-Delete'),
 @('-Delete','-Select','../backend/storage'),
 @('-Delete','-Select','frontend/.next-phase12-native-production-final'),
 @('-Delete','-Select','frontend/unknown-output')
)
foreach($arguments in $cases) {
 $output=& (Join-Path $PSHOME 'pwsh.exe') -NoProfile -File $script @arguments 2>&1
 if($LASTEXITCODE -eq 0) { throw 'Unsafe cleanup invocation was not refused' }
}
'4 negative cleanup invocation tests passed (missing confirmation, traversal, protected build, unknown path).'

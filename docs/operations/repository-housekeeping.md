# Repository hygiene and safe artifact cleanup

Audit date: 2026-11-05. Existing uncommitted source and historical acceptance evidence were preserved. No commits, pushes, database mutations, Docker volume operations or service stops were performed by cleanup.

## Removed and retained inventory

| Next output | Initial bytes | Classification | Disposition |
| --- | ---: | --- | --- |
| `.next` | 1,003,432,209 | Mixed default development/production output, current Next config default | Retained: current Next config default |
| `.next-integration-build` | 105,869,783 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |
| `.next-phase10-native` | 233,239,388 | Development output/cache and generated types | Removed: obsolete ignored QA output |
| `.next-phase10-native-production` | 100,058,718 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |
| `.next-phase11-native-production` | 100,581,632 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |
| `.next-phase12-native-production` | 101,577,003 | Production build with generated static assets/types and cache | Retained: current launcher default |
| `.next-phase12-native-production-final` | 103,509,264 | Production build with generated static assets/types and cache | Retained: active native assets match |
| `.next-phase85` | 431,783,886 | Development output/cache and generated types | Removed: obsolete ignored QA output |
| `.next-phase85-build` | 94,639,507 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |
| `.next-phase85-employee` | 235,179,933 | Development output/cache and generated types | Removed: obsolete ignored QA output |
| `.next-phase85-manager` | 193,281,988 | Development output/cache and generated types | Removed: obsolete ignored QA output |
| `.next-phase9c` | 335,481,285 | Development output/cache and generated types | Removed: obsolete ignored QA output |
| `.next-phase9c-build` | 97,597,797 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |
| `.next-phase9c-final` | 97,332,211 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |
| `.next-phase9d` | 308,050,471 | Development output/cache and generated types | Removed: obsolete ignored QA output |
| `.next-phase9d-final` | 98,700,101 | Production build with generated static assets/types and cache | Removed: obsolete ignored QA output |

Removed 13 obsolete Next output trees plus `frontend/tsconfig.tsbuildinfo` (284,970 bytes) and `backend/.phpunit.result.cache` (38,252 bytes): **2,432,119,922 logical bytes (2.265 GiB)**, 9,886 files. This is the sum of file lengths, not a filesystem free-space measurement (allocation/compression/other activity can differ). Compiler/test caches may be recreated by verification.

The running native Next server uses port 3010; its requested login JavaScript asset paths matched only the retained final production build. No obsolete Next process was found. Docker runs its own built image assets and named volumes; host output deletion cannot remove those image layers. No Docker images/build cache/volumes were pruned. Native Go's `bin/ai-go.exe` (11,915,264 bytes) is running and retained.

## Repository-wide review

- Frontend: removed historical generated type include paths from tsconfig; retained current/default type references and `next-env.d.ts`. Generated static assets were removed only inside the obsolete build trees. Public assets and source remain. TypeScript/ESLint check current imports and types; no unsupported claim of exhaustive dead-code elimination is made.
- Backend: retained every environment/QA profile, fixture, migration and operational command. Container bindings for provider HTTP transports and document scanner, scheduled recovery, connector services, registry handlers and test references are dynamic dependencies, not deletion candidates. Storage, logs, cache-store records, encrypted documents, secrets and acceptance evidence are outside cleanup policy.
- Go: no loose obsolete executables/test binaries/profiles were found outside the active binary directory in the inspected project trees. No Go source, module files or runtime binary was removed.
- Test tooling: no repository coverage directory was present at inspection. Node dependencies, Composer vendor files and package lockfiles were retained. No newly confirmed unused dependency, duplicate utility, obsolete launcher or dead component justified deletion; prior Phase 12 dependency cleanup remains unchanged.
- Documentation: historical build names in acceptance reports describe the tested historical commands and remain unchanged. Current launcher defaults remain valid. The current repository index links this housekeeping procedure. Historical QA configurations are retained for guarded regression/reproduction, not silently superseded.
- Ignore rules now cover all frontend `.next*` outputs, TypeScript build info, local PHPUnit caches, coverage and conventional Go binary/test/coverage outputs. Ignore rules do not authorize deletion.

## Safe Windows PowerShell command

From repository root, with PowerShell 7:

```powershell
./scripts/cleanup-artifacts.ps1
./scripts/cleanup-artifacts.Tests.ps1
```

Default is inventory/dry-run. Deletion requires exact allowlisted paths plus an explicit confirmation phrase. Example for a disposable verification build, after reviewing dry-run output:

```powershell
./scripts/cleanup-artifacts.ps1 -Select 'frontend/.next-hygiene-check' -Delete -ConfirmDeletion 'DELETE SELECTED GENERATED ARTIFACTS'
```

The script resolves absolute paths within this repository, refuses tracked/unignored files and reparse points (including nested links), checks generated Next structure, rejects secret/database-shaped files inside outputs, scans current launcher/container references and inspects running Node/Next processes. It conservatively retains any output containing all currently served native script paths, unless one positively identified live protected build ID differs from the candidate build ID. This distinguishes identical-chunk builds; ambiguity still refuses deletion. Unfamiliar Next processes, inaccessible process inventory or unavailable native asset identification block deletion. An empty/unknown/protected selection or absent confirmation is rejected. All selections are validated before the first removal and rechecked immediately before each deletion. It uses native `Remove-Item -LiteralPath` in the same shell; no shell-built cross-shell deletion, Git clean or Docker prune.

The closed allowlist intentionally excludes `.next`, all Phase 12 builds, Go binaries, coverage trees, logs, storage, secrets, databases and evidence. Unknown custom outputs require source review before adding them; they are never inferred safe from a name alone. Run during a maintenance interval without concurrent builds/file writers: validation cannot provide an atomic filesystem lock against another local process changing files. Failure stops the run; earlier successfully removed generated outputs remain reproducible. Windows process inspection is required; this is not claimed as a tested cross-platform cleanup utility.

The four negative invocation tests cover missing confirmation, traversal, protected active-build selection and unknown paths. Actual dry-run/deletion exercised ignore/tracking/structure/process/reference checks. Native/Docker operational checks and final build results are recorded below.

## Verification

- Removed original artifacts: 13 QA build trees and two caches, 2,432,119,922 logical bytes (2.265 GiB), 9,886 files. The additional disposable verification build (101,164,848 bytes) was created, verified and removed; its deletion is not counted as net recovery from preexisting material.
- TypeScript and ESLint passed sequentially before build and again after restoring declarations/removing transient type includes. The isolated production build passed all 32 routes, and was then removed with the guarded command. The running native build was never overwritten.
- Laravel focused security/runtime suite: five passed, 13 assertions. Go regression suite: all seven packages passed; no provider calls and no running binary replacement. No backend/Go application source changed, so broader previously accepted suites were not unnecessarily repeated.
- Four cleanup refusal tests passed, including after the final guard update. Default dry-run performed no deletion; compiler/test cache files regenerated by verification are eligible for a later selected cleanup.
- Native and Docker login/startup endpoints returned HTTP 200. Existing native launcher processes and all Docker demo services remained running; no forced stop/start cycle was performed because preserving active services was required. Go container remained healthy. Integrity commands verified 24 baseline users/eight workflows/100 original requests in each installation, with 110 native and 108 Docker workspace requests. No reseeding/reset or Docker volume operation occurred.
- Documentation validation: 148 local file links, zero missing targets (remote URLs/anchors outside this check). Seven previously inventoried historical reports retained identical hashes. Git diff review found no newly deleted source/configuration file. The protected storage/evidence trees and all environment profiles were outside the deletion allowlist.
- Next feedback review retained no framework issue; automatic generated-type additions were expected behavior. No build, security or test failure remains from cleanup.

The report describes running-service/startup-endpoint verification, not a new fresh-install acceptance. Native and Docker fresh-install evidence remains in the preserved phase reports. Private machine-readable inventory, original generated declaration reference, dry-run and deletion logs are under ignored `backend/storage/app/phase12-audit/hygiene-*`. No secret values are included in this guide.

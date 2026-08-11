---
name: boschifai-gen-e2e-mobile
description: "E2E test generation rules for mobile app journeys using Patrol (Flutter integration tests with native OS-level actions) — extends boschifai-gen-e2e"
---

# E2E Test Generation — Mobile (Flutter / Patrol)

Tech-specific rules for generating device-driven E2E tests for Flutter apps using Patrol, which wraps `integration_test` and adds native OS-level actions (permission dialogs, airplane mode, notifications). Extends the base `boschifai-gen-e2e` skill. Written against `redrabbit-flutter-app`'s conventions.

## Before Generating — Read the Project First

1. Read `integration_test/helpers/` — this is the project's page-object-equivalent layer: one static-method helper class per feature/flow (e.g. `auth_actions.dart`, `asset_actions.dart`, `inspection_actions.dart`), each taking the Patrol tester `$` as its first argument. Compose against these, don't call `$.tap`/`$.enterText` inline when a helper already exists for that action.
2. Check `integration_test/test_bundle.dart` — it's **generated** by `patrol build`/`patrol test` (marked with `// START/END: GENERATED TEST IMPORTS/GROUPS`). Don't hand-edit inside those markers; add a new feature's test file and let Patrol's build step regenerate the bundle, or add the `group(...)` line consistent with existing entries if regeneration isn't available in this session.
3. Confirm widget `Key` constants exist in `lib/` (e.g. `LoginScreenTestConstants`) before inventing new ones — test-ID constants are defined alongside the screen they identify, not duplicated under `test/` or `integration_test/`.
4. Never hardcode credentials. The existing convention reads `EMAIL`/`PASSWORD` via `String.fromEnvironment(...)` (populated by `--dart-define` at run time) and throws if unset — follow that pattern exactly. (`integration_test/example_test.dart` hardcodes a real-looking email/password inline — that file is legacy/superseded, not a template to copy from.)
5. Use `faker` for any data that must be unique per run (asset/group/landlord/tenant names) to avoid collisions when tests run repeatedly against a shared environment.

## Test Frameworks

| Type | Framework | Notes |
|------|-----------|-------|
| Test runner | Patrol (`package:patrol/patrol.dart`) | `patrolTest`, `$` tester — wraps `integration_test`, not used directly |
| Native actions | `$.native.*` | Permission dialogs, airplane mode, app lifecycle — guard device-only checks with `$.native.isVirtualDevice()` |
| Test data | `faker` | Unique names per run |
| Invocation | Per-feature `run_<feature>_test.sh` | Device auto-detection (booted iOS simulator / connected Android device), `--flavor` defaults to `staging`, `--dart-define=ENVIRONMENT=...` |

## What Counts as a Mobile E2E Test Here

| Scenario | Mobile E2E (Patrol) | Unit/Widget Test Instead |
|----------|----------------------|---------------------------|
| Full login flow including validation-error cases and real auth | **Yes** | No |
| Multi-step flow across real screens (create inspection with new asset + template) | **Yes** | No |
| Native permission-dialog handling, airplane-mode behavior | **Yes** | No |
| Single screen's rendering or a provider's logic in isolation | No | **Yes** (`boschifai-gen-unit-flutter`) |

## Test File Convention

- Location: `integration_test/<Feature>/<flow_name>_test.dart` (one folder per feature, e.g. `Login/`, `Inspection/`)
- Helpers: `integration_test/helpers/<feature>_actions.dart`
- Data providers for validation-case tables: `integration_test/<Feature>/<flow_name>_data_provider.dart`
- Runner script: `integration_test/<Feature>/run_<feature>_test.sh`

## Test Structure

```dart
import 'package:patrol/patrol.dart';
import 'package:faker/faker.dart';
import '../helpers/auth_actions.dart';
import '../helpers/asset_actions.dart';
import '../helpers/inspection_actions.dart';

void main() {
  group('Inspection Tests', () {
    patrolTest('creates an inspection for a new asset with a template', ($) async {
      // GIVEN a logged-in user
      await AuthActions.login($);

      // WHEN creating an inspection against a newly created asset + template
      final assetName = '${faker.company.name()} Unit ${faker.randomGenerator.integer(999)}';
      await AssetActions.createAsset($, name: assetName);
      await InspectionActions.startInspection($, assetName: assetName, useTemplate: true);

      // THEN the inspection is created and visible in the list
      await InspectionActions.verifyInspectionExists($, assetName: assetName);

      await AuthActions.logout($);
    });

    patrolTest('creates an inspection for an existing asset, copying from a previous inspection', ($) async {
      await AuthActions.login($);

      await InspectionActions.startInspectionFromExisting($, copyFromPrevious: true);

      await InspectionActions.verifyInspectionCreatedFromCopy($);

      await AuthActions.logout($);
    });
  });
}
```

## Native/Device-Specific Checks

```dart
if (!await $.native.isVirtualDevice()) {
  // physical-device-only checks, e.g. airplane mode toggling
  await $.native.enableAirplaneMode();
  // ... assert offline handling ...
  await $.native.disableAirplaneMode();
}

// Permission dialogs — loop until dismissed, dialogs can appear in varying order per OS version
while (await $.native.isPermissionDialogVisible()) {
  await $.native.grantPermissionWhenInUse();
}
```

## Credentials

```dart
static const String email = String.fromEnvironment('EMAIL');
static const String password = String.fromEnvironment('PASSWORD');

static void _requireCredentials() {
  if (email.isEmpty) throw ArgumentError('EMAIL not set — pass --dart-define=EMAIL=...');
  if (password.isEmpty) throw ArgumentError('PASSWORD not set — pass --dart-define=PASSWORD=...');
}
```

Never hardcode credentials, even for a "test-only" account — pass them via `--dart-define` from CI secrets or a local `.env` that's gitignored.

## Required Scenarios Per Flow

| Category | What to Test | Min Count |
|----------|-------------|-----------|
| Happy path | Complete flow with valid data succeeds end-to-end | 1 per flow |
| Validation | Each required field's error state (via a data-provider table, not one test per field) | 1 table per flow with form input |
| Permutations | Distinct meaningful paths through the flow (new vs existing entity, with/without template, copy-from-previous) | 1 per meaningful permutation |
| Native/offline | Airplane-mode or permission-dialog handling, physical-device-only | 1 if the flow touches device capabilities |
| Cleanup | Logout / return to a clean state at the end of the test | 1 per flow |

## Quality Rules

1. Compose via `integration_test/helpers/` action classes — don't inline raw `$.tap`/`$.enterText` when a helper exists.
2. Use `faker` for any entity name that could collide across repeated runs against shared staging.
3. Never hardcode credentials — always `String.fromEnvironment`.
4. Guard physical-device-only assertions with `$.native.isVirtualDevice()`.
5. Don't hand-edit the `GENERATED` block in `test_bundle.dart` — add the file/group entry consistent with the existing pattern, or note that `patrol build` should be re-run.
6. These tests are not currently wired into CI (invoked via local `run_<feature>_test.sh` scripts only) — say so if asked "will this run in CI," don't assume it does.

## Output File Naming

- Test: `integration_test/<Feature>/<flow_name>_test.dart`
- Data provider (if the flow has a validation-case table): `integration_test/<Feature>/<flow_name>_data_provider.dart`
- New helper methods: added to the existing `integration_test/helpers/<feature>_actions.dart`, or a new file there if the feature has none yet

## Mobile E2E Generation Checklist

- [ ] Read `integration_test/helpers/` first; reused or extended existing action classes
- [ ] No hardcoded credentials — `String.fromEnvironment` with a clear error if unset
- [ ] `faker` used for any entity name that must be unique per run
- [ ] Happy path + validation-table + meaningful permutations covered
- [ ] Physical-device-only checks guarded by `$.native.isVirtualDevice()`
- [ ] Test ends in a clean state (logout / navigate back)
- [ ] Did not hand-edit `test_bundle.dart`'s generated markers
- [ ] File at `integration_test/<Feature>/<flow_name>_test.dart`

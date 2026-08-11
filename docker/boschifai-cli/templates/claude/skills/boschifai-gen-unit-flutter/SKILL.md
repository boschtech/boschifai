---
name: boschifai-gen-unit-flutter
description: "Unit and widget test generation rules for Flutter apps (flutter_test, mockito, golden_toolkit, sqflite_common_ffi) — extends boschifai-gen-unit"
---

# Unit Test Generation — Flutter (flutter_test / mockito / golden_toolkit)

Tech-specific rules for generating unit and widget tests in Flutter applications. Extends the base `boschifai-gen-unit` skill. Written against `redrabbit-flutter-app`'s conventions; applies to any Flutter project using the same package choices (`mockito`, not `mocktail`; `golden_toolkit` for visual regression).

## Before Generating — Read the Project First

1. Check for `test/flutter_test_config.dart` — if present, `flutter test` already wraps every run in a shared configuration (fixed clock, mock `SharedPreferences`, golden tolerance comparator, font loading). Don't duplicate that setup inside individual test files.
2. Check for a shared widget-test harness (e.g. `test/test_utils.dart`'s `appWrapper(...)`) that wraps a widget under test with the real provider tree, mocked remote-config/analytics, and a fixed clock — use it instead of hand-rolling `MaterialApp`/`MultiProvider` boilerplate.
3. Check for a DB test harness (e.g. `test/database_config.dart`'s `DatabaseConfig.setup()`) if the class under test touches the local database — it stands up a real (file-based FFI) SQLite instance and runs real migrations; repository tests should exercise real SQL through this, not a mocked DB layer.
4. Confirm whether mocks are hand-written or codegen'd via `build_runner`/`mockito` (`.mocks.dart` files, typically generated from a `@GenerateMocks([...])` annotation in a shared mocks file). If codegen'd, add the new class to the existing `@GenerateMocks` list and run `dart run build_runner build` rather than hand-writing a `Mock` subclass.
5. Mirror the file path: a test for `lib/screens/inspection/inspection_overview_screen.dart` belongs at `test/unit_tests/screens/inspection/inspection_overview_screen_test.dart`. Don't invent a different tree.

## Test Frameworks

| Type | Framework | Notes |
|------|-----------|-------|
| Test runner | `flutter_test` (widgets) / `test` (pure Dart, e.g. repositories) | Use plain `test` when no widget tree is needed |
| Mocking | `mockito` | Not `mocktail` — `Mock`, `when()`, `verify()`, `verifyNever()`, `thenAnswer`, `thenThrow`; mocks generated via `build_runner` into `.mocks.dart` files |
| Golden/visual regression | `golden_toolkit` | Reference images under a mirrored `test/goldens/` path; tolerance governed by the project's `ToleranceComparator` |
| DB tests | `sqflite_common_ffi` | Real in-memory-ish (file-based FFI) SQLite, real migrations — not mocked |
| HTTP mocking | `nock` (Dart port) | For any class making raw HTTP calls without an injectable client |

## What Counts as a Unit/Widget Test Here

Flutter doesn't have a separate "component test" tier in this codebase — provider tests, repository tests, and widget tests are all under `test/unit_tests/`, mirroring `lib/`'s structure. Only browser-free logic and widget rendering belong here; anything that drives the app across multiple real screens belongs in `integration_test/` (`boschifai-gen-e2e-mobile`).

| Scenario | Unit/Widget Test | Integration (Patrol) Test Instead |
|----------|-------------------|-------------------------------------|
| Provider method logic, listener notifications | **Yes** | No |
| Repository method against a real (FFI) SQLite DB | **Yes** | No |
| Single widget's rendering/interaction in isolation | **Yes** (`appWrapper`) | No |
| Visual regression of a screen/widget | **Yes** (golden test, mirrored `test/goldens/` file) | No |
| Multi-screen user flow (login, create inspection end-to-end) | No | **Yes** |

## Provider/Service Test Structure

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';
import '../mocks/general_mocks.mocks.dart';
import 'package:red_rabbit/providers/camera_settings_provider.dart';

void main() {
  group('CameraSettingsProvider', () {
    late MockCameraSettingsRepo mockRepo;
    late CameraSettingsProvider provider;

    setUp(() {
      mockRepo = MockCameraSettingsRepo();
      provider = CameraSettingsProvider(repo: mockRepo);
    });

    test('loads saved quality setting on init', () async {
      // GIVEN a repo that returns a saved quality preference
      when(mockRepo.getQuality()).thenAnswer((_) async => 'high');

      // WHEN the provider initializes
      await provider.load();

      // THEN it exposes that preference
      expect(provider.quality, 'high');
    });

    test('notifies listeners exactly once when quality changes', () {
      final listener = MockListener();
      provider.addListener(listener.call);

      provider.setQuality('low');

      verify(listener.call()).called(1);
    });

    test('does not notify listeners when setting the same quality twice', () {
      provider.setQuality('low');
      final listener = MockListener();
      provider.addListener(listener.call);

      provider.setQuality('low');

      verifyNever(listener.call());
    });
  });
}

class MockListener extends Mock {
  void call();
}
```

## Widget Test Structure (via the shared harness)

```dart
import 'package:flutter_test/flutter_test.dart';
import '../../test_utils.dart';
import 'package:red_rabbit/screens/inspection/widgets/inspection_summary_card.dart';

void main() {
  testWidgets('shows the ticket count badge when tickets exist', (tester) async {
    await tester.pumpWidget(appWrapper(
      child: InspectionSummaryCard(ticketsCount: 3),
    ));

    expect(find.text('3 tickets'), findsOneWidget);
  });

  testWidgets('hides the badge when there are no tickets', (tester) async {
    await tester.pumpWidget(appWrapper(
      child: InspectionSummaryCard(ticketsCount: 0),
    ));

    expect(find.byKey(const Key('ticketsBadge')), findsNothing);
  });
}
```

## Golden Test Structure (separate, mirrored file)

```dart
// test/goldens/screens/inspection/inspection_overview_screen_test.dart
import 'package:flutter_test/flutter_test.dart';
import 'package:golden_toolkit/golden_toolkit.dart';
import '../../../test_utils.dart';
import 'package:red_rabbit/screens/inspection/inspection_overview_screen.dart';

void main() {
  testGoldens('InspectionOverviewScreen renders correctly with active tickets', (tester) async {
    await tester.pumpWidgetBuilder(
      appWrapper(child: const InspectionOverviewScreen()),
      surfaceSize: const Size(400, 800),
    );

    await screenMatchesGolden(tester, 'inspection_overview_screen_active_tickets');
  });
}
```

Golden files are captured/verified on macOS by developer convention — CI does not gate on golden diffs today (see project CI notes); still write them, don't skip the golden just because CI won't currently enforce it.

## Repository Test Structure (real SQLite via FFI)

```dart
import 'package:flutter_test/flutter_test.dart';
import '../../database_config.dart';
import 'package:red_rabbit/repositories/asset_group_repo.dart';

void main() {
  late AssetGroupRepo repo;

  setUpAll(() async {
    await DatabaseConfig.setup(userUuid: 'test-user');
    repo = AssetGroupRepo();
  });

  tearDown(() async {
    // clear table rows between tests, per project convention — don't drop the DB here
  });

  tearDownAll(() async {
    await DatabaseConfig.deleteDatabase();
  });

  test('saveBatch persists asset groups queryable by team', () async {
    // ... insert via model.saveBatch + commitBatch, then assert via repo query
  });
}
```

## Coverage Techniques Per Input Type

| Input Type | Technique | Tests to Generate |
|------------|-----------|-------------------|
| Nullable model field | EP | present, null |
| Enum-driven UI state | Decision | every enum case that changes rendered output |
| Async provider load | States | loading, success, error/exception |
| List of items (tickets, checklist items) | EP + BVA | empty, one, many |
| DB query with team/tenant scoping | Boundary | correct team, different team — RedRabbit is multi-tenant, verify isolation |
| Widget with conditional rendering | Decision | each branch (e.g. badge shown vs hidden) |

## What NOT to Unit Test

- Full multi-screen flows (login → create inspection) — that's `boschifai-gen-e2e-mobile`
- Firebase/Crashlytics/Analytics SDK internals — mock the wrapper, don't test the SDK
- Framework rendering internals unrelated to this app's logic

## Naming Convention

`testWidgets('<condition> renders/shows/hides <expected outcome>', ...)` for widgets; `test('<method> <condition> <expected outcome>', ...)` for providers/repos/services.

## Output File Naming

- Unit/widget test: `test/unit_tests/<mirrored lib/ path>/<name>_test.dart`
- Golden test: `test/goldens/<mirrored lib/ path>/<name>_test.dart` (separate file, same relative path)

## Coverage

No numeric threshold is enforced in-repo — gating happens via SonarCloud's quality gate on `coverage/lcov.info` (generated by `flutter test --coverage`, or the project's `./ribbit.sh coverage_test` helper). New tests should move coverage in the right direction for the touched file, consistent with the org's 40% baseline expectation, even though this repo doesn't hard-fail locally on it.

## Unit/Widget Test Generation Checklist

- [ ] File mirrors the `lib/` path 1:1 under `test/unit_tests/`
- [ ] Uses `appWrapper`/existing harness for widget tests, `DatabaseConfig.setup()` for repo tests — no ad-hoc `MaterialApp`/DB bootstrapping
- [ ] Mocks via `mockito` (codegen'd `.mocks.dart`), not hand-rolled fakes, unless the collaborator has no existing mock
- [ ] Golden test (if visual) lives in a separate file at the mirrored `test/goldens/` path
- [ ] Multi-tenant scoping tested for any repository/provider touching team-scoped data
- [ ] No full navigation/multi-screen flow — that belongs in `integration_test/`
- [ ] Async states (loading/success/error) covered for any Future-returning provider method

---
name: boschifai-gen-feature-php-laravel
description: "Component test generation rules for Laravel HTTP feature tests (PHPUnit 11, actingAs/getJson, SQLite in-memory) — extends boschifai-gen-component"
---

# Component Test Generation — PHP / Laravel Feature Tests

Tech-specific rules for generating Laravel `tests/Feature/` tests. Extends the base `boschifai-gen-component` skill. In this codebase family (RAMS, RedRabbit), a Laravel **Feature test** is the component-test boundary: it drives the app's own HTTP kernel and a real (in-memory) database, while external systems — the sibling app (RAMS↔RedRabbit), banking/credit-bureau APIs, queues, mail — are faked or mocked. This is the direct equivalent of `boschifai-gen-component-java-rest`'s `@SpringBootTest` + `MockMvc` pattern.

## Before Generating — Read the Project First

RAMS and RedRabbit share the same testing framework but differ in base classes and helpers:

1. Read `tests/TestCase.php` for the target repo. Confirm the `RefreshDatabase`-family trait (RAMS uses `RefreshDatabase`; RedRabbit uses `LazilyRefreshDatabase`) and the available `setUp*()` fixtures (`setUpTeam()`, `setUpAsset()`, `setUpLease()`, `setUpApiUser()` in RAMS; `setUpTeam()`, `setupUsers()`, `setUpTicket()`, `setUpTask()`, `setupInspections()`, `setUpDevice()` in RedRabbit).
2. Check for a domain-specific Feature base class before defaulting to `Tests\TestCase` — e.g. RedRabbit's `Tests\InspectionTestCase` (fluent builder: `->onTeam()->onAsset()->withAreas()->withChecklistItems()->setupInspection()`) is the correct parent for inspection-heavy controllers, not the plain `TestCase`.
3. Check `tests/Setup/` (RAMS factories) and `tests/Traits/` (RedRabbit mixins) for existing fixture builders before writing new ad-hoc factory logic inline.
4. If the controller under test calls into the other product (RAMS calling RedRabbit or vice versa), look at the existing `tests/Feature/RedRabbitApi/*` (RAMS) or `tests/Feature/RamsIntegration/*` (RedRabbit) tests first — these mock the sibling service's HTTP client, they do not call the real sibling app.
5. `tests/Contract/` in RAMS is currently dead/skipped (pending an uninstalled `pact-php` dependency, not registered in `phpunit.xml`) — do not use it as a pattern reference.

## Test Frameworks

| Type | Framework | Notes |
|------|-----------|-------|
| Test runner | PHPUnit 11.5+ | `#[Test]` attribute convention — method names carry no `it_`/`test_` prefix; `#[Test]` alone marks a method as a test, `#[Title(...)]` supplies its human-readable description |
| HTTP driver | Laravel's built-in `Illuminate\Foundation\Testing\TestCase` | `actingAs()`, `getJson()`/`postJson()`/`putJson()`/`deleteJson()` |
| Database | SQLite in-memory (`:memory:`) | Set via `phpunit.xml`; real migrations run per test via the refresh trait |
| Fakes for framework services | `Bus::fake()`, `Queue::fake()`, `Event::fake()`, `Mail::fake()`, `Notification::fake()` | Assert with `Queue::assertPushed(...)`, `Event::assertDispatched(...)` etc. |
| External HTTP mocking | Mockery on the injected client/service class (e.g. `RedRabbitService`, bank gateway clients) | Laravel's `Http::fake()` is also acceptable for raw HTTP-facade calls |
| Reporting | Qase (`qase/phpunit-reporter`) | `#[Suite]`/`#[Title]` — same convention as unit tests |

## Test File Convention

- Location: `tests/Feature/<Domain>/<Thing>ControllerTest.php` if under a domain subdirectory (e.g. `Banking/`, `DebitOrders/`, `Inspection/`), otherwise flat `tests/Feature/<Thing>ControllerTest.php`.
- Namespace: `Tests\Feature` (or `Tests\Feature\<Domain>`).
- Class extends the most specific applicable base: a domain Feature base class if one exists (e.g. `Tests\InspectionTestCase`), otherwise `Tests\TestCase`.

## Test Class Structure

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Qase\PHPUnitReporter\Attributes\Suite;
use Qase\PHPUnitReporter\Attributes\Title;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncAssetToRedRabbit;
use App\Enums\PermissionName;

#[Suite('Asset Management')]
final class AssetControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTeam();
        $this->setUpApiUser();
    }

    #[Test]
    #[Title('Returns 200 with the asset payload when the asset belongs to the caller\'s team')]
    public function returns_the_asset_for_the_owning_team(): void
    {
        // GIVEN an asset that belongs to the authenticated team
        $asset = $this->setUpAsset();

        // WHEN requesting the asset
        $response = $this->actingAs($this->user)->getJson(route('assets.show', $asset));

        // THEN the response contains the expected structure
        $response->assertOk()->assertJsonStructure([
            'id', 'name', 'address', 'team_id',
        ]);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    #[Test]
    #[Title('Returns 403 when the asset belongs to a different team')]
    public function forbids_access_to_another_teams_asset(): void
    {
        $otherTeamAsset = $this->setUpAsset(team: $this->createAnotherTeam());

        $response = $this->actingAs($this->user)->getJson(route('assets.show', $otherTeamAsset));

        $response->assertForbidden();
    }

    #[Test]
    #[Title('Returns 403 when the user lacks the assets.view permission')]
    public function forbids_access_without_permission(): void
    {
        $asset = $this->setUpAsset();
        $this->user->revokePermissionTo(PermissionName::AssetsView);

        $response = $this->actingAs($this->user)->getJson(route('assets.show', $asset));

        $response->assertForbidden();
    }

    #[Test]
    #[Title('Dispatches a RedRabbit sync job when an asset is created')]
    public function dispatches_sync_job_on_creation(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson(route('assets.store'), [
            'name' => 'Unit 4B, Sea Point Heights',
            'address' => '12 Main Road, Sea Point',
        ]);

        $response->assertCreated();
        Queue::assertPushed(SyncAssetToRedRabbit::class);
    }

    #[Test]
    #[Title('Returns 422 when required fields are missing')]
    public function returns_validation_errors_for_missing_fields(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('assets.store'), []);

        $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'address']);
    }
}
```

## Mocking External Systems (RAMS ↔ RedRabbit and third parties)

```php
// Mock the outbound service client — never call the real sibling app in a Feature test
$this->mock(RedRabbitService::class, function ($mock) {
    $mock->shouldReceive('syncAsset')->once()->andReturn(true);
});

// Or via Http::fake() for facade-based calls
Http::fake([
    'redrabbit.test/api/v2/*' => Http::response(['status' => 'ok'], 200),
]);

// Framework fakes for side effects
Queue::fake();
Event::fake();
Mail::fake();
```

## Required Test Categories Per Endpoint

| Category | What to Test | Min Count |
|----------|-------------|-----------|
| Happy path | 200/201 with correct JSON structure and DB state | 1 |
| Auth failure | Unauthenticated request | 1 |
| Authorization/permission failure | `assertForbidden()` when permission revoked or wrong team | 1 per permission gate |
| Multi-tenant isolation | Cross-team access attempt is rejected | 1 if the resource is team-scoped |
| Validation | 422 with field-level errors for missing/invalid input | 1 per required-field group |
| Side effects | Queue/Event/Mail assertions for anything the endpoint should trigger | 1 per side effect |
| Downstream failure | Sibling-app or bank/gateway client throws — verify graceful handling, not a 500 leak | 1 per external dependency, where the controller has explicit handling |
| Not found | 404 for a non-existent resource ID | 1 |

## Assertion Patterns

```php
$response->assertOk();
$response->assertCreated();
$response->assertForbidden();
$response->assertUnprocessable()->assertJsonValidationErrors(['field']);
$response->assertJsonStructure(['data' => ['id', 'name']]);
$response->assertJsonPath('data.status', 'approved');

$this->assertDatabaseHas('table', ['column' => $value]);
$this->assertDatabaseMissing('table', ['column' => $value]);
$this->assertModelExists($model);

Queue::assertPushed(JobClass::class);
Queue::assertPushedOn('queue-name', JobClass::class);
Event::assertDispatched(EventClass::class);
```

## What NOT to Feature-Test

- Pure calculation/mapping logic with no HTTP boundary — that belongs in `boschifai-gen-unit-php-laravel`
- Real calls to the sibling app (RAMS/RedRabbit) or any real third-party bank/credit-bureau API — always mock the client
- Anything gated behind `REDRABBIT_TESTING_ACTIVE`/`RedRabbitApiTestCase`'s live-integration skip — that path is an intentionally separate, opt-in live-integration test, not a standard Feature test

## Qase Attribute Convention

Same as `boschifai-gen-unit-php-laravel`: `#[Suite('<Domain>')]` on the class, `#[Title('<scenario>')]` on every test method. Apply to new/regenerated Feature tests even though existing coverage of this attribute is sparse.

## Component (Feature) Test Generation Checklist

- [ ] Read `tests/TestCase.php` (and any domain Feature base class) before writing anything
- [ ] Extends the correct base class for the domain (not always plain `Tests\TestCase`)
- [ ] `#[Test]` + `#[Title(...)]` on every method, `#[Suite(...)]` on the class — method name itself carries no `it_`/`test_` prefix
- [ ] Happy path, auth failure, permission failure, validation, not-found all covered
- [ ] Multi-tenant cross-team access test included if the resource is team-scoped
- [ ] External clients (sibling app, banking, credit bureau) mocked — never called for real
- [ ] Side-effect assertions use the matching fake (`Queue::fake()` + `assertPushed`, etc.)
- [ ] `assertDatabaseHas`/`assertModelExists` used for state verification, not just HTTP status
- [ ] File at `tests/Feature/<Domain>/<Thing>ControllerTest.php` matching existing sibling naming
- [ ] No test calls a real external endpoint (RedRabbit base URL, bank/XDS/Netcash/Compuscan) even under mistaken config

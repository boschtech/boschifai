---
name: boschifai-gen-unit-php-laravel
description: "Unit test generation rules for PHP/Laravel services (PHPUnit 11 attributes, Mockery, Faker) — extends boschifai-gen-unit"
---

# Unit Test Generation — PHP / Laravel (PHPUnit 11)

Tech-specific rules for generating unit tests in Laravel applications. Extends the base `boschifai-gen-unit` skill. Applies to RAMS and RedRabbit (Sinov8/WeconnectU) and any other Laravel + PHPUnit 11 project.

## Before Generating — Read the Project First

Laravel base test classes and available helpers differ between projects even when both use PHPUnit 11. Do not assume:

1. Read `tests/TestCase.php` — note the exact `RefreshDatabase`-family trait in use (`RefreshDatabase`, `LazilyRefreshDatabase`, or a project-specific `RefreshDatabaseFast`), and the domain `setUp*()` helpers already defined (e.g. `setUpTeam()`, `setUpAsset()`, `setUpApiUser()`).
2. Grep `tests/Unit/` for 2–3 sibling test files nearest the class under test to confirm current style (`#[Test]` attribute vs legacy `test_*` method names — both exist in RAMS and RedRabbit; prefer `#[Test]` for new tests but match the immediate neighbours if this file is clearly still on the legacy convention).
3. Check whether `tests/Setup/` (RAMS: fluent builder classes like `AssetFactory`) or `tests/Traits/` (RedRabbit: mixins like `CanCreateInspections`) already provides fixture-building helpers for the domain — reuse them, don't duplicate.
4. Check for existing Qase `#[Suite]`/`#[Title]` attribute usage on neighbouring tests (`use Qase\PHPUnitReporter\Attributes\{Suite,Title};`). It's inconsistently applied today — add it to new test classes as a forward-looking convention, don't strip it from files that already have it.

## Test Frameworks

| Type | Framework | Notes |
|------|-----------|-------|
| Test runner | PHPUnit 11.5+ | Attribute-based (`#[Test]`), not `test_` prefix, for new tests |
| Mocking | Mockery | `Mockery::mock()`, `shouldReceive()`, `andReturn()` |
| Test data | FakerPHP (`fakerphp/faker`) | Via Laravel model factories, or `fake()->...()` directly |
| Assertions | PHPUnit native + Laravel `TestCase` assertions | `assertSame`, `assertModelExists`, `assertDatabaseHas` (Feature-test only, not pure Unit) |
| Parallel runner | Paratest | Tests must be independent — no shared mutable state between test methods |
| Reporting | Qase (`qase/phpunit-reporter`) | Optional `#[Suite]`/`#[Title]` attributes; report upload gated by env, absence doesn't break the test |

## What Counts as a Unit Test Here

A **unit test** in these codebases exercises a single class or service method with all collaborators mocked — no HTTP kernel, no route resolution, no real database rows beyond what a plain PHPUnit `TestCase` needs. If the code under test needs `actingAs()`, `getJson()`/`postJson()`, or asserts on HTTP status codes, it belongs in `tests/Feature/` — use `boschifai-gen-component-php-laravel` instead.

| Scenario | Unit Test | Feature Test Instead |
|----------|-----------|----------------------|
| Service class method with mocked repository/client | **Yes** | No |
| Value object / calculator / mapper (e.g. `MoneyHelper`, `VatHelperUnitTest`) | **Yes** | No |
| Enum behavior, permission logic in isolation | **Yes** | No |
| Job/Listener logic without dispatching through the queue | **Yes** | No |
| Anything hitting a route via `actingAs()->getJson(...)` | No | **Yes** |
| Anything asserting `assertDatabaseHas` against a real migrated schema | No | **Yes** |

## Test File Convention

- Location: `tests/Unit/<Domain>/<Thing>Test.php` if the class under test lives in a namespaced `app/` subdirectory; flat `tests/Unit/<Thing>Test.php` otherwise (existing convention mixes `<Thing>Test.php` and `<Thing>UnitTest.php` — match the suffix already used for sibling classes of the same domain, default to `Test.php` for new domains).
- Namespace: `Tests\Unit` (or `Tests\Unit\<Domain>` matching the directory).
- Class: `final class <Thing>Test extends Tests\TestCase` (plain `Tests\TestCase`, not a Feature-only subclass like `Tests\InspectionTestCase`).

## Test Class Structure

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Qase\PHPUnitReporter\Attributes\Suite;
use Qase\PHPUnitReporter\Attributes\Title;
use Mockery;
use App\Services\PaymentService;
use App\Contracts\PaymentGatewayClient;

#[Suite('Payment Service')]
final class PaymentServiceTest extends TestCase
{
    private PaymentGatewayClient $gatewayClient;
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gatewayClient = Mockery::mock(PaymentGatewayClient::class);
        $this->service = new PaymentService($this->gatewayClient);
    }

    #[Test]
    #[Title('Returns a successful result when the gateway approves the payment')]
    public function it_returns_success_when_gateway_approves(): void
    {
        // GIVEN a gateway that will approve any charge
        $this->gatewayClient
            ->shouldReceive('charge')
            ->once()
            ->with(10000, 'ZAR')
            ->andReturn(['status' => 'approved', 'reference' => 'REF-123']);

        // WHEN charging the payment service for R100.00
        $result = $this->service->charge(10000, 'ZAR');

        // THEN the result reflects the approval
        $this->assertTrue($result->isApproved());
        $this->assertSame('REF-123', $result->reference());
    }

    #[Test]
    #[Title('Throws a domain exception when the gateway declines the payment')]
    public function it_throws_when_gateway_declines(): void
    {
        $this->gatewayClient
            ->shouldReceive('charge')
            ->once()
            ->andReturn(['status' => 'declined', 'reason' => 'insufficient_funds']);

        $this->expectException(\App\Exceptions\PaymentDeclinedException::class);

        $this->service->charge(10000, 'ZAR');
    }

    #[Test]
    #[DataProvider('invalidAmountProvider')]
    public function it_rejects_invalid_amounts(int $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->charge($amount, 'ZAR');
    }

    public static function invalidAmountProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-100],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

## Mocking Patterns

### Mockery for injected dependencies

```php
$client = Mockery::mock(RedRabbitService::class);
$client->shouldReceive('syncAsset')->once()->with($assetId)->andReturn(true);
```

### Partial mocks for spying on real objects (matches `UserAuthenticationTest`'s `OTPSMSClient::spy()` pattern)

```php
$spy = $this->partialMock(OTPSMSClient::class, function ($mock) {
    $mock->shouldReceive('send')->once();
});
```

### Faker for realistic, non-placeholder test data

```php
$email = fake()->unique()->safeEmail();
$amount = fake()->numberBetween(100, 500000); // cents
```

Never use `Mockery::mock()` on a class you don't own without an interface/contract boundary — mock at the collaborator boundary (repository, HTTP client, gateway), not on internal value objects.

## Coverage Techniques Per Input Type

| Input Type | Technique | Tests to Generate |
|------------|-----------|-------------------|
| Money/cents (int) | BVA | 0, negative, 1 cent, typical amount, max allowed |
| Enum (e.g. `PermissionName`, `TicketStatus`) | Decision | every enum case that changes behavior |
| Nullable model relation | EP | present, null, soft-deleted |
| Collection/array of models | EP + BVA | empty, one, many |
| External client response | State | success, declined/error shape, exception thrown, timeout |
| Team/tenant scoping | Boundary | correct team, different team (must not leak — RAMS/RedRabbit are both multi-tenant) |

## What NOT to Unit Test

- Anything requiring `actingAs()` or route resolution — that's a Feature test (`boschifai-gen-component-php-laravel`)
- Laravel framework internals (Eloquent relation mechanics, validation rule internals)
- Trivial getters/casts with no branching
- The `RedRabbitService`/RAMS-sync classes' actual HTTP calls — mock the client, test the service logic only (see `RedRabbitApi/RedRabbitServiceAssetTest.php` for the existing pattern)

## Naming Convention

Method names: `it_<condition>_<expected_outcome>` (snake_case is the established convention here, not camelCase) paired with a `#[Title('...')]` sentence for Qase.

Good: `it_throws_when_gateway_declines`
Bad: `test1`, `testCharge`

## Qase Attribute Convention (apply to new test classes)

```php
use Qase\PHPUnitReporter\Attributes\Suite;
use Qase\PHPUnitReporter\Attributes\Title;

#[Suite('<Domain Area>')]
final class ExampleTest extends TestCase
{
    #[Test]
    #[Title('<human-readable scenario description>')]
    public function it_does_the_thing(): void { /* ... */ }
}
```

This is currently applied to only a handful of files in RAMS and none in RedRabbit — treat it as the target convention for new/regenerated tests, not a hard gate that blocks generation if the surrounding suite lacks it.

## Unit Test Generation Checklist

- [ ] Read `tests/TestCase.php` and 2–3 sibling tests before writing anything
- [ ] Extends plain `Tests\TestCase` (not a Feature-only subclass)
- [ ] `#[Test]` attribute + `#[Title(...)]` on every test method
- [ ] `#[Suite('<Domain>')]` on the class
- [ ] All collaborators mocked with Mockery; `Mockery::close()` in `tearDown()`
- [ ] Happy path, error/exception path, and boundary values covered
- [ ] Multi-tenant scoping tested where the class touches team-scoped data
- [ ] No `actingAs()`, no `getJson`/`postJson`, no `assertDatabaseHas` (would belong in Feature)
- [ ] Faker used for realistic data, no magic placeholder strings
- [ ] File at `tests/Unit/<Domain>/<Thing>Test.php` matching existing sibling naming

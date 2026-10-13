<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/** The default credit packs (D5) — used whenever BILLING_PACKS is empty, as phpunit.xml sets it. */
class DefaultCreditPacksTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    public function test_the_defaults_are_a_small_entry_pack_then_larger_packs_with_bonus_credits(): void
    {
        $this->assertSame([
            'starter' => ['credits_usd' => '10', 'price_usd' => '10'],
            'team' => ['credits_usd' => '55', 'price_usd' => '50'],
            'business' => ['credits_usd' => '115', 'price_usd' => '100'],
            'scale' => ['credits_usd' => '300', 'price_usd' => '250'],
        ], config('billing.packs'));
    }

    public function test_billing_shows_each_packs_price_and_bonus(): void
    {
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.packs', [
            ['key' => 'starter', 'credits_usd' => '10.00', 'price_usd' => '10.00', 'bonus_usd' => null],
            ['key' => 'team', 'credits_usd' => '55.00', 'price_usd' => '50.00', 'bonus_usd' => '5.00'],
            ['key' => 'business', 'credits_usd' => '115.00', 'price_usd' => '100.00', 'bonus_usd' => '15.00'],
            ['key' => 'scale', 'credits_usd' => '300.00', 'price_usd' => '250.00', 'bonus_usd' => '50.00'],
        ]);
    }
}

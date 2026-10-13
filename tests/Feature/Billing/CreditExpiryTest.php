<?php

namespace Tests\Feature\Billing;

use App\Enums\CreditEntryType;
use App\Enums\PaymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\CreditLedgerEntry;
use App\Models\CreditLot;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CreditsExpiringNotification;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SignsInUser;
use Tests\TestCase;

/**
 * Credits stay usable for 36 months from purchase (CPA s63), the oldest are used first, owners
 * and admins are reminded 60 and 14 days ahead, and what's left then expires — never credits
 * held for a running step.
 */
class CreditExpiryTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-01-15 10:00:00');
        $this->ledger = app(CreditLedger::class);
    }

    private function buy(int $micro, string $ref = 'p1'): Payment
    {
        $payment = Payment::create(['workspace_id' => $this->workspace()->id, 'provider' => 'fake', 'provider_ref' => $ref, 'pack' => 'x', 'credits_micro' => $micro, 'price_usd_micro' => $micro, 'charge_currency' => 'USD', 'charge_amount_minor' => intdiv($micro, 10_000), 'status' => PaymentStatus::Succeeded]);
        $this->ledger->purchase($payment);

        return $payment;
    }

    private function available(): int
    {
        return $this->ledger->balance($this->workspace())->available_micro;
    }

    // ── Lots ───────────────────────────────────────────────────────────────────────────

    public function test_purchases_and_grants_last_36_months_and_repeating_one_adds_no_second_lot(): void
    {
        $payment = $this->buy(10_000_000);
        $this->ledger->purchase($payment);
        $this->ledger->adjust($this->workspace(), 5_000_000, null, 'Goodwill', 'g1');

        $lots = CreditLot::orderBy('id')->get();
        $this->assertSame([['purchase', 10_000_000], ['adjustment', 5_000_000]], $lots->map(fn ($l) => [$l->kind, $l->remaining_micro])->all());
        $this->assertSame('2029-01-15', $lots[0]->expires_at->toDateString());
        $this->assertTrue($this->ledger->isConsistent($this->workspace()));
    }

    public function test_charges_and_debits_use_the_credits_that_expire_soonest(): void
    {
        $this->buy(3_000_000, 'old');
        $this->travel(30)->days();
        $this->buy(10_000_000, 'new');

        $hold = $this->ledger->hold($this->workspace(), 5_000_000, 'h1');
        $this->ledger->settle($hold, 4_000_000);
        $this->ledger->adjust($this->workspace(), -1_000_000, null, 'Correction', 'c1');

        $this->assertSame([0, 8_000_000], CreditLot::orderBy('expires_at')->pluck('remaining_micro')->all());
        $this->assertTrue($this->ledger->isConsistent($this->workspace()));
    }

    // ── Expiring ───────────────────────────────────────────────────────────────────────

    public function test_whats_left_of_a_lot_expires_on_its_date_once(): void
    {
        Notification::fake();
        $this->buy(10_000_000, 'old');
        $this->travel(20)->days();
        $this->buy(4_000_000, 'new');
        $this->ledger->settle($this->ledger->hold($this->workspace(), 6_000_000, 'h1'), 6_000_000); // all from the old lot

        $this->travelTo('2029-01-15 10:00:01');
        $this->artisan('boschifai:expire-credits')
            ->expectsOutputToContain('Expired US$4.00 of workspace')
            // The newer purchase is now 20 days from its own expiry, so it gets its reminder.
            ->expectsOutput('Expired 1 lot(s); sent 1 reminder(s).')
            ->assertSuccessful();
        $this->artisan('boschifai:expire-credits')->expectsOutput('Expired 0 lot(s); sent 0 reminder(s).')->assertSuccessful();

        $this->assertSame(4_000_000, $this->available(), 'Only the newer purchase is left.');
        $entry = CreditLedgerEntry::where('type', CreditEntryType::Expiry)->sole();
        $this->assertSame([-4_000_000, 'Credits expired 2029-01-15.'], [$entry->available_delta_micro, $entry->reason]);
        $this->assertTrue($this->ledger->isConsistent($this->workspace()));
    }

    public function test_credits_held_for_a_running_step_are_never_expired_out_from_under_it(): void
    {
        $this->buy(10_000_000);
        $hold = $this->ledger->hold($this->workspace(), 8_000_000, 'h1');

        $this->travelTo('2029-01-16 00:00:00');
        $this->artisan('boschifai:expire-credits')->assertSuccessful();
        $this->assertSame([0, 8_000_000], [$this->available(), $this->ledger->balance($this->workspace())->held_micro], 'Only the 2.00 not held expired.');

        // The step finishes using 3.00; the unused 5.00 comes back and expires on the next run.
        $this->ledger->settle($hold, 3_000_000);
        $this->assertSame(5_000_000, $this->available());
        $this->artisan('boschifai:expire-credits')->assertSuccessful();

        $this->assertSame(0, $this->available());
        $this->assertSame(0, (int) CreditLot::sum('remaining_micro'));
        $this->assertTrue($this->ledger->isConsistent($this->workspace()));
    }

    public function test_expiring_does_nothing_before_the_date_or_while_everything_is_held(): void
    {
        $this->buy(5_000_000);
        $lot = CreditLot::sole();
        $this->assertSame(0, $this->ledger->expire($lot), 'Not due yet.');

        $this->ledger->hold($this->workspace(), 5_000_000, 'h1');
        $this->travelTo('2029-02-01 00:00:00');
        $this->assertSame(0, $this->ledger->expire($lot), 'Everything is held for a running step.');
        $this->assertSame(0, CreditLedgerEntry::where('type', CreditEntryType::Expiry)->count());
    }

    public function test_a_purchase_must_add_credits(): void
    {
        $payment = Payment::make(['workspace_id' => $this->workspace()->id, 'credits_micro' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->purchase($payment);
    }

    // ── Reminders ──────────────────────────────────────────────────────────────────────

    public function test_owners_and_admins_are_reminded_60_and_14_days_ahead_once_each(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $this->workspace()->addMember($admin, WorkspaceRole::Admin);
        $this->workspace()->addMember($member, WorkspaceRole::Member);
        $this->buy(7_500_000);

        $this->travelTo('2028-11-10 10:00:00'); // 66 days before
        $this->artisan('boschifai:expire-credits')->expectsOutput('Expired 0 lot(s); sent 0 reminder(s).');

        $this->travelTo('2028-11-20 10:00:00'); // 56 days before
        $this->artisan('boschifai:expire-credits')->expectsOutput('Expired 0 lot(s); sent 1 reminder(s).');
        $this->artisan('boschifai:expire-credits')->expectsOutput('Expired 0 lot(s); sent 0 reminder(s).');

        $this->travelTo('2029-01-05 10:00:00'); // 10 days before
        $this->artisan('boschifai:expire-credits')->expectsOutput('Expired 0 lot(s); sent 1 reminder(s).');

        Notification::assertSentTo([$this->user, $admin], CreditsExpiringNotification::class, 2);
        Notification::assertNotSentTo($member, CreditsExpiringNotification::class);
        Notification::assertSentTo($admin, CreditsExpiringNotification::class, function ($notification) use ($admin) {
            $mail = $notification->toMail($admin);

            return $mail->subject === 'US$7.50 of BoschifAI credits expire on Mon, Jan 15, 2029'
                && str_contains(implode(' ', $mail->introLines), 'Credits stay usable for 36 months from purchase');
        });
    }

    public function test_a_workspace_with_nothing_left_gets_no_reminder(): void
    {
        Notification::fake();
        $this->buy(1_000_000);
        $this->ledger->settle($this->ledger->hold($this->workspace(), 1_000_000, 'h'), 1_000_000);

        $this->travelTo('2029-01-05 10:00:00');
        $this->artisan('boschifai:expire-credits')->expectsOutput('Expired 0 lot(s); sent 0 reminder(s).');

        Notification::assertNothingSent();
    }

    // ── Shown on the billing page ──────────────────────────────────────────────────────

    public function test_billing_shows_how_long_credits_last_and_what_expires_next(): void
    {
        $this->buy(10_000_000, 'a');
        $this->travel(5)->days();
        $this->buy(4_000_000, 'b');

        $this->getJson('/api/billing')->assertOk()
            ->assertJsonPath('data.credit_validity_months', 36)
            ->assertJsonPath('data.next_expiry.amount_usd', '10.00')
            ->assertJsonPath('data.next_expiry.expires_at', '2029-01-15T10:00:00.000000Z');

    }
}

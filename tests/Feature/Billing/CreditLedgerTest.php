<?php

namespace Tests\Feature\Billing;

use App\Enums\CreditEntryType;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\InsufficientCredits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(CreditLedger::class);
    }

    private function funded(int $micro = 10_000_000): Workspace
    {
        $workspace = $this->workspace();
        $this->ledger->adjust($workspace, $micro, null, 'Starting credit', 'start');

        return $workspace;
    }

    private function assertBalance(Workspace $workspace, int $available, int $held): void
    {
        $balance = $this->ledger->balance($workspace);
        $this->assertSame([$available, $held], [$balance->available_micro, $balance->held_micro]);
        $this->assertTrue($this->ledger->isConsistent($workspace), 'The cached balance must equal the ledger sums.');
    }

    public function test_a_new_workspace_has_no_credits(): void
    {
        $this->assertBalance($this->workspace(), 0, 0);
    }

    public function test_an_adjustment_credits_or_debits_with_its_reason_and_actor(): void
    {
        $admin = User::factory()->create();
        $workspace = $this->funded(5_000_000);

        $entry = $this->ledger->adjust($workspace, -2_000_000, $admin, '  Duplicate grant  ', 'fix-1');

        $this->assertSame(CreditEntryType::Adjustment, $entry->type);
        $this->assertSame('Duplicate grant', $entry->reason);
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertBalance($workspace, 3_000_000, 0);
    }

    public function test_an_adjustment_needs_an_amount_and_a_reason_and_cannot_overdraw(): void
    {
        $workspace = $this->funded(1_000_000);

        foreach ([[0, 'x'], [5, '  ']] as [$micro, $reason]) {
            try {
                $this->ledger->adjust($workspace, $micro, null, $reason, "bad-{$micro}");
                $this->fail('Expected the adjustment to be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InsufficientCredits::class);
        $this->ledger->adjust($workspace, -1_000_001, null, 'Too much', 'over');
    }

    public function test_a_hold_moves_credits_from_available_to_held(): void
    {
        $workspace = $this->funded();

        $hold = $this->ledger->hold($workspace, 3_000_000, 'hold-1');

        $this->assertSame(CreditEntryType::Hold, $hold->type);
        $this->assertBalance($workspace, 7_000_000, 3_000_000);
    }

    public function test_a_hold_that_does_not_fit_records_nothing(): void
    {
        $workspace = $this->funded(1_000_000);

        try {
            $this->ledger->hold($workspace, 1_500_000, 'hold-1');
            $this->fail('Expected InsufficientCredits.');
        } catch (InsufficientCredits $e) {
            $this->assertSame(1_000_000, $e->availableMicro);
            $this->assertSame(1_500_000, $e->requiredMicro);
            $this->assertSame('Not enough credits: US$1.50 needed, US$1.00 available.', $e->getMessage());
        }

        $this->assertSame(1, CreditLedgerEntry::count());
        $this->assertBalance($workspace, 1_000_000, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->hold($workspace, 0, 'hold-0');
    }

    public function test_settling_charges_part_of_a_hold_and_returns_the_rest(): void
    {
        $workspace = $this->funded();
        $hold = $this->ledger->hold($workspace, 3_000_000, 'hold-1');

        $charge = $this->ledger->settle($hold, 1_250_000);

        $this->assertSame(CreditEntryType::Charge, $charge->type);
        $this->assertSame($hold->id, $charge->related_entry_id);
        $release = CreditLedgerEntry::where('type', CreditEntryType::Release)->sole();
        $this->assertSame([1_750_000, -1_750_000], [$release->available_delta_micro, $release->held_delta_micro]);
        $this->assertBalance($workspace, 8_750_000, 0);
    }

    public function test_settling_the_whole_hold_leaves_nothing_to_release(): void
    {
        $workspace = $this->funded();
        $hold = $this->ledger->hold($workspace, 3_000_000, 'hold-1');

        $this->ledger->settle($hold, 3_000_000);

        $this->assertFalse(CreditLedgerEntry::where('type', CreditEntryType::Release)->exists());
        $this->assertBalance($workspace, 7_000_000, 0);
    }

    public function test_releasing_returns_the_whole_hold(): void
    {
        $workspace = $this->funded();
        $hold = $this->ledger->hold($workspace, 3_000_000, 'hold-1');

        $this->ledger->release($hold);

        $this->assertFalse(CreditLedgerEntry::where('type', CreditEntryType::Charge)->exists());
        $this->assertBalance($workspace, 10_000_000, 0);
    }

    public function test_repeating_a_movement_with_the_same_key_moves_credits_only_once(): void
    {
        $workspace = $this->funded();

        $first = $this->ledger->hold($workspace, 3_000_000, 'hold-1');
        $again = $this->ledger->hold($workspace, 3_000_000, 'hold-1');
        $this->assertTrue($first->is($again));

        $charge = $this->ledger->settle($first, 1_000_000);
        $this->assertTrue($charge->is($this->ledger->settle($first, 1_000_000)));

        $this->assertBalance($workspace, 9_000_000, 0);
    }

    public function test_a_released_hold_can_be_released_again_harmlessly_but_not_charged(): void
    {
        $workspace = $this->funded();
        $hold = $this->ledger->hold($workspace, 3_000_000, 'hold-1');
        $this->ledger->release($hold);
        $this->ledger->release($hold);
        $this->assertBalance($workspace, 10_000_000, 0);

        $this->expectException(LogicException::class);
        $this->ledger->settle($hold, 500_000);
    }

    public function test_a_key_reused_for_a_different_movement_is_refused(): void
    {
        $workspace = $this->funded();
        $this->ledger->hold($workspace, 3_000_000, 'hold-1');

        $this->expectException(LogicException::class);
        $this->ledger->hold($workspace, 2_000_000, 'hold-1');
    }

    public function test_only_a_hold_can_be_settled_and_never_for_more_than_was_held(): void
    {
        $workspace = $this->funded();
        $hold = $this->ledger->hold($workspace, 3_000_000, 'hold-1');

        try {
            $this->ledger->settle(CreditLedgerEntry::where('idempotency_key', 'start')->sole(), 0);
            $this->fail('Expected only holds to be settleable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->settle($hold, 3_000_001);
    }

    public function test_workspaces_have_separate_balances(): void
    {
        $this->funded();
        $other = Workspace::create(['name' => 'Other']);

        $this->assertBalance($other, 0, 0);
        $this->expectException(InsufficientCredits::class);
        $this->ledger->hold($other, 1, 'other-hold');
    }

    public function test_ledger_entries_cannot_be_changed_or_deleted(): void
    {
        $this->funded();
        $entry = CreditLedgerEntry::sole();

        foreach ([fn () => $entry->update(['reason' => 'edited']), fn () => $entry->delete()] as $tamper) {
            try {
                $tamper();
                $this->fail('Expected the ledger to refuse.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Starting credit', $entry->fresh()->reason);
    }

    public function test_an_out_of_band_balance_change_is_detected(): void
    {
        $workspace = $this->funded();
        $this->ledger->balance($workspace)->update(['available_micro' => 1]);

        $this->assertFalse($this->ledger->isConsistent($workspace));
    }
}

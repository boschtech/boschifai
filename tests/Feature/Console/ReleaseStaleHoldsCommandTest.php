<?php

namespace Tests\Feature\Console;

use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseStaleHoldsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_old_unsettled_holds_are_released(): void
    {
        config(['boschifai.claude.timeouts' => ['gap_analysis' => 600, 'code_generation' => 1800]]);
        $ledger = app(CreditLedger::class);
        $workspace = $this->workspace();
        $ledger->adjust($workspace, 10_000_000, null, 'Start', 'start');

        $this->travelTo(now()->subMinutes(61));
        $stale = $ledger->hold($workspace, 1_000_000, 'stale');
        $settled = $ledger->hold($workspace, 2_000_000, 'settled');
        $ledger->settle($settled, 500_000);
        $this->travelBack();
        $this->travelTo(now()->subMinutes(59));
        $running = $ledger->hold($workspace, 3_000_000, 'still-running');
        $this->travelBack();

        // Default threshold: the longest step timeout (30 min) + 30 minutes = 60 minutes.
        $this->artisan('boschifai:release-stale-holds')
            ->expectsOutput("Released hold {$stale->id} (workspace {$workspace->id}, run —): US$1.00")
            ->expectsOutput('Released 1 stale hold(s).')
            ->assertSuccessful();

        $balance = $ledger->balance($workspace);
        $this->assertSame([6_500_000, 3_000_000], [$balance->available_micro, $balance->held_micro], 'Only the still-running hold is left held.');
        $this->assertTrue($ledger->isConsistent($workspace));

        $this->artisan('boschifai:release-stale-holds')->expectsOutput('No holds older than 60 minutes are waiting to be settled.')->assertSuccessful();

        $this->artisan('boschifai:release-stale-holds', ['--minutes' => 1])
            ->expectsOutput("Released hold {$running->id} (workspace {$workspace->id}, run —): US$3.00")
            ->assertSuccessful();
    }
}

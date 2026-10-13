<?php

namespace Tests\Feature\Console;

use App\Enums\ChargeOutcome;
use App\Models\AuditEvent;
use App\Models\ClaudeInvocation;
use App\Models\CreditLedgerEntry;
use App\Models\InvocationCharge;
use App\Models\RepoConfig;
use App\Models\Run;
use App\Models\RunStep;
use App\Services\Billing\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCommandsTest extends TestCase
{
    use RefreshDatabase;

    // ── boschifai:adjust-credits ───────────────────────────────────────────────────────

    public function test_an_admin_adds_credits_with_a_reason_and_it_is_audited(): void
    {
        $workspace = $this->workspace();

        $this->artisan('boschifai:adjust-credits', ['workspace' => $workspace->slug, 'amount' => '25', '--reason' => 'Starting credit'])
            ->expectsConfirmation('Add US$25.00 to "Test Workspace"?', 'yes')
            ->expectsOutput('Done. Available credits: US$25.00')
            ->assertSuccessful();

        $entry = CreditLedgerEntry::sole();
        $this->assertSame([25_000_000, 'Starting credit'], [$entry->available_delta_micro, $entry->reason]);
        $audit = AuditEvent::where('action', 'credits.adjusted')->sole();
        $this->assertSame(['amount_usd' => '25.00', 'reason' => 'Starting credit'], $audit->meta);
    }

    public function test_credits_can_be_removed_by_workspace_id_but_never_below_zero(): void
    {
        $workspace = $this->workspace();
        app(CreditLedger::class)->adjust($workspace, 10_000_000, null, 'Start', 'start');

        $this->artisan('boschifai:adjust-credits', ['workspace' => (string) $workspace->id, 'amount' => '-2.5', '--reason' => 'Refund'])
            ->expectsConfirmation('Remove US$2.50 from "Test Workspace"?', 'yes')
            ->expectsOutput('Done. Available credits: US$7.50')
            ->assertSuccessful();

        $this->artisan('boschifai:adjust-credits', ['workspace' => (string) $workspace->id, 'amount' => '-8', '--reason' => 'Too much'])
            ->expectsConfirmation('Remove US$8.00 from "Test Workspace"?', 'yes')
            ->expectsOutput('Not enough credits: US$8.00 needed, US$7.50 available.')
            ->assertFailed();
    }

    public function test_nothing_changes_without_confirmation_or_with_bad_input(): void
    {
        $slug = $this->workspace()->slug;

        $this->artisan('boschifai:adjust-credits', ['workspace' => $slug, 'amount' => '5', '--reason' => 'Gift'])
            ->expectsConfirmation('Add US$5.00 to "Test Workspace"?', 'no')
            ->expectsOutput('Nothing changed.')
            ->assertFailed();

        $refusals = [
            [['workspace' => 'nope', 'amount' => '5', '--reason' => 'x'], 'No workspace with that id or slug.'],
            [['workspace' => $slug, 'amount' => '5$', '--reason' => 'x'], 'The amount must be a number of US dollars, e.g. 25 or -12.50.'],
            [['workspace' => $slug, 'amount' => '0', '--reason' => 'x'], 'The amount can\'t be zero.'],
            [['workspace' => $slug, 'amount' => '5'], 'Give a --reason; it is kept with the entry.'],
        ];
        foreach ($refusals as [$arguments, $message]) {
            $this->artisan('boschifai:adjust-credits', $arguments)->expectsOutput($message)->assertFailed();
        }

        $this->assertSame(0, CreditLedgerEntry::count());
    }

    // ── boschifai:billing-report ───────────────────────────────────────────────────────

    private ?Run $run = null;

    private function charge(string $step, ChargeOutcome $outcome, ?int $cost, int $charge, int $markup = 2_000_000): void
    {
        $run = $this->run ??= Run::create([
            'repo_config_id' => RepoConfig::create(['workspace_id' => $this->workspace()->id, 'name' => 'r', 'display_name' => 'r', 'git_remote_path' => '/r', 'base_branch' => 'main'])->id,
            'requirement_text' => 'x', 'target_file_path' => 'x', 'state' => 'failed',
        ]);
        $step = RunStep::firstOrCreate(['run_id' => $run->id, 'key' => $step], ['status' => 'succeeded']);
        $invocation = ClaudeInvocation::create(['run_step_id' => $step->id, 'command' => '/x', 'prompt_text' => 'x']);

        InvocationCharge::create([
            'workspace_id' => $this->workspace()->id, 'claude_invocation_id' => $invocation->id, 'step_key' => $step->key,
            'outcome' => $outcome, 'cost_micro' => $cost, 'hold_micro' => null, 'charge_micro' => $charge,
            'markup_millionths' => $markup, 'enforced' => false,
        ]);
    }

    public function test_the_report_says_when_there_is_nothing_to_show(): void
    {
        config(['billing.markup' => null]);

        $this->artisan('boschifai:billing-report')
            ->expectsOutput('Last 30 days · markup not set (priced at cost) · minimum charge US$0.00')
            ->expectsOutput('No priced steps in this period yet.')
            ->assertSuccessful();
    }

    public function test_the_report_shows_cost_spread_charges_and_what_holds_absorbed(): void
    {
        config(['billing.markup' => '2', 'billing.min_charge_usd' => '0.01', 'billing.stage_holds_usd.code_generation' => '1.50']);
        $this->charge('code_generation', ChargeOutcome::Charged, 500_000, 1_000_000);
        $this->charge('code_generation', ChargeOutcome::Charged, 1_000_000, 1_500_000); // capped: 0.50 absorbed
        $this->charge('code_generation', ChargeOutcome::Released, 200_000, 0);
        $this->charge('gap_analysis', ChargeOutcome::Partial, null, 0);

        $this->artisan('boschifai:billing-report', ['--days' => 7])
            ->expectsOutput('Last 7 days · markup 2.00× · minimum charge US$0.01')
            ->expectsTable(
                ['Step', 'Runs', 'Charged / partial / free', 'Cost avg', 'Cost p95', 'Cost max', 'Hold', 'Charged', 'Absorbed'],
                [
                    ['code_generation', 3, '2 / 0 / 1', '0.5666', '1.0000', '1.0000', '1.5000', '2.5000', '0.5000'],
                    ['gap_analysis', 1, '0 / 1 / 0', '—', '—', '—', 'not set', '0.0000', '0.0000'],
                ],
            )
            ->expectsOutput('Total cost US$1.7000 · charged US$2.5000 · margin US$0.8000')
            ->assertSuccessful();
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\CreditEntryType;
use App\Models\CreditLedgerEntry;
use App\Services\Billing\CreditLedger;
use App\Support\Billing\MicroUsd;
use Illuminate\Console\Command;

/**
 * Returns credit holds that were never settled — e.g. the worker died mid-step, or recording
 * the charge failed. A hold is only stale once it's older than the longest any step may run, so
 * a step that is still running is never touched. Releasing charges nothing: the platform absorbs
 * a step whose outcome was lost. Safe to run repeatedly (e.g. hourly from cron).
 */
class ReleaseStaleHoldsCommand extends Command
{
    protected $signature = 'boschifai:release-stale-holds
        {--minutes= : Only holds older than this (default: the longest step timeout + 30 minutes)}';

    protected $description = 'Return credit holds left behind by steps that never finished';

    public function handle(CreditLedger $ledger): int
    {
        $minutes = $this->option('minutes') !== null
            ? max(1, (int) $this->option('minutes'))
            : (int) ceil(max(config('boschifai.claude.timeouts')) / 60) + 30;

        $stale = CreditLedgerEntry::where('type', CreditEntryType::Hold)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->whereNotExists(fn ($settled) => $settled->from('credit_ledger_entries as settled')
                ->whereColumn('settled.related_entry_id', 'credit_ledger_entries.id'))
            ->orderBy('id')
            ->get();

        foreach ($stale as $hold) {
            $ledger->release($hold);
            $this->line(sprintf('Released hold %d (workspace %d, run %s): US$%s', $hold->id, $hold->workspace_id, $hold->run_id ?? '—', MicroUsd::format($hold->held_delta_micro, 2)));
        }

        $this->info($stale->isEmpty() ? "No holds older than {$minutes} minutes are waiting to be settled." : "Released {$stale->count()} stale hold(s).");

        return self::SUCCESS;
    }
}

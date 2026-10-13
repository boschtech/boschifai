<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Workspace;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\InsufficientCredits;
use App\Support\Billing\MicroUsd;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A platform admin's manual credit or debit — e.g. starting credit for a workspace, or a
 * goodwill top-up. Always needs a reason, asks for confirmation, and is audited.
 */
class AdjustCreditsCommand extends Command
{
    protected $signature = 'boschifai:adjust-credits
        {workspace : Workspace id or slug}
        {amount : Credits in USD, e.g. 25 or 12.50 (negative to take credits away)}
        {--reason= : Why — stored with the entry and in the audit log}';

    protected $description = 'Add or remove a workspace\'s prepaid credits';

    public function handle(CreditLedger $ledger): int
    {
        $workspace = Workspace::where('slug', $this->argument('workspace'))->first()
            ?? (ctype_digit((string) $this->argument('workspace')) ? Workspace::find($this->argument('workspace')) : null);
        if (! $workspace) {
            $this->error('No workspace with that id or slug.');

            return self::FAILURE;
        }

        try {
            $micro = MicroUsd::parse((string) $this->argument('amount'));
        } catch (InvalidArgumentException) {
            $this->error('The amount must be a number of US dollars, e.g. 25 or -12.50.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($micro === 0 || $reason === '') {
            $this->error($micro === 0 ? 'The amount can\'t be zero.' : 'Give a --reason; it is kept with the entry.');

            return self::FAILURE;
        }

        $verb = $micro > 0 ? 'Add' : 'Remove';
        if (! $this->confirm(sprintf('%s US$%s %s "%s"?', $verb, MicroUsd::format(abs($micro), 2), $micro > 0 ? 'to' : 'from', $workspace->name))) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $entry = $ledger->adjust($workspace, $micro, null, $reason, 'adjustment:'.Str::uuid());
        } catch (InsufficientCredits $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        AuditEvent::record('credits.adjusted', $workspace, null, $entry, ['amount_usd' => MicroUsd::format($micro, 2), 'reason' => $reason]);
        $this->info('Done. Available credits: US$'.MicroUsd::format($ledger->balance($workspace)->available_micro, 2));

        return self::SUCCESS;
    }
}

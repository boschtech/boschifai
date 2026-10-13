<?php

namespace App\Console\Commands;

use App\Enums\WorkspaceRole;
use App\Models\CreditLot;
use App\Notifications\CreditsExpiringNotification;
use App\Services\Billing\CreditLedger;
use App\Support\Billing\MicroUsd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Removes credits past their expiry date (billing.credit_validity_months after purchase) and
 * emails owners and admins billing.expiry_reminder_days before credits expire — each reminder
 * once per lot. Run by the scheduler (routes/console.php); safe to run repeatedly.
 */
class ExpireCreditsCommand extends Command
{
    protected $signature = 'boschifai:expire-credits';

    protected $description = 'Expire credits past their date and send expiry reminders';

    public function handle(CreditLedger $ledger): int
    {
        $expired = 0;
        foreach (CreditLot::where('remaining_micro', '>', 0)->where('expires_at', '<=', now())->orderBy('id')->get() as $lot) {
            $amount = $ledger->expire($lot);
            if ($amount > 0) {
                $expired++;
                $this->line(sprintf('Expired US$%s of workspace %d (lot %d).', MicroUsd::format($amount, 2), $lot->workspace_id, $lot->id));
            }
        }

        $reminded = $this->sendReminders();
        $this->info("Expired {$expired} lot(s); sent {$reminded} reminder(s).");

        return self::SUCCESS;
    }

    /** One email per workspace and reminder threshold, covering every lot it applies to. */
    private function sendReminders(): int
    {
        $sent = 0;
        foreach (collect(config('billing.expiry_reminder_days'))->sortDesc() as $days) {
            $due = CreditLot::with('workspace')
                ->where('remaining_micro', '>', 0)
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays($days))
                ->where(fn ($q) => $q->whereNull('reminded_days')->orWhere('reminded_days', '>', $days))
                ->get()
                ->groupBy('workspace_id');

            foreach ($due as $lots) {
                $workspace = $lots->first()->workspace;
                $recipients = $workspace->users()->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])->get();
                Notification::send($recipients, new CreditsExpiringNotification($workspace, (int) $lots->sum('remaining_micro'), $lots->min('expires_at')));
                CreditLot::whereKey($lots->pluck('id'))->update(['reminded_days' => $days]);
                $sent++;
            }
        }

        return $sent;
    }
}

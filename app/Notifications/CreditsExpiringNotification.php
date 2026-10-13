<?php

namespace App\Notifications;

use App\Models\Workspace;
use App\Support\Billing\MicroUsd;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a workspace's owners and admins that some of its credits expire soon. */
class CreditsExpiringNotification extends Notification
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly int $amountMicro,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = 'US$'.MicroUsd::format($this->amountMicro, 2);
        $date = $this->expiresAt->toFormattedDayDateString();

        return (new MailMessage)
            ->subject("{$amount} of BoschifAI credits expire on {$date}")
            ->line("{$amount} of {$this->workspace->name}'s credits expire on {$date}.")
            ->line('Credits stay usable for '.config('billing.credit_validity_months').' months from purchase, and the oldest are always used first. Credits still unused on that date are removed from the balance.')
            ->action('View billing', url('/billing'));
    }
}

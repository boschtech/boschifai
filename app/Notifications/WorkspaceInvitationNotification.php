<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    use Queueable;

    /** @param  string  $token  the plain token for the link — only ever exists in this email */
    public function __construct(private WorkspaceInvitation $invitation, private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->invitedBy?->name ?? 'A colleague';
        $workspace = $this->invitation->workspace->name;

        return (new MailMessage)
            ->subject("You're invited to {$workspace} on BoschifAI")
            ->line("{$inviter} has invited you to join the \"{$workspace}\" workspace on BoschifAI as {$this->invitation->role->value}.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires in '.WorkspaceInvitation::LIFETIME_DAYS.' days. If you weren\'t expecting it, you can ignore this email.');
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Sends one plain email through the configured mailer, so the MAIL_* / SES settings can be
 * checked before relying on them for invitations and password resets. Prints the settings in
 * use (never credentials) and, on failure, the mail server's own reason.
 */
class SendTestEmailCommand extends Command
{
    protected $signature = 'boschifai:send-test-email {email : Where to send the test message}';

    protected $description = 'Send a test email to check the mail (SES) settings';

    /** Mailers that accept a message without delivering it anywhere. */
    private const NOT_DELIVERED = ['log', 'array'];

    public function handle(): int
    {
        $email = trim($this->argument('email'));
        if (Validator::make(['email' => $email], ['email' => ['not_regex:/[\x00-\x1F\x7F]/', 'email']])->fails()) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $this->line("Mailer: {$mailer}");
        $this->line('From: '.config('mail.from.address'));
        if ($transport === 'ses') {
            $this->line('SES region: '.config('services.ses.region'));
            $this->line('Credentials: '.(config('services.ses.key') ? 'access key from the environment' : 'AWS default chain (task role / instance profile)'));
        }

        try {
            Mail::raw('This is a test message from BoschifAI. If you can read it, outgoing email works.', function (Message $message) use ($email) {
                $message->to($email)->subject('BoschifAI test email');
            });
        } catch (TransportExceptionInterface $e) {
            $this->error('Sending failed: '.$e->getMessage());
            if ($transport === 'ses') {
                $this->line('Check that MAIL_FROM_ADDRESS is on an identity verified in SES in that region, and — while the');
                $this->line('SES account is still in the sandbox — that the recipient address is verified too.');
            }

            return self::FAILURE;
        }

        if (in_array($transport, self::NOT_DELIVERED, true)) {
            $this->warn("The \"{$mailer}\" mailer doesn't deliver email — the message was only recorded locally. Set MAIL_MAILER=ses to send for real.");

            return self::SUCCESS;
        }

        $this->info("Sent to {$email}. If it doesn't arrive, check the spam folder and the SES sending statistics.");

        return self::SUCCESS;
    }
}

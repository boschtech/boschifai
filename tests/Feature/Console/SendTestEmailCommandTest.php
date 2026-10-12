<?php

namespace Tests\Feature\Console;

use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class SendTestEmailCommandTest extends TestCase
{
    public function test_it_sends_a_message_through_the_configured_mailer(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'no-reply@boschifai.test']);

        $this->artisan('boschifai:send-test-email', ['email' => 'ops@example.com'])
            ->expectsOutput('Mailer: array')
            ->expectsOutput('From: no-reply@boschifai.test')
            ->expectsOutputToContain("doesn't deliver email")
            ->assertSuccessful();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('ops@example.com', $sent[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertSame('BoschifAI test email', $sent[0]->getOriginalMessage()->getSubject());
    }

    public function test_it_reports_a_delivering_mailer_as_sent(): void
    {
        $this->useMailer(fn () => null);

        $this->artisan('boschifai:send-test-email', ['email' => 'ops@example.com'])
            ->expectsOutput('Sent to ops@example.com. If it doesn\'t arrive, check the spam folder and the SES sending statistics.')
            ->assertSuccessful();
    }

    public function test_it_shows_the_ses_settings_but_never_the_credentials(): void
    {
        $this->useMailer(fn () => null, transport: 'ses');
        config(['services.ses' => ['key' => 'AKIA-NOT-REAL', 'secret' => 'not-a-real-secret', 'region' => 'af-south-1']]);

        $this->artisan('boschifai:send-test-email', ['email' => 'ops@example.com'])
            ->expectsOutput('SES region: af-south-1')
            ->expectsOutput('Credentials: access key from the environment')
            ->doesntExpectOutputToContain('AKIA-NOT-REAL')
            ->doesntExpectOutputToContain('not-a-real-secret')
            ->assertSuccessful();

        config(['services.ses.key' => null, 'services.ses.secret' => null]);
        $this->artisan('boschifai:send-test-email', ['email' => 'ops@example.com'])
            ->expectsOutput('Credentials: AWS default chain (task role / instance profile)')
            ->assertSuccessful();
    }

    public function test_it_explains_a_refused_message(): void
    {
        $this->useMailer(fn () => throw new TransportException('554 Message rejected: Email address is not verified.'), transport: 'ses');
        config(['services.ses.region' => 'af-south-1']);

        $this->artisan('boschifai:send-test-email', ['email' => 'ops@example.com'])
            ->expectsOutput('Sending failed: 554 Message rejected: Email address is not verified.')
            ->expectsOutputToContain('verified in SES')
            ->assertFailed();
    }

    public function test_the_real_ses_mailer_is_built_from_the_ses_settings(): void
    {
        config(['services.ses' => ['key' => null, 'secret' => null, 'region' => 'af-south-1']]);

        $transport = Mail::mailer('ses')->getSymfonyTransport();

        $this->assertInstanceOf(SesTransport::class, $transport);
        $this->assertSame('af-south-1', $transport->ses()->getRegion());
    }

    public function test_it_rejects_an_invalid_address(): void
    {
        foreach (['not-an-address', "ops@example.com\r\nBcc: x@example.com"] as $email) {
            $this->artisan('boschifai:send-test-email', ['email' => $email])
                ->expectsOutput('That is not a valid email address.')
                ->assertFailed();
        }
    }

    /**
     * Routes mail through a stand-in transport registered under $transport, so the command sees
     * e.g. an "ses" mailer without calling AWS. $send runs for each message.
     */
    private function useMailer(callable $send, string $transport = 'fake-delivery'): void
    {
        Mail::extend($transport, fn () => new class($send) extends AbstractTransport
        {
            public function __construct(private $send)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                ($this->send)();
            }

            public function __toString(): string
            {
                return 'fake';
            }
        });
        config(['mail.mailers.test-mailer' => ['transport' => $transport], 'mail.default' => 'test-mailer']);
    }
}

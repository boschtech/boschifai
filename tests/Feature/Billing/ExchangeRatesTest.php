<?php

namespace Tests\Feature\Billing;

use App\Models\ExchangeRate;
use App\Services\Payments\EcbReferenceRates;
use App\Services\Payments\ExchangeRates;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\SignsInUser;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/**
 * Rand (and other non-USD) quotes use the ECB's daily reference rate, fetched by
 * boschifai:refresh-exchange-rates, plus the configured buffer — never a stale or implausible rate.
 */
class ExchangeRatesTest extends TestCase
{
    use RefreshDatabase, SignsInUser;

    private const FEED = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** @var array{0: int, 1: string} [HTTP status, body] the fake ECB answers with */
    private array $ecb;

    /** @var list<string> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.fx.ZAR' => ['rate' => null, 'buffer' => '0.08'], 'payments.fx_feed.ecb_url' => self::FEED]);
        $this->ecb = [200, $this->feed('2026-10-13', ['USD' => '1.0845', 'ZAR' => '19.8765', 'GBP' => '0.8312'])];
        Http::fake([self::FEED => fn () => Http::response($this->ecb[1], $this->ecb[0])]);
        app(ExceptionHandler::class)->reportable(function (RuntimeException $e) {
            $this->reported[] = $e->getMessage();

            return false;
        });
    }

    /** The ECB's real XML layout: a dated Cube holding one Cube per currency, rates per euro. */
    private function feed(string $date, array $rates): string
    {
        $cubes = implode('', array_map(fn ($c, $r) => "<Cube currency=\"{$c}\" rate=\"{$r}\"/>", array_keys($rates), $rates));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
            .'<gesmes:subject>Reference rates</gesmes:subject>'
            ."<Cube><Cube time=\"{$date}\">{$cubes}</Cube></Cube></gesmes:Envelope>";
    }

    private function storeRate(string $rate, string $publishedOn): void
    {
        ExchangeRate::create(['currency' => 'ZAR', 'rate' => $rate, 'source' => 'ecb', 'published_on' => $publishedOn]);
    }

    // ── The feed ───────────────────────────────────────────────────────────────────────

    public function test_rates_per_us_dollar_are_cross_rates_from_the_euro_feed(): void
    {
        $feed = app(EcbReferenceRates::class)->fetch(['ZAR', 'GBP', 'XYZ']);

        // 19.8765 ZAR/EUR ÷ 1.0845 USD/EUR = 18.3278008… → 18.327801 (half up, 6 places)
        $this->assertSame(['published_on' => '2026-10-13', 'rates' => ['ZAR' => '18.327801', 'GBP' => '0.766436']], $feed);
    }

    public function test_an_unreadable_or_incomplete_feed_is_an_error(): void
    {
        foreach ([[500, ''], [200, 'not xml'], [200, $this->feed('2026-10-13', ['ZAR' => '19.8'])], [200, $this->feed('yesterday', ['USD' => '1.08'])]] as $answer) {
            $this->ecb = $answer;
            try {
                app(EcbReferenceRates::class)->fetch(['ZAR']);
                $this->fail('Expected the feed to be refused.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── Refreshing ─────────────────────────────────────────────────────────────────────

    public function test_the_daily_refresh_stores_the_rate_once_per_publication_date(): void
    {
        $this->artisan('boschifai:refresh-exchange-rates')
            ->expectsOutput('ZAR: 18.327801 per US dollar (ECB, 2026-10-13).')
            ->assertSuccessful();
        $this->artisan('boschifai:refresh-exchange-rates')->assertSuccessful();

        $rate = ExchangeRate::sole();
        $this->assertSame(['ZAR', '18.327801', 'ecb', '2026-10-13'], [$rate->currency, $rate->rate, $rate->source, $rate->published_on->toDateString()]);
    }

    public function test_an_implausible_jump_is_not_used_and_is_reported(): void
    {
        $this->storeRate('16.500000', '2026-10-11'); // the new 18.33 is an 11% move

        $this->artisan('boschifai:refresh-exchange-rates')
            ->expectsOutputToContain('ZAR: the ECB rate moved from 16.500000 to 18.327801 (11.0%) — not used')
            ->assertFailed();

        $this->assertSame(1, ExchangeRate::count());
        $this->assertCount(1, $this->reported);

        // Within the limit (18.00 → 18.33 is under 2%) it's accepted.
        $this->storeRate('18.000000', '2026-10-12');
        $this->artisan('boschifai:refresh-exchange-rates')->assertSuccessful();
    }

    public function test_a_feed_failure_or_missing_currency_fails_the_refresh(): void
    {
        $this->ecb = [503, ''];
        $this->artisan('boschifai:refresh-exchange-rates')->expectsOutput('The ECB rates feed answered HTTP 503.')->assertFailed();

        $this->ecb = [200, $this->feed('2026-10-13', ['USD' => '1.0845'])];
        $this->artisan('boschifai:refresh-exchange-rates')->expectsOutput('ZAR: not in the ECB feed.')->assertFailed();

        $this->assertSame(0, ExchangeRate::count());
    }

    // ── Which rate is used ─────────────────────────────────────────────────────────────

    public function test_the_latest_fresh_ecb_rate_is_used_and_an_admin_override_wins(): void
    {
        $this->travelTo('2026-10-14 09:00:00');
        $this->storeRate('18.100000', '2026-10-10');
        $this->storeRate('18.327801', '2026-10-13');

        $this->assertSame(['rate' => '18.327801', 'rate_millionths' => 18_327_801, 'source' => 'ecb:2026-10-13'], app(ExchangeRates::class)->current('zar'));

        config(['payments.fx.ZAR.rate' => '19.5']);
        $this->assertSame(['rate' => '19.5', 'rate_millionths' => 19_500_000, 'source' => 'admin'], app(ExchangeRates::class)->current('ZAR'));
    }

    public function test_a_stale_rate_is_never_used(): void
    {
        $this->travelTo('2026-10-20 09:00:00');
        $this->storeRate('18.327801', '2026-10-13'); // a week old; the limit is 4 days

        $this->assertNull(app(ExchangeRates::class)->current('ZAR'));
    }

    // ── Quotes ─────────────────────────────────────────────────────────────────────────

    public function test_a_rand_quote_uses_the_ecb_rate_plus_the_8_percent_buffer(): void
    {
        $this->travelTo('2026-10-14 09:00:00');
        $this->storeRate('18.327801', '2026-10-13');
        config(['billing.packs' => ['starter' => ['credits_usd' => '10', 'price_usd' => '10']], 'payments.enabled' => ['fake']]);
        app(PaymentProviderRegistry::class)->register($provider = new FakePaymentProvider(['ZAR']));

        $quote = app(PaymentService::class)->quote('starter', $provider, 'ZAR');

        // US$10 × 18.327801 = R183.27801; × 1.08 = R197.940250… → R197.95 (rounded up to the cent)
        $this->assertSame(['ZAR', 19795, '18.327801', 'ecb:2026-10-13'], [$quote['charge_currency'], $quote['charge_amount_minor'], $quote['fx_rate'], $quote['fx_source']]);
        $this->getJson('/api/billing')->assertJsonPath('data.providers', [['key' => 'fake', 'currencies' => ['ZAR']]]);

        // No fresh rate → rand isn't offered at all.
        $this->travelTo('2026-10-30 09:00:00');
        $this->getJson('/api/billing')->assertJsonPath('data.providers', []);
    }
}

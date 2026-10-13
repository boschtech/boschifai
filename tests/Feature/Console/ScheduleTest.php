<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** What the `scheduler` container runs (routes/console.php), and when. */
class ScheduleTest extends TestCase
{
    public function test_exchange_rates_and_stale_holds_run_every_hour(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->keyBy(fn (Event $event) => preg_replace("/^.*artisan'? /", '', $event->command));

        foreach (['boschifai:refresh-exchange-rates', 'boschifai:release-stale-holds'] as $command) {
            $this->assertTrue($events->has($command), "{$command} is scheduled");
            $this->assertSame('30 * * * *', $events[$command]->expression, $command);
            $this->assertTrue($events[$command]->withoutOverlapping, "{$command} must not overlap itself");
        }
    }
}

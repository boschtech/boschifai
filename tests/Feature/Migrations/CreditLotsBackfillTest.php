<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CreditLotsBackfillTest extends TestCase
{
    use DatabaseMigrations;

    public function test_balances_that_exist_at_launch_become_a_lot_valid_for_36_months_from_then(): void
    {
        $later = collect(File::files(database_path('migrations')))
            ->filter(fn ($file) => $file->getFilename() >= '2026_10_22_100000_create_credit_lots_table.php')
            ->count();
        $this->artisan('migrate:rollback', ['--step' => $later])->assertSuccessful();

        $this->travelTo('2026-10-20 08:00:00');
        $now = now();
        $funded = DB::table('workspaces')->insertGetId(['name' => 'Funded', 'slug' => 'funded', 'created_at' => $now, 'updated_at' => $now]);
        $empty = DB::table('workspaces')->insertGetId(['name' => 'Empty', 'slug' => 'empty', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('workspace_credit_balances')->insert([
            ['workspace_id' => $funded, 'available_micro' => 7_000_000, 'held_micro' => 1_000_000, 'updated_at' => $now],
            ['workspace_id' => $empty, 'available_micro' => 0, 'held_micro' => 0, 'updated_at' => $now],
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $lot = DB::table('credit_lots')->sole();
        $this->assertSame([$funded, 'balance_at_launch', 8_000_000, 8_000_000], [(int) $lot->workspace_id, $lot->kind, (int) $lot->amount_micro, (int) $lot->remaining_micro]);
        $this->assertStringStartsWith('2029-10-20', $lot->expires_at);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credits by lot, so each can expire on its own date (CPA s63: prepaid credits may not expire
 * before 3 years). The ledger stays the record of every movement; lots track what's left of each
 * purchase or grant. Balances that exist when this ships become one lot each, dated from now, so
 * nobody's credits expire early.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            // The purchase/adjustment that created it; null for balances that existed at launch.
            $table->foreignId('ledger_entry_id')->nullable()->unique()->constrained('credit_ledger_entries')->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount_micro');
            $table->bigInteger('remaining_micro');
            $table->timestamp('expires_at')->nullable();
            // The smallest "days before expiry" reminder already sent (e.g. 60, then 14).
            $table->unsignedSmallInteger('reminded_days')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'expires_at']);
        });

        $months = (int) config('billing.credit_validity_months', 36);
        foreach (DB::table('workspace_credit_balances')->get() as $balance) {
            $total = (int) $balance->available_micro + (int) $balance->held_micro;
            if ($total > 0) {
                DB::table('credit_lots')->insert([
                    'workspace_id' => $balance->workspace_id, 'kind' => 'balance_at_launch',
                    'amount_micro' => $total, 'remaining_micro' => $total,
                    'expires_at' => now()->addMonths($months), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_lots');
    }
};

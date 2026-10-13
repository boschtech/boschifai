<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit purchases and the provider webhooks about them. Financial records: never removed with
 * their workspace. Webhook payloads are stored redacted (no card or buyer details).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            // The provider's id for the checkout/payment; unique per provider.
            $table->string('provider_ref', 191)->nullable();
            $table->string('pack', 50);
            $table->bigInteger('credits_micro');
            $table->bigInteger('price_usd_micro');
            // What the customer is actually charged, in the provider's currency (minor units).
            $table->char('charge_currency', 3);
            $table->bigInteger('charge_amount_minor');
            // The quote used when the charge currency isn't USD.
            $table->string('fx_rate', 32)->nullable();
            $table->string('fx_source', 50)->nullable();
            $table->timestamp('fx_quoted_at')->nullable();
            $table->string('status', 20);
            $table->string('failure_reason')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('credit_ledger_entries')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 191);
            $table->string('event_type', 100)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('payload')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // A provider redelivering an event is recognised and ignored.
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};

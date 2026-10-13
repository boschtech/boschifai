<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid credits. Every amount is integer micro-USD. Ledger entries are financial records:
 * append-only (enforced in CreditLedgerEntry), and never removed with their workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->bigInteger('available_delta_micro');
            $table->bigInteger('held_delta_micro');
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('related_entry_id')->nullable()->constrained('credit_ledger_entries')->restrictOnDelete();
            $table->uuid('run_id')->nullable();
            $table->foreign('run_id')->references('id')->on('runs')->nullOnDelete();
            $table->foreignId('claude_invocation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
        });

        // A cache of the ledger's sums per workspace, updated in the same transaction as each
        // entry and locked while it is (see CreditLedger). The ledger is the source of truth.
        Schema::create('workspace_credit_balances', function (Blueprint $table) {
            $table->foreignId('workspace_id')->primary()->constrained()->restrictOnDelete();
            $table->bigInteger('available_micro')->default(0);
            $table->bigInteger('held_micro')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        // What each Claude step cost the platform and what it is (or, in shadow mode, would be)
        // charged — for calibrating holds and markup, and for margin reporting.
        Schema::create('invocation_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            // Kept when its run is deleted (deleting a run removes its invocations): what was
            // charged is financial history.
            $table->foreignId('claude_invocation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('step_key', 50);
            $table->string('outcome', 20);
            $table->bigInteger('cost_micro')->nullable();
            $table->bigInteger('hold_micro')->nullable();
            $table->bigInteger('charge_micro');
            $table->unsignedInteger('markup_millionths');
            $table->boolean('enforced');
            $table->foreignId('ledger_entry_id')->nullable()->constrained('credit_ledger_entries')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['step_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invocation_charges');
        Schema::dropIfExists('workspace_credit_balances');
        Schema::dropIfExists('credit_ledger_entries');
    }
};

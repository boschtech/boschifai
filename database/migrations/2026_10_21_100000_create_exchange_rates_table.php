<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference exchange rates (units of a currency per US dollar), one row per currency, source and
 * publication date — kept as history so any quote can be traced back to the rate it used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->string('rate', 32);
            $table->string('source', 20);
            $table->date('published_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['currency', 'source', 'published_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};

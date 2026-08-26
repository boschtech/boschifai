<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_invocations', function (Blueprint $table) {
            $table->unsignedBigInteger('input_tokens')->nullable()->after('num_turns');
            $table->unsignedBigInteger('output_tokens')->nullable()->after('input_tokens');
            $table->unsignedBigInteger('cache_creation_input_tokens')->nullable()->after('output_tokens');
            $table->unsignedBigInteger('cache_read_input_tokens')->nullable()->after('cache_creation_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('claude_invocations', function (Blueprint $table) {
            $table->dropColumn([
                'input_tokens',
                'output_tokens',
                'cache_creation_input_tokens',
                'cache_read_input_tokens',
            ]);
        });
    }
};

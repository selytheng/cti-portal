<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Spec 4 modal runtime overrides: keyword toggle + AI provider choice. */
    public function up(): void
    {
        Schema::table('query_runs', function (Blueprint $table) {
            $table->boolean('use_keywords')->default(true)->after('lookback_days');
            $table->foreignId('ai_integration_id')->nullable()->after('match_existing')->constrained('ai_integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('query_runs', function (Blueprint $table) {
            $table->dropForeign(['ai_integration_id']);
            $table->dropColumn(['use_keywords', 'ai_integration_id']);
        });
    }
};

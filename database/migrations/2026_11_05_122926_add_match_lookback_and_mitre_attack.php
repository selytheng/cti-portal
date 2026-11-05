<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('query_runs', function (Blueprint $table) {
            $table->unsignedInteger('match_lookback_days')->default(7)->after('match_existing');
        });

        Schema::table('cti_records', function (Blueprint $table) {
            $table->jsonb('mitre_attack')->nullable()->after('target_objects');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_runs', function (Blueprint $table) {
            $table->dropColumn('match_lookback_days');
        });

        Schema::table('cti_records', function (Blueprint $table) {
            $table->dropColumn('mitre_attack');
        });
    }
};

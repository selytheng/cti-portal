<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CVE trust-source verification config (NVD API key) — extends the
     * verifier pattern to CVEs so CTI records can be enriched against the
     * authoritative source (spec Section 6: cves with CVSS/CWE/KEV).
     */
    public function up(): void
    {
        Schema::create('cve_verifiers', function (Blueprint $table) {
            $table->id();
            $table->string('service')->default('nvd');
            $table->text('api_key')->nullable(); // encrypted via Eloquent cast
            $table->string('base_url')->nullable();
            $table->unsignedTinyInteger('request_interval_seconds')->default(2); // NVD rate limiting
            $table->boolean('auto_verify')->default(true);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::table('cve_verifiers')->insert([
            'service' => 'nvd',
            'base_url' => 'https://services.nvd.nist.gov',
            'request_interval_seconds' => 2,
            'auto_verify' => true,
            'is_active' => false, // inactive until the NVD API key is entered
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cve_verifiers');
    }
};

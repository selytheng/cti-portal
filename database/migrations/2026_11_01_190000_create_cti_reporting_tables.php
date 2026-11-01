<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec Section 6 (analyst_notes) + Section 10.1 item 11 (cti_reports).
     */
    public function up(): void
    {
        Schema::create('cti_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cti_record_id')->constrained('cti_records')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('applicability')->default('Investigating'); // Affected|Not Affected|Mitigated|Investigating
            $table->timestamps();
        });

        Schema::create('cti_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cti_record_id')->nullable()->constrained('cti_records')->nullOnDelete(); // null = consolidated multi-CTI
            $table->foreignId('template_id')->nullable()->constrained('report_templates')->nullOnDelete();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->jsonb('cti_record_ids');
            $table->string('file_path');
            $table->string('format'); // html | pdf
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cti_reports');
        Schema::dropIfExists('cti_notes');
    }
};

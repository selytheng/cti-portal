<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core CTI tables — spec Section 10.1 (items 7–10) and Section 6 schema.
     */
    public function up(): void
    {
        // Query execution runs (spec 4: live status, history, stage tracking).
        Schema::create('query_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger_type')->default('manual'); // manual | scheduled
            $table->unsignedInteger('lookback_days'); // unit: "days ago"
            $table->boolean('match_existing')->default(true); // spec 4: match & update developing CTIs
            $table->jsonb('feed_ids')->nullable(); // null = all active feeds
            $table->string('status')->default('queued'); // queued|running|completed|failed|cancelled
            $table->string('current_stage')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->unsignedInteger('feeds_processed')->default(0);
            $table->unsignedInteger('items_fetched')->default(0);
            $table->unsignedInteger('items_in_date_window')->default(0);
            $table->unsignedInteger('items_filtered_exception')->default(0);
            $table->unsignedInteger('duplicates_skipped')->default(0);
            $table->unsignedInteger('ctis_created')->default(0);
            $table->unsignedInteger('ctis_updated')->default(0);
            $table->jsonb('created_cti_ids')->nullable();
            $table->jsonb('updated_cti_ids')->nullable();
            $table->string('ai_provider_used')->nullable();
            $table->text('error_log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // CTI records (spec Section 6 schema).
        Schema::create('cti_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('cti_id')->unique(); // CTI-2026-00042
            $table->text('subject');
            $table->string('type')->default('Vul'); // Vul | Mal
            $table->jsonb('subtypes')->nullable(); // Ransomware, APT, Exploit, Zero-Day, Supply Chain
            $table->jsonb('sources'); // [{name,url,author,feed_id,published_at,content_hash,added_at}]
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable(); // last enrichment/edit
            $table->boolean('is_developing')->default(true);
            $table->unsignedInteger('revision_count')->default(1);
            $table->string('tlp')->default('TLP:CLEAR');
            $table->string('workflow_status')->default('New'); // New|Developing|Under Review|Verified|Published|False Positive|Archived
            $table->unsignedTinyInteger('confidence_score')->default(50);
            $table->jsonb('cves')->nullable(); // [{cve,cvss,vector,epss,cwe,kev}]
            $table->string('exploit_status')->nullable(); // Unproven|PoC Available|Actively Exploited in the Wild|Weaponized
            $table->string('severity')->default('Medium');
            $table->text('summary')->nullable();
            $table->text('technical_analysis')->nullable();
            $table->jsonb('impact_hardware')->nullable();
            $table->jsonb('impact_software')->nullable();
            $table->jsonb('threat_actors')->nullable();
            $table->jsonb('target_objects')->nullable();
            $table->text('mitigation')->nullable();
            $table->jsonb('detection_rules')->nullable();
            $table->jsonb('matched_focus_keywords')->nullable();
            $table->foreignId('latest_query_run_id')->nullable()->constrained('query_runs')->nullOnDelete();
            $table->timestamps();

            $table->index('workflow_status');
            $table->index('severity');
            $table->index('published_at');
        });

        // Full-text + similarity search infrastructure (spec 1.1/8: tsvector + pg_trgm).
        DB::statement("ALTER TABLE cti_records ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(subject,'') || ' ' || coalesce(summary,''))) STORED");
        DB::statement('CREATE INDEX cti_records_search_idx ON cti_records USING GIN (search)');
        DB::statement('CREATE INDEX cti_records_subject_trgm_idx ON cti_records USING GIN (subject gin_trgm_ops)');

        Schema::create('cti_iocs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cti_record_id')->constrained('cti_records')->cascadeOnDelete();
            $table->string('ioc_type'); // IPv4|IPv6|Domain|URL|MD5|SHA1|SHA256|Email|Mutex|User-Agent|Registry Key
            $table->string('value');
            $table->string('defanged_value')->nullable();
            $table->text('context')->nullable();
            $table->string('verdict')->default('Unknown'); // Malicious|Suspicious|Clean|Unknown
            $table->jsonb('otx_data')->nullable();
            $table->jsonb('abusech_data')->nullable();
            $table->unsignedInteger('added_in_revision')->default(1);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['cti_record_id', 'ioc_type', 'value']);
        });

        Schema::create('cti_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cti_record_id')->constrained('cti_records')->cascadeOnDelete();
            $table->foreignId('query_run_id')->nullable()->constrained('query_runs')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_url')->nullable();
            $table->jsonb('fields_updated');
            $table->jsonb('previous_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cti_revisions');
        Schema::dropIfExists('cti_iocs');
        Schema::dropIfExists('cti_records');
        Schema::dropIfExists('query_runs');
    }
};

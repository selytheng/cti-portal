<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuration Center tables — spec Section 5 and Section 10.1 (items 2–6).
     */
    public function up(): void
    {
        // 5.1 RSS & Intelligence Feed Manager
        Schema::create('rss_feeds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url')->unique();
            $table->string('category')->default('Security Blog'); // Vendor Advisory | CERT/Gov | Security Blog | Exploit Feed
            $table->unsignedInteger('polling_interval_minutes')->default(30);
            $table->jsonb('custom_headers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            $table->unsignedInteger('last_status_code')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
        });

        // 5.2 Multi-Provider AI Agent Integration
        Schema::create('ai_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // gemini | claude | openai | openrouter | custom
            $table->string('name');
            $table->text('api_key')->nullable(); // encrypted via Eloquent cast
            $table->string('base_url')->nullable(); // required for openrouter/custom
            $table->string('model_id')->nullable(); // e.g. gemini-2.5-pro
            $table->text('system_prompt')->nullable(); // initial CTI extraction prompt
            $table->text('merge_prompt')->nullable(); // cross-story correlation & merge prompt
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->unsignedInteger('timeout')->nullable(); // seconds
            $table->boolean('is_default')->default(false);
            $table->boolean('is_fallback')->default(false);
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestamps();
        });

        // 5.3 IOC Verifier Configuration (OTX + Abuse.ch)
        Schema::create('ioc_verifiers', function (Blueprint $table) {
            $table->id();
            $table->string('service'); // otx | abusech
            $table->text('api_key')->nullable(); // encrypted via Eloquent cast
            $table->string('base_url')->nullable();
            $table->jsonb('sub_services')->nullable(); // abuse.ch: urlhaus, malwarebazaar, threatfox, feodo_tracker
            $table->unsignedInteger('cache_ttl_minutes')->default(60);
            $table->boolean('auto_enrich')->default(false);
            $table->boolean('is_active')->default(false); // inactive until API key entered
            $table->timestamps();
        });

        // 5.4 Keyword Focus & Exception Engine
        Schema::create('keyword_rules', function (Blueprint $table) {
            $table->id();
            $table->string('keyword');
            $table->string('match_type')->default('exact'); // exact | wildcard | regex
            $table->string('rule_type')->default('focus'); // focus | exception
            $table->string('category')->nullable(); // Software | Hardware | Vendor | Cloud Service | Industry | Internal Asset
            $table->integer('severity_boost')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Global master toggles + arbitrary portal settings (focus_keywords_enabled, etc.)
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });

        // 5.5 HTML Report Template & Branding Studio
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('html_content');
            $table->text('css_styles')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('watermark_text')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('keyword_rules');
        Schema::dropIfExists('ioc_verifiers');
        Schema::dropIfExists('ai_integrations');
        Schema::dropIfExists('rss_feeds');
    }
};

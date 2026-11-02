<?php

namespace Database\Seeders;

use App\Models\AiIntegration;
use App\Models\IocVerifier;
use App\Models\RssFeed;
use App\Models\ReportTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DefaultConfigurationSeeder extends Seeder
{
    /**
     * Starter configuration per spec Section 2:
     * default RSS feeds, inactive IOC verifier records (until API keys are
     * entered in the UI), keyword-engine master toggles, and the default
     * enterprise HTML report template.
     */
    public function run(): void
    {
        // BleepingComputer is seeded inactive: its feed returns HTTP 403 to
        // datacenter egress IPs (observed 2026-11-01).
        $feeds = [
            ['name' => 'CISA Cybersecurity Advisories', 'url' => 'https://www.cisa.gov/cybersecurity-advisories/all.xml', 'category' => 'CERT/Gov', 'polling_interval_minutes' => 60, 'is_active' => true],
            ['name' => 'The Hacker News', 'url' => 'https://feeds.feedburner.com/TheHackersNews', 'category' => 'Security Blog', 'polling_interval_minutes' => 30, 'is_active' => true],
            ['name' => 'NVD Recent CVEs (JSON)', 'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0?resultsPerPage=50', 'category' => 'Vendor Advisory', 'polling_interval_minutes' => 60, 'is_active' => true],
            ['name' => 'BleepingComputer', 'url' => 'https://www.bleepingcomputer.com/feed/', 'category' => 'Security Blog', 'polling_interval_minutes' => 30, 'is_active' => false],
        ];

        foreach ($feeds as $feed) {
            RssFeed::updateOrCreate(['url' => $feed['url']], $feed);
        }

        // IOC verifiers — inactive until API keys are entered in the UI (spec 5.3).
        IocVerifier::updateOrCreate(['service' => 'otx'], [
            'base_url' => 'https://otx.alienvault.com/api/v1',
            'sub_services' => ['pulse_lookup' => true, 'passive_dns' => true],
            'cache_ttl_minutes' => 60,
            'auto_enrich' => false,
            'is_active' => false,
        ]);

        IocVerifier::updateOrCreate(['service' => 'abusech'], [
            'base_url' => 'https://abuse.ch',
            'sub_services' => [
                'urlhaus' => true, 'malwarebazaar' => true,
                'threatfox' => true, 'feodo_tracker' => true,
            ],
            'cache_ttl_minutes' => 60,
            'auto_enrich' => false,
            'is_active' => false,
        ]);

        // CVE verifier (NVD) — inactive until the API key is entered (spec 6).
        \App\Models\CveVerifier::updateOrCreate(['service' => 'nvd'], [
            'base_url' => 'https://services.nvd.nist.gov',
            'request_interval_seconds' => 2,
            'auto_verify' => true,
            'is_active' => false,
        ]);

        // Keyword engine master toggles + default match mode (spec 5.4).
        SystemSetting::put('focus_keywords_enabled', true);
        SystemSetting::put('exception_keywords_enabled', true);
        SystemSetting::put('keyword_match_mode', 'prioritize'); // strict | prioritize

        // AI integration records are created by the operator in the UI
        // (provider cards, spec 5.2) — nothing seeded here by design.

        // Default enterprise report template (variables per spec 5.5).
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{{cti_id}} — {{subject}}</title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; margin: 40px; color: #1a1a2e; }
  .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #4f46e5; padding-bottom: 16px; }
  .header img { max-height: 60px; }
  h1 { font-size: 22px; margin: 12px 0 4px; }
  .meta { color: #555; font-size: 12px; }
  .badges span { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-right: 6px; }
  .severity { background: #fee2e2; color: #b91c1c; }
  .tlp { background: #e0e7ff; color: #3730a3; }
  section { margin-top: 24px; }
  h2 { font-size: 15px; border-left: 4px solid #4f46e5; padding-left: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
  th { background: #f1f5f9; text-align: left; }
  th, td { border: 1px solid #e2e8f0; padding: 6px 8px; }
  .footer { margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 8px; font-size: 11px; color: #666; }
</style>
</head>
<body>
  <div class="header">
    <div>
      <h1>{{cti_id}} — {{subject}}</h1>
      <div class="meta">Published: {{published_at}} &middot; Last modified: {{modified_at}}</div>
      <div class="badges"><span class="severity">{{severity}}</span><span class="tlp">{{tlp}}</span><span class="tlp">{{type}}</span></div>
    </div>
    <img src="{{logo_url}}" alt="Logo" />
  </div>

  <section><h2>Executive Summary</h2><div>{{summary}}</div></section>
  <section><h2>Technical Analysis</h2><div>{{technical_analysis}}</div></section>

  <section><h2>Affected CVEs</h2>{{cves_table}}</section>
  <section><h2>Indicators of Compromise</h2>{{iocs_table}}</section>

  <section><h2>Impacted Assets</h2>
    <p><strong>Hardware:</strong> {{impact_hardware}}</p>
    <p><strong>Software:</strong> {{impact_software}}</p>
  </section>

  <section><h2>Threat Actor &amp; Targeting</h2>
    <p><strong>Attribution:</strong> {{threat_actor}}</p>
    <p><strong>Targets:</strong> {{target_object}}</p>
  </section>

  <section><h2>Mitigation</h2><div>{{mitigation}}</div></section>

  <section><h2>Sources</h2><div>{{sources}}</div></section>

  <div class="footer">Generated by {{generated_by}} on {{generated_at}} &middot; {{cti_id}}</div>
</body>
</html>
HTML;

        $superadmin = User::where('email', 'manage@superadmin.admin')->first();

        ReportTemplate::updateOrCreate(
            ['name' => 'Default Enterprise Advisory'],
            [
                'html_content' => $html,
                'is_default' => true,
                'created_by' => $superadmin?->id,
            ]
        );

        $this->command?->info('Default configuration seeded: feeds, IOC verifiers (inactive), keyword toggles, default report template.');
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\RunIntelligenceQueryJob;
use App\Models\CtiRecord;
use App\Models\KeywordRule;
use App\Models\QueryRun;
use App\Models\RssFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CtiPortalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function superadmin(): User
    {
        $user = User::where('email', 'manage@superadmin.admin')->firstOrFail();

        // Clear the onboarding flags so route middleware lets requests through.
        $user->forceFill(['must_change_password' => false, 'mfa_enabled' => true])->save();

        return $user->refresh();
    }

    private function monitoringUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('monitoring');

        return $user;
    }

    private function rssWithItems(array $items): string
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Test Feed</title>';

        foreach ($items as $item) {
            $xml .= '<item>'
                .'<title>'.htmlspecialchars($item['title']).'</title>'
                .'<link>'.htmlspecialchars($item['link']).'</link>'
                .'<pubDate>'.$item['pubDate'].'</pubDate>'
                .'</item>';
        }

        return $xml.'</channel></rss>';
    }

    private function fakeFeed(array $items): void
    {
        Http::fake(['*' => Http::response($this->rssWithItems($items), 200)]);
    }

    public function test_seeder_creates_roles_permissions_and_secure_superadmin(): void
    {
        $this->assertDatabaseCount('roles', 3);

        $admin = User::where('email', 'manage@superadmin.admin')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('superadmin'));
        $this->assertTrue($admin->must_change_password, 'Seeded admin must be forced to change password');
    }

    public function test_monitoring_role_cannot_access_user_management(): void
    {
        $monitor = $this->monitoringUser();

        $this->actingAs($monitor)->get(route('users.index'))->assertForbidden();
        $this->actingAs($this->superadmin())->get(route('users.index'))->assertOk();
    }

    public function test_pipeline_ingests_fake_feed_and_creates_cti_records(): void
    {
        Event::fake(); // suppress live broadcasting in tests

        $this->fakeFeed([
            ['title' => 'Critical CVE-2026-9999 remote code execution disclosed', 'link' => 'https://example.com/a1', 'pubDate' => now()->toRfc2822String()],
            ['title' => 'New malware campaign targeting banks', 'link' => 'https://example.com/a2', 'pubDate' => now()->toRfc2822String()],
        ]);

        $feed = RssFeed::create([
            'name' => 'Test Feed', 'url' => 'https://example.com/feed.xml',
            'category' => 'Security Blog', 'polling_interval_minutes' => 30, 'is_active' => true,
        ]);

        // Scope the run to the test feed so seeded active feeds don't also
        // fetch (they return the same faked items and correctly dedupe).
        $run = QueryRun::create([
            'uuid' => (string) Str::uuid(), 'trigger_type' => 'manual',
            'lookback_days' => 3, 'use_keywords' => true, 'match_existing' => true,
            'feed_ids' => [$feed->id], 'status' => 'queued',
        ]);

        RunIntelligenceQueryJob::dispatchSync($run);

        $this->assertDatabaseCount('cti_records', 2);
        $this->assertDatabaseHas('cti_records', ['subject' => 'Critical CVE-2026-9999 remote code execution disclosed', 'type' => 'Vul']);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->ctis_created);
        $this->assertSame(2, $run->items_in_date_window);
    }

    public function test_keyword_exception_drops_articles_before_extraction(): void
    {
        Event::fake();

        KeywordRule::create([
            'keyword' => 'gaming', 'match_type' => 'exact', 'rule_type' => 'exception',
            'severity_boost' => 0, 'is_active' => true,
        ]);

        $this->fakeFeed([
            ['title' => 'New gaming console review roundup', 'link' => 'https://example.com/noise', 'pubDate' => now()->toRfc2822String()],
            ['title' => 'APT campaign targets telecom sector', 'link' => 'https://example.com/signal', 'pubDate' => now()->toRfc2822String()],
        ]);

        $feed = RssFeed::create([
            'name' => 'Test Feed', 'url' => 'https://example.com/feed.xml',
            'category' => 'Security Blog', 'polling_interval_minutes' => 30, 'is_active' => true,
        ]);

        // Scope the run to the test feed (see note in the pipeline test).
        $run = QueryRun::create([
            'uuid' => (string) Str::uuid(), 'trigger_type' => 'manual',
            'lookback_days' => 3, 'use_keywords' => true, 'match_existing' => true,
            'feed_ids' => [$feed->id], 'status' => 'queued',
        ]);

        RunIntelligenceQueryJob::dispatchSync($run);

        $this->assertDatabaseCount('cti_records', 1);
        $run->refresh();
        $this->assertSame(1, $run->items_filtered_exception);
    }

    public function test_repository_search_finds_records_and_generates_pdf(): void
    {
        Event::fake();

        $record = CtiRecord::create([
            'uuid' => (string) Str::uuid(), 'cti_id' => 'CTI-'.now()->year.'-90001',
            'subject' => 'Test Subject Zero-Day Advisory', 'type' => 'Vul',
            'sources' => [['name' => 'Test', 'url' => 'https://example.com/x', 'content_hash' => 'abc']],
            'published_at' => now(), 'modified_at' => now(), 'severity' => 'High',
        ]);

        $this->actingAs($this->superadmin());

        $this->get(route('cti.index', ['search' => 'Zero-Day Advisory']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CtiRepository/Index')->has('records.data', 1));

        $this->post(route('cti.report-pdf', ['cti_ids' => [$record->id]]))
            ->assertRedirect();

        $this->assertDatabaseCount('cti_reports', 1);
    }

    public function test_siem_ioc_feed_requires_authentication(): void
    {
        $this->getJson(route('api.iocs'))->assertUnauthorized();
    }

    public function test_pagination_per_page_options_on_audit_cti_and_query_hub(): void
    {
        $this->actingAs($this->superadmin());

        $this->get(route('audit.index', ['per_page' => 50]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Audit/Index')
                ->where('filters.per_page', 50)
                ->where('activities.per_page', 50));

        $this->get(route('cti.index', ['per_page' => 100]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CtiRepository/Index')
                ->where('filters.per_page', 100)
                ->where('records.per_page', 100));

        $this->get(route('queryhub.index', ['per_page' => 50]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('QueryHub/Index')
                ->where('perPage', 50)
                ->where('history.per_page', 50));
    }

    public function test_bulk_status_accepts_not_applicable(): void
    {
        $this->actingAs($this->superadmin());

        $record = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-90002',
            'subject' => 'Test Subject for Status Update',
            'type' => 'Vul',
            'severity' => 'Medium',
            'workflow_status' => 'New',
            'sources' => [['name' => 'Test', 'url' => 'https://example.com/2']],
            'published_at' => now(),
            'modified_at' => now(),
        ]);

        $this->post(route('cti.bulk-status'), [
            'ids' => [$record->id],
            'workflow_status' => 'Not Applicable',
        ])->assertRedirect();

        $this->assertSame('Not Applicable', $record->fresh()->workflow_status);
    }

    public function test_report_pdf_returns_json_download_url_and_can_be_downloaded(): void
    {
        Event::fake();
        $this->actingAs($this->superadmin());

        $record = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-90003',
            'subject' => 'Test PDF Download Subject',
            'type' => 'Vul',
            'severity' => 'High',
            'sources' => [['name' => 'Test', 'url' => 'https://example.com/3']],
            'published_at' => now(),
            'modified_at' => now(),
        ]);

        $response = $this->postJson(route('cti.report-pdf'), [
            'cti_ids' => [$record->id],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['download_url']);

        $downloadUrl = $response->json('download_url');
        $this->assertNotEmpty($downloadUrl);

        $downloadResponse = $this->get($downloadUrl);
        $downloadResponse->assertOk();
        $this->assertSame('application/pdf', $downloadResponse->headers->get('content-type'));
    }
}

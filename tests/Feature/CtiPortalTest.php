<?php

namespace Tests\Feature;

use App\Jobs\RunIntelligenceQueryJob;
use App\Models\CtiRecord;
use App\Models\CtiUserRead;
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

    public function test_cti_repository_partitions_new_updated_read_and_sorts_by_cti_id_desc(): void
    {
        $superadmin = $this->superadmin();
        $this->actingAs($superadmin);

        // Record 1: Read (read_at is after modified_at)
        $readRecord = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-80001',
            'subject' => 'Read Subject',
            'type' => 'Vul',
            'severity' => 'Low',
            'sources' => [['name' => 'T', 'url' => 'https://example.com/r1']],
            'published_at' => now()->subDays(5),
            'modified_at' => now()->subDays(5),
            'revision_count' => 1,
        ]);
        CtiUserRead::create([
            'user_id' => $superadmin->id,
            'cti_record_id' => $readRecord->id,
            'read_at' => now()->subDays(4),
        ]);

        // Record 2: Updated (has revision_count > 1, or modified after read_at)
        $updatedRecord = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-80002',
            'subject' => 'Updated Subject',
            'type' => 'Vul',
            'severity' => 'High',
            'sources' => [['name' => 'T', 'url' => 'https://example.com/r2']],
            'published_at' => now()->subDays(3),
            'modified_at' => now()->subHour(),
            'revision_count' => 2,
        ]);

        // Record 3: New with lower ID
        $newRecordA = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-80003',
            'subject' => 'New Subject A',
            'type' => 'Vul',
            'severity' => 'Critical',
            'sources' => [['name' => 'T', 'url' => 'https://example.com/r3']],
            'published_at' => now()->subMinutes(30),
            'modified_at' => now()->subMinutes(30),
            'revision_count' => 1,
        ]);

        // Record 4: New with higher ID
        $newRecordB = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-80004',
            'subject' => 'New Subject B',
            'type' => 'Vul',
            'severity' => 'Critical',
            'sources' => [['name' => 'T', 'url' => 'https://example.com/r4']],
            'published_at' => now()->subMinutes(10),
            'modified_at' => now()->subMinutes(10),
            'revision_count' => 1,
        ]);

        $response = $this->get(route('cti.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CtiRepository/Index'));

        $records = $response->viewData('page')['props']['records']['data'];
        $ctiIds = collect($records)->pluck('cti_id')->all();

        // New records should come first, ordered CTI ID DESC: 80004, 80003
        // Then Updated: 80002
        // Then Read: 80001
        $this->assertSame('CTI-'.now()->year.'-80004', $ctiIds[0]);
        $this->assertSame('CTI-'.now()->year.'-80003', $ctiIds[1]);
        $this->assertSame('CTI-'.now()->year.'-80002', $ctiIds[2]);
        $this->assertSame('CTI-'.now()->year.'-80001', $ctiIds[3]);

        $this->assertSame('New', $records[0]['read_state']);
        $this->assertSame('New', $records[1]['read_state']);
        $this->assertSame('Updated', $records[2]['read_state']);
        $this->assertSame('Read', $records[3]['read_state']);
    }

    public function test_cti_single_and_bulk_mark_as_read(): void
    {
        $superadmin = $this->superadmin();
        $this->actingAs($superadmin);

        $record1 = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-80010',
            'subject' => 'Mark Read Test 1',
            'type' => 'Vul',
            'severity' => 'Medium',
            'sources' => [['name' => 'T', 'url' => 'https://example.com/m1']],
            'published_at' => now(),
            'modified_at' => now(),
            'revision_count' => 1,
        ]);

        $record2 = CtiRecord::create([
            'uuid' => (string) Str::uuid(),
            'cti_id' => 'CTI-'.now()->year.'-80011',
            'subject' => 'Mark Read Test 2',
            'type' => 'Vul',
            'severity' => 'Medium',
            'sources' => [['name' => 'T', 'url' => 'https://example.com/m2']],
            'published_at' => now(),
            'modified_at' => now(),
            'revision_count' => 1,
        ]);

        // Single mark as read
        $this->post(route('cti.mark-read', $record1->id))->assertRedirect();
        $this->assertDatabaseHas('cti_user_reads', [
            'user_id' => $superadmin->id,
            'cti_record_id' => $record1->id,
        ]);

        // Bulk mark as read
        $this->post(route('cti.bulk-mark-read'), ['ids' => [$record2->id]])->assertRedirect();
        $this->assertDatabaseHas('cti_user_reads', [
            'user_id' => $superadmin->id,
            'cti_record_id' => $record2->id,
        ]);
    }

    public function test_query_hub_store_accepts_match_lookback_days(): void
    {
        $this->actingAs($this->superadmin());

        $feed = RssFeed::create([
            'name' => 'Lookback Feed', 'url' => 'https://example.com/feed.xml',
            'category' => 'Security Blog', 'polling_interval_minutes' => 30, 'is_active' => true,
        ]);

        $response = $this->post(route('queryhub.store'), [
            'lookback_days' => 1,
            'feed_ids' => [$feed->id],
            'use_keywords' => true,
            'match_existing' => true,
            'match_lookback_days' => 30,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('query_runs', [
            'lookback_days' => 1,
            'match_existing' => true,
            'match_lookback_days' => 30,
        ]);
    }

    public function test_query_pipeline_saves_all_repository_fields_including_mitre_and_revisions(): void
    {
        Event::fake();

        $this->fakeFeed([
            [
                'title' => 'Critical Flaw Exploit CVE-2026-8888',
                'link' => 'https://example.com/mitre-test',
                'pubDate' => now()->toRfc2822String(),
            ],
        ]);

        $feed = RssFeed::create([
            'name' => 'Security Feed', 'url' => 'https://example.com/feed.xml',
            'category' => 'Security Blog', 'polling_interval_minutes' => 30, 'is_active' => true,
        ]);

        $run = QueryRun::create([
            'uuid' => (string) Str::uuid(), 'trigger_type' => 'manual',
            'lookback_days' => 7, 'use_keywords' => false, 'match_existing' => true,
            'match_lookback_days' => 7,
            'feed_ids' => [$feed->id], 'status' => 'queued',
        ]);

        RunIntelligenceQueryJob::dispatchSync($run);

        $record = CtiRecord::where('subject', 'like', '%CVE-2026-8888%')->first();
        $this->assertNotNull($record);
        $this->assertNotEmpty($record->summary);
        $this->assertNotEmpty($record->sources);
        $this->assertGreaterThanOrEqual(1, $record->revision_count);

        // Enrichment & Revision Timeline initial entry (Rev 1)
        $this->assertDatabaseHas('cti_revisions', [
            'cti_record_id' => $record->id,
            'query_run_id' => $run->id,
            'source_url' => 'https://example.com/mitre-test',
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\CtiRecord;
use App\Models\CveVerifier;
use App\Services\CveVerifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Repository-wide CVE verification pass: walks every CTI record carrying
 * CVEs and enriches them against NVD + CISA KEV + EPSS (user request:
 * "go through the CTI repo again since the CVE should also be verified with
 * trust source").
 */
class VerifyCveRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct()
    {
    }

    public function handle(CveVerifierService $service): void
    {
        $verifier = CveVerifier::query()->where('service', 'nvd')->first();

        if (! $verifier) {
            return;
        }

        activity()->event('cve_verify_started')->log('Repository-wide CVE verification started');

        $records = CtiRecord::query()
            ->whereRaw("jsonb_array_length(coalesce(cves, '[]'::jsonb)) > 0")
            ->orderBy('cti_id')
            ->get();

        $enriched = 0;

        foreach ($records as $record) {
            $enriched += $service->verifyRecord($record, $verifier);
        }

        activity()->event('cve_verify_completed')
            ->log("Repository-wide CVE verification completed: {$enriched} of {$records->count()} records enriched");
    }

    public function failed(Throwable $e): void
    {
        activity()->event('cve_verify_failed')->log('Repository-wide CVE verification failed: '.$e->getMessage());
    }
}

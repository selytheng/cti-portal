<?php

namespace App\Services;

use App\Models\CtiIoc;
use App\Models\CtiRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Machine-readable exports (spec Section 8): CSV for spreadsheets/SIEM and
 * STIX 2.1 bundles for TIP integration.
 */
class CtiExportService
{
    public function toCsv(Collection $records): string
    {
        $rows = [[
            'cti_id', 'subject', 'type', 'severity', 'tlp', 'workflow_status',
            'is_developing', 'revision_count', 'published_at', 'modified_at',
            'cves', 'ioc_count', 'threat_actors', 'source_urls',
        ]];

        foreach ($records as $record) {
            $rows[] = [
                $record->cti_id,
                $record->subject,
                $record->type,
                $record->severity,
                $record->tlp,
                $record->workflow_status,
                $record->is_developing ? 'yes' : 'no',
                $record->revision_count,
                $record->published_at?->toIso8601String(),
                $record->modified_at?->toIso8601String(),
                collect($record->cves ?? [])->pluck('cve')->implode(' '),
                $record->iocs()->count(),
                collect($record->threat_actors ?? [])->implode(' '),
                collect($record->sources ?? [])->pluck('url')->implode(' '),
            ];
        }

        return collect($rows)
            ->map(fn (array $row) => implode(',', array_map(
                fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"',
                $row
            )))
            ->implode("\n");
    }

    /**
     * STIX 2.1 bundle: indicators for every IOC plus vulnerability objects
     * for every CVE found in the selected records.
     */
    public function toStix(Collection $records): array
    {
        $objects = [];
        $now = now()->toIso8601String();

        $typeMap = [
            'IPv4' => ['ipv4-addr', 'value'],
            'IPv6' => ['ipv6-addr', 'value'],
            'Domain' => ['domain-name', 'value'],
            'URL' => ['url', 'value'],
            'Email' => ['email-addr', 'value'],
            'MD5' => ['file', "hashes.'MD5'"],
            'SHA1' => ['file', "hashes.'SHA-1'"],
            'SHA256' => ['file', "hashes.'SHA-256'"],
        ];

        foreach ($records as $record) {
            foreach ($record->iocs as $ioc) {
                $map = $typeMap[$ioc->ioc_type] ?? null;

                if ($map === null) {
                    continue;
                }

                [$stixType, $stixKey] = $map;

                $objects[] = [
                    'type' => 'indicator',
                    'spec_version' => '2.1',
                    'id' => 'indicator--'.Str::uuid()->toString(),
                    'created' => $ioc->created_at?->toIso8601String() ?? $now,
                    'modified' => $ioc->last_verified_at?->toIso8601String() ?? $now,
                    'name' => $ioc->defanged_value ?: $ioc->value,
                    'description' => "From {$record->cti_id} — {$record->subject} (verdict: {$ioc->verdict})",
                    'pattern' => "[{$stixType}:{$stixKey} = '".addcslashes($ioc->value, "'")."']",
                    'pattern_type' => 'stix',
                    'valid_from' => $record->published_at?->toIso8601String() ?? $now,
                    'labels' => ['malicious-activity'],
                    'confidence' => $ioc->verdict === 'Malicious' ? 90 : 50,
                ];
            }

            foreach (collect($record->cves ?? [])->pluck('cve') as $cve) {
                $objects[] = [
                    'type' => 'vulnerability',
                    'spec_version' => '2.1',
                    'id' => 'vulnerability--'.Str::uuid()->toString(),
                    'created' => $record->published_at?->toIso8601String() ?? $now,
                    'modified' => $record->modified_at?->toIso8601String() ?? $now,
                    'name' => $cve,
                    'description' => $record->subject,
                    'external_references' => [
                        ['source_name' => 'cve', 'external_id' => $cve],
                    ],
                    'labels' => ['vulnerability'],
                ];
            }
        }

        return [
            'type' => 'bundle',
            'id' => 'bundle--'.Str::uuid()->toString(),
            'objects' => $objects,
        ];
    }

    /** Map a CtiRecord into ReportRenderer variable data (spec 5.5). */
    public function toRendererData(CtiRecord $record): array
    {
        $iocs = $record->iocs->map(fn (CtiIoc $ioc) => [
            'type' => $ioc->ioc_type,
            'value' => $ioc->defanged_value ?: $ioc->value,
            'verdict' => $ioc->verdict,
        ])->all();

        return [
            'cti_id' => $record->cti_id,
            'subject' => $record->subject,
            'type' => $record->type,
            'severity' => $record->severity,
            'tlp' => $record->tlp,
            'published_at' => $record->published_at?->toDayDateTimeString() ?? '—',
            'modified_at' => $record->modified_at?->toDayDateTimeString() ?? '—',
            'sources' => collect($record->sources ?? [])
                ->map(fn (array $s) => ($s['name'] ?? 'source').': '.($s['url'] ?? ''))
                ->implode("\n"),
            'summary' => $record->summary ?? '',
            'technical_analysis' => $record->technical_analysis ?? '',
            'cves' => collect($record->cves ?? [])->map(fn (array $c) => [
                'cve' => $c['cve'],
                'cvss' => $c['cvss'] ?? '—',
                'kev' => $c['kev'] ?? '—',
            ])->all(),
            'iocs' => $iocs,
            'impact_hardware' => $record->impact_hardware ?? [],
            'impact_software' => $record->impact_software ?? [],
            'threat_actor' => $record->threat_actors ?? [],
            'target_object' => $record->target_objects ?? [],
            'mitigation' => $record->mitigation ?? '',
            'generated_by' => 'CTI Portal',
        ];
    }
}

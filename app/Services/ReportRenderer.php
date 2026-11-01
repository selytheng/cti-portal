<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Renders HTML report templates by substituting the {{variable}} chips from
 * spec Section 5.5. Shared by the "preview" pane in the Report Studio and by
 * the Phase 4 report generator.
 */
class ReportRenderer
{
    /**
     * Substitute template variables. Non-string values (arrays) are rendered
     * as simple HTML tables for the *_table variables and comma lists
     * otherwise.
     */
    public function render(string $html, array $data, ?string $css = null, ?string $logoUrl = null, ?string $watermark = null): string
    {
        $data['logo_url'] = $logoUrl ?: ($data['logo_url'] ?? '');
        $data['watermark'] = $watermark ?: ($data['watermark'] ?? '');
        $data['generated_at'] = $data['generated_at'] ?? now()->toDayDateTimeString();

        // Spec 5.5 chips use {{cves_table}}/{{iocs_table}} while callers pass
        // the lists under 'cves'/'iocs' — expose both names.
        foreach (['cves', 'iocs'] as $listKey) {
            if (isset($data[$listKey]) && ! isset($data[$listKey.'_table'])) {
                $data[$listKey.'_table'] = $data[$listKey];
            }
        }

        foreach ($data as $key => $value) {
            $html = str_replace('{{'.$key.'}}', $this->stringify($key, $value), $html);
        }

        // Remove any chips without a value so drafts preview cleanly.
        $html = preg_replace('/\{\{[a-z_]+\}\}/', '', $html);

        if ($css) {
            $html = preg_replace('/<style>(.*?)<\/style>/s', '<style>'.$css.'</style>', $html);
        }

        return $html;
    }

    /**
     * A realistic sample CTI record so the Report Studio preview shows the
     * template exactly as a generated report would look.
     */
    public function sampleData(): array
    {
        return [
            'cti_id' => 'CTI-2026-00042',
            'subject' => 'PHP2Shell — Critical Unauthenticated RCE in PHP Core',
            'type' => 'Vul (Zero-Day)',
            'severity' => 'Critical',
            'tlp' => 'TLP:AMBER',
            'published_at' => '2026-10-21 13:30 UTC',
            'modified_at' => '2026-10-29 18:00 UTC',
            'summary' => 'Two critical vulnerabilities in PHP core allow unauthenticated remote code execution on PHP 5.4+ servers. Fixes released in PHP 8.5.12, 8.4.27, 8.3.36 and 8.2.35. Exploitation observed in the wild; web-shell deployment expected at scale.',
            'technical_analysis' => 'CVE-2026-109618: OOM longjmp() leaves request memory uninitialized before allocation — subsequent cleanup reads stale memory, enabling RCE. CVE-2026-109619: global request-state may point at freed memory after reset; later requests branch on the stale state. Both chains were considered theoretical for a decade until frontier AI models materially reduced exploit-development cost.',
            'cves' => [
                ['cve' => 'CVE-2026-109618', 'cvss' => '9.8', 'kev' => 'Yes'],
                ['cve' => 'CVE-2026-109619', 'cvss' => '9.8', 'kev' => 'Yes'],
            ],
            'iocs' => [
                ['type' => 'IPv4', 'value' => '185[.]220.101.34', 'verdict' => 'Malicious'],
                ['type' => 'Domain', 'value' => 'shellcmd[.]example.net', 'verdict' => 'Suspicious'],
                ['type' => 'SHA256', 'value' => '9f2a…c41d', 'verdict' => 'Malicious'],
            ],
            'impact_hardware' => 'Any x86/ARM server hardware running affected PHP builds',
            'impact_software' => 'PHP 5.4 – 8.5.11 (all SAPIs); WordPress, Drupal, Joomla, Magento deployments',
            'threat_actor' => 'Opportunistic mass-exploitation; web-shell botnets (pre-attribution)',
            'target_object' => 'Internet-facing web servers globally; government and education sectors observed',
            'mitigation' => 'Upgrade to PHP ≥ 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12. Apply WAF rules; isolate unpatchable internet-facing applications until patched.',
            'sources' => 'CERT-EU 2026-018, CISA KEV, KrebsOnSecurity, Unit 42, Fortinet FG-IR-26-186',
            'generated_by' => 'CTI Portal',
        ];
    }

    private function stringify(string $key, mixed $value): string
    {
        if (is_array($value)) {
            // Lists of structs (CVEs, IOCs…) render as tables regardless of
            // the key name — this was the "Array to string conversion" crash.
            $containsArrays = collect($value)->contains(fn ($v) => is_array($v));

            if (str_ends_with($key, '_table') || $containsArrays) {
                return $this->table($value);
            }

            return implode(', ', array_map(
                fn ($v) => is_array($v) ? $this->table([$v]) : strval($v),
                $value
            ));
        }

        return Str::of((string) $value)->replace(['<', '>'], ['&lt;', '&gt;'])->toString();
    }

    private function table(array $rows): string
    {
        if ($rows === []) {
            return '<p><em>None recorded.</em></p>';
        }

        $headers = array_keys($rows[0]);
        $head = implode('', array_map(fn ($h) => '<th>'.htmlspecialchars((string) $h).'</th>', $headers));
        $body = implode('', array_map(function (array $row) {
            return '<tr>'.implode('', array_map(
                fn ($cell) => '<td>'.htmlspecialchars((string) $cell).'</td>',
                array_values($row)
            )).'</tr>';
        }, $rows));

        return "<table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>";
    }
}

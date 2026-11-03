<?php

namespace App\Services;

use Illuminate\Support\Str;

class ReportRenderer
{
    /**
     * Substitute template variables.
     */
    public function render(
        string $html,
        array $data,
        ?string $css = null,
        ?string $logoUrl = null,
        ?string $watermark = null
    ): string {
        // Clean out Javascript 'undefined' or 'null' string literals
        if (in_array($logoUrl, ['undefined', 'null', ''], true)) {
            $logoUrl = null;
        }

        if (isset($data['logo_url']) && in_array($data['logo_url'], ['undefined', 'null'], true)) {
            $data['logo_url'] = '';
        }

        // Resolve logo: parameter > data array > empty string
        $resolvedLogo = $logoUrl ?: ($data['logo_url'] ?? '');

        // If no logo is provided, strip <img> tag so a broken image icon doesn't show
        if (empty($resolvedLogo)) {
            $html = preg_replace('/<img[^>]*src=["\']\{\{logo_url\}\}["\'][^>]*\s*\/?>/i', '', $html);
        } else {
            $data['logo_url'] = $resolvedLogo;
        }

        $data['watermark'] = $watermark ?: ($data['watermark'] ?? '');
        $data['generated_at'] = $data['generated_at'] ?? now()->toDayDateTimeString();

        // Support both list array and _table suffix
        foreach (['cves', 'iocs'] as $listKey) {
            if (isset($data[$listKey]) && ! isset($data[$listKey . '_table'])) {
                $data[$listKey . '_table'] = $data[$listKey];
            }
        }

        // Variable substitution
        foreach ($data as $key => $value) {
            $html = str_replace('{{' . $key . '}}', $this->stringify($key, $value), $html);
        }

        // Remove unpopulated chips
        $html = preg_replace('/\{\{[a-z0-9_]+\}\}/', '', $html);

        // Inject custom CSS if provided
        if ($css && trim($css) !== '') {
            if (str_contains($html, '<style>')) {
                $html = preg_replace('/<style>(.*?)<\/style>/s', '<style>' . $css . '</style>', $html);
            } else {
                $html = "<style>\n{$css}\n</style>\n" . $html;
            }
        }

        return $html;
    }

    /**
     * Resolves local storage, public files, or external URLs into Base64 format.
     */
    public function imageToBase64(?string $pathOrUrl): ?string
    {
        if (empty($pathOrUrl) || in_array($pathOrUrl, ['undefined', 'null'], true)) {
            return null;
        }

        if (str_starts_with($pathOrUrl, 'data:image/')) {
            return $pathOrUrl;
        }

        $resolvedPath = null;

        if (file_exists($pathOrUrl)) {
            $resolvedPath = $pathOrUrl;
        } elseif (file_exists(public_path($pathOrUrl))) {
            $resolvedPath = public_path($pathOrUrl);
        } elseif (file_exists(storage_path('app/public/' . ltrim($pathOrUrl, '/')))) {
            $resolvedPath = storage_path('app/public/' . ltrim($pathOrUrl, '/'));
        }

        if ($resolvedPath && file_exists($resolvedPath)) {
            $mimeType = mime_content_type($resolvedPath) ?: 'image/png';
            $data = file_get_contents($resolvedPath);
            return 'data:' . $mimeType . ';base64,' . base64_encode($data);
        }

        return $pathOrUrl;
    }

    /**
     * Sample fallback SVG logo for Studio preview when no custom logo is uploaded yet.
     */
    public function placeholderLogo(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="48" viewBox="0 0 160 48">
            <rect width="160" height="48" rx="8" fill="#1e293b"/>
            <path d="M20 14L28 24L20 34" stroke="#38bdf8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            <text x="38" y="29" fill="#f8fafc" font-family="sans-serif" font-size="14" font-weight="bold">CTI PORTAL</text>
        </svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Realistic sample CTI record for Report Studio template previews.
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
            $containsArrays = collect($value)->contains(fn($v) => is_array($v));

            if (str_ends_with($key, '_table') || $containsArrays) {
                return $this->table($value);
            }

            return implode(', ', array_map(
                fn($v) => is_array($v) ? $this->table([$v]) : strval($v),
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
        $head = implode('', array_map(fn($h) => '<th>' . htmlspecialchars((string) $h) . '</th>', $headers));
        $body = implode('', array_map(function (array $row) {
            return '<tr>' . implode('', array_map(
                fn($cell) => '<td>' . htmlspecialchars((string) $cell) . '</td>',
                array_values($row)
            )) . '</tr>';
        }, $rows));

        return "<table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>";
    }
}

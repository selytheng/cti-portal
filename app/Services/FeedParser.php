<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Parses RSS 2.0, Atom and JSON feeds (incl. the NVD CVE JSON shape) into a
 * normalized item preview. Used by the "Test Feed" button (spec 5.1) and,
 * later, by the Phase 3 ingestion pipeline.
 */
class FeedParser
{
    /**
     * @return array{format: string, items: array<int, array{title: string, url: ?string, published_at: ?string}>}
     */
    public function parse(string $url, array $headers = []): array
    {
        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->withUserAgent('CTI-Portal/1.0 (threat-intel ingestion)')
            ->throw()
            ->get($url);

        $body = $response->body();
        $trimmed = ltrim($body);

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return ['format' => 'JSON', 'items' => $this->parseJson($body)];
        }

        if (str_contains($trimmed, '<feed')) {
            return ['format' => 'Atom', 'items' => $this->parseAtom($body)];
        }

        return ['format' => 'RSS 2.0', 'items' => $this->parseRss($body)];
    }

    private function parseRss(string $xml): array
    {
        $root = simplexml_load_string($xml, options: LIBXML_NOCDATA);
        $items = [];

        foreach ($root?->channel?->item ?? [] as $item) {
            $items[] = [
                'title' => (string) $item->title,
                'url' => (string) $item->link,
                'published_at' => $this->normalizeDate((string) $item->pubDate),
            ];
        }

        return array_slice($items, 0, 10);
    }

    private function parseAtom(string $xml): array
    {
        $root = simplexml_load_string($xml, options: LIBXML_NOCDATA);
        $items = [];

        foreach ($root?->entry ?? [] as $entry) {
            $link = null;
            foreach ($entry->link ?? [] as $l) {
                $link ??= (string) $l['href'];
            }

            $items[] = [
                'title' => (string) $entry->title,
                'url' => $link,
                'published_at' => $this->normalizeDate((string) ($entry->published ?: $entry->updated)),
            ];
        }

        return array_slice($items, 0, 10);
    }

    /**
     * Handles generic JSON feeds and the NVD CVE 2.0 API shape.
     */
    private function parseJson(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        // NVD CVE API 2.0: {"vulnerabilities": [{"cve": {"id": ..., "published": ..., "descriptions": [...]}}]}
        if (isset($data['vulnerabilities']) && is_array($data['vulnerabilities'])) {
            return collect($data['vulnerabilities'])
                ->map(function (array $wrapper) {
                    $cve = $wrapper['cve'] ?? [];
                    $summary = $cve['descriptions'][0]['value'] ?? '';

                    return [
                        'title' => ($cve['id'] ?? 'CVE').Str::limit(' — '.$summary, 90),
                        'url' => "https://nvd.nist.gov/vuln/detail/".($cve['id'] ?? ''),
                        'published_at' => $cve['published'] ?? null,
                    ];
                })
                ->take(10)
                ->values()
                ->all();
        }

        // Generic: array of objects with title-ish keys.
        $list = $data['items'] ?? $data['results'] ?? $data['articles'] ?? (array_is_list($data) ? $data : []);

        return collect($list)
            ->filter(is_array(...))
            ->map(fn (array $item) => [
                'title' => (string) ($item['title'] ?? $item['name'] ?? $item['id'] ?? ''),
                'url' => $item['url'] ?? $item['link'] ?? null,
                'published_at' => $item['published_at'] ?? $item['published'] ?? $item['date'] ?? null,
            ])
            ->filter(fn (array $item) => $item['title'] !== '')
            ->take(10)
            ->values()
            ->all();
    }

    private function normalizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return $value;
        }
    }
}

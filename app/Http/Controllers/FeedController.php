<?php

namespace App\Http\Controllers;

use App\Models\RssFeed;
use App\Services\FeedParser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validated($request);

        $feed = RssFeed::create($data);

        activity()->causedBy($request->user())->event('created')
            ->log("Added RSS feed {$feed->name} ({$feed->url})");

        return back()->with('success', "Feed {$feed->name} added.");
    }

    public function update(Request $request, RssFeed $feed)
    {
        $feed->update($this->validated($request, $feed));

        activity()->causedBy($request->user())->event('updated')
            ->log("Updated RSS feed {$feed->name}");

        return back()->with('success', "Feed {$feed->name} updated.");
    }

    public function destroy(Request $request, RssFeed $feed)
    {
        $name = $feed->name;
        $feed->delete();

        activity()->causedBy($request->user())->event('deleted')
            ->log("Deleted RSS feed {$name}");

        return back()->with('success', "Feed {$name} deleted.");
    }

    /**
     * "Test Feed" (spec 5.1): live fetch + parse, previewing items and
     * publication dates in the UI.
     */
    public function test(RssFeed $feed, FeedParser $parser)
    {
        try {
            $result = $parser->parse($feed->url, $feed->custom_headers ?? []);

            return back()->with('testResult', [
                'type' => 'feed',
                'feed_id' => $feed->id,
                'name' => $feed->name,
                'format' => $result['format'],
                'items' => $result['items'],
            ]);
        } catch (\Throwable $e) {
            return back()->with('testResult', [
                'type' => 'feed',
                'feed_id' => $feed->id,
                'name' => $feed->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validated(Request $request, ?RssFeed $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048', Rule::unique('rss_feeds', 'url')->ignore($existing?->id)],
            'category' => ['required', Rule::in(RssFeed::CATEGORIES)],
            'polling_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'custom_headers' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}

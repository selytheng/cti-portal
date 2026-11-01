<?php

namespace App\Http\Controllers;

use App\Models\KeywordRule;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KeywordController extends Controller
{
    public function store(Request $request)
    {
        $rule = KeywordRule::create($this->validated($request));

        activity()->causedBy($request->user())->event('created')
            ->log("Added {$rule->rule_type} keyword rule: {$rule->keyword}");

        return back()->with('success', "Keyword rule {$rule->keyword} added.");
    }

    public function update(Request $request, KeywordRule $keyword)
    {
        $keyword->update($this->validated($request, $keyword));

        activity()->causedBy($request->user())->event('updated')
            ->log("Updated keyword rule: {$keyword->keyword}");

        return back()->with('success', "Keyword rule {$keyword->keyword} saved.");
    }

    public function destroy(Request $request, KeywordRule $keyword)
    {
        $text = $keyword->keyword;
        $keyword->delete();

        activity()->causedBy($request->user())->event('deleted')
            ->log("Deleted keyword rule: {$text}");

        return back()->with('success', "Keyword rule {$text} deleted.");
    }

    /** Global master toggles + strict/prioritize mode (spec 5.4). */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'focus_keywords_enabled' => ['required', 'boolean'],
            'exception_keywords_enabled' => ['required', 'boolean'],
            'keyword_match_mode' => ['required', Rule::in(['strict', 'prioritize'])],
        ]);

        foreach ($data as $key => $value) {
            SystemSetting::put($key, $value);
        }

        activity()->causedBy($request->user())->event('updated')
            ->log('Updated keyword engine settings');

        return back()->with('success', 'Keyword engine settings saved.');
    }

    private function validated(Request $request, ?KeywordRule $existing = null): array
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:512'],
            'match_type' => ['required', Rule::in(KeywordRule::MATCH_TYPES)],
            'rule_type' => ['required', Rule::in(KeywordRule::RULE_TYPES)],
            'category' => ['nullable', Rule::in(KeywordRule::CATEGORIES)],
            'severity_boost' => ['required', 'integer', 'min:0', 'max:3'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($data['match_type'] === 'regex' && @preg_match($data['keyword'], '') === false) {
            abort(422, 'The keyword is not a valid regular expression.');
        }

        return $data;
    }
}

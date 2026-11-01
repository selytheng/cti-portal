<?php

namespace App\Services;

use App\Models\KeywordRule;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Keyword Focus & Exception engine (spec Section 5.4) — evaluated per article
 * inside the ingestion pipeline, BEFORE AI extraction.
 */
class KeywordMatcher
{
    public function __construct(
        private readonly bool $focusEnabled,
        private readonly bool $exceptionEnabled,
        private readonly string $mode, // strict | prioritize
        /** @var Collection<int, KeywordRule> */ private readonly Collection $rules
    ) {
    }

    public static function fromDatabase(): self
    {
        return new self(
            (bool) SystemSetting::get('focus_keywords_enabled', true),
            (bool) SystemSetting::get('exception_keywords_enabled', true),
            (string) SystemSetting::get('keyword_match_mode', 'prioritize'),
            KeywordRule::query()->where('is_active', true)->get()
        );
    }

    /**
     * Evaluate an article's text.
     *
     * @return array{dropped: bool, matchedFocus: array<int, string>, boost: int}
     */
    public function evaluate(string $text): array
    {
        $result = ['dropped' => false, 'matchedFocus' => [], 'boost' => 0];

        // Exception rules drop noise before AI extraction (spec 5.4).
        if ($this->exceptionEnabled) {
            foreach ($this->exceptionRules() as $rule) {
                if ($this->matches($rule, $text)) {
                    $result['dropped'] = true;

                    return $result;
                }
            }
        }

        if ($this->focusEnabled) {
            foreach ($this->focusRules() as $rule) {
                if ($this->matches($rule, $text)) {
                    $result['matchedFocus'][] = $rule->keyword;
                    $result['boost'] = max($result['boost'], $rule->severity_boost);
                }
            }

            // Strict filter mode: only ingest articles matching active focus rules.
            if ($this->mode === 'strict' && $this->focusRules()->isNotEmpty() && $result['matchedFocus'] === []) {
                $result['dropped'] = true;
            }
        }

        return $result;
    }

    /** @var Collection<int, KeywordRule> */ private Collection $focusRules;

    /** @return Collection<int, KeywordRule> */
    private function focusRules(): Collection
    {
        return $this->rules->where('rule_type', 'focus');
    }

    /** @return Collection<int, KeywordRule> */
    private function exceptionRules(): Collection
    {
        return $this->rules->where('rule_type', 'exception');
    }

    private function matches(KeywordRule $rule, string $text): bool
    {
        return match ($rule->match_type) {
            'exact' => Str::contains(mb_strtolower($text), mb_strtolower($rule->keyword)),
            'wildcard' => fnmatch(mb_strtolower($rule->keyword), mb_strtolower($text), FNM_CASEFOLD),
            'regex' => @preg_match($rule->keyword, $text) === 1,
            default => false,
        };
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HelpFaqService
{
    /**
     * @return array<string, mixed>
     */
    public function responseFor(string $role, ?string $query = null, ?string $question = null): array
    {
        $items = $this->itemsForRole($role);
        $suggestedQuestions = $items->pluck('question')->take(6)->values()->all();

        if ($question) {
            $selected = $items->first(fn (array $item) => $this->normalize($item['question']) === $this->normalize($question));

            return [
                'success' => true,
                'role' => $role,
                'suggested_questions' => $suggestedQuestions,
                'results' => $selected ? [$selected] : [],
                'answer' => $selected['answer'] ?? null,
            ];
        }

        $results = $query
            ? $this->searchItems($items, $query)->values()
            : $items->take(6)->values();

        return [
            'success' => true,
            'role' => $role,
            'suggested_questions' => $suggestedQuestions,
            'results' => $results->all(),
            'answer' => $results->first()['answer'] ?? null,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function itemsForRole(string $role): Collection
    {
        $items = config("help_faq.{$role}", []);

        return collect($items)
            ->map(fn (array $item) => [
                'category' => (string) ($item['category'] ?? 'Umum'),
                'question' => (string) ($item['question'] ?? ''),
                'answer' => (string) ($item['answer'] ?? ''),
                'keywords' => array_values($item['keywords'] ?? []),
            ])
            ->filter(fn (array $item) => $item['question'] !== '' && $item['answer'] !== '')
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function searchItems(Collection $items, string $query): Collection
    {
        $normalizedQuery = $this->normalize($query);
        $tokens = collect(preg_split('/\s+/', $normalizedQuery) ?: [])
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return $items->take(6);
        }

        return $items
            ->values()
            ->map(function (array $item, int $index) use ($normalizedQuery, $tokens) {
                $haystack = $this->normalize(implode(' ', [
                    $item['category'],
                    $item['question'],
                    $item['answer'],
                    implode(' ', $item['keywords']),
                ]));

                $score = Str::contains($haystack, $normalizedQuery) ? 20 : 0;

                foreach ($tokens as $token) {
                    if (Str::contains($this->normalize($item['question']), $token)) {
                        $score += 4;
                    } elseif (Str::contains($this->normalize(implode(' ', $item['keywords'])), $token)) {
                        $score += 3;
                    } elseif (Str::contains($haystack, $token)) {
                        $score += 1;
                    }
                }

                return $item + [
                    '_index' => $index,
                    '_score' => $score,
                ];
            })
            ->filter(fn (array $item) => $item['_score'] > 0)
            ->sort(fn (array $a, array $b) => $b['_score'] <=> $a['_score'] ?: $a['_index'] <=> $b['_index'])
            ->map(fn (array $item) => collect($item)->except(['_index', '_score'])->all())
            ->take(8);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->squish()
            ->toString();
    }
}

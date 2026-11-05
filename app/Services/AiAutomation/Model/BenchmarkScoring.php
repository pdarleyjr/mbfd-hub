<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Model;

/** Pure scoring for ai-automation:benchmark, kept apart from the command so it can be tested without a model. */
final class BenchmarkScoring
{
    /**
     * @param  array<string, mixed>  $sample  one entry of the samples file
     * @param  string|null  $validated  the reply text the validator accepted, or null when it refused or the model gave none
     * @return array{valid: bool, grounded: bool, correct: bool}
     */
    public static function scoreSample(array $sample, ?string $validated): array
    {
        $type = (string) ($sample['type'] ?? 'question');

        if ($type === 'no_answer') {
            // The articles do not cover it: the right outcome is a refusal, not a confident answer.
            return ['valid' => $validated !== null, 'grounded' => false, 'correct' => $validated === null];
        }
        if ($validated === null) {
            return ['valid' => false, 'grounded' => false, 'correct' => false];
        }

        $grounded = self::mentionsAll($validated, (array) ($sample['must_include'] ?? []));

        return ['valid' => true, 'grounded' => $grounded, 'correct' => $grounded];
    }

    /**
     * Every group must be hit; within a group any one phrase will do (case-insensitive substring).
     *
     * @param  array<int, mixed>  $groups
     */
    public static function mentionsAll(string $text, array $groups): bool
    {
        $lower = mb_strtolower($text);
        foreach ($groups as $group) {
            $hit = false;
            foreach ((array) $group as $phrase) {
                if (str_contains($lower, mb_strtolower((string) $phrase))) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nearest-rank percentile (0 when there are no values).
     *
     * @param  list<int>  $values
     */
    public static function percentile(array $values, float $p): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $rank = (int) ceil($p / 100 * count($values));

        return $values[max(0, min(count($values) - 1, $rank - 1))];
    }
}

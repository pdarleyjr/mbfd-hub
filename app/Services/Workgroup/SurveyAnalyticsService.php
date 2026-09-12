<?php

declare(strict_types=1);

namespace App\Services\Workgroup;

use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyAnswer;
use App\Models\WorkgroupSurveyResponse;
use Illuminate\Support\Collection;

/** Deterministic report metrics. AI only receives the output of this service. */
final class SurveyAnalyticsService
{
    /** @return array<string, mixed> */
    public function calculate(WorkgroupSurvey $survey): array
    {
        $survey->loadMissing('questions');
        $participants = $survey->participants()->get();
        $eligibleParticipants = $participants->where('is_eligible', true);
        $eligibleSubmittedParticipants = $eligibleParticipants->whereNotNull('submitted_at');
        $includedTokens = $participants->whereNotNull('submitted_at')->where('is_eligible', true)->where('include_in_analysis', true)->pluck('response_token');
        $responses = WorkgroupSurveyResponse::query()
            ->where('survey_id', $survey->id)
            ->whereNotNull('submitted_at')
            ->whereIn('participant_token', $includedTokens)
            ->with('answers')
            ->get();

        $questions = [];
        foreach ($survey->questions as $question) {
            $answers = $responses->map(fn (WorkgroupSurveyResponse $response) => $response->answers->firstWhere('survey_question_id', $question->id))
                ->filter(fn ($answer) => $answer instanceof WorkgroupSurveyAnswer);
            $definition = $this->definitionFor($question, $answers);
            $questions[] = [
                'position' => $definition['position'],
                'section' => $this->sectionForPosition($definition['position']),
                'prompt' => $definition['prompt'],
                'type' => $definition['type'],
                'metrics' => $this->questionMetrics($definition['type'], $definition['configuration'], $answers),
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'survey_id' => $survey->id,
            'revision' => $survey->revision,
            'summary' => [
                'eligible_participants' => $eligibleParticipants->count(),
                'submitted_participants' => $participants->whereNotNull('submitted_at')->count(),
                'included_in_analysis' => $responses->count(),
                'excluded_from_analysis' => $participants->whereNotNull('submitted_at')->where(fn ($p) => ! $p->is_eligible || ! $p->include_in_analysis)->count(),
                'response_rate' => $eligibleParticipants->isEmpty() ? null : round(($eligibleSubmittedParticipants->count() / $eligibleParticipants->count()) * 100, 1),
            ],
            'questions' => $questions,
            'demographics' => $this->demographics($survey, $responses),
            'methodology' => [
                'anonymity' => $survey->is_anonymous ? 'Application-level de-identified response anonymity; completion is tracked separately.' : 'Identified response mode.',
                'minimum_subgroup_size' => $survey->minimum_subgroup_size,
                'non_scored_options' => 'Non-scored options remain in distributions and are excluded from numeric denominators.',
            ],
        ];
    }

    private function sectionForPosition(int $position): string
    {
        return match (true) {
            $position <= 2 => 'Current Condition',
            $position <= 8 => 'Qualification / Accountability',
            $position <= 10 => 'Officer / Tactical',
            $position <= 12 => 'Back to Basics Program',
            $position === 13 => 'Leadership',
            $position === 14 => 'Mid-Mount Equipment',
            default => 'Future Priorities',
        };
    }

    /** @param Collection<int, WorkgroupSurveyAnswer> $answers @return array{position: int, prompt: string, type: string, configuration: array<string, mixed>} */
    private function definitionFor(\App\Models\WorkgroupSurveyQuestion $question, Collection $answers): array
    {
        $snapshot = $answers->first()?->questionSnapshotData() ?? [];

        return [
            'position' => is_int($snapshot['position'] ?? null) ? $snapshot['position'] : $question->position,
            'prompt' => is_string($snapshot['prompt'] ?? null) ? $snapshot['prompt'] : $question->prompt,
            'type' => is_string($snapshot['type'] ?? null) ? $snapshot['type'] : $question->type,
            'configuration' => is_array($snapshot['configuration'] ?? null) ? $snapshot['configuration'] : $question->configurationData(),
        ];
    }

    /** @param Collection<int, WorkgroupSurveyAnswer> $answers @param array<string, mixed> $config @return array<string, mixed> */
    private function questionMetrics(string $type, array $config, Collection $answers): array
    {
        return match ($type) {
            'single' => $this->choiceMetrics($answers->map(fn (WorkgroupSurveyAnswer $answer): mixed => $answer->value())->filter(), $config['options'] ?? []),
            'multi' => $this->multiQuestionMetrics($answers, $config),
            'matrix' => $this->matrixMetrics($answers, $config),
            'compound' => $this->compoundMetrics($answers, $config),
            default => ['response_n' => 0],
        };
    }

    /** @param Collection<int, WorkgroupSurveyAnswer> $answers @param array<string, mixed> $config @return array<string, mixed> */
    private function multiQuestionMetrics(Collection $answers, array $config): array
    {
        $values = $answers->map(fn (WorkgroupSurveyAnswer $answer): mixed => $answer->value())
            ->map(fn (mixed $value): mixed => is_array($value) && ! array_is_list($value) ? ($value['selections'] ?? null) : $value)
            ->filter(fn (mixed $value): bool => is_array($value) && array_is_list($value))
            ->values();
        /** @var Collection<int, array<int, string>> $values */

        return $this->multiMetrics($values, $config['options'] ?? []);
    }

    /** @param Collection<int, mixed> $values @param array<int, array<string,mixed>> $options @return array<string,mixed> */
    private function choiceMetrics(Collection $values, array $options): array
    {
        $distribution = [];
        $scored = [];
        $favorable = 0;
        foreach ($options as $option) {
            $key = $option['key'];
            $count = $values->filter(fn ($value) => $value === $key)->count();
            $distribution[] = ['key' => $key, 'label' => $option['label'], 'count' => $count, 'percentage' => $values->isEmpty() ? null : round($count / $values->count() * 100, 1)];
            if (isset($option['score']) && $count > 0) {
                $scored = array_merge($scored, array_fill(0, $count, (float) $option['score']));
                $favorable += ! empty($option['favorable']) ? $count : 0;
            }
        }
        $denominator = count($scored);

        return [
            'response_n' => $values->count(), 'distribution' => $distribution,
            'scored_n' => $denominator, 'mean' => $denominator === 0 ? null : round(array_sum($scored) / $denominator, 2),
            'favorable_n' => $favorable, 'favorable_percentage' => $denominator === 0 ? null : round($favorable / $denominator * 100, 1),
        ];
    }

    /** @param Collection<int, array<int,string>> $values @param array<int, array<string,mixed>> $options @return array<string,mixed> */
    private function multiMetrics(Collection $values, array $options): array
    {
        $n = $values->count();
        $items = collect($options)->map(fn (array $option): array => [
            'key' => $option['key'], 'label' => $option['label'],
            'count' => $values->filter(fn (array $selection) => in_array($option['key'], $selection, true))->count(),
        ])->map(fn (array $item): array => $item + ['percentage' => $n === 0 ? null : round($item['count'] / $n * 100, 1)])
            ->sortByDesc('count')->values()->all();

        return ['response_n' => $n, 'items' => $items];
    }

    /** @param Collection<int, WorkgroupSurveyAnswer> $answers @param array<string,mixed> $config @return array<string,mixed> */
    private function matrixMetrics(Collection $answers, array $config): array
    {
        $rows = [];
        foreach ($config['rows'] ?? [] as $row) {
            $values = $answers->map(function (WorkgroupSurveyAnswer $answer) use ($row): mixed {
                $value = $answer->value();

                return is_array($value) ? ($value[$row['key']] ?? null) : null;
            })->filter();
            $rows[] = ['key' => $row['key'], 'label' => $row['label'], 'metrics' => $this->choiceMetrics($values, $config['options'] ?? [])];
        }

        return ['rows' => $rows];
    }

    /** @param Collection<int, WorkgroupSurveyAnswer> $answers @param array<string,mixed> $config @return array<string,mixed> */
    private function compoundMetrics(Collection $answers, array $config): array
    {
        $parts = [];
        foreach ($config['parts'] ?? [] as $part) {
            $values = $answers->map(function (WorkgroupSurveyAnswer $answer) use ($part): mixed {
                $value = $answer->value();

                return is_array($value) ? ($value[$part['key']] ?? null) : null;
            })->filter();
            $parts[] = ['key' => $part['key'], 'label' => $part['label'], 'metrics' => $this->choiceMetrics($values, $part['options'] ?? [])];
        }

        return ['parts' => $parts];
    }

    /** @param Collection<int, WorkgroupSurveyResponse> $responses @return array<int, array<string,mixed>> */
    private function demographics(WorkgroupSurvey $survey, Collection $responses): array
    {
        return collect($survey->demographic_fields ?? [])->map(function (array $field) use ($responses, $survey): array {
            $counts = $responses->map(fn (WorkgroupSurveyResponse $response): mixed => $response->demographicValue($field['key']))->filter()->countBy();
            $items = $counts->map(function (int $count, string $value) use ($survey): array {
                if ($count < $survey->minimum_subgroup_size) {
                    // Do not retain small-cell category or count in the analytics
                    // snapshot. The same safe data may be sent to AI/exported.
                    return ['suppressed' => true, 'label' => 'Suppressed — insufficient responses for anonymous reporting.'];
                }

                return ['value' => $value, 'count' => $count, 'suppressed' => false];
            })->values()->all();

            return ['key' => $field['key'], 'label' => $field['label'], 'items' => $items];
        })->all();
    }
}

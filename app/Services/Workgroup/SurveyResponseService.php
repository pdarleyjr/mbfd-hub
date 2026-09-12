<?php

declare(strict_types=1);

namespace App\Services\Workgroup;

use App\Models\User;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyAnswer;
use App\Models\WorkgroupSurveyParticipant;
use App\Models\WorkgroupSurveyResponse;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Owns the protected participant-to-response link and submission immutability. */
final class SurveyResponseService
{
    public function participantFor(WorkgroupSurvey $survey, User $user): WorkgroupSurveyParticipant
    {
        $this->requireCurrentSurveyContext($survey, $user);
        abort_unless($survey->isOpen(), 422, 'This survey is not open.');
        $member = app(WorkgroupContext::class)->requireMember($user);

        return WorkgroupSurveyParticipant::query()->firstOrCreate(
            ['survey_id' => $survey->id, 'workgroup_member_id' => $member->id],
            [
                'is_eligible' => true,
                // This is a one-time default only. Later roster changes are survey-specific.
                'include_in_analysis' => $member->count_evaluations,
            ],
        );
    }

    public function existingParticipantFor(WorkgroupSurvey $survey, User $user): ?WorkgroupSurveyParticipant
    {
        $this->requireCurrentSurveyContext($survey, $user);
        $member = app(WorkgroupContext::class)->requireMember($user);

        return WorkgroupSurveyParticipant::query()
            ->where('survey_id', $survey->id)
            ->where('workgroup_member_id', $member->id)
            ->first();
    }

    public function draftFor(WorkgroupSurvey $survey, User $user): WorkgroupSurveyResponse
    {
        $participant = $this->existingParticipantFor($survey, $user);
        if ($participant?->submitted_at !== null && $participant->response_token !== null) {
            $submitted = WorkgroupSurveyResponse::query()
                ->where('survey_id', $survey->id)
                ->where('participant_token', $participant->response_token)
                ->whereNotNull('submitted_at')
                ->first();
            if ($submitted instanceof WorkgroupSurveyResponse) {
                return $submitted;
            }
        }

        abort_unless($survey->isOpen(), 422, 'This survey is not open.');
        $participant ??= $this->participantFor($survey, $user);
        abort_unless($participant->is_eligible, 404);

        if ($participant->response_token === null) {
            $participant->forceFill(['response_token' => (string) Str::uuid()])->save();
        }

        $response = WorkgroupSurveyResponse::query()->firstOrCreate(
            ['participant_token' => $participant->response_token],
            [
                'survey_id' => $survey->id,
                'workgroup_member_id' => $survey->is_anonymous ? null : $participant->workgroup_member_id,
                'survey_revision' => $survey->revision,
            ],
        );

        $memberId = $survey->is_anonymous ? null : $participant->workgroup_member_id;
        if ($response->workgroup_member_id !== $memberId) {
            $response->update(['workgroup_member_id' => $memberId]);
        }

        return $response;
    }

    /** @param array<int|string, mixed> $answers @param array<string, mixed> $demographics */
    public function saveDraft(WorkgroupSurvey $survey, User $user, array $answers, array $demographics = []): WorkgroupSurveyResponse
    {
        $response = $this->draftFor($survey, $user);
        abort_if($response->submitted_at !== null, 422, 'Submitted survey responses are immutable.');
        $this->validateAnswers($survey, $answers, false);
        $response->update(['demographics' => $this->allowedDemographics($survey, $demographics)]);
        $this->replaceAnswers($response, $survey, $answers);

        return $response->fresh(['answers']);
    }

    /** @param array<int|string, mixed> $answers @param array<string, mixed> $demographics */
    public function submit(WorkgroupSurvey $survey, User $user, array $answers, array $demographics = []): WorkgroupSurveyResponse
    {
        abort_unless($survey->isOpen(), 422, 'This survey is not open.');

        return DB::transaction(function () use ($survey, $user, $answers, $demographics): WorkgroupSurveyResponse {
            $response = $this->draftFor($survey, $user);
            abort_if($response->submitted_at !== null, 422, 'This survey has already been submitted.');
            $this->validateAnswers($survey, $answers, true);
            $this->replaceAnswers($response, $survey, $answers);
            $response->update([
                'demographics' => $this->allowedDemographics($survey, $demographics),
                'submitted_at' => now(),
            ]);

            WorkgroupSurveyParticipant::query()
                ->where('survey_id', $survey->id)
                ->where('response_token', $response->participant_token)
                ->update(['submitted_at' => now()]);

            return $response->fresh(['answers']);
        });
    }

    public function setAnalysisInclusion(WorkgroupSurvey $survey, User $actor, WorkgroupSurveyParticipant $participant, bool $included): void
    {
        app(WorkgroupAccess::class)->requireManageSurvey($actor, $survey);
        abort_unless($participant->survey_id === $survey->id, 404);
        $participant->update(['include_in_analysis' => $included]);
    }

    /** @param array<int|string, mixed> $answers */
    private function validateAnswers(WorkgroupSurvey $survey, array $answers, bool $requireComplete): void
    {
        // Form pages can retain a loaded relation while an authorized draft is
        // edited. Validate against the current revision definitions, not a
        // stale in-memory question collection.
        $survey->load('questions');
        $questionIds = $survey->questions->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        foreach (array_keys($answers) as $key) {
            if (! in_array((string) $key, $questionIds, true)) {
                throw ValidationException::withMessages(["answers.{$key}" => 'Unexpected survey question.']);
            }
        }

        foreach ($survey->questions as $question) {
            $value = Arr::get($answers, (string) $question->id, Arr::get($answers, $question->id));
            if ($this->isEmptyAnswer($value)) {
                if ($requireComplete && $question->is_required) {
                    throw ValidationException::withMessages(["answers.{$question->id}" => 'This question is required.']);
                }

                continue;
            }

            $config = $question->configurationData();
            match ($question->type) {
                'single' => $this->validateSingle($question->id, $value, $config),
                'multi' => $this->validateMulti($question->id, $value, $config, $requireComplete),
                'matrix' => $this->validateMatrix($question->id, $value, $config, $requireComplete && $question->is_required),
                'compound' => $this->validateCompound($question->id, $value, $config, $requireComplete && $question->is_required),
                default => throw ValidationException::withMessages(["answers.{$question->id}" => 'Unsupported survey question type.']),
            };
        }
    }

    /** @param array<string, mixed> $config */
    private function validateSingle(int $questionId, mixed $value, array $config): void
    {
        $keys = array_column($config['options'] ?? [], 'key');
        if (! is_string($value) || ! in_array($value, $keys, true)) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Choose one of the supplied options.']);
        }
    }

    /** @param array<string, mixed> $config */
    private function validateMulti(int $questionId, mixed $value, array $config, bool $enforceMinimum): void
    {
        $selections = $this->multiSelections($questionId, $value, $config);
        if (count($selections) !== count(array_unique($selections))) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Choose valid, non-duplicate options.']);
        }
        $keys = array_column($config['options'] ?? [], 'key');
        if (array_diff($selections, $keys) !== []) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Choose only supplied options.']);
        }
        $limit = $config['max_selections'] ?? null;
        if (is_int($limit) && count($selections) > $limit) {
            throw ValidationException::withMessages(["answers.{$questionId}" => "Select no more than {$limit} options."]);
        }
        $minimum = $config['min_selections'] ?? null;
        if ($enforceMinimum && is_int($minimum) && count($selections) < $minimum) {
            throw ValidationException::withMessages(["answers.{$questionId}" => "Select at least {$minimum} options."]);
        }
        $exclusive = $config['exclusive_option'] ?? null;
        if (is_string($exclusive) && in_array($exclusive, $selections, true) && count($selections) !== 1) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'The None option cannot be combined with another option.']);
        }
    }

    /** @param array<string, mixed> $config */
    private function validateMatrix(int $questionId, mixed $value, array $config, bool $required): void
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Rate each applicable row.']);
        }
        $rows = array_column($config['rows'] ?? [], 'key');
        $options = array_column($config['options'] ?? [], 'key');
        foreach ($rows as $row) {
            if ($required && ! array_key_exists($row, $value)) {
                throw ValidationException::withMessages(["answers.{$questionId}.{$row}" => 'This row is required.']);
            }
            if (array_key_exists($row, $value) && ! in_array($value[$row], $options, true)) {
                throw ValidationException::withMessages(["answers.{$questionId}.{$row}" => 'Choose a supplied rating.']);
            }
        }
        if (array_diff(array_keys($value), $rows) !== []) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Unexpected matrix row.']);
        }
    }

    /** @param array<string, mixed> $config */
    private function validateCompound(int $questionId, mixed $value, array $config, bool $required): void
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Complete each part of this question.']);
        }
        foreach ($config['parts'] ?? [] as $part) {
            $key = $part['key'] ?? null;
            if (! is_string($key)) {
                continue;
            }
            if ($required && ! array_key_exists($key, $value)) {
                throw ValidationException::withMessages(["answers.{$questionId}.{$key}" => 'This part is required.']);
            }
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $this->validateSingle($questionId, $value[$key], ['options' => $part['options'] ?? []]);
        }
    }

    /** @param array<int|string, mixed> $answers */
    private function replaceAnswers(WorkgroupSurveyResponse $response, WorkgroupSurvey $survey, array $answers): void
    {
        foreach ($survey->questions as $question) {
            $value = Arr::get($answers, (string) $question->id, Arr::get($answers, $question->id));
            if ($this->isEmptyAnswer($value)) {
                $response->answers()->where('survey_question_id', $question->id)->delete();

                continue;
            }
            $storedValue = $question->type === 'multi'
                ? $this->canonicalMultiValue($question->id, $value, $question->configurationData())
                : $value;
            WorkgroupSurveyAnswer::query()->updateOrCreate(
                ['survey_response_id' => $response->id, 'survey_question_id' => $question->id],
                ['answer' => ['value' => $storedValue], 'question_snapshot' => $question->only(['position', 'type', 'prompt', 'help_text', 'configuration'])],
            );
        }
    }

    private function isEmptyAnswer(mixed $value): bool
    {
        return $value === null || $value === '' || $value === []
            || (is_array($value) && array_key_exists('selections', $value) && $value['selections'] === [] && ($value['other_text'] ?? '') === '');
    }

    /** @param array<string, mixed> $config @return array<int, string> */
    private function multiSelections(int $questionId, mixed $value, array $config): array
    {
        $canonical = $this->canonicalMultiValue($questionId, $value, $config);

        return array_is_list($canonical) ? $canonical : $canonical['selections'];
    }

    /** @param array<string, mixed> $config @return array<int, string>|array{selections: array<int, string>, other_text: string} */
    private function canonicalMultiValue(int $questionId, mixed $value, array $config): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Choose valid, non-duplicate options.']);
        }

        if (! ($config['allow_other_text'] ?? false)) {
            if (! array_is_list($value) || ! collect($value)->every(fn (mixed $selection): bool => is_string($selection))) {
                throw ValidationException::withMessages(["answers.{$questionId}" => 'Choose valid, non-duplicate options.']);
            }

            return $value;
        }

        $otherKey = collect($config['options'] ?? [])->first(fn (mixed $option): bool => is_array($option) && ($option['label'] ?? null) === 'Other')['key'] ?? null;
        if (array_is_list($value)) {
            if (! collect($value)->every(fn (mixed $selection): bool => is_string($selection))) {
                throw ValidationException::withMessages(["answers.{$questionId}" => 'Choose valid, non-duplicate options.']);
            }
            if (is_string($otherKey) && in_array($otherKey, $value, true)) {
                throw ValidationException::withMessages(["answers.{$questionId}.other_text" => 'Provide an explanation for Other.']);
            }

            return $value;
        }
        if (array_diff(array_keys($value), ['selections', 'other_text']) !== [] || ! array_key_exists('selections', $value) || ! is_array($value['selections']) || ! array_is_list($value['selections']) || ! collect($value['selections'])->every(fn (mixed $selection): bool => is_string($selection))) {
            throw ValidationException::withMessages(["answers.{$questionId}" => 'Unexpected auxiliary answer data.']);
        }

        $otherText = $value['other_text'] ?? '';
        if (! is_string($otherText)) {
            throw ValidationException::withMessages(["answers.{$questionId}.other_text" => 'Other explanation must be text.']);
        }
        $otherSelected = is_string($otherKey) && in_array($otherKey, $value['selections'], true);
        if (! $otherSelected && $otherText !== '') {
            throw ValidationException::withMessages(["answers.{$questionId}.other_text" => 'Only provide an Other explanation when Other is selected.']);
        }
        if ($otherSelected && trim($otherText) === '') {
            throw ValidationException::withMessages(["answers.{$questionId}.other_text" => 'Provide an explanation for Other.']);
        }
        if (mb_strlen($otherText) > 1000) {
            throw ValidationException::withMessages(["answers.{$questionId}.other_text" => 'Other explanation must be 1000 characters or fewer.']);
        }

        return ['selections' => $value['selections'], 'other_text' => trim($otherText)];
    }

    /** @param array<string, mixed> $demographics @return array<string, string> */
    private function allowedDemographics(WorkgroupSurvey $survey, array $demographics): array
    {
        $fields = collect($survey->demographic_fields ?? [])
            ->filter(fn (array $field): bool => is_string($field['key'] ?? null))
            ->keyBy('key');
        $validated = [];

        foreach ($demographics as $key => $value) {
            if (! $fields->has($key)) {
                throw ValidationException::withMessages(['demographics' => 'Unexpected demographic field.']);
            }
            if ($value === '') {
                continue;
            }
            if (! is_string($value)) {
                throw ValidationException::withMessages(["demographics.{$key}" => 'Choose one of the supplied demographic options.']);
            }
            $options = $fields->get($key)['options'] ?? [];
            if (! is_array($options) || ! in_array($value, $options, true)) {
                throw ValidationException::withMessages(["demographics.{$key}" => 'Choose one of the supplied demographic options.']);
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    private function requireCurrentSurveyContext(WorkgroupSurvey $survey, User $user): void
    {
        app(WorkgroupAccess::class)->requireSurvey($user, $survey);
        $current = app(WorkgroupContext::class)->requireCurrent($user);
        abort_unless($current->id === $survey->workgroup_id, 404);
    }
}

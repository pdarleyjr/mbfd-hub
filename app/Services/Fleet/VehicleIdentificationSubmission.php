<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Support\Fleet\VehicleIdentificationFields as Fields;
use Illuminate\Support\Facades\Log;

/**
 * Cleans the `vehicle_identification` section a crew sends, item by item.
 *
 * At Daily Checkout (lenient) a malformed item is dropped and logged: identification can never
 * reject a checkout. On the dedicated endpoint (strict) the same items come back as field errors
 * so the person can fix what they typed.
 *
 * The cleaned section is what gets stored in the submission payload, so a replay is identical.
 */
final class VehicleIdentificationSubmission
{
    public const MAX_ANSWERS = 12;

    public const MAX_NOTE = 200;

    /**
     * @return array{section: array{questions_version: string, answers: list<array<string, mixed>>}|null, errors: array<string, string>, dropped: int}
     */
    public function clean(mixed $raw, string $kind, bool $strict = false): array
    {
        if (! is_array($raw)) {
            return ['section' => null, 'errors' => [], 'dropped' => 0];
        }

        $items = is_array($raw['answers'] ?? null) ? array_slice(array_values($raw['answers']), 0, self::MAX_ANSWERS) : [];
        $seen = [];
        $answers = [];
        $errors = [];
        $dropped = 0;
        foreach ($items as $index => $item) {
            [$answer, $error] = $this->cleanItem($item, $kind, $seen);
            if ($answer === null) {
                $dropped++;
                $errors["answers.{$index}"] = $error ?? 'This answer could not be used.';

                continue;
            }
            $seen[$answer['field']] = true;
            $answers[] = $answer;
        }

        if ($dropped > 0 && ! $strict) {
            Log::info('Malformed vehicle identification answers were dropped from a checkout.', [
                'dropped' => $dropped,
                'reasons' => array_values($errors),
            ]);
        }

        $version = is_string($raw['questions_version'] ?? null) ? mb_substr(trim($raw['questions_version']), 0, 64) : '';
        if ($answers === [] && ! $strict) {
            return ['section' => null, 'errors' => $errors, 'dropped' => $dropped];
        }

        return [
            'section' => ['questions_version' => $version, 'answers' => $answers],
            'errors' => $errors,
            'dropped' => $dropped,
        ];
    }

    /**
     * @param  array<string, true>  $seen
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function cleanItem(mixed $item, string $kind, array $seen): array
    {
        if (! is_array($item) || ! Fields::isField($item['field'] ?? null)) {
            return [null, 'Unknown question.'];
        }
        $field = (string) $item['field'];
        if (isset($seen[$field])) {
            return [null, 'This question was answered twice.'];
        }

        if (is_string($item['value'] ?? null) || is_int($item['value'] ?? null)) {
            $value = Fields::clean($field, $kind, $item['value']);

            return $value === null
                ? [null, Fields::refusal($field, $kind)]
                : [['field' => $field, 'value' => $value], null];
        }

        $reason = $item['unavailable_reason'] ?? null;
        if (! Fields::isReason($reason)) {
            return [null, 'Enter the information or say why it is not available.'];
        }
        $note = is_string($item['note'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $item['note'])) : '';
        $note = mb_substr($note, 0, self::MAX_NOTE);
        if (Fields::reasonNeedsNote((string) $reason) && $note === '') {
            return [null, 'Add a short note.'];
        }

        return [['field' => $field, 'unavailable_reason' => (string) $reason, 'note' => $note === '' ? null : $note], null];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Services\AiAutomation\Finding;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ResponseComposer;
use App\Services\SupportTriage\EquipmentClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shows what a member and staff would see for an equipment rule as it is typed on the screen, before anything
 * is saved. The reply is built by the real ResponseComposer, handed a stand-in classifier that answers with the
 * values in the form, so the preview and a real reply cannot drift apart. Nothing is written, sent or logged
 * against a record, and no AI model is called.
 */
final class EquipmentRulePreview
{
    /**
     * @param  array<string, mixed>  $state  the form values
     * @return array{reply: string, level: string, level_label: string, handler: string, alerts: string, meaning: string}
     */
    public function build(array $state): array
    {
        $name = trim((string) ($state['name'] ?? ''));
        $level = in_array($state['level'] ?? null, EquipmentClassification::LEVELS, true) ? (string) $state['level'] : 'medium';
        $handler = array_key_exists((string) ($state['handler'] ?? ''), AdminLabels::handlers()) ? (string) $state['handler'] : 'support_services';
        $contact = array_key_exists((string) ($state['contact_300'] ?? ''), AdminLabels::contact300Options()) ? (string) $state['contact_300'] : 'urgent';
        $custom = trim((string) ($state['member_response'] ?? ''));

        $classification = new EquipmentClassification(
            level: $level,
            category: (string) ($state['category'] ?? 'other'),
            handler: $handler,
            ruleName: $name !== '' ? $name : null,
            reasons: ['catalog_match'],
            memberResponse: $custom !== '' ? $custom : null,
            isPpe: (bool) ($state['is_ppe'] ?? false),
            isCritical: (bool) ($state['is_critical'] ?? false),
            contact300: $contact,
        );

        return [
            'reply' => $this->reply($name !== '' ? $name : 'this item', $classification),
            'level' => $level,
            'level_label' => AdminLabels::level($level),
            'handler' => AdminLabels::handler($handler),
            'alerts' => AdminLabels::alertSentence($level, $handler, $contact),
            'meaning' => AdminLabels::levelMeaning($level),
        ];
    }

    private function reply(string $itemName, EquipmentClassification $classification): string
    {
        try {
            $composer = app()->makeWith(ResponseComposer::class, ['classifier' => new FixedClassifier($classification)]);
            $facts = new IntakeFacts('vehicle', 'inspection_findings', null, [new Finding($itemName, 'missing', false, true)], null, null, false, null, false, CarbonImmutable::now()->subDay());

            return $composer->compose($facts, CarbonImmutable::now(), false)->text;
        } catch (Throwable $e) {
            Log::warning('Equipment rule preview could not build a reply.', ['error' => $e::class]);

            return $classification->memberResponse !== null
                ? trim(strtr($classification->memberResponse, ['{item}' => $itemName]))
                : 'The standard message is used for this item.';
        }
    }
}

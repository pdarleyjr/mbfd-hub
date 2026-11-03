<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Services\AiAutomation\Model\WorkersAiClient;
use Carbon\CarbonImmutable;

/**
 * Deterministic-first reply selection. Rules and the Playbooks templates decide almost everything with no
 * model call. The model is only asked to phrase an answer to a member's low-risk question when a
 * published Knowledge Library article matches; risk set by the rules can never be lowered by the model.
 */
final class ResponseComposer
{
    private const QUESTION_STARTS = ['how', 'where', 'what', 'when', 'who', 'which', 'why', 'can', 'could', 'should', 'do', 'does', 'is', 'are', 'will', 'would'];

    public function __construct(
        private readonly PlaybookRepository $playbooks,
        private readonly KnowledgeLibrary $library,
        private readonly WorkersAiClient $model,
        private readonly ReplyValidator $validator,
    ) {}

    public function compose(IntakeFacts $facts, CarbonImmutable $now, bool $allowModel = true): ComposedReply
    {
        return match ($facts->kind) {
            'inspection_findings' => $this->forFindings($facts, $now),
            'station_request', 'supply_request' => $this->forStationRequest($facts),
            default => $this->forMessage($facts, $allowModel),
        };
    }

    private function forFindings(IntakeFacts $facts, CarbonImmutable $now): ComposedReply
    {
        if ($facts->findings === []) {
            return $this->receipt(['no_findings']);
        }

        $safety = $this->playbooks->terms('safety_terms');
        $expensive = $this->playbooks->terms('expensive_terms');
        $guidance = [];
        $damagedRoutine = 0;

        foreach ($facts->findings as $finding) {
            $text = $finding->itemName.' '.($finding->note ?? '');
            if ($finding->significant || TermMatcher::any($text, $safety)) {
                return $this->receipt(['safety_or_significant'], 'critical');
            }
            if (TermMatcher::any($text, $expensive)) {
                return $this->receipt(['expensive_item'], 'elevated');
            }
            if ($finding->condition === 'damaged') {
                $damagedRoutine++;

                continue;
            }
            if ($finding->condition !== 'missing') {
                return $this->receipt(['other_condition']);
            }
            $class = $this->missingClass($finding->itemName);
            if ($class === null) {
                return $this->receipt(['no_guidance_for_item']);
            }
            $guidance[] = [$class, $finding];
        }

        if ($guidance === [] && $damagedRoutine > 0) {
            return $this->template('noncritical_followup', ComposedReply::RECEIPT_ONLY, ['damaged_noncritical']);
        }
        if ($guidance === [] || $damagedRoutine > 0) {
            return $this->receipt(['mixed_findings']);
        }

        if (count($guidance) > 1) {
            $reply = $this->template('missing_multiple', ComposedReply::RECEIPT_PLUS_GUIDANCE, ['missing_routine_multiple']);
        } else {
            [$class, $finding] = $guidance[0];
            $reply = $this->template($class, ComposedReply::RECEIPT_PLUS_GUIDANCE, ['missing_routine'], ['{item}' => $this->itemLabel($finding->itemName)]);
        }

        return $this->withWeekendLine($reply, $facts, $now);
    }

    private function forStationRequest(IntakeFacts $facts): ComposedReply
    {
        $text = ($facts->category ?? '').' '.($facts->memberText ?? '');
        if (TermMatcher::any($text, $this->playbooks->terms('safety_terms'))) {
            return $this->receipt(['safety_term'], 'critical');
        }

        // "Forwarded" is only true when the adapter confirmed an existing staff work list received it.
        if ($facts->routed) {
            return $this->template('receipt_forwarded', ComposedReply::RECEIPT_ONLY, ['routed']);
        }

        return $this->receipt(['not_routed']);
    }

    private function forMessage(IntakeFacts $facts, bool $allowModel): ComposedReply
    {
        $text = (string) $facts->memberText;

        if ($facts->kind === 'support_message' && TermMatcher::any($text, $this->playbooks->terms('bunker_dropoff_terms'))) {
            // Gear words are safety words, so drop-offs are judged on exposure or damage instead.
            return TermMatcher::any($text, $this->playbooks->terms('exposure_terms'))
                ? $this->receipt(['bunker_gear_drop_off', 'exposure_or_damage'], 'critical')
                : $this->receipt(['bunker_gear_drop_off']);
        }
        // A message on an issue thread is judged together with the item the thread is about.
        $context = $text.' '.implode(' ', array_map(static fn (Finding $finding): string => $finding->itemName, $facts->findings));
        $significant = array_filter($facts->findings, static fn (Finding $finding): bool => $finding->significant) !== [];
        if ($significant || TermMatcher::any($context, $this->playbooks->terms('safety_terms'))) {
            return $this->receipt(['safety_term'], 'critical');
        }
        if (TermMatcher::any($context, $this->playbooks->terms('expensive_terms'))) {
            return $this->receipt(['expensive_item'], 'elevated');
        }
        if (! $this->isQuestion($text)) {
            return $this->receipt(['statement']);
        }

        $articles = $this->library->retrieve($text);
        if ($articles === []) {
            return $this->receipt(['question_no_published_guidance']);
        }
        if (! $allowModel || ! $this->model->available()) {
            return $this->receipt(['question_model_unavailable']);
        }

        $guidanceText = implode("\n\n", array_map(
            static fn ($version): string => '### '.$version->title."\n".$version->body,
            $articles,
        ));
        $reply = $this->model->compose(ModelPrompt::system(), ModelPrompt::user($text, $guidanceText));
        if ($reply === null) {
            return $this->receipt(['model_failed']);
        }

        $answer = $this->validator->validateModelAnswer($reply->text, $guidanceText);
        $refs = array_map(static fn ($version): array => [
            'article_id' => (int) $version->article_id,
            'version_id' => (int) $version->getKey(),
            'version' => (int) $version->version,
        ], $articles);

        if ($answer === null) {
            return new ComposedReply(ComposedReply::RECEIPT_ONLY, $this->templateText('receipt'), 'routine', true, 'receipt',
                $this->playbooks->template('receipt')['version'], ['model_answer_rejected'], $refs, $reply->modelCallId);
        }

        return new ComposedReply(ComposedReply::APPROVED_RAG_GUIDANCE, $answer, 'routine', true, null, null,
            ['approved_guidance'], $refs, $reply->modelCallId);
    }

    private function missingClass(string $itemName): ?string
    {
        if (TermMatcher::any($itemName, $this->playbooks->terms('dewalt_battery_terms')) && TermMatcher::any($itemName, ['battery', 'batteries'])) {
            return 'missing_dewalt_battery';
        }
        if (TermMatcher::any($itemName, $this->playbooks->terms('tool_battery_terms'))) {
            return 'missing_tool_battery';
        }
        if (TermMatcher::any($itemName, $this->playbooks->terms('consumable_terms'))) {
            return 'missing_consumable';
        }
        if (TermMatcher::any($itemName, $this->playbooks->terms('interchangeable_terms'))) {
            return 'missing_interchangeable';
        }

        return null;
    }

    private function withWeekendLine(ComposedReply $reply, IntakeFacts $facts, CarbonImmutable $now): ComposedReply
    {
        $local = $now->setTimezone((string) config('ai_automation.timezone', 'America/New_York'));
        $notes = implode(' ', array_map(static fn (Finding $finding): string => (string) $finding->note, $facts->findings)).' '.$facts->memberText;

        if (! $local->isWeekend() || ! TermMatcher::any($notes, $this->playbooks->terms('urgency_terms'))) {
            return $reply;
        }

        $line = $this->templateText('weekend_spare');

        return new ComposedReply($reply->decision, $reply->text.' '.$line, $reply->riskTier, $reply->needsHuman,
            $reply->templateKey, $reply->templateVersion, [...$reply->reasonCodes, 'weekend_needed_now']);
    }

    private function isQuestion(string $text): bool
    {
        $normalized = TermMatcher::normalize($text);
        if ($normalized === '' || mb_strlen($text) > 600) {
            return false;
        }
        if (str_contains($text, '?')) {
            return true;
        }

        return in_array(strtok($normalized, ' '), self::QUESTION_STARTS, true);
    }

    /** "Kitty Litter (bag)" -> "kitty litter"; keeps the member's own item name, never invents one. */
    private function itemLabel(string $itemName): string
    {
        $label = trim((string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $itemName));
        $label = mb_strtolower($label !== '' ? $label : $itemName);

        return mb_substr($label, 0, 60);
    }

    /**
     * @param  list<string>  $reasons
     * @param  'routine'|'elevated'|'critical'  $tier
     */
    private function receipt(array $reasons, string $tier = 'routine'): ComposedReply
    {
        $decision = $tier === 'routine' ? ComposedReply::RECEIPT_ONLY : ComposedReply::RECEIPT_AND_HUMAN_FLAG;

        return $this->template('receipt', $decision, $reasons, [], $tier);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, string>  $placeholders
     * @param  'routine'|'elevated'|'critical'  $tier
     */
    private function template(string $key, string $decision, array $reasons, array $placeholders = [], string $tier = 'routine'): ComposedReply
    {
        $template = $this->playbooks->template($key);
        $text = trim(strtr($template['body'], $placeholders));

        return new ComposedReply($decision, $text, $tier, true, $key, $template['version'], $reasons);
    }

    private function templateText(string $key): string
    {
        return trim($this->playbooks->template($key)['body']);
    }
}

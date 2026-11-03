<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Services\AiAutomation\Model\WorkersAiClient;
use Carbon\CarbonImmutable;

/**
 * Deterministic-first reply selection. The Playbooks templates decide every report (missing or damaged
 * equipment, station requests, plain statements) with no model call. The model is only asked to phrase an
 * answer to a member's question when a published Knowledge Library article matches; risk set by the rules
 * can never be lowered by the model.
 *
 * Owner decisions (2026-11-03): every missing vehicle item gets the "look for it first, then call or text
 * before heading to Station 2" reply naming the item; readiness items add "notify your officer and 300";
 * on Saturday and Sunday, with no Support Services staff on duty, replies add the 300 weekend line.
 */
final class ResponseComposer
{
    private const QUESTION_STARTS = ['how', 'where', 'what', 'when', 'who', 'which', 'why', 'can', 'could', 'should', 'do', 'does', 'is', 'are', 'will', 'would'];

    private const MAX_NAMED_ITEMS = 3;

    /** Why the last question got no grounded answer (recorded in Activity). */
    private string $lastQuestionReason = 'question_no_published_guidance';

    public function __construct(
        private readonly PlaybookRepository $playbooks,
        private readonly KnowledgeLibrary $library,
        private readonly WorkersAiClient $model,
        private readonly ReplyValidator $validator,
    ) {}

    public function compose(IntakeFacts $facts, CarbonImmutable $now, bool $allowModel = true): ComposedReply
    {
        $weekend = $now->setTimezone((string) config('ai_automation.timezone', 'America/New_York'))->isWeekend();

        return match ($facts->kind) {
            'inspection_findings' => $this->forFindings($facts, $weekend),
            'station_request', 'supply_request' => $this->forStationRequest($facts, $weekend, $allowModel),
            default => $this->forMessage($facts, $weekend, $allowModel),
        };
    }

    private function forFindings(IntakeFacts $facts, bool $weekend): ComposedReply
    {
        if ($facts->findings === []) {
            return $this->receipt(['no_findings']);
        }

        $safety = $this->playbooks->terms('safety_terms');
        $critical = false;
        $missing = [];
        $damaged = 0;
        foreach ($facts->findings as $finding) {
            if ($finding->significant || TermMatcher::any($finding->itemName.' '.($finding->note ?? ''), $safety)) {
                $critical = true;
            }
            match ($finding->condition) {
                'missing' => $missing[] = $finding,
                'damaged' => $damaged++,
                default => null,
            };
        }

        $tier = $critical ? 'critical' : 'routine';
        $decision = $critical ? ComposedReply::RECEIPT_AND_HUMAN_FLAG : ComposedReply::RECEIPT_PLUS_GUIDANCE;

        if ($missing !== []) {
            $batteries = array_filter($missing, fn (Finding $finding): bool => TermMatcher::any($finding->itemName.' '.($finding->note ?? ''), $this->playbooks->terms('battery_terms')));
            $names = $this->itemList($missing);
            if (count($missing) === 1) {
                $key = $batteries !== [] ? 'missing_battery' : 'missing_item';
                $reply = $this->template($key, $decision, [$critical ? 'missing_readiness_item' : 'missing_item'], ['{item}' => $names], $tier);
            } else {
                $reply = $this->template('missing_items', $decision, [$critical ? 'missing_readiness_items' : 'missing_items'], ['{item}' => $names], $tier);
                if ($batteries !== []) {
                    $reply = $this->append($reply, 'battery_line', 'battery');
                }
            }
        } elseif ($damaged > 0) {
            $reply = $critical
                ? $this->receipt(['damaged_readiness_item'], 'critical')
                : $this->template('damaged_item', ComposedReply::RECEIPT_ONLY, ['damaged_item']);
        } else {
            $reply = $this->receipt(['other_condition'], $tier);
        }

        // 300 already covers readiness on the weekend; on weekdays the officer and 300 line stands on its own.
        if ($weekend) {
            return $this->append($reply, 'weekend_line', 'weekend');
        }

        return $critical ? $this->append($reply, 'readiness_line', 'readiness') : $reply;
    }

    private function forStationRequest(IntakeFacts $facts, bool $weekend, bool $allowModel): ComposedReply
    {
        $text = ($facts->category ?? '').' '.($facts->memberText ?? '');
        if (TermMatcher::any($text, $this->playbooks->terms('safety_terms'))) {
            $reply = $this->receipt(['safety_term'], 'critical');
        } elseif ($this->isQuestion((string) $facts->memberText) && ($answer = $this->answerQuestion((string) $facts->memberText, $allowModel)) !== null) {
            return $answer;
        } elseif ($facts->routed) {
            // "Forwarded" is only true when the adapter confirmed an existing staff work list received it.
            $reply = $this->template('receipt_forwarded', ComposedReply::RECEIPT_ONLY, ['routed']);
        } else {
            $reply = $this->receipt(['not_routed']);
        }

        return $weekend ? $this->append($reply, 'weekend_line', 'weekend') : $reply;
    }

    private function forMessage(IntakeFacts $facts, bool $weekend, bool $allowModel): ComposedReply
    {
        $text = (string) $facts->memberText;
        $urgentWeekend = $weekend && TermMatcher::any($text, $this->playbooks->terms('urgency_terms'));
        $finish = fn (ComposedReply $reply): ComposedReply => $urgentWeekend ? $this->append($reply, 'weekend_line', 'weekend') : $reply;

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
            return $finish($this->receipt(['safety_term'], 'critical'));
        }
        if (TermMatcher::any($context, $this->playbooks->terms('expensive_terms'))) {
            return $finish($this->receipt(['expensive_item'], 'elevated'));
        }
        if (! $this->isQuestion($text)) {
            return $finish($this->receipt(['statement']));
        }

        return $this->answerQuestion($text, $allowModel) ?? $finish($this->receipt([$this->lastQuestionReason]));
    }

    /** A grounded 1-3 sentence answer, or null so the caller falls back to its acknowledgment. */
    private function answerQuestion(string $text, bool $allowModel): ?ComposedReply
    {
        $articles = $this->library->retrieve($text);
        if ($articles === []) {
            $this->lastQuestionReason = 'question_no_published_guidance';

            return null;
        }
        if (! $allowModel || ! $this->model->available()) {
            $this->lastQuestionReason = 'question_model_unavailable';

            return null;
        }

        // Standing guidance (Knowledge Library articles marked "use in every answer") travels with every call.
        $sources = [];
        foreach ([...$this->library->standing(), ...$articles] as $version) {
            $sources[(int) $version->getKey()] = $version;
        }
        $sources = array_values($sources);
        $guidanceText = implode("\n\n", array_map(
            static fn ($version): string => '### '.$version->title."\n".$version->body,
            $sources,
        ));
        $refs = array_map(static fn ($version): array => [
            'article_id' => (int) $version->article_id,
            'version_id' => (int) $version->getKey(),
            'version' => (int) $version->version,
        ], $sources);

        $reply = $this->model->compose(ModelPrompt::system(), ModelPrompt::user($text, $guidanceText));
        if ($reply === null) {
            $this->lastQuestionReason = 'model_failed';

            return null;
        }

        $answer = $this->validator->validateModelAnswer($reply->text, $guidanceText);
        if ($answer === null) {
            $receipt = $this->receipt(['model_answer_rejected']);

            return new ComposedReply($receipt->decision, $receipt->text, 'routine', true, $receipt->templateKey,
                $receipt->templateVersion, $receipt->reasonCodes, $refs, $reply->modelCallId);
        }

        return new ComposedReply(ComposedReply::APPROVED_RAG_GUIDANCE, $answer, 'routine', true, null, null,
            ['approved_guidance'], $refs, $reply->modelCallId);
    }

    /**
     * "spanner wrench", "spanner wrench and kitty litter", "spanner wrench, kitty litter and K-tool",
     * "spanner wrench, kitty litter, K-tool and other items". The member's own item names, never invented.
     *
     * @param  list<Finding>  $findings
     */
    private function itemList(array $findings): string
    {
        $names = [];
        foreach ($findings as $finding) {
            $label = $this->itemLabel($finding->itemName);
            if ($label !== '' && ! in_array($label, $names, true)) {
                $names[] = $label;
            }
        }
        if ($names === []) {
            return 'item';
        }

        $shown = array_slice($names, 0, self::MAX_NAMED_ITEMS);
        if (count($names) > self::MAX_NAMED_ITEMS) {
            return implode(', ', $shown).' and other items';
        }
        if (count($shown) === 1) {
            return $shown[0];
        }

        return implode(', ', array_slice($shown, 0, -1)).' and '.end($shown);
    }

    /** "Kitty Litter (bag)" -> "kitty litter"; keeps acronyms like "SCBA" and "K-Tool" readable. */
    private function itemLabel(string $itemName): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $itemName)));
        $label = $label !== '' ? $label : trim($itemName);
        $words = array_map(
            static fn (string $word): string => preg_match('/^[A-Z0-9][A-Z0-9\-]+$/u', $word) === 1 ? $word : mb_strtolower($word),
            explode(' ', $label),
        );

        return mb_substr(implode(' ', $words), 0, 60);
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

    private function append(ComposedReply $reply, string $key, string $reason): ComposedReply
    {
        $line = trim($this->playbooks->template($key)['body']);
        if ($line === '') {
            return $reply;
        }

        return new ComposedReply($reply->decision, $reply->text.' '.$line, $reply->riskTier, $reply->needsHuman,
            $reply->templateKey, $reply->templateVersion, [...$reply->reasonCodes, $reason], $reply->knowledgeRefs, $reply->modelCallId);
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
}

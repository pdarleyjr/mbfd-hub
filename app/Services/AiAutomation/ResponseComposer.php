<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Services\AiAutomation\Model\WorkersAiClient;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\EquipmentClassification;
use App\Services\SupportTriage\TriageProjector;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Deterministic-first reply selection. The equipment catalog (EquipmentClassifier) is the one opinion about
 * importance: it decides LOW, MEDIUM or HIGH and who handles the item, and ReplySelector turns that into one of
 * the editable Playbooks templates. A checkout finding that already has a defect row uses the level staff see
 * (TriageProjector), so the member reply always matches the staff screens. No model is involved in any
 * statement about equipment.
 *
 * The model is only asked to phrase an answer when a member asks a question, or writes free text the catalog
 * cannot classify, AND a published Knowledge Library article matches (strongly, for non-questions). Hazard,
 * protective-equipment and critical reports never reach it, and nothing it writes can lower a risk tier.
 */
final class ResponseComposer
{
    private const QUESTION_STARTS = ['how', 'where', 'what', 'when', 'who', 'which', 'why', 'can', 'could', 'should', 'do', 'does', 'is', 'are', 'will', 'would'];

    private const LEAK_WORDS = '/\bleak(?:s|ed|ing|y)?\b/iu';

    private const DEWALT_WORDS = '/\b(?:dewalt|flexvolt)\b/iu';

    /** Why the last question got no grounded answer (recorded in Activity). */
    private string $lastQuestionReason = 'question_no_published_guidance';

    private readonly ReplySelector $selector;

    public function __construct(
        private readonly PlaybookRepository $playbooks,
        private readonly KnowledgeLibrary $library,
        private readonly WorkersAiClient $model,
        private readonly ReplyValidator $validator,
        private readonly EquipmentClassifier $classifier,
    ) {
        $this->selector = new ReplySelector;
    }

    public function compose(IntakeFacts $facts, CarbonImmutable $now, bool $allowModel = true): ComposedReply
    {
        $weekend = $this->weekendLineApplies($facts, $now);

        return match ($facts->kind) {
            'inspection_findings' => $this->forFindings($facts, $weekend),
            'station_request', 'supply_request' => $this->forStationRequest($facts, $weekend, $allowModel),
            default => $this->forMessage($facts, $weekend, $allowModel),
        };
    }

    private function forFindings(IntakeFacts $facts, bool $weekend): ComposedReply
    {
        if ($facts->findings === []) {
            // An eligible checkout with nothing to answer (VehicleAdapter only builds this for a live row): the fixed
            // one-line receipt. Template path only, no model call, no weekend line.
            return $this->template('clean_receipt', ComposedReply::RECEIPT_ONLY, ['clean_checkout']);
        }

        $assessed = [];
        foreach ($facts->findings as $index => $finding) {
            $assessed[] = $this->assess($finding, $index);
        }
        // Highest level first; within a level, missing items before damaged ones, then checklist order.
        usort($assessed, static fn (array $a, array $b): int => [EquipmentClassification::rank($b['level']), $a['order']] <=> [EquipmentClassification::rank($a['level']), $b['order']]);

        $driver = $assessed[0];
        $group = array_values(array_filter($assessed, fn (array $row): bool => $this->groupOf($row['choice']) === $this->groupOf($driver['choice'])
            && $row['choice']->tier === $driver['choice']->tier));
        $names = array_map(static fn (array $row): string => $row['finding']->itemName, $group);
        $key = $this->groupKey($driver['choice']->key, $names);

        $reply = $this->fromChoice($driver['choice'], $key, ItemNamer::join($names), $driver['cls']);
        if ($key === 'missing_low_multi') {
            $batteries = array_filter($group, static fn (array $row): bool => $row['cls']->category === 'battery');
            if ($batteries !== []) {
                $reply = $this->append($reply, 'battery_line', 'battery');
            }
        }

        $urgent = $key === 'medium_tool' && $driver['cls']->contact300 !== 'never'
            && TermMatcher::any(implode(' ', array_map(static fn (array $row): string => $row['finding']->itemName.' '.($row['finding']->note ?? ''), $group)), $this->playbooks->terms('urgency_terms'));
        if ($urgent) {
            return $this->append($reply, 'medium_urgent_line', 'urgent');
        }

        return $weekend && $driver['choice']->supportServices && $driver['choice']->tier === 'routine' ? $this->append($reply, 'weekend_line', 'weekend') : $reply;
    }

    /**
     * @return array{finding: Finding, cls: EquipmentClassification, level: string, choice: ReplyChoice, order: int}
     */
    private function assess(Finding $finding, int $index): array
    {
        $cls = $this->classifier->classifyItem($finding->itemName, $finding->note, $finding->apparatusType, $finding->significant, $finding->condition);
        // The defect's projected level is what staff see (including a staff override); the catalog fills in the rest.
        $level = $this->projectedLevel($finding->defectId) ?? $cls->level;
        $leak = preg_match(self::LEAK_WORDS, $finding->itemName.' '.($finding->note ?? '')) === 1;
        $dewalt = preg_match(self::DEWALT_WORDS, $finding->itemName) === 1;
        $choice = $this->selector->choose($cls, $level, 'item', $finding->condition, $leak, $dewalt);

        return ['finding' => $finding, 'cls' => $cls, 'level' => $level, 'choice' => $choice, 'order' => ($finding->condition === 'missing' ? 0 : 1000) + $index];
    }

    /** Several low missing items read as one list whichever of them is a battery. */
    private function groupOf(ReplyChoice $choice): string
    {
        return str_starts_with($choice->key, 'missing_') ? 'missing_low' : $choice->key;
    }

    /** @param  list<string>  $names */
    private function groupKey(string $key, array $names): string
    {
        if (! str_starts_with($key, 'missing_')) {
            return $key;
        }

        return ItemNamer::distinctCount($names) > 1 ? 'missing_low_multi' : $key;
    }

    private function forStationRequest(IntakeFacts $facts, bool $weekend, bool $allowModel): ComposedReply
    {
        $text = trim(($facts->category ?? '').' '.($facts->memberText ?? ''));
        $cls = $this->classifier->classifyText($text, $facts->kind === 'supply_request' ? 'supply' : 'station');
        $choice = $this->selector->choose($cls, $cls->level, 'station', 'other', preg_match(self::LEAK_WORDS, $text) === 1);

        if ($choice->key !== ReplySelector::PLAIN) {
            $reply = $this->fromChoice($choice, $choice->key, '', $cls);
        } elseif ($this->canAskModel($cls) && $this->isQuestion((string) $facts->memberText) && ($answer = $this->answerFromLibrary((string) $facts->memberText, $allowModel, false)) !== null) {
            return $answer;
        } elseif ($facts->routed) {
            // "Forwarded" is only true when the adapter confirmed an existing staff work list received it.
            $reply = $this->template('receipt_forwarded', ComposedReply::RECEIPT_ONLY, ['routed', ...$choice->reasons], [], $choice->tier);
        } else {
            $reply = $this->receipt(['not_routed', ...$choice->reasons], $choice->tier);
        }

        return $weekend && $choice->supportServices && $choice->tier === 'routine' ? $this->append($reply, 'weekend_line', 'weekend') : $reply;
    }

    private function forMessage(IntakeFacts $facts, bool $weekend, bool $allowModel): ComposedReply
    {
        $text = (string) $facts->memberText;
        $urgent = TermMatcher::any($text, $this->playbooks->terms('urgency_terms'));

        if ($facts->kind === 'support_message' && TermMatcher::any($text, $this->playbooks->terms('bunker_dropoff_terms'))) {
            // Gear words are safety words, so drop-offs are judged on exposure or damage instead.
            return TermMatcher::any($text, $this->playbooks->terms('exposure_terms'))
                ? $this->receipt(['bunker_gear_drop_off', 'exposure_or_damage'], 'critical')
                : $this->receipt(['bunker_gear_drop_off']);
        }

        // A message on an issue thread is judged together with the item the thread is about.
        [$cls, $choice] = $this->strongest($text, $facts->findings);
        $leak = preg_match(self::LEAK_WORDS, $text.' '.implode(' ', array_map(static fn (Finding $f): string => $f->itemName, $facts->findings))) === 1;
        if ($leak) {
            $choice = $this->selector->choose($cls, $cls->level, 'message', 'other', true);
        }

        if ($choice->key !== ReplySelector::PLAIN) {
            $reply = $this->fromChoice($choice, $choice->key, '', $cls);
        } else {
            $question = $this->isQuestion($text);
            if ($this->canAskModel($cls) && ($question || $cls->unclassified)
                && ($answer = $this->answerFromLibrary($text, $allowModel, ! $question)) !== null) {
                return $answer;
            }
            $reply = $this->receipt([$question ? $this->lastQuestionReason : 'statement', ...$choice->reasons], $choice->tier);
        }

        return $weekend && $urgent && $choice->supportServices && $choice->tier === 'routine' ? $this->append($reply, 'weekend_line', 'weekend') : $reply;
    }

    /**
     * The most important reading of a message: its own text, or any item on the thread it belongs to.
     *
     * @param  list<Finding>  $findings
     * @return array{0: EquipmentClassification, 1: ReplyChoice}
     */
    private function strongest(string $text, array $findings): array
    {
        $best = $this->classifier->classifyText($text, 'support');
        $bestChoice = $this->selector->choose($best, $best->level, 'message');
        foreach ($findings as $finding) {
            $cls = $this->classifier->classifyItem($finding->itemName, $finding->note ?? $text, $finding->apparatusType, $finding->significant, $finding->condition);
            $level = $this->projectedLevel($finding->defectId) ?? $cls->level;
            if (EquipmentClassification::rank($level) > EquipmentClassification::rank($best->level) || ($best->unclassified && ! $cls->unclassified)) {
                $best = $cls;
                $bestChoice = $this->selector->choose($cls, $level, 'message');
            }
        }

        return [$best, $bestChoice];
    }

    /** Only low or unknown, non-protective, non-hazard text may be phrased by the model. */
    private function canAskModel(EquipmentClassification $cls): bool
    {
        return ! $cls->hazard && ! $cls->isPpe && ! $cls->isCritical
            && ($cls->level === EquipmentClassification::LOW || $cls->unclassified);
    }

    /**
     * A grounded 1-3 sentence answer, or null so the caller falls back to its acknowledgment.
     *
     * @param  bool  $strongOnly  free text that is not a question needs a strong article match before a model is used
     */
    private function answerFromLibrary(string $text, bool $allowModel, bool $strongOnly): ?ComposedReply
    {
        $articles = $strongOnly ? $this->library->retrieveStrong($text) : $this->library->retrieve($text);
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

        $answer = $this->validator->validateModelAnswer($reply->text, $guidanceText, $text);
        if ($answer === null) {
            $this->lastQuestionReason = 'model_answer_rejected';
            $receipt = $this->receipt(['model_answer_rejected']);

            return new ComposedReply($receipt->decision, $receipt->text, 'routine', true, $receipt->templateKey,
                $receipt->templateVersion, $receipt->reasonCodes, $refs, $reply->modelCallId);
        }

        return new ComposedReply(ComposedReply::APPROVED_RAG_GUIDANCE, $answer, 'routine', true, null, null,
            ['approved_guidance'], $refs, $reply->modelCallId);
    }

    /**
     * Builds the reply for a choice: the catalog's own member text if the administrator wrote one, else the
     * Playbooks template, with the item names filled in.
     */
    private function fromChoice(ReplyChoice $choice, string $key, string $names, EquipmentClassification $cls): ComposedReply
    {
        $decision = $choice->tier === 'routine' ? ComposedReply::RECEIPT_PLUS_GUIDANCE : ComposedReply::RECEIPT_AND_HUMAN_FLAG;
        $reasons = ['catalog_reply', ...$choice->reasons];

        if ($choice->key === ReplySelector::CUSTOM) {
            $text = trim(strtr((string) $cls->memberResponse, ['{item}' => $names !== '' ? $names : 'item']));

            return new ComposedReply($decision, $text, $choice->tier, true, ReplySelector::CUSTOM, null, [...$reasons, 'catalog_member_response', ...($cls->ruleId !== null ? ['rule_'.$cls->ruleId] : [])]);
        }
        if (in_array($key, ['damaged_low', 'receipt'], true)) {
            $decision = ComposedReply::RECEIPT_ONLY;
        }

        return $this->template($key, $decision, $reasons, ['{item}' => $names !== '' ? $names : 'item'], $choice->tier);
    }

    /** Weekend line: a weekend now AND the submission itself made in the last 48 hours (never on old backlog). */
    private function weekendLineApplies(IntakeFacts $facts, CarbonImmutable $now): bool
    {
        if (! $now->setTimezone((string) config('ai_automation.timezone', 'America/New_York'))->isWeekend() || $facts->submittedAt === null) {
            return false;
        }

        $age = $now->getTimestamp() - $facts->submittedAt->getTimestamp();

        return $age >= -3600 && $age <= (int) config('ai_automation.reply.weekend_line_max_age_hours', 48) * 3600;
    }

    /** The triage level staff see for this defect, or null when it has no projection row yet (the catalog level is used). */
    private function projectedLevel(?int $defectId): ?string
    {
        if ($defectId === null) {
            return null;
        }

        try {
            $level = app(TriageProjector::class)->levelFor('apparatus_defect', $defectId);
        } catch (Throwable) {
            return null; // a projection problem must never stop the member being answered
        }

        return $level !== null && in_array($level, EquipmentClassification::LEVELS, true) ? $level : null;
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

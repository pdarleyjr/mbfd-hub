<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\Finding;
use App\Services\AiAutomation\IntakeFacts;
use App\Services\AiAutomation\ResponseComposer;
use App\Services\SupportTriage\Contracts\EquipmentClassifier;
use App\Services\SupportTriage\EquipmentClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Try a message" on the Overview page: a made-up checkout finding, station request or Support Services message
 * goes through the same priority rules and reply wording as a real one. Nothing is saved, sent or logged, and no
 * AI model is called.
 */
trait TriesAMessage
{
    public string $testArea = 'vehicle';

    public string $testItem = '';

    public string $testCondition = 'missing';

    /** Checklist type of the vehicle (engine, ladder1 ...) or empty for any. */
    public string $testApparatus = '';

    public string $testText = '';

    public ?string $testResult = null;

    public ?string $testLevel = null;

    public ?string $testHandler = null;

    public ?string $testAlerts = null;

    public ?string $testWhy = null;

    public ?string $testReason = null;

    /** @var list<string> */
    public array $testGuidance = [];

    public function runTestResponse(): void
    {
        abort_unless(AiAutomationAccess::allowsCurrentUser(), 403);

        $this->validate([
            'testArea' => ['required', 'in:vehicle,station,support'],
            'testItem' => ['nullable', 'string', 'max:120'],
            'testCondition' => ['required', 'in:missing,damaged,other'],
            'testApparatus' => ['nullable', 'in:'.implode(',', array_keys(AdminLabels::trucks()))],
            'testText' => ['nullable', 'string', 'max:600'],
        ]);

        $text = $this->testText !== '' ? $this->testText : null;
        $truck = $this->testApparatus !== '' ? $this->testApparatus : null;
        $classification = $this->classifyTestMessage($text, $truck);

        $facts = match ($this->testArea) {
            'vehicle' => new IntakeFacts('vehicle', 'inspection_findings', null,
                [new Finding($this->testItem !== '' ? $this->testItem : 'Item', $this->testCondition, false, true, $text, $truck)], null, null, false, null, false, CarbonImmutable::now()),
            'station' => new IntakeFacts('station', 'station_request', null, [], $text, null, false, null, true, CarbonImmutable::now()),
            default => new IntakeFacts('support', 'support_message', null, [], $text, null, false, null, false, CarbonImmutable::now()),
        };

        $reply = app(ResponseComposer::class)->compose($facts, CarbonImmutable::now(), false);

        $this->testResult = $reply->text;
        $this->testWhy = AdminLabels::decision($reply->decision);
        $this->testReason = $reply->needsHuman || $reply->riskTier !== 'routine'
            ? 'Staff still see the original submission and follow up.'
            : 'No further staff action is needed.';
        $this->testGuidance = $reply->knowledgeRefs === [] ? [] : ['Wording from Knowledge & Instructions'];

        if ($classification instanceof EquipmentClassification) {
            $this->testLevel = $classification->level;
            $this->testHandler = AdminLabels::handler($classification->handler);
            $this->testAlerts = AdminLabels::alertSentence($classification->level, $classification->handler, $classification->contact300);
        } else {
            $this->testLevel = null;
            $this->testHandler = null;
            $this->testAlerts = null;
        }
    }

    private function classifyTestMessage(?string $text, ?string $truck): ?EquipmentClassification
    {
        try {
            $classifier = app(EquipmentClassifier::class);

            return match ($this->testArea) {
                'vehicle' => $classifier->classifyItem($this->testItem !== '' ? $this->testItem : 'Item', $text, $truck, false, $this->testCondition),
                'station' => $classifier->classifyText((string) $text, 'station'),
                default => $classifier->classifyText((string) $text, 'support'),
            };
        } catch (Throwable $e) {
            Log::warning('Try a message could not classify the made-up message.', ['error' => $e::class]);

            return null;
        }
    }
}

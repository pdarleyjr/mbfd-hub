<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrectionResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryCorrectionDiff;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOperationException;
use App\Services\Inventory\InventoryOverlay;
use App\Support\Fleet\InventoryCorrectionAccess;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One inventory correction: what the member saw and proposed, what the checklist says now,
 * the impact of a decision across apparatus, the conversation and the audit trail.
 */
class ViewInventoryCorrection extends ViewRecord
{
    protected static string $resource = InventoryCorrectionResource::class;

    protected static string $view = 'filament.resources.inventory-correction-resource.pages.view-inventory-correction';

    /** @var array<string, array<string, mixed>> plans computed during this request, by form state */
    private array $plans = [];

    public function getTitle(): string|Htmlable
    {
        return 'Inventory correction '.$this->correction()->reference();
    }

    public function getHeading(): string|Htmlable
    {
        return InventoryCorrectionResource::unitLabel($this->correction()).': '.InventoryCorrectionReason::label($this->correction()->reason);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $correction = $this->correction()->load(['apparatus', 'reporter', 'reporterEmployee', 'decidedBy', 'inspection']);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($correction->apparatus);
        $planner = app(InventoryChangePlanner::class);
        $current = $correction->item_key !== null && is_array($resolution['checklist'] ?? null)
            ? $planner->describe($resolution['checklist'], (string) $correction->item_key)
            : null;
        $suggestedPlan = $this->safePlan(InventoryCorrectionReason::suggestedOperation($correction), InventoryChangePlanner::SCOPE_UNIT);
        $sourceUnit = collect($suggestedPlan['units'] ?? [])->firstWhere('is_source', true);

        return [
            'correction' => $correction,
            'reasonLabel' => InventoryCorrectionReason::label($correction->reason),
            'statusLabel' => ApparatusInventoryCorrection::statusLabel($correction->status),
            'current' => $current,
            'diffRows' => InventoryCorrectionDiff::rows($correction),
            'suggestedAfter' => $sourceUnit['after'] ?? null,
            'suggestedVerdict' => $sourceUnit['verdict_label'] ?? null,
            'compartmentNames' => $this->compartmentOptions(),
            'events' => $correction->events()->with('actor:id,name,display_name')->get(),
            'changes' => ApparatusInventoryChange::query()->where('apparatus_inventory_correction_id', $correction->getKey())
                ->with('apparatus:id,designation,name')->orderBy('id')->get(),
            'inspectionUrl' => $correction->apparatus_inspection_id !== null
                ? InspectionResource::getUrl('view', ['record' => $correction->apparatus_inspection_id])
                : null,
            'apparatusUrl' => ApparatusResource::canView($correction->apparatus) ? ApparatusResource::getUrl('view', ['record' => $correction->apparatus_id]) : null,
            'photoUrl' => $correction->photo_path !== null ? route('inventory-corrections.photo', $correction) : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            Action::make('requestClarification')
                ->label('Ask the member')
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->correction()->isOpen() && $this->isReviewer())
                ->modalHeading('Ask the member a question')
                ->modalDescription('The correction waits for the answer. The member gets a notification and can reply from the Hub.')
                ->modalSubmitActionLabel('Send question')
                ->fillForm(fn (): array => ['lock_version' => $this->correction()->lock_version, 'key' => (string) Str::uuid()])
                ->form([
                    Forms\Components\Hidden::make('lock_version'),
                    Forms\Components\Hidden::make('key'),
                    Forms\Components\Textarea::make('message')->label('Your question')->required()->rows(4)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                ])
                ->action(fn (array $data) => $this->run(fn (User $user) => app(InventoryCorrectionService::class)->requestClarification(
                    (int) $this->correction()->getKey(), $user, (string) $data['message'], (int) $data['lock_version'], $data['key'] ?? null,
                ), 'Question sent to the member')),
            Action::make('reply')
                ->label('Reply or add note')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('gray')
                ->visible(fn (): bool => $this->isReviewer())
                ->modalHeading('Write in this correction\'s thread')
                ->modalSubmitActionLabel('Save')
                ->fillForm(fn (): array => ['visibility' => Event::VISIBILITY_MEMBER, 'key' => (string) Str::uuid()])
                ->form([
                    Forms\Components\Hidden::make('key'),
                    Forms\Components\Radio::make('visibility')->label('Who can read it')->required()->options([
                        Event::VISIBILITY_MEMBER => 'The member and Logistics (the member is notified)',
                        Event::VISIBILITY_INTERNAL => 'Logistics only (internal note)',
                    ]),
                    Forms\Components\Textarea::make('body')->label('Message')->required()->rows(4)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                ])
                ->action(fn (array $data) => $this->run(fn (User $user) => app(InventoryCorrectionService::class)->postMessage(
                    (int) $this->correction()->getKey(), $user, (string) $data['body'], (string) $data['visibility'], $data['key'] ?? null,
                ), $data['visibility'] === Event::VISIBILITY_INTERNAL ? 'Internal note saved' : 'Message sent to the member')),
            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->correction()->isOpen() && $this->isReviewer())
                ->modalHeading('Reject this request')
                ->modalDescription('The checklist stays exactly as it is. The member is told it was rejected, with your message if you write one.')
                ->modalSubmitActionLabel('Reject request')
                ->fillForm(fn (): array => ['lock_version' => $this->correction()->lock_version, 'key' => (string) Str::uuid()])
                ->form([
                    Forms\Components\Hidden::make('lock_version'),
                    Forms\Components\Hidden::make('key'),
                    Forms\Components\Textarea::make('reason')->label('Message to the member (optional)')->rows(4)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                    Forms\Components\Textarea::make('internal_note')->label('Internal note (Logistics only, optional)')->rows(2)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                ])
                ->action(fn (array $data) => $this->run(fn (User $user) => app(InventoryCorrectionService::class)->reject(
                    (int) $this->correction()->getKey(), $user, $data['reason'] ?? null, $data['internal_note'] ?? null, (int) $data['lock_version'], $data['key'] ?? null,
                ), 'Request rejected. The checklist was not changed and the member was told.')),
            Action::make('reverse')
                ->label('Reverse change')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->correction()->status === ApparatusInventoryCorrection::STATUS_APPLIED && $this->isReviewer())
                ->requiresConfirmation()
                ->modalHeading('Reverse this inventory change')
                ->modalDescription('Every apparatus this correction changed goes back to its previous checklist. Past inspections are not affected.')
                ->fillForm(fn (): array => ['lock_version' => $this->correction()->lock_version, 'key' => (string) Str::uuid()])
                ->form([
                    Forms\Components\Hidden::make('lock_version'),
                    Forms\Components\Hidden::make('key'),
                    Forms\Components\Textarea::make('reason')->label('Reason shared with the member')->required()->rows(3)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                ])
                ->action(fn (array $data) => $this->run(fn (User $user) => app(InventoryCorrectionService::class)->reverse(
                    (int) $this->correction()->getKey(), $user, (string) $data['reason'], (int) $data['lock_version'], $data['key'] ?? null,
                ), 'Change reversed')),
        ];
    }

    private function approveAction(): Action
    {
        $correction = fn (): ApparatusInventoryCorrection => $this->correction();
        $isNew = fn (): bool => $correction()->item_key === null;
        $refresh = function (Get $get, Set $set): void {
            $plan = $this->safePlan($this->operationFromState($get), (string) ($get('scope') ?: InventoryChangePlanner::SCOPE_UNIT));
            $set('preview_fingerprint', $plan['fingerprint'] ?? '');
            $set('apparatus_ids', collect($plan['units'] ?? [])->where('default_selected', true)->pluck('apparatus_id')->map(fn ($id): string => (string) $id)->values()->all());
        };

        return Action::make('approve')
            ->label('Review and approve')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $correction()->isOpen() && $this->isReviewer())
            ->modalHeading('Approve and update the checklist')
            ->modalDescription('Check the change and where it applies. Approving updates the next Daily Checkout checklist for the selected apparatus. Past inspections never change.')
            ->modalSubmitActionLabel('Approve and apply')
            ->modalWidth('4xl')
            ->fillForm(function () use ($correction): array {
                $state = $this->defaultApprovalState($correction());
                $plan = $this->safePlan($this->operationFromArray($state), InventoryChangePlanner::SCOPE_UNIT);

                return [
                    ...$state,
                    'scope' => InventoryChangePlanner::SCOPE_UNIT,
                    'preview_fingerprint' => $plan['fingerprint'] ?? '',
                    'apparatus_ids' => collect($plan['units'] ?? [])->where('default_selected', true)->pluck('apparatus_id')->map(fn ($id): string => (string) $id)->values()->all(),
                    'lock_version' => $correction()->lock_version,
                    'key' => (string) Str::uuid(),
                ];
            })
            ->form([
                Forms\Components\Hidden::make('lock_version'),
                Forms\Components\Hidden::make('key'),
                Forms\Components\Hidden::make('preview_fingerprint'),
                Forms\Components\Section::make('The change')
                    ->description('Starts from what the member proposed. Edit anything before approving; the member\'s original proposal is kept.')
                    ->schema([
                        Forms\Components\Radio::make('op')
                            ->label('Decision')
                            ->options(fn (): array => $isNew()
                                ? [ApparatusInventoryChange::OP_ADD => 'Add this item to the checklist']
                                : [ApparatusInventoryChange::OP_MODIFY => 'Change this item', ApparatusInventoryChange::OP_REMOVE => 'Remove this item from the checklist'])
                            ->required()
                            ->live()
                            ->afterStateUpdated($refresh),
                        Forms\Components\Select::make('compartment_id')
                            ->label(fn (): string => $isNew() ? 'Compartment' : 'Compartment (change to move it)')
                            ->options(fn (): array => $this->compartmentOptions())
                            ->required(fn (Get $get): bool => $get('op') === ApparatusInventoryChange::OP_ADD)
                            ->visible(fn (Get $get): bool => $get('op') !== ApparatusInventoryChange::OP_REMOVE)
                            ->live()
                            ->afterStateUpdated($refresh),
                        Forms\Components\TextInput::make('name')->label('Item name')->maxLength(160)
                            ->required(fn (Get $get): bool => $get('op') !== ApparatusInventoryChange::OP_REMOVE)
                            ->visible(fn (Get $get): bool => $get('op') !== ApparatusInventoryChange::OP_REMOVE)
                            ->live(onBlur: true)->afterStateUpdated($refresh),
                        Forms\Components\TextInput::make('expectedQuantity')->label('Expected quantity')->numeric()->integer()->minValue(1)->maxValue(999)
                            ->helperText('Leave empty when the checklist does not count this item.')
                            ->visible(fn (Get $get): bool => $get('op') !== ApparatusInventoryChange::OP_REMOVE)
                            ->live(onBlur: true)->afterStateUpdated($refresh),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('manufacturer')->label('Manufacturer')->maxLength(120)->live(onBlur: true)->afterStateUpdated($refresh),
                            Forms\Components\TextInput::make('model')->label('Model')->maxLength(120)->live(onBlur: true)->afterStateUpdated($refresh),
                        ])->visible(fn (Get $get): bool => $get('op') !== ApparatusInventoryChange::OP_REMOVE),
                        Forms\Components\Textarea::make('description')->label('Description')->rows(2)->maxLength(500)
                            ->visible(fn (Get $get): bool => $get('op') !== ApparatusInventoryChange::OP_REMOVE)
                            ->live(onBlur: true)->afterStateUpdated($refresh),
                    ]),
                Forms\Components\Section::make('Where it applies')
                    ->schema([
                        Forms\Components\Radio::make('scope')
                            ->label('Apply to')
                            ->options(fn (Get $get): array => $this->scopeOptions($get('op')))
                            ->default(InventoryChangePlanner::SCOPE_UNIT)
                            ->required()
                            ->live()
                            ->afterStateUpdated($refresh)
                            ->helperText(fn (): ?string => InventoryCorrectionAccess::canPropagate(auth()->user()) ? null : 'Changing more than one apparatus requires a Logistics or administrator role.'),
                        Forms\Components\Placeholder::make('impact')
                            ->label('Impact')
                            ->content(fn (Get $get): HtmlString => $this->impactHtml($get)),
                        Forms\Components\CheckboxList::make('apparatus_ids')
                            ->label('Apparatus to change')
                            ->helperText('Eligible apparatus are selected. Excluded ones explain why; some can still be included.')
                            ->options(fn (Get $get): array => $this->unitOptions($get))
                            ->descriptions(fn (Get $get): array => $this->unitDescriptions($get))
                            ->required()
                            ->columns(1),
                    ]),
                Forms\Components\Section::make('Messages')
                    ->schema([
                        Forms\Components\Textarea::make('member_message')->label('Note to the member (optional)')->rows(2)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                        Forms\Components\Textarea::make('internal_note')->label('Internal note (Logistics only, optional)')->rows(2)->maxLength(InventoryCorrectionService::MAX_MESSAGE),
                    ])->collapsible(),
            ])
            ->action(function (array $data): void {
                $this->run(fn (User $user) => app(InventoryCorrectionService::class)->approve(
                    (int) $this->correction()->getKey(),
                    $user,
                    $this->operationFromArray($data),
                    (string) $data['scope'],
                    array_values($data['apparatus_ids'] ?? []),
                    (string) ($data['preview_fingerprint'] ?? ''),
                    (int) $data['lock_version'],
                    (string) $data['key'],
                    $data['member_message'] ?? null,
                    $data['internal_note'] ?? null,
                ), 'Approved. The next checklist for the selected apparatus includes this change.');
            });
    }

    /** @return array<string, mixed> */
    private function defaultApprovalState(ApparatusInventoryCorrection $correction): array
    {
        $suggested = InventoryCorrectionReason::suggestedOperation($correction);
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($correction->apparatus);
        $current = $correction->item_key !== null && is_array($resolution['checklist'] ?? null)
            ? app(InventoryChangePlanner::class)->describe($resolution['checklist'], (string) $correction->item_key)
            : null;
        $fields = $suggested['fields'];

        return [
            'op' => $suggested['op'],
            'compartment_id' => $fields['compartment_id'] ?? ($current['compartment_id'] ?? $correction->compartment_key),
            'name' => $fields['name'] ?? ($current['name'] ?? null),
            'expectedQuantity' => array_key_exists('expectedQuantity', $fields) ? $fields['expectedQuantity'] : ($current['expectedQuantity'] ?? null),
            'manufacturer' => $fields['manufacturer'] ?? ($current['manufacturer'] ?? null),
            'model' => $fields['model'] ?? ($current['model'] ?? null),
            'description' => $fields['description'] ?? ($current['description'] ?? null),
        ];
    }

    /** @return array{op: string, fields: array<string, mixed>} */
    private function operationFromState(Get $get): array
    {
        return $this->operationFromArray([
            'op' => $get('op'),
            'compartment_id' => $get('compartment_id'),
            'name' => $get('name'),
            'expectedQuantity' => $get('expectedQuantity'),
            'manufacturer' => $get('manufacturer'),
            'model' => $get('model'),
            'description' => $get('description'),
        ]);
    }

    /**
     * Only what differs from the item as it is today becomes part of the decision.
     *
     * @param  array<string, mixed>  $state
     * @return array{op: string, fields: array<string, mixed>}
     */
    private function operationFromArray(array $state): array
    {
        $op = (string) ($state['op'] ?? '');
        if ($op === ApparatusInventoryChange::OP_REMOVE) {
            return ['op' => $op, 'fields' => []];
        }
        $quantity = $state['expectedQuantity'] ?? null;
        $values = [
            'compartment_id' => filled($state['compartment_id'] ?? null) ? (string) $state['compartment_id'] : null,
            'name' => is_string($state['name'] ?? null) ? trim($state['name']) : null,
            'expectedQuantity' => $quantity === null || $quantity === '' ? null : (int) $quantity,
            'manufacturer' => filled($state['manufacturer'] ?? null) ? trim((string) $state['manufacturer']) : null,
            'model' => filled($state['model'] ?? null) ? trim((string) $state['model']) : null,
            'description' => filled($state['description'] ?? null) ? trim((string) $state['description']) : null,
        ];
        if ($op === ApparatusInventoryChange::OP_ADD) {
            return ['op' => $op, 'fields' => array_filter($values, static fn (mixed $value): bool => $value !== null)];
        }

        $correction = $this->correction();
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($correction->apparatus);
        $current = is_array($resolution['checklist'] ?? null)
            ? app(InventoryChangePlanner::class)->describe($resolution['checklist'], (string) $correction->item_key)
            : null;
        $fields = [];
        foreach ($values as $key => $value) {
            $was = $key === 'compartment_id' ? ($current['compartment_id'] ?? null) : ($current[$key] ?? null);
            if ($value !== $was && ! ($key === 'compartment_id' && $value === null)) {
                $fields[$key] = $value;
            }
        }

        return ['op' => $op, 'fields' => $fields];
    }

    /** @return array<string, string> */
    private function scopeOptions(?string $op): array
    {
        $family = DailyCheckoutChecklistResolver::familyForChecklistType((string) $this->correction()->checklist_type);
        $options = [InventoryChangePlanner::SCOPE_UNIT => 'This apparatus only ('.InventoryCorrectionResource::unitLabel($this->correction()).')'];
        if (InventoryCorrectionAccess::canPropagate(auth()->user())) {
            $options[InventoryChangePlanner::SCOPE_FAMILY] = 'Every compatible '.($family !== null ? Str::of($family)->title()->plural() : 'apparatus').' (variants checked one by one)';
            if ($op !== ApparatusInventoryChange::OP_ADD) {
                $options[InventoryChangePlanner::SCOPE_EQUIPMENT] = 'Every apparatus carrying this exact item';
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private function unitOptions(Get $get): array
    {
        $plan = $this->safePlan($this->operationFromState($get), (string) ($get('scope') ?: InventoryChangePlanner::SCOPE_UNIT));

        return collect($plan['units'] ?? [])->where('includable', true)
            ->mapWithKeys(fn (array $unit): array => [(string) $unit['apparatus_id'] => $unit['label'].($unit['is_source'] ? ' (reported here)' : '')])
            ->all();
    }

    /** @return array<string, string> */
    private function unitDescriptions(Get $get): array
    {
        $plan = $this->safePlan($this->operationFromState($get), (string) ($get('scope') ?: InventoryChangePlanner::SCOPE_UNIT));

        return collect($plan['units'] ?? [])->where('includable', true)
            ->mapWithKeys(fn (array $unit): array => [(string) $unit['apparatus_id'] => $this->unitSummary($unit)])
            ->all();
    }

    private function impactHtml(Get $get): HtmlString
    {
        try {
            $plan = $this->planner()->plan($this->correction(), $this->operationFromState($get), (string) ($get('scope') ?: InventoryChangePlanner::SCOPE_UNIT));
        } catch (ValidationException $exception) {
            return new HtmlString('<p class="hub-insp-muted">'.e((string) collect($exception->errors())->flatten()->first()).'</p>');
        }
        $excluded = collect($plan['units'])->where('includable', false);
        $source = collect($plan['units'])->firstWhere('is_source', true);
        $html = '<div class="hub-inv-impact">';
        if (($source['verdict'] ?? null) !== InventoryChangePlanner::ELIGIBLE) {
            $html .= '<p class="hub-inv-impact__blocked" role="alert"><strong>Cannot apply to the reporting apparatus:</strong> '.e((string) ($source['verdict_label'] ?? 'unavailable')).'. Change the decision or do not approve.</p>';
        }
        $html .= '<p>'.e(collect($plan['units'])->where('includable', true)->count().' can change · '.$excluded->count().' excluded').'</p>';
        if ($excluded->isNotEmpty()) {
            $html .= '<ul class="hub-inv-impact__excluded">';
            foreach ($excluded as $unit) {
                $html .= '<li><strong>'.e($unit['label']).'</strong>: '.e($unit['verdict_label']).'</li>';
            }
            $html .= '</ul>';
        }

        return new HtmlString($html.'</div>');
    }

    /** @param array<string, mixed> $unit */
    private function unitSummary(array $unit): string
    {
        $status = $unit['verdict'] === InventoryChangePlanner::ELIGIBLE ? '' : $unit['verdict_label'].'. ';
        $before = $unit['before'];
        $after = $unit['after'];
        if ($before === null && $after !== null) {
            return $status.'Adds "'.$after['name'].'" to '.$after['compartment'].'.';
        }
        if ($after === null) {
            $open = (int) ($unit['open_issues'] ?? 0);

            return $status.'Removes "'.($before['name'] ?? 'item').'" from '.($before['compartment'] ?? 'the checklist').'.'
                .($open > 0 ? ' '.$open.' open '.($open === 1 ? 'issue' : 'issues').' on it stay open; resolve them, or close them with no action required, separately.' : '');
        }
        $parts = [];
        foreach (['compartment' => 'compartment', 'name' => 'name', 'expectedQuantity' => 'quantity', 'manufacturer' => 'manufacturer', 'model' => 'model', 'description' => 'description'] as $key => $label) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $parts[] = $label.' '.($before[$key] ?? 'none').' → '.($after[$key] ?? 'none');
            }
        }

        return $status.'"'.($before['name'] ?? '').'": '.($parts === [] ? 'no visible change' : implode('; ', $parts)).'.';
    }

    /**
     * @param  array{op: string, fields: array<string, mixed>}  $operation
     * @return array<string, mixed>|null
     */
    private function safePlan(array $operation, string $scope): ?array
    {
        $key = md5(json_encode([$operation, $scope]) ?: '');
        if (array_key_exists($key, $this->plans)) {
            return $this->plans[$key];
        }
        try {
            $plan = $this->planner()->plan($this->correction(), $operation, $scope);
        } catch (ValidationException) {
            $plan = null;
        }

        return $this->plans[$key] = $plan;
    }

    /** @return array<string, string> compartment id => name on the reporting apparatus */
    private function compartmentOptions(): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($this->correction()->apparatus);

        return collect($resolution['checklist']['compartments'] ?? [])
            ->mapWithKeys(fn (array $compartment): array => [(string) $compartment['id'] => InventoryOverlay::compartmentName($compartment)])
            ->all();
    }

    /** @param Closure(User): mixed $work */
    private function run(Closure $work, string $success): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $this->isReviewer(), 403);
        try {
            $work($user);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('That was not saved')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))->send();

            return;
        } catch (InventoryCorrectionConflict $conflict) {
            Notification::make()->warning()->title('Nothing was changed')->body($conflict->getMessage())->persistent()->send();
            $this->refreshRecord();

            return;
        } catch (InventoryOperationException $exception) {
            Notification::make()->danger()->title('Nothing was changed')->body($exception->getMessage())->persistent()->send();

            return;
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title('You do not have permission to do this.')->body($exception->getMessage() ?: null)->send();

            return;
        }
        $this->refreshRecord();
        Notification::make()->success()->title($success)->send();
    }

    private function refreshRecord(): void
    {
        $this->record = $this->correction()->refresh();
        $this->plans = [];
    }

    private function isReviewer(): bool
    {
        return InventoryCorrectionAccess::canReview(auth()->user());
    }

    private function planner(): InventoryChangePlanner
    {
        return app(InventoryChangePlanner::class);
    }

    private function correction(): ApparatusInventoryCorrection
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApparatusInventoryCorrection, 404);

        return $record;
    }
}

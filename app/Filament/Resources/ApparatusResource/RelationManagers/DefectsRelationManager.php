<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\RecommendationResource;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\DefectHistory;
use App\Filament\Support\DefectServiceTicketActions;
use App\Models\AdminAlertEvent;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Services\Exports\ExportableTable;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectFollowUpState;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class DefectsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'defects';

    protected static ?string $title = 'Defects';

    /** Selected follow-up tab: "all" or a DefectFollowUpState key. */
    public string $followUpTab = 'all';

    /** Badge shows unresolved issues, matching the Overview tab count. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus ? $ownerRecord->openDefects()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function updatedFollowUpTab(): void
    {
        if ($this->followUpTab !== 'all' && ! array_key_exists($this->followUpTab, DefectFollowUpState::options())) {
            $this->followUpTab = 'all';
        }

        $this->resetPage();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Where, what and how it failed identify the report: readiness counts, continuity matching and
                // notifications key on them, and an edit leaves no event, actor or reason. They are typed when a
                // defect is reported by hand and read-only afterwards (the apparatus is the owner record and never
                // changes). A report entered in error is closed with Close — No Action Required.
                Forms\Components\TextInput::make('compartment')
                    ->required()
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\TextInput::make('item')
                    ->required()
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\Select::make('issue_type')
                    ->required()
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ])
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),

                Forms\Components\Textarea::make('notes')
                    ->rows(3),

                Forms\Components\FileUpload::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->directory('defects')
                    ->image()
                    ->imageEditor(),

                // There is deliberately NO status field here. An issue's status changes only through
                // DefectCorrectiveActions (Acknowledge, Update, Resolve, Close — No Action Required, Reopen),
                // which record who did what and why and tell the people involved. A status select here once let
                // an administrator close a defect with no event, actor, notes or notification.
            ]);
    }

    /**
     * Fields no create or edit form on this page may ever write, even if a request tries to send them.
     *
     * @var list<string>
     */
    private const LIFECYCLE_FIELDS = [
        'status', 'resolved', 'resolved_at', 'resolved_by_user_id', 'resolution_type', 'resolution_notes',
        'action_status', 'action_details', 'operational_impact', 'service_ticket_id', 'severity',
        'acknowledged_at', 'acknowledged_by_user_id',
    ];

    /**
     * What identifies the report. Typed once when it is created; an edit can never rewrite them, even
     * if a request sends them.
     *
     * @var list<string>
     */
    private const IDENTITY_FIELDS = ['apparatus_id', 'compartment', 'item', 'issue_type'];

    public function table(Table $table): Table
    {
        return static::withExportActions($table
            ->recordTitleAttribute('item')
            ->modifyQueryUsing(fn (Builder $query): Builder => DefectFollowUpState::applyFilter(
                $query->with('serviceTicket'),
                $this->followUpTab === 'all' ? null : $this->followUpTab,
            ))
            ->header(fn () => view('filament.resources.apparatus-resource.partials.defect-follow-up-tabs', [
                'tabs' => $this->followUpTabs(),
                'active' => $this->followUpTab,
            ]))
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->getStateUsing(fn (ApparatusDefect $record): ?string => $record->availablePhotoPath())
                    ->disk('public')
                    ->width(48)
                    ->height(48)
                    ->square()
                    ->visibleFrom('md'),

                // Phones: one column carries the item, where it is and its follow-up, so the row actions stay on screen.
                Tables\Columns\TextColumn::make('item_phone')
                    ->label('Item')
                    ->getStateUsing(fn (ApparatusDefect $record): string => (string) $record->item)
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (ApparatusDefect $record): string => collect([
                        $record->compartment,
                        str_replace('_', ' ', ucfirst((string) $record->issue_type)),
                        DefectFollowUpState::label(DefectFollowUpState::for($record)),
                        data_get($record, 'serviceTicket.ticket_number'),
                    ])->filter()->implode(' · '))
                    ->hiddenFrom('md'),

                // Compartment and "photo unavailable" ride under the item instead of taking columns of their own.
                Tables\Columns\TextColumn::make('item')
                    ->searchable(['item', 'compartment'])
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (ApparatusDefect $record): ?string => collect([
                        $record->compartment,
                        $record->hasMissingPhotoReference() ? 'Photo unavailable' : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->visibleFrom('md'),

                Tables\Columns\TextColumn::make('follow_up')
                    ->label('Follow-up')
                    ->badge()
                    ->getStateUsing(fn (ApparatusDefect $record): string => DefectFollowUpState::for($record))
                    ->formatStateUsing(fn (string $state): string => DefectFollowUpState::label($state))
                    ->color(fn (string $state): string => DefectFollowUpState::color($state))
                    ->description(fn (ApparatusDefect $record): ?string => data_get($record, 'serviceTicket.ticket_number'))
                    ->visibleFrom('md'),

                Tables\Columns\TextColumn::make('issue_type')
                    ->label('Issue')
                    ->badge()
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state)))
                    ->visibleFrom('md'),

                Tables\Columns\TextColumn::make('reported_date')
                    ->label('Reported')
                    ->date('M j, Y')
                    ->sortable()
                    ->visibleFrom('md'),

                // Secondary detail, one click away in the column toggle.
                Tables\Columns\TextColumn::make('compartment')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('serviceTicket.ticket_number')
                    ->label('Ticket')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Reporting a defect by hand is fine; it always starts as a new, open, reported issue.
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...Arr::except($data, self::LIFECYCLE_FIELDS),
                        'status' => 'open',
                        'action_status' => DefectActionStatus::REPORTED,
                        'resolved' => false,
                    ]),
            ])
            ->actions([
                // The ticket step stays one tap away as an icon button (tooltip and screen-reader label carry the words);
                // everything else lives in the row menu, so nothing is clipped at 1024px.
                DefectServiceTicketActions::configureView(
                    Tables\Actions\Action::make(DefectServiceTicketActions::VIEW)->iconButton()->tooltip('View service ticket'),
                    fn (Tables\Actions\Action $action): ?ApparatusDefect => DefectServiceTicketActions::defectFrom($action->getRecord()),
                ),
                DefectServiceTicketActions::configureCreate(
                    Tables\Actions\Action::make(DefectServiceTicketActions::CREATE)->iconButton(),
                    fn (Tables\Actions\Action $action): ?ApparatusDefect => DefectServiceTicketActions::defectFrom($action->getRecord()),
                ),

                Tables\Actions\ActionGroup::make([
                    // Read-only audit trail: who did what and when, with Captain 5 actions labelled as recorded.
                    Tables\Actions\Action::make('issue_history')
                        ->label('Issue history')
                        ->icon('heroicon-o-clock')
                        ->color('gray')
                        ->modalHeading(fn (ApparatusDefect $record): string => 'Issue history: '.$record->item)
                        ->modalContent(fn (ApparatusDefect $record) => view('filament.resources.apparatus-resource.partials.defect-history', [
                            'events' => DefectHistory::rows($record, DefectCorrectiveActions::canManage(auth()->user())),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),

                    // Resolution goes through the corrective-action service (event, notification, required type and notes).
                    DefectCorrectiveActions::resolve(
                        Tables\Actions\Action::make('mark_resolved'),
                        fn (MountableAction $action): ?ApparatusDefect => $action instanceof Tables\Actions\Action
                            ? DefectServiceTicketActions::defectFrom($action->getRecord())
                            : null,
                    )
                        ->label('Resolve')
                        ->visible(fn (Tables\Actions\Action $action): bool => DefectCorrectiveActions::canManage(auth()->user())
                            && ($defect = DefectServiceTicketActions::defectFrom($action->getRecord())) instanceof ApparatusDefect
                            && ! DefectActionStatus::isClosed($defect->action_status)),

                    Tables\Actions\Action::make('request_replacement')
                        ->label('Request Replacement')
                        ->icon('heroicon-o-shopping-cart')
                        ->color('info')
                        ->visible(fn ($record) => $record->status !== 'resolved' && $record->issue_type === 'missing')
                        ->action(function ($record) {
                            // Create a manual recommendation (will be enhanced by AI later)
                            $recommendation = ApparatusDefectRecommendation::create([
                                'apparatus_defect_id' => $record->id,
                                'equipment_item_id' => null, // Manual mapping required
                                'match_method' => 'manual',
                                'match_confidence' => 0,
                                'recommended_qty' => 1,
                                'reasoning' => 'Manual replacement request from defect management',
                                'status' => 'pending',
                                'created_by_user_id' => auth()->id(),
                            ]);

                            AdminAlertEvent::create([
                                'type' => 'recommendation_created',
                                'severity' => 'info',
                                'message' => "Replacement request created for {$record->item} on {$record->apparatus->unit_id}",
                                'related_type' => 'apparatus_defect_recommendation',
                                'related_id' => $recommendation->id,
                                'created_by_user_id' => auth()->id(),
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Replacement request created')
                                ->body('Go to Recommendations to assign an item')
                                ->send();

                            return redirect()->to(RecommendationResource::getUrl('edit', ['record' => $recommendation]));
                        }),

                    // Edits describe the report (item, notes, photo). They can never change its status.
                    Tables\Actions\EditAction::make()
                        ->mutateFormDataUsing(fn (array $data): array => Arr::except($data, [...self::LIFECYCLE_FIELDS, ...self::IDENTITY_FIELDS])),
                ])
                    ->label('More actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('More actions'),
            ])
            ->defaultSort('reported_date', 'desc'));
    }

    /**
     * Tab label and count for each follow-up stage, counted from the same
     * resolver the badges use so tabs and rows can never disagree.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    private function followUpTabs(): array
    {
        $owner = $this->getOwnerRecord();
        $counts = ($owner instanceof Apparatus ? $owner->defects()->with('serviceTicket')->get() : new Collection)
            ->countBy(fn (ApparatusDefect $defect): string => DefectFollowUpState::for($defect));

        $tabs = [['key' => 'all', 'label' => 'All', 'count' => $counts->sum()]];
        foreach (DefectFollowUpState::options() as $key => $label) {
            $tabs[] = ['key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $tabs;
    }
}

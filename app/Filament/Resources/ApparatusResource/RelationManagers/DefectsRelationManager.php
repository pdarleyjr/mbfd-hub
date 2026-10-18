<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Resources\RecommendationResource;
use App\Filament\Support\DefectServiceTicketActions;
use App\Models\AdminAlertEvent;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Support\Fleet\DefectFollowUpState;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DefectsRelationManager extends RelationManager
{
    protected static string $relationship = 'defects';

    protected static ?string $title = 'Defects';

    /** Selected follow-up tab: "all" or a DefectFollowUpState key. */
    public string $followUpTab = 'all';

    /** Badge shows unresolved issues, matching the Overview tab count. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->openDefects()->count();

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
                Forms\Components\TextInput::make('compartment')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('item')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('issue_type')
                    ->required()
                    ->options([
                        'missing' => 'Missing',
                        'damaged' => 'Damaged',
                        'expired' => 'Expired',
                        'low_quantity' => 'Low Quantity',
                        'other' => 'Other',
                    ]),

                Forms\Components\Textarea::make('notes')
                    ->rows(3),

                Forms\Components\FileUpload::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->directory('defects')
                    ->image()
                    ->imageEditor(),

                Forms\Components\Select::make('status')
                    ->required()
                    ->default('open')
                    ->options([
                        'open' => 'Open',
                        'in_progress' => 'In Progress',
                        'resolved' => 'Resolved',
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
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
                    ->width(60)
                    ->height(60),
                Tables\Columns\TextColumn::make('photo_availability')
                    ->label('Photo status')
                    ->getStateUsing(fn (ApparatusDefect $record): ?string => $record->hasMissingPhotoReference()
                        ? 'Photo unavailable'
                        : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('compartment')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('item')
                    ->searchable(),

                Tables\Columns\TextColumn::make('follow_up')
                    ->label('Follow-up')
                    ->badge()
                    ->getStateUsing(fn (ApparatusDefect $record): string => DefectFollowUpState::for($record))
                    ->formatStateUsing(fn (string $state): string => DefectFollowUpState::label($state))
                    ->color(fn (string $state): string => DefectFollowUpState::color($state)),

                Tables\Columns\TextColumn::make('serviceTicket.ticket_number')
                    ->label('Ticket')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('issue_type')
                    ->label('Issue Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state))),

                Tables\Columns\TextColumn::make('reported_date')
                    ->label('Reported')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                DefectServiceTicketActions::configureView(
                    Tables\Actions\Action::make(DefectServiceTicketActions::VIEW),
                    fn (Tables\Actions\Action $action): ?ApparatusDefect => $action->getRecord(),
                ),
                DefectServiceTicketActions::configureCreate(
                    Tables\Actions\Action::make(DefectServiceTicketActions::CREATE),
                    fn (Tables\Actions\Action $action): ?ApparatusDefect => $action->getRecord(),
                ),

                Tables\Actions\Action::make('mark_resolved')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ApparatusDefect $record) => $record->status !== 'resolved')
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Resolution Notes')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (ApparatusDefect $record, array $data) {
                        $record->update([
                            'status' => 'resolved',
                            'resolution_notes' => $data['resolution_notes'],
                            'resolved_at' => now(),
                        ]);
                    }),

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

                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('reported_date', 'desc');
    }

    /**
     * Tab label and count for each follow-up stage, counted from the same
     * resolver the badges use so tabs and rows can never disagree.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    private function followUpTabs(): array
    {
        $counts = $this->getOwnerRecord()->defects()->with('serviceTicket')->get()
            ->countBy(fn (ApparatusDefect $defect): string => DefectFollowUpState::for($defect));

        $tabs = [['key' => 'all', 'label' => 'All', 'count' => $counts->sum()]];
        foreach (DefectFollowUpState::options() as $key => $label) {
            $tabs[] = ['key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $tabs;
    }
}

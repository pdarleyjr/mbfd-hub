<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\User;
use App\Services\Exports\ExportableTable;
use App\Services\Fleet\VehicleIdentificationPlanner;
use App\Services\Fleet\VehicleIdentificationService;
use App\Support\Fleet\VehicleIdentificationFields;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

/**
 * The one-time vehicle questions crews were asked about this unit: what was answered, what was
 * reported as not available, the history of each question, and "Ask again" for Fleet.
 */
class IdentificationQuestionsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'identificationQuestions';

    protected static ?string $title = 'Identification';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Apparatus
            && $user->can('view', $ownerRecord)
            && $ownerRecord->identificationQuestions()->exists();
    }

    /** Badge counts the questions still open after a reason was given. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus
            ? $ownerRecord->identificationQuestions()->where('state', ApparatusIdentificationQuestion::STATE_OPEN)->count()
            : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        $unit = $this->getOwnerRecord();
        $kind = $unit instanceof Apparatus ? VehicleIdentificationFields::kindFor($unit) : VehicleIdentificationFields::KIND_VEHICLE;

        return static::withExportActions($table
            ->recordTitleAttribute('field')
            ->heading('Identification questions')
            ->description('Asked of crews once, then closed. A question answered or closed as not applicable is not asked again until you ask again.')
            ->columns([
                TextColumn::make('field')
                    ->label('Question')
                    ->searchable()->sortable()
                    ->formatStateUsing(fn (string $state): string => VehicleIdentificationFields::label($state, $kind)),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ApparatusIdentificationQuestion::STATE_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ApparatusIdentificationQuestion::STATE_ANSWERED => 'success',
                        ApparatusIdentificationQuestion::STATE_NOT_APPLICABLE => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('answered_value')->label('Answer')->searchable()->placeholder('None'),
                TextColumn::make('last_reason')
                    ->label('Last reason given')
                    ->formatStateUsing(fn (?string $state): ?string => VehicleIdentificationFields::reasonLabel($state))
                    ->placeholder('None')
                    ->wrap(),
                TextColumn::make('resolved_at')->label('Closed')->dateTime('M j, Y g:i A', \App\Support\HubTimezone::NAME)->placeholder('Open'),
                TextColumn::make('resolvedBy.name')->label('By')->placeholder('None'),
                TextColumn::make('next_checkout')
                    ->label('Asked at next checkout')
                    ->state(function (ApparatusIdentificationQuestion $record): string {
                        $apparatus = $record->apparatus;

                        return $apparatus === null
                            ? 'No'
                            : (app(VehicleIdentificationPlanner::class)->whyNotAsked($apparatus, $record->field) ?? 'Yes');
                    })
                    ->wrap(),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->actions([
                Action::make('exportHistory')->label('Export history')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->visible(fn (): bool => auth()->user() instanceof User && $this->canExportTableData(auth()->user()))
                    ->form([\Filament\Forms\Components\Radio::make('format')->label('File type')->options(['xlsx' => 'Excel (.xlsx)', 'pdf' => 'PDF'])->default('xlsx')->required()])
                    ->action(function (ApparatusIdentificationQuestion $record, array $data, Action $action) {
                        try {
                            return app(\App\Services\Exports\TableExportService::class)->downloadDataset(
                                auth()->user(), static::class, (string) ($data['format'] ?? ''),
                                fn (): bool => $this->canExportTableData(auth()->user()) && (int) $record->apparatus_id === (int) $this->getOwnerRecord()->getKey(),
                                fn (): \App\Services\Exports\ExportDataset => app(\App\Services\Exports\RecordHistoryDatasets::class)->build(auth()->user(), $record, 'identification'),
                                'identification question history',
                            );
                        } catch (\App\Services\Exports\ExportFailedException $exception) {
                            Notification::make()->title('Export not created')->body($exception->getMessage())->danger()->send();
                            $action->halt();

                            return null;
                        }
                    }),
                Action::make('history')
                    ->label('History')
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->modalHeading(fn (ApparatusIdentificationQuestion $record): string => VehicleIdentificationFields::label($record->field, $kind))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (ApparatusIdentificationQuestion $record): View => view('filament.fleet.identification-history', [
                        'kind' => $kind,
                        'events' => ApparatusIdentificationEvent::query()
                            ->where('apparatus_id', $record->apparatus_id)
                            ->where('field', $record->field)
                            ->with('actor')
                            ->orderByDesc('id')
                            ->get(),
                    ])),
                Action::make('askAgain')
                    ->label('Ask again')
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Crews will be asked this question again at the next checkout, if the vehicle record still lacks it.')
                    ->visible(fn (ApparatusIdentificationQuestion $record): bool => $record->state !== ApparatusIdentificationQuestion::STATE_OPEN || $record->last_reason !== null)
                    ->authorize(fn (): bool => $unit instanceof Apparatus && (auth()->user()?->can('update', $unit) ?? false))
                    ->action(function (ApparatusIdentificationQuestion $record): void {
                        $user = auth()->user();
                        $apparatus = $record->apparatus;
                        try {
                            abort_unless($user instanceof User && $apparatus instanceof Apparatus, 403);
                            $reopened = app(VehicleIdentificationService::class)->reopen($apparatus, $record->field, $user);
                        } catch (AuthorizationException) {
                            Notification::make()->title('You cannot ask this question again.')->danger()->send();

                            return;
                        }
                        if (! $reopened) {
                            Notification::make()->title('This question is already open.')->warning()->send();

                            return;
                        }
                        Notification::make()->title('Crews will be asked again.')->success()->send();
                    }),
            ]));
    }
}

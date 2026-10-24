<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalListResource\RelationManagers;

use App\Filament\Support\PromotionalListForms;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Services\Personnel\Promotions\PromotionalListAccess;
use App\Services\Personnel\Promotions\PromotionalListService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

final class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Entries';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof PromotionalList && app(PromotionalListAccess::class)->canView(auth()->user(), false);
    }

    /** Re-render after the page changes the list (new entry, list status change). */
    #[On('promotional-list-changed')]
    public function refreshEntries(): void
    {
        // Nothing to do: the event triggers a re-render with fresh data.
    }

    public function table(Table $table): Table
    {
        $manage = fn (): bool => app(PromotionalListAccess::class)->canManage(auth()->user(), false);
        $scoreColumns = [];
        foreach ((array) config('promotional_lists.score_columns') as $key => $label) {
            $column = Tables\Columns\TextColumn::make('scores.'.$key)->label($label)->placeholder('-')->alignEnd()
                ->toggleable(isToggledHiddenByDefault: ! in_array($key, ['written', 'oral', 'total'], true));
            $scoreColumns[] = $column;
        }

        return $table
            ->recordTitleAttribute('last_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('employee:id,name,employee_id,roster_status'))
            ->defaultSort('original_rank')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('current_rank')->label('Current rank')->sortable()->placeholder('Not ranked')->weight('bold'),
                Tables\Columns\TextColumn::make('original_rank')->label('Original rank')->sortable(),
                Tables\Columns\TextColumn::make('last_name')->label('Name')->sortable()->searchable(['last_name', 'first_name', 'soi_id'])
                    ->state(fn (PromotionalListEntry $record): string => $record->last_name.', '.$record->first_name)
                    ->description(fn (PromotionalListEntry $record): ?string => $record->soi_id ? 'SOI ID '.$record->soi_id : null),
                Tables\Columns\TextColumn::make('employee.name')->label('Personnel record')->placeholder('Not linked')
                    ->description(fn (PromotionalListEntry $record): ?string => $record->employee?->employee_id),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->sortable()
                    ->state(fn (PromotionalListEntry $record): string => $record->statusLabel())
                    ->color(fn (PromotionalListEntry $record): string => match ($record->status) {
                        'eligible' => 'success', 'promoted' => 'info', 'declined', 'expired' => 'gray', default => 'warning',
                    })
                    ->description(fn (PromotionalListEntry $record): ?string => $record->status_reason),
                ...$scoreColumns,
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(PromotionalListEntry::statuses()),
                Tables\Filters\TernaryFilter::make('linked')->label('Personnel record')->placeholder('All')
                    ->trueLabel('Linked')->falseLabel('Not linked')
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('employee_id'), false: fn (Builder $query) => $query->whereNull('employee_id'), blank: fn (Builder $query) => $query),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('changeStatus')->label('Change status')->icon('heroicon-o-arrow-path')
                        ->form(PromotionalListForms::statusFields())
                        ->action(fn (PromotionalListEntry $record, array $data, Tables\Actions\Action $action) => $this->change($action, 'Entry status changed',
                            fn () => app(PromotionalListService::class)->changeEntryStatus(auth()->user(), $record, $data['status'], $data['reason']))),
                    Tables\Actions\Action::make('link')->label('Link to personnel record')->icon('heroicon-o-link')
                        ->form(PromotionalListForms::linkFields())
                        ->fillForm(fn (PromotionalListEntry $record): array => ['employee_id' => $record->employee_id])
                        ->action(fn (PromotionalListEntry $record, array $data, Tables\Actions\Action $action) => $this->change($action, 'Personnel link saved',
                            fn () => app(PromotionalListService::class)->linkEmployee(auth()->user(), $record, filled($data['employee_id'] ?? null) ? (int) $data['employee_id'] : null))),
                    Tables\Actions\Action::make('editEntry')->label('Correct entry')->icon('heroicon-o-pencil-square')
                        ->modalWidth('3xl')->form(PromotionalListForms::entryFields())
                        ->fillForm(fn (PromotionalListEntry $record): array => $record->only(['original_rank', 'soi_id', 'last_name', 'first_name', 'scores']))
                        ->action(fn (PromotionalListEntry $record, array $data, Tables\Actions\Action $action) => $this->change($action, 'Entry corrected',
                            fn () => app(PromotionalListService::class)->updateEntry(auth()->user(), $record, $data))),
                ])->icon('heroicon-m-ellipsis-vertical')->visible($manage),
            ])
            ->emptyStateHeading('No entries yet')
            ->emptyStateDescription('Add entries one at a time, or import a CSV with the personnel:import-promotional-list command.');
    }

    private function change(Tables\Actions\Action $action, string $success, \Closure $work): void
    {
        PromotionalListForms::run($action, $work, $success);
        $this->dispatch('promotional-list-changed');
    }
}

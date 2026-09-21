<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ApparatusInspectionExceptionResource\Pages\ListInspectionExceptions;
use App\Models\ApparatusInspectionException;
use App\Models\User;
use App\Services\ApparatusInspectionExceptionService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ApparatusInspectionExceptionResource extends Resource
{
    protected static ?string $model = ApparatusInspectionException::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Fleet Management';

    protected static ?string $navigationLabel = 'Inspection Exceptions';

    protected static ?string $modelLabel = 'Inspection Exception';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['apparatus', 'inspection.reviewEvents']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('apparatus.designation')->label('Unit')->searchable(),
            Tables\Columns\TextColumn::make('inspection.vehicle_number')->label('Physical vehicle')->searchable(),
            Tables\Columns\TextColumn::make('inspection.operator_name')->label('Submitted by')->searchable(),
            Tables\Columns\TextColumn::make('field')->formatStateUsing(fn (string $state): string => str_starts_with($state, 'defect:') ? 'Equipment finding' : str($state)->headline()->toString()),
            Tables\Columns\TextColumn::make('reason')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            Tables\Columns\TextColumn::make('submitted_value')->label('Reported')->placeholder('Equipment finding'),
            Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            Tables\Columns\TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('station_id')->label('Station')
                ->options(fn (): array => \App\Models\Station::query()->orderBy('station_number')->pluck('name', 'id')->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $stationId): Builder => $query->whereHas('apparatus', fn (Builder $apparatus): Builder => $apparatus->where('station_id', $stationId)))),
            Tables\Filters\SelectFilter::make('apparatus_id')->relationship('apparatus', 'designation')
                ->getOptionLabelFromRecordUsing(fn (\App\Models\Apparatus $record): string => $record->designation ?: $record->name ?: $record->getAttribute('unit_id'))
                ->label('Apparatus'),
        ])->actions([
            Tables\Actions\Action::make('inspection')->label('Original inspection')->icon('heroicon-o-document-text')
                ->url(fn (ApparatusInspectionException $record): string => ApparatusResource::getUrl('view-inspection', ['record' => $record->apparatus_id, 'inspection' => $record->apparatus_inspection_id])),
            Tables\Actions\Action::make('reconcile')->label('Review')->icon('heroicon-o-pencil-square')
                ->visible(fn (ApparatusInspectionException $record): bool => ! in_array($record->status, ['resolved', 'dismissed'], true) && (auth()->user()?->can('update', $record) ?? false))
                ->fillForm(fn (ApparatusInspectionException $record): array => ['expected_current_value' => $record->apparatus->getAttribute('current_'.$record->field)])
                ->form([
                    Placeholder::make('original')->label('Original observation')->content(fn (ApparatusInspectionException $record): string => self::observation($record)),
                    Placeholder::make('history')->label('Review history')->content(fn (ApparatusInspectionException $record): string => $record->inspection->reviewEvents->filter(fn ($event): bool => (int) ($event->metadata['exception_id'] ?? 0) === (int) $record->id)->map(fn ($event): string => $event->created_at->format('M j H:i').' — '.($event->metadata['actor_name'] ?? 'System').': '.$event->internal_note.(isset($event->metadata['revision']['value']) ? ' (reported revision: '.$event->metadata['revision']['value'].')' : ''))->implode("\n") ?: 'No decisions yet.'),
                    Hidden::make('expected_current_value'),
                    Select::make('action')->label('Decision')->required()->live()->options(fn (ApparatusInspectionException $record): array => [
                        'accept_submitted' => str_starts_with($record->field, 'defect:') ? 'Classify operational impact' : 'Accept reported value',
                        ...(! str_starts_with($record->field, 'defect:') ? ['correct' => 'Use a corrected value'] : []),
                        'request_revision' => 'Ask the submitting member to clarify',
                        'dismiss' => 'Dismiss exception without changing fleet data',
                    ]),
                    TextInput::make('value')->label('Corrected value')->numeric()->minValue(0)->required()->visible(fn (Get $get): bool => $get('action') === 'correct'),
                    Select::make('operational_impact')->label('Operational impact')->required()->live()
                        ->visible(fn (Get $get, ApparatusInspectionException $record): bool => $get('action') === 'accept_submitted' && str_starts_with($record->field, 'defect:'))
                        ->options(['non_blocking' => 'Informational — apparatus can remain in service', 'needs_repair' => 'Needs repair', 'needs_admin_review' => 'Further review needed', 'out_of_service' => 'Place apparatus Out of Service']),
                    Placeholder::make('oos_warning')->label('Operational hold')->content('This decision will place the physical apparatus Out of Service. Returning it to service follows the existing fleet workflow.')->visible(fn (Get $get): bool => $get('operational_impact') === 'out_of_service'),
                    Toggle::make('create_service_ticket')->label('Create a linked service ticket')->live()->visible(fn (Get $get, ApparatusInspectionException $record): bool => $get('action') === 'accept_submitted' && str_starts_with($record->field, 'defect:')),
                    TextInput::make('service_ticket.title')->label('Service title')->required()->maxLength(180)->visible(fn (Get $get): bool => (bool) $get('create_service_ticket')),
                    Textarea::make('service_ticket.description')->label('Service description')->required()->maxLength(10000)->visible(fn (Get $get): bool => (bool) $get('create_service_ticket')),
                    Select::make('service_ticket.category')->label('Category')->required()->options(\App\Enums\ApparatusServiceTicketCategory::options())->visible(fn (Get $get): bool => (bool) $get('create_service_ticket')),
                    Select::make('service_ticket.priority')->label('Priority')->required()->default('routine')->options(\App\Enums\ApparatusServiceTicketPriority::options())->visible(fn (Get $get): bool => (bool) $get('create_service_ticket')),
                    Textarea::make('reason')->label('Decision note')->required()->maxLength(2000)->rows(3),
                ])->action(function (ApparatusInspectionException $record, array $data): void {
                    $reviewer = auth()->user();
                    abort_unless($reviewer instanceof User, 403);
                    if (! ($data['create_service_ticket'] ?? false)) {
                        unset($data['service_ticket']);
                    }
                    app(ApparatusInspectionExceptionService::class)->reconcile((int) $record->id, $reviewer, $data);
                    Notification::make()->success()->title('Decision recorded')->send();
                }),
        ])->bulkActions([]);
    }

    private static function observation(ApparatusInspectionException $record): string
    {
        if (str_starts_with($record->field, 'defect:')) {
            $defect = \App\Models\ApparatusDefect::query()->where('apparatus_id', $record->apparatus_id)->find($record->metadata['defect_id'] ?? 0);

            return $defect ? $defect->compartment.' / '.$defect->item.' — '.$defect->issue_type : 'Original finding retained in the inspection.';
        }

        return 'Reported: '.($record->submitted_value ?? 'Not entered').' · Original baseline: '.($record->baseline_value ?? 'Unknown').' · Current fleet value: '.($record->apparatus->getAttribute('current_'.$record->field) ?? 'Unknown');
    }

    public static function getPages(): array
    {
        return ['index' => ListInspectionExceptions::route('/')];
    }
}

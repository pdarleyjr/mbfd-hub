<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Filament\Clusters\PersonnelUniformsEquipment;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages;
use App\Models\PersonnelRequest;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PersonnelRequestResource extends Resource
{
    protected static ?string $model = PersonnelRequest::class;

    protected static ?string $cluster = PersonnelUniformsEquipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Requests';

    protected static ?string $modelLabel = 'personnel request';

    protected static ?int $navigationSort = -90;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('request_number')->label('Request')->searchable()->sortable(),
            TextColumn::make('type')->badge()->formatStateUsing(fn (PersonnelRequestType $state) => $state->label())->color(fn (PersonnelRequestType $state) => $state === PersonnelRequestType::Uniform ? 'info' : 'warning'),
            TextColumn::make('beneficiary_name')->label('Member')->description(fn (PersonnelRequest $record) => trim("{$record->beneficiary_rank} · {$record->beneficiary_employee_number}"))->searchable(['beneficiary_name', 'beneficiary_employee_number'])->sortable(),
            TextColumn::make('requester_name')->label('Submitted by')->description(fn (PersonnelRequest $record) => $record->requester_rank)->toggleable(),
            TextColumn::make('status')->badge()->formatStateUsing(fn (PersonnelRequestStatus $state) => $state->label())->color(fn (PersonnelRequestStatus $state) => match ($state) {
                PersonnelRequestStatus::Completed => 'success',
                PersonnelRequestStatus::Denied, PersonnelRequestStatus::Cancelled => 'danger',
                PersonnelRequestStatus::NeedsInformation => 'warning',
                PersonnelRequestStatus::ReadyForPickup, PersonnelRequestStatus::Arrived => 'info',
                default => 'gray',
            }),
            TextColumn::make('items_count')->counts('items')->label('Items')->alignCenter(),
            TextColumn::make('created_at')->label('Submitted')->dateTime('M j, Y g:i A')->sortable(),
        ])->filters([
            SelectFilter::make('type')->options(['uniform' => 'Uniform', 'equipment' => 'Personnel Equipment']),
            SelectFilter::make('status')->options(collect(PersonnelRequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all())->multiple(),
        ])->actions([ViewAction::make()])->bulkActions([])->defaultSort('created_at', 'desc')->poll('30s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Request chain of custody')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('request_number')->label('Request number')->weight('bold'),
                    TextEntry::make('type')->formatStateUsing(fn (PersonnelRequestType $state) => $state->label()),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (PersonnelRequestStatus $state) => $state->label()),
                    TextEntry::make('beneficiary_name')->label('Beneficiary')->formatStateUsing(fn ($state, PersonnelRequest $record) => "{$record->beneficiary_rank} — {$state} — {$record->beneficiary_employee_number}"),
                    TextEntry::make('requester_name')->label('Submitted by')->formatStateUsing(fn ($state, PersonnelRequest $record) => "{$record->requester_rank} — {$state} — {$record->requester_employee_number}"),
                    TextEntry::make('originatingStation.station_number')->label('Originating station')->formatStateUsing(fn ($state) => $state ? "Station {$state}" : '—'),
                    TextEntry::make('signed_at')->label('Officer signed')->dateTime('M j, Y g:i A')->placeholder('Not applicable'),
                    TextEntry::make('created_at')->label('Submitted')->dateTime('M j, Y g:i A'),
                ]),
            ]),
            Section::make('Requested items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->schema([
                    TextEntry::make('item_name')->label('Item')->weight('bold'),
                    TextEntry::make('size')->placeholder('—'),
                    TextEntry::make('quantity'),
                    TextEntry::make('reason')->formatStateUsing(fn ($state) => $state ? str($state)->title() : '—'),
                    TextEntry::make('fulfillment_status')->badge()->formatStateUsing(fn ($state) => str($state)->replace('_', ' ')->title()),
                ])->columns(5),
            ]),
            Section::make('Workflow history')->schema([
                RepeatableEntry::make('updates')->hiddenLabel()->schema([
                    TextEntry::make('created_at')->label('When')->dateTime('M j, Y g:i A'),
                    TextEntry::make('status')->formatStateUsing(fn (PersonnelRequestStatus $state) => $state->label())->badge(),
                    TextEntry::make('employee_visible_note')->label('Employee-visible note')->placeholder('—'),
                    TextEntry::make('internal_note')->label('Internal note')->placeholder('—'),
                ])->columns(4),
            ]),
            Section::make('Private attachments')->schema([
                RepeatableEntry::make('attachments')->hiddenLabel()->schema([
                    TextEntry::make('original_filename')->label('File')->url(fn ($record) => route('admin.personnel-request-attachments.download', $record))->openUrlInNewTab(),
                    TextEntry::make('document_type')->formatStateUsing(fn ($state) => str($state)->replace('_', ' ')->title()),
                    TextEntry::make('file_size')->formatStateUsing(fn ($state) => number_format($state / 1024, 1).' KB'),
                    TextEntry::make('created_at')->dateTime('M j, Y g:i A'),
                ])->columns(4),
            ])->collapsible(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPersonnelRequests::route('/'),
            'view' => Pages\ViewPersonnelRequest::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'logistics_admin']) ?? false;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationRequestResource\Pages;

use App\Enums\StationRequestStatus;
use App\Filament\Resources\StationRequestResource;
use App\Models\User;
use App\Services\StationRequestWorkflowService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStationRequest extends ViewRecord
{
    protected static string $resource = StationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('update_workflow')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('primary')
                ->visible(fn (): bool => $this->record->is_open)
                ->fillForm(fn (): array => [
                    'status' => $this->record->status,
                    'assigned_to_user_id' => $this->record->assigned_to_user_id,
                    'assigned_vendor' => $this->record->assigned_vendor,
                ])
                ->form([
                    Forms\Components\Select::make('status')
                        ->options(collect(StationRequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all())
                        ->required(),
                    Forms\Components\Select::make('assigned_to_user_id')
                        ->relationship('assignedTo', 'name')
                        ->searchable()
                        ->preload()
                        ->label('Assigned To'),
                    Forms\Components\TextInput::make('assigned_vendor')->maxLength(255),
                    Forms\Components\Textarea::make('public_note')
                        ->label('Public Update')
                        ->helperText('Visible to station staff in request history.')
                        ->rows(3),
                    Forms\Components\Textarea::make('internal_note')
                        ->label('Internal Note')
                        ->helperText('Visible only to authorized administrators.')
                        ->rows(3),
                    Forms\Components\Textarea::make('status_detail')->rows(2),
                    Forms\Components\Repeater::make('asset_operations')
                        ->label('Asset Fulfillment (optional)')
                        ->helperText('Explicitly create, link, or replace a room asset in the same transaction.')
                        ->schema([
                            Forms\Components\Select::make('operation')
                                ->options(['create' => 'Create', 'link' => 'Link existing', 'replace' => 'Replace existing'])
                                ->required(),
                            Forms\Components\Select::make('room_id')
                                ->options(fn (): array => \App\Models\Room::query()
                                    ->where('station_id', $this->record->station_id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->label('Room'),
                            Forms\Components\Select::make('room_asset_id')
                                ->options(fn (): array => \App\Models\RoomAsset::query()
                                    ->whereHas('room', fn ($query) => $query->where('station_id', $this->record->station_id))
                                    ->with('room:id,name')
                                    ->get()
                                    ->mapWithKeys(fn ($asset) => [$asset->id => "{$asset->room->name} — {$asset->name}"])
                                    ->all())
                                ->searchable()
                                ->label('Existing Asset'),
                            Forms\Components\TextInput::make('name')->maxLength(255),
                            Forms\Components\TextInput::make('asset_tag')->maxLength(255),
                            Forms\Components\TextInput::make('category')->maxLength(100),
                            Forms\Components\TextInput::make('quantity')->numeric()->minValue(1)->default(1),
                            Forms\Components\TextInput::make('condition')->maxLength(100)->default('new'),
                            Forms\Components\TextInput::make('serial_number')->maxLength(255),
                            Forms\Components\TextInput::make('manufacturer')->maxLength(255),
                            Forms\Components\TextInput::make('model_number')->maxLength(255),
                            Forms\Components\TextInput::make('vendor')->maxLength(255),
                            Forms\Components\TextInput::make('cost')->numeric()->minValue(0)->prefix('$'),
                            Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
                        ])->columns(3)->collapsed(),
                ])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    $this->record = app(StationRequestWorkflowService::class)->transition($this->record, $data, $actor);
                    Notification::make()->title('Station request updated')->success()->send();
                }),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationRequestResource\Pages;

use App\Enums\StationRequestStatus;
use App\Filament\Resources\StationRequestResource;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\StationRequest;
use App\Models\StationRequestItem;
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
                ->modalHeading('Update Station Request')
                ->modalSubmitActionLabel('Update Status')
                ->visible(fn (): bool => $this->stationRequest()->is_open)
                ->fillForm(fn (): array => [
                    'status' => $this->stationRequest()->status,
                    'assigned_to_user_id' => $this->stationRequest()->assigned_to_user_id,
                    'assigned_vendor' => $this->stationRequest()->assigned_vendor,
                ])
                ->form([
                    Forms\Components\Select::make('status')
                        ->options(collect(StationRequestStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all())
                        ->required(),
                    Forms\Components\Select::make('assigned_to_user_id')
                        ->options(fn (): array => User::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
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
                            Forms\Components\Select::make('station_request_item_id')
                                ->options(fn (): array => $this->stationRequest()->items()
                                    ->get()
                                    ->mapWithKeys(fn (StationRequestItem $item): array => [$item->id => "{$item->quantity}x {$item->item_name}"])
                                    ->all())
                                ->label('Request Item')
                                ->required(),
                            Forms\Components\Select::make('operation')
                                ->options(['create' => 'Create', 'link' => 'Link existing', 'replace' => 'Replace existing'])
                                ->required(),
                            Forms\Components\Select::make('room_id')
                                ->options(fn (): array => Room::query()
                                    ->where('station_id', $this->stationRequest()->station_id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->label('Room'),
                            Forms\Components\Select::make('room_asset_id')
                                ->options(fn (): array => RoomAsset::query()
                                    ->whereHas('room', fn ($query) => $query->where('station_id', $this->stationRequest()->station_id))
                                    ->with('room:id,name')
                                    ->get()
                                    ->mapWithKeys(function (RoomAsset $asset): array {
                                        /** @var Room $room */
                                        $room = $asset->room;

                                        return [$asset->id => "{$room->name} — {$asset->name}"];
                                    })
                                    ->all())
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
                    $this->record = app(StationRequestWorkflowService::class)->transition($this->stationRequest(), $data, $actor);
                    Notification::make()->title('Station request updated')->success()->send();
                }),
        ];
    }

    private function stationRequest(): StationRequest
    {
        $record = $this->getRecord();
        assert($record instanceof StationRequest);

        return $record;
    }
}

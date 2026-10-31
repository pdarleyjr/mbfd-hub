<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\HasPageTableExportActions;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Services\Exports\ExportableTable;
use App\Services\Notifications\PushDeliveryTrace;
use App\Services\Notifications\PushDeviceStatus;
use App\Support\HubTimezone;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only: every enrolled push device and what its push service last answered.
 *
 * "Accepted" only ever means Apple, Google, Microsoft or Mozilla took the alert; whether the phone
 * displayed it cannot be observed from the server, and nothing on this page says "delivered". No
 * endpoint, key or secret is shown: the provider is worked out from the endpoint's host and the
 * key columns are never selected. Access: super administrators and the existing notifications
 * administrators (admin.notifications.manage).
 */
final class PushDeliveryHealth extends Page implements ExportableTable, HasTable
{
    use HasPageTableExportActions;
    use InteractsWithTable;

    public const PERMISSION = 'admin.notifications.manage';

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Push delivery health';

    protected static ?string $title = 'Push delivery health';

    protected static ?string $slug = 'push-delivery-health';

    protected static ?int $navigationSort = 65;

    protected static string $view = 'filament.pages.push-delivery-health';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Super administrators pass through the global Gate::before; everyone else needs the permission itself.
        return $user instanceof User
            && $user->isAuthenticationAllowed()
            && $user->can(self::PERMISSION);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function explanation(): string
    {
        return PushDeviceStatus::EXPLANATION;
    }

    public function table(Table $table): Table
    {
        return self::withExportActions($table
            ->query(fn (): Builder => $this->devices())
            ->columns([
                TextColumn::make('member_name')
                    ->label('Member')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('users.name', 'like', '%'.$search.'%'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('users.name', $direction)),
                TextColumn::make('device_label')
                    ->label('Device')
                    ->placeholder('Unknown device')
                    ->searchable(),
                TextColumn::make('provider')
                    ->label('Push service')
                    ->state(fn (HubPushSubscription $record): string => PushDeliveryTrace::providerLabel(PushDeliveryTrace::providerFamily($record->endpoint))),
                TextColumn::make('content_encoding')
                    ->label('Sent as'),
                TextColumn::make('last_success_at')
                    ->label('Last accepted')
                    ->dateTime('M j, g:i A', HubTimezone::NAME)
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('last_failure_at')
                    ->label('Last problem')
                    ->dateTime('M j, g:i A', HubTimezone::NAME)
                    ->placeholder('None')
                    ->description(fn (HubPushSubscription $record): ?string => PushDeviceStatus::plainReason($record->last_failure_reason))
                    ->sortable(),
                TextColumn::make('backoff_until')
                    ->label('Waiting until')
                    ->state(fn (HubPushSubscription $record): ?string => $record->backoff_until?->isFuture() === true
                        ? $record->backoff_until->timezone(HubTimezone::NAME)->format('M j, g:i A')
                        : null)
                    ->placeholder('Not waiting'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (HubPushSubscription $record): string => PushDeviceStatus::for($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PushDeviceStatus::ACCEPTED => 'success',
                        PushDeviceStatus::REJECTED => 'danger',
                        PushDeviceStatus::SETUP_NEEDED, PushDeviceStatus::WAITING => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('member_name')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No push devices yet')
            ->emptyStateDescription('A device appears here once a member turns on alerts on it.'));
    }

    /** Only the columns the page shows: the public key and auth secret are never loaded. */
    private function devices(): Builder
    {
        $devices = (new HubPushSubscription)->getTable();

        return HubPushSubscription::query()
            ->join('users', function ($join) use ($devices): void {
                $join->on('users.id', '=', $devices.'.subscribable_id')
                    ->where($devices.'.subscribable_type', '=', (new User)->getMorphClass());
            })
            ->select([
                $devices.'.id',
                $devices.'.endpoint',
                $devices.'.content_encoding',
                $devices.'.device_label',
                $devices.'.last_success_at',
                $devices.'.last_failure_at',
                $devices.'.last_failure_reason',
                $devices.'.backoff_until',
                'users.name as member_name',
            ]);
    }
}

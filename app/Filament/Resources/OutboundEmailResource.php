<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OutboundEmailResource\Pages;
use App\Models\OutboundEmail;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class OutboundEmailResource extends Resource
{
    protected static ?string $model = OutboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Sent';

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['recipients', 'initiatedBy']))->columns([
            Tables\Columns\Layout\Split::make([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('recipient_summary')->label('To')
                        ->getStateUsing(fn (OutboundEmail $record): string => $record->recipient_summary)
                        ->tooltip(fn (OutboundEmail $record): string => implode(', ', $record->to_recipients ?? []))
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                            $query->whereRaw('LOWER(CAST(to_recipients AS TEXT)) LIKE ?', ['%'.strtolower($search).'%'])
                                ->orWhereRaw('LOWER(CAST(cc_recipients AS TEXT)) LIKE ?', ['%'.strtolower($search).'%']);
                        })),
                    Tables\Columns\TextColumn::make('subject')->searchable()->weight('semibold')->wrap()->limit(90),
                ]),
                Tables\Columns\TextColumn::make('source_type')->label('Source')->formatStateUsing(fn (string $state): string => \App\Services\Communications\EmailConversation::sourceLabel($state)),
                Tables\Columns\TextColumn::make('status')->label('Delivery')->badge()
                    ->formatStateUsing(fn (OutboundEmail $record): string => $record->deliveryStatusLabel())
                    ->icon(fn (OutboundEmail $record): string => $record->deliveryStatusIcon())
                    ->color(fn (OutboundEmail $record): string => $record->deliveryStatusColor()),
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->description('Created'),
                    Tables\Columns\TextColumn::make('last_event_at')->label('Last event')->dateTime()->placeholder('No provider event')->description('Last provider event'),
                ]),
            ])->from('md'),
            Tables\Columns\TextColumn::make('provider_message_id')->searchable()->wrap()->limit(48)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->label('Delivery status')->options([
                'pending' => 'Preparing', 'submitted' => 'Submitted to provider', 'queued' => 'Pending delivery',
                'accepted_with_delivery_issues' => 'Accepted with delivery issues', 'deferred' => 'Deferred', 'delivered' => 'Delivered',
                'partially_delivered' => 'Partially delivered', 'bounced' => 'Bounced', 'rejected' => 'Rejected / suppressed',
                'failed' => 'Failed', 'complained' => 'Complained', 'unknown' => 'Status unknown', 'historical_unknown' => 'Delivery status unavailable / historical',
            ])->query(function (Builder $query, array $data): Builder {
                if (($data['value'] ?? null) === 'historical_unknown') {
                    return $query->where(function (Builder $query): void {
                        $query->where('status', 'historical_unknown')->orWhere(function (Builder $query): void {
                            $query->where('created_at', '<', now()->subDays(31))
                                ->whereIn('status', ['pending', 'reserved', 'submitted', 'accepted', 'queued', 'deferred', 'acceptance_unknown', 'accepted_with_delivery_issues'])
                                ->whereDoesntHave('recipients', fn (Builder $recipients): Builder => $recipients->where('is_terminal', true));
                        });
                    });
                }

                return $query->when($data['value'] ?? null, function (Builder $query, string $value): Builder {
                    $statuses = match ($value) {
                        'pending' => ['pending', 'reserved'],
                        'queued' => ['queued', 'accepted'],
                        'failed' => ['failed', 'failed_pre_acceptance'],
                        'unknown' => ['unknown', 'acceptance_unknown'],
                        default => [$value],
                    };

                    return $query->whereIn('status', $statuses)->when(in_array($value, ['pending', 'submitted', 'queued', 'deferred', 'accepted_with_delivery_issues'], true), fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->where('created_at', '>=', now()->subDays(31))
                            ->orWhereHas('recipients', fn (Builder $recipients): Builder => $recipients->where('is_terminal', true));
                    }));
                });
            }),
            Tables\Filters\SelectFilter::make('source_type')->label('Source')->options(fn (): array => OutboundEmail::query()->distinct()->pluck('source_type', 'source_type')->map(fn ($value) => \App\Services\Communications\EmailConversation::sourceLabel($value))->all()),
            Tables\Filters\SelectFilter::make('initiated_by_user_id')->label('Initiated by')->relationship('initiatedBy', 'name')->searchable()->preload(),
            Tables\Filters\Filter::make('created')->form([
                Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date))),
        ])->defaultSort('created_at', 'desc')->actions([Tables\Actions\ViewAction::make()->label('Open')->color('primary')])->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOutboundEmails::route('/'), 'view' => Pages\ViewOutboundEmail::route('/{record}')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.communications.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}

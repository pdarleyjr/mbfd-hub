<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\DepartmentUpdateAudience;
use App\Enums\DepartmentUpdateCategory;
use App\Enums\DepartmentUpdatePriority;
use App\Enums\DepartmentUpdateStatus;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\DepartmentUpdateResource\Pages;
use App\Jobs\SendDepartmentUpdateNotification;
use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Support\Security\SafeHtml;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DepartmentUpdateResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = DepartmentUpdate::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Department Updates';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(180)
                        ->columnSpanFull(),
                    Forms\Components\RichEditor::make('body')
                        ->required()
                        ->maxLength(250000)
                        ->dehydrateStateUsing(fn (?string $state): string => SafeHtml::report($state))
                        ->columnSpanFull(),
                    Forms\Components\Select::make('category')
                        ->options(DepartmentUpdateCategory::options())
                        ->required()
                        ->default(DepartmentUpdateCategory::General->value),
                    Forms\Components\Select::make('priority')
                        ->options(DepartmentUpdatePriority::options())
                        ->required()
                        ->default(DepartmentUpdatePriority::Normal->value),
                ])->columns(2),
            Forms\Components\Section::make('Publishing')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Publish to MBFD Hub')
                        ->options([
                            DepartmentUpdateStatus::Draft->value => DepartmentUpdateStatus::Draft->label(),
                            DepartmentUpdateStatus::Published->value => DepartmentUpdateStatus::Published->label(),
                        ])
                        ->rule('in:draft,published')
                        ->required()
                        ->default(DepartmentUpdateStatus::Draft->value)
                        ->helperText('Choose Draft or Published. Archive an already-published update from the table.'),
                    Forms\Components\Toggle::make('is_pinned')
                        ->label('Pin to top')
                        ->default(false),
                    Forms\Components\DateTimePicker::make('publish_at')
                        ->label('Publish at')
                        ->timezone('America/New_York')
                        ->seconds(false)
                        ->required(fn (Get $get): bool => $get('status') === DepartmentUpdateStatus::Published->value)
                        ->helperText('Times are shown in America/New_York.'),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Expires at')
                        ->timezone('America/New_York')
                        ->seconds(false)
                        ->after('publish_at')
                        ->helperText('Leave blank to keep the update active until it is unpublished or archived.'),
                ])->columns(2),
            Forms\Components\Section::make('Optional Content')
                ->schema([
                    Forms\Components\FileUpload::make('image_path')
                        ->label('Image')
                        ->disk('local')
                        ->directory('department-updates/images')
                        ->visibility('private')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(5120)
                        ->storeFileNamesIn('image_name'),
                    Forms\Components\FileUpload::make('attachment_path')
                        ->label('Attachment')
                        ->disk('local')
                        ->directory('department-updates/attachments')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain',
                        ])
                        ->maxSize(15360)
                        ->storeFileNamesIn('attachment_name'),
                    Forms\Components\TextInput::make('cta_label')
                        ->label('Button label')
                        ->maxLength(80)
                        ->requiredWith('cta_url'),
                    Forms\Components\TextInput::make('cta_url')
                        ->label('Button URL')
                        ->maxLength(2048)
                        ->requiredWith('cta_label')
                        ->rule('regex:#^(?:/(?!/).*|https?://.+)$#i')
                        ->helperText('Use an internal path beginning with / or a full http/https URL.'),
                ])->columns(2)
                ->collapsible(),
            Forms\Components\Section::make('Notification Delivery')
                ->description('Publishing to the Hub does not require an alert. Selected alerts are sent once when the update becomes effective.')
                ->schema([
                    Forms\Components\Toggle::make('send_in_app')
                        ->label('Send in-app notification')
                        ->default(false),
                    Forms\Components\Toggle::make('send_web_push')
                        ->label('Send web push notification')
                        ->default(false),
                    Forms\Components\Select::make('audience')
                        ->options(DepartmentUpdateAudience::options())
                        ->required()
                        ->live()
                        ->default(DepartmentUpdateAudience::Everyone->value),
                    Forms\Components\Select::make('audience_user_ids')
                        ->label('Selected members')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => User::query()
                            ->where('account_status', 'active')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->helperText('Driver Engineer is not a distinct rank in the canonical roster, so those recipients must be selected explicitly.')
                        ->visible(fn (Get $get): bool => in_array($get('audience'), [
                            DepartmentUpdateAudience::DriverEngineers->value,
                            DepartmentUpdateAudience::Selected->value,
                        ], true))
                        ->required(fn (Get $get): bool => in_array($get('audience'), [
                            DepartmentUpdateAudience::DriverEngineers->value,
                            DepartmentUpdateAudience::Selected->value,
                        ], true)),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->limit(60)->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (DepartmentUpdateCategory $state): string => $state->label())
                    ->color(fn (DepartmentUpdateCategory $state): string => $state->color()),
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (DepartmentUpdatePriority $state): string => $state->label())
                    ->color(fn (DepartmentUpdatePriority $state): string => $state->color()),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DepartmentUpdateStatus $state): string => $state->label())
                    ->color(fn (DepartmentUpdateStatus $state): string => $state->color()),
                Tables\Columns\IconColumn::make('is_pinned')->label('Pinned')->boolean(),
                Tables\Columns\TextColumn::make('author.name')->label('Author')->placeholder('Former user'),
                Tables\Columns\TextColumn::make('publish_at')->dateTime(timezone: 'America/New_York')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime(timezone: 'America/New_York')->placeholder('No expiration')->sortable(),
                Tables\Columns\TextColumn::make('notification_delivery_status')
                    ->label('Notification')
                    ->state(fn (DepartmentUpdate $record): string => $record->notificationDeliveryStatus())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Delivered' => 'success',
                        'Pending', 'Awaiting preparation' => 'warning',
                        'Completed with cancellations' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(DepartmentUpdateStatus::options()),
                Tables\Filters\SelectFilter::make('category')->options(DepartmentUpdateCategory::options()),
                Tables\Filters\SelectFilter::make('priority')->options(DepartmentUpdatePriority::options()),
                Tables\Filters\TernaryFilter::make('is_pinned')->label('Pinned'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->defaultSort('publish_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('publish')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (DepartmentUpdate $record): bool => self::canEdit($record))
                    ->visible(fn (DepartmentUpdate $record): bool => $record->status === DepartmentUpdateStatus::Draft)
                    ->action(function (DepartmentUpdate $record): void {
                        abort_unless(self::canEdit($record) && $record->status === DepartmentUpdateStatus::Draft, 403);
                        $record->update([
                            'status' => DepartmentUpdateStatus::Published,
                            'publish_at' => $record->publish_at ?? now(),
                        ]);
                        SendDepartmentUpdateNotification::dispatch($record->id)->afterCommit();
                    }),
                Tables\Actions\Action::make('unpublish')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (DepartmentUpdate $record): bool => self::canEdit($record))
                    ->visible(fn (DepartmentUpdate $record): bool => $record->status === DepartmentUpdateStatus::Published)
                    ->action(function (DepartmentUpdate $record): void {
                        abort_unless(self::canEdit($record) && $record->status === DepartmentUpdateStatus::Published, 403);
                        $record->update(['status' => DepartmentUpdateStatus::Draft]);
                    }),
                Tables\Actions\Action::make('archive')
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->authorize(fn (DepartmentUpdate $record): bool => self::canEdit($record))
                    ->visible(fn (DepartmentUpdate $record): bool => $record->canArchiveAsPublishedHistory())
                    ->action(function (DepartmentUpdate $record): void {
                        abort_unless(self::canEdit($record) && $record->canArchiveAsPublishedHistory(), 403);
                        $record->archiveAsPublishedHistory();
                    }),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Department Update')->schema([
                Infolists\Components\TextEntry::make('title')->columnSpanFull(),
                Infolists\Components\TextEntry::make('body')
                    ->formatStateUsing(fn (?string $state): string => SafeHtml::report($state))
                    ->html()
                    ->columnSpanFull(),
                Infolists\Components\TextEntry::make('category')->badge(),
                Infolists\Components\TextEntry::make('priority')->badge(),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('author.name')->label('Author')->placeholder('Former user'),
                Infolists\Components\TextEntry::make('publish_at')->dateTime(timezone: 'America/New_York'),
                Infolists\Components\TextEntry::make('expires_at')->dateTime(timezone: 'America/New_York')->placeholder('No expiration'),
            ])->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepartmentUpdates::route('/'),
            'create' => Pages\CreateDepartmentUpdate::route('/create'),
            'view' => Pages\ViewDepartmentUpdate::route('/{record}'),
            'edit' => Pages\EditDepartmentUpdate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'notificationDeliveries as notification_delivered_count' => fn (Builder $query): Builder => $query
                ->whereNotNull('delivered_at')
                ->whereNull('cancelled_at'),
            'notificationDeliveries as notification_cancelled_count' => fn (Builder $query): Builder => $query
                ->whereNull('delivered_at')
                ->whereNotNull('cancelled_at'),
            'notificationDeliveries as notification_pending_count' => fn (Builder $query): Builder => $query
                ->whereNull('delivered_at')
                ->whereNull('cancelled_at'),
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', DepartmentUpdate::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', DepartmentUpdate::class) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete', $record) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('deleteAny', DepartmentUpdate::class) ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->can('restore', $record) ?? false;
    }
}

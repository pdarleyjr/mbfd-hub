<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\InboundEmailResource\Pages;
use App\Models\InboundEmail;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class InboundEmailResource extends Resource
{
    use HasExportActions;

    protected static ?int $navigationSort = 30;

    protected static ?string $model = InboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Inbound Email';

    public static function table(Table $table): Table
    {
        return self::withExportActions($table->columns([
            Tables\Columns\Layout\Split::make([
                Tables\Columns\TextColumn::make('from_address')->label('From')->searchable(),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(70),
                Tables\Columns\TextColumn::make('received_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('processing_status')->badge(),
            ])->from('md'),
        ])->defaultSort('received_at', 'desc')->actions([
            Tables\Actions\ViewAction::make(),
        ])->bulkActions([]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboundEmails::route('/'),
            'view' => Pages\ViewInboundEmail::route('/{record}'),
        ];
    }

    /** Email has one entry in the sidebar (Communications > Email); the old address keeps working. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** The Email Center's read level, which still honours the older admin.communications.view. */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof \App\Models\User && app(\App\Services\Mail\MailAccess::class)->canView($user);
    }

    /** The capability gate maps this model to admin.communications only; the Email Center's read level is the rule here. */
    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
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

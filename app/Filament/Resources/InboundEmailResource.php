<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InboundEmailResource\Pages;
use App\Models\InboundEmail;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class InboundEmailResource extends Resource
{
    protected static ?string $model = InboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Inbox';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('from_address')->disabled(),
            Forms\Components\TextInput::make('to_address')->disabled(),
            Forms\Components\TextInput::make('subject')->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('text_body')->disabled()->rows(16)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('from_address')->label('From')->searchable(),
            Tables\Columns\TextColumn::make('subject')->searchable()->limit(70),
            Tables\Columns\TextColumn::make('received_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('processing_status')->badge(),
        ])->defaultSort('received_at', 'desc')->actions([
            Tables\Actions\ViewAction::make(),
        ])->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboundEmails::route('/'),
            'view' => Pages\ViewInboundEmail::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('admin.communications.view') ?? false;
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

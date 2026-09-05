<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OutboundEmailResource\Pages;
use App\Models\OutboundEmail;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class OutboundEmailResource extends Resource
{
    protected static ?string $model = OutboundEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Sent';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('from_address')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
            Forms\Components\TextInput::make('subject')->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('text_body')->disabled()->rows(16)->columnSpanFull(),
            Forms\Components\Textarea::make('failure_reason')->disabled()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('to_recipients')->label('To')->formatStateUsing(
                fn (mixed $state): string => implode(', ', is_array($state) ? $state : []),
            )->limit(60),
            Tables\Columns\TextColumn::make('subject')->searchable()->limit(70),
            Tables\Columns\TextColumn::make('recipient_count')->numeric(),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc')->actions([
            Tables\Actions\ViewAction::make(),
        ])->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutboundEmails::route('/'),
            'view' => Pages\ViewOutboundEmail::route('/{record}'),
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

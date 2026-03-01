<?php

namespace App\Filament\Training\Resources;

use App\Filament\Training\Resources\ExternalSourceResource\Pages;
use App\Models\ExternalSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExternalSourceResource extends Resource
{
    protected static ?string $model = ExternalSource::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'External Tools';

    protected static ?string $navigationLabel = 'External Sources';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && $user->hasRole('training_admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('division')->default('training'),
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('provider')
                ->options(['baserow' => 'Baserow'])
                ->default('baserow')
                ->required(),
            Forms\Components\TextInput::make('base_url')
                ->label('Base URL')
                ->url()
                ->required()
                ->default('https://baserow.mbfdhub.com'),
            Forms\Components\TextInput::make('token')
                ->label('API Token')
                ->password()
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Token is encrypted at rest. Leave blank to keep existing.'),
            Forms\Components\TextInput::make('token_hint')
                ->label('Token Hint')
                ->placeholder('e.g., env: BASEROW_TRAINING_TOKEN')
                ->maxLength(255),
            Forms\Components\Select::make('status')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('provider')->badge(),
                Tables\Columns\TextColumn::make('base_url')->limit(40),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExternalSources::route('/'),
            'create' => Pages\CreateExternalSource::route('/create'),
            'edit' => Pages\EditExternalSource::route('/{record}/edit'),
        ];
    }
}

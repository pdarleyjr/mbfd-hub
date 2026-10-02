<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Manual;

final class ManualResource extends LibraryResource
{
    protected static ?string $model = Manual::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Manuals';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, ?string $old, ?string $state, string $operation): void {
                    if ($operation !== 'create' || (filled($get('slug')) && $get('slug') !== Str::limit(Str::slug($old ?? ''), 100, ''))) {
                        return;
                    }
                    $set('slug', Str::limit(Str::slug($state ?? ''), 100, ''));
                }),
            Forms\Components\TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(100),
            Forms\Components\Select::make('type')->options(['sog' => 'SOGs', 'medical' => 'Medical Protocols', 'other' => 'Other'])->required(),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0)->required(),
            Forms\Components\Toggle::make('is_active')->label('Available to members')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('type'),
            Tables\Columns\IconColumn::make('is_active')->boolean()->label('Available'),
            Tables\Columns\TextColumn::make('activeEdition.label')->label('Published edition'),
        ])->defaultSort('sort_order')->reorderable('sort_order')->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListManuals::route('/'), 'create' => Pages\CreateManual::route('/create'), 'edit' => Pages\EditManual::route('/{record}/edit')];
    }
}

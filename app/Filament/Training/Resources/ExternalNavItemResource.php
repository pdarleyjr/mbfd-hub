<?php

namespace App\Filament\Training\Resources;

use App\Filament\Training\Resources\ExternalNavItemResource\Pages;
use App\Models\ExternalNavItem;
use App\Models\ExternalSource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ExternalNavItemResource extends Resource
{
    protected static ?string $model = ExternalNavItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationGroup = 'Training Data';

    protected static ?string $navigationLabel = 'Dynamic Nav Items';

    protected static ?int $navigationSort = 91;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && $user->hasRole('training_admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('division')->default('training'),

            Forms\Components\Section::make('Basic Info')->schema([
                Forms\Components\TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, ?string $state) =>
                        $set('slug', Str::slug($state))
                    ),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) =>
                        $rule->where('division', 'training')
                    ),
                Forms\Components\Select::make('type')
                    ->options(['iframe' => 'Iframe (Shared View)', 'api_table' => 'API Table'])
                    ->default('iframe')
                    ->required()
                    ->live(),
            ]),

            Forms\Components\Section::make('Iframe Settings')
                ->visible(fn (Forms\Get $get) => $get('type') === 'iframe')
                ->schema([
                    Forms\Components\TextInput::make('url')
                        ->label('Shared View URL')
                        ->url()
                        ->required(fn (Forms\Get $get) => $get('type') === 'iframe')
                        ->rules(['regex:/^https:\/\//'])
                        ->helperText('Must be HTTPS. Use Baserow shared view embed URL.'),
                ]),

            Forms\Components\Section::make('API Table Settings')
                ->visible(fn (Forms\Get $get) => $get('type') === 'api_table')
                ->schema([
                    Forms\Components\Select::make('external_source_id')
                        ->label('External Source')
                        ->options(ExternalSource::where('division', 'training')
                            ->where('status', 'active')
                            ->pluck('name', 'id'))
                        ->searchable(),
                    Forms\Components\TextInput::make('baserow_table_id')->numeric(),
                    Forms\Components\TextInput::make('baserow_view_id')->numeric(),
                    Forms\Components\TextInput::make('baserow_workspace_id')->numeric(),
                    Forms\Components\TextInput::make('baserow_database_id')->numeric(),
                ]),

            Forms\Components\Section::make('Access & Display')->schema([
                Forms\Components\Select::make('allowed_roles')
                    ->multiple()
                    ->options(Role::pluck('name', 'name'))
                    ->required(),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('is_active')->default(true),
                Forms\Components\Toggle::make('open_in_new_tab')->default(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->searchable()->sortable(),
                Tables\Columns\BadgeColumn::make('type'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
                Tables\Columns\TextColumn::make('allowed_roles')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExternalNavItems::route('/'),
            'create' => Pages\CreateExternalNavItem::route('/create'),
            'edit' => Pages\EditExternalNavItem::route('/{record}/edit'),
        ];
    }
}

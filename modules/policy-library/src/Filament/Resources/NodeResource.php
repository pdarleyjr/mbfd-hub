<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Models\ManualNode;

final class NodeResource extends LibraryResource
{
    protected static ?string $model = ManualNode::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'Navigation & Documents';

    protected static ?string $modelLabel = 'navigation entry';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('edition_id')->label('Manual edition')->options(fn () => Edition::query()->with('manual')->get()->mapWithKeys(fn ($edition) => [$edition->id => $edition->manual->name.' — '.$edition->label.' ('.$edition->state.')']))->required()->live()->disabledOn('edit')->dehydrated(),
            Forms\Components\Select::make('parent_id')->label('Parent section')->options(fn (Forms\Get $get) => ManualNode::query()->where('edition_id', $get('edition_id'))->where('type', 'section')->pluck('title', 'id'))->searchable()->nullable(),
            Forms\Components\Select::make('type')->options(['section' => 'Section / Subsection', 'document' => 'Policy / Protocol'])->required()->default('document'),
            Forms\Components\TextInput::make('title')->required()->maxLength(255)->live(onBlur: true)
                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, ?string $old, ?string $state, string $operation): void {
                    if ($operation !== 'create' || (filled($get('slug')) && $get('slug') !== Str::limit(Str::slug($old ?? ''), 255, ''))) {
                        return;
                    }
                    $set('slug', Str::limit(Str::slug($state ?? ''), 255, ''));
                }),
            Forms\Components\TextInput::make('short_title')->maxLength(255),
            Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(255),
            Forms\Components\TextInput::make('sort_order')->numeric()->required()->default(0)->label('Order within parent'),
            Forms\Components\Toggle::make('is_active')->label('Available to members')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->searchable(),
            Tables\Columns\TextColumn::make('manual.name')->label('Manual'),
            Tables\Columns\TextColumn::make('edition.label')->label('Edition'),
            Tables\Columns\TextColumn::make('parent.title')->label('Section'),
            Tables\Columns\TextColumn::make('type'),
            Tables\Columns\IconColumn::make('is_active')->boolean()->label('Available'),
        ])->defaultSort('sort_order')->filters([
            Tables\Filters\SelectFilter::make('manual_id')->relationship('manual', 'name')->label('Manual'),
            Tables\Filters\SelectFilter::make('edition_id')->relationship('edition', 'label')->label('Edition'),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListNodes::route('/'), 'create' => Pages\CreateNode::route('/create'), 'edit' => Pages\EditNode::route('/{record}/edit')];
    }
}

<?php

namespace App\Filament\Resources\Workgroup;

use App\Filament\Resources\Workgroup\Pages;
use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class CandidateProductResource extends Resource
{
    protected static ?string $model = CandidateProduct::class;
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Workgroup Management';
    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        if (!$user) return false;
        $member = WorkgroupMember::where('user_id', $user->id)->where('is_active', true)->first();
        return $member && in_array($member->role, ['admin', 'facilitator']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Product Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255)->label('Product Name'),
                        Forms\Components\Select::make('workgroup_session_id')
                            ->label('Session')
                            ->options(fn () => WorkgroupSession::orderBy('name')->pluck('name', 'id'))
                            ->searchable()->required(),
                        Forms\Components\Select::make('category_id')
                            ->label('Category')
                            ->options(fn () => EvaluationCategory::orderBy('name')->pluck('name', 'id'))
                            ->searchable()->nullable(),
                        Forms\Components\TextInput::make('manufacturer')->maxLength(255),
                        Forms\Components\TextInput::make('model')->maxLength(255),
                        Forms\Components\Textarea::make('description')->maxLength(1000)->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Product Details')->schema([
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('session.name')->label('Session'),
                Infolists\Components\TextEntry::make('category.name')->label('Category'),
                Infolists\Components\TextEntry::make('manufacturer'),
                Infolists\Components\TextEntry::make('model'),
                Infolists\Components\TextEntry::make('description'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('session.name')->sortable()->label('Session'),
                Tables\Columns\TextColumn::make('category.name')->sortable()->label('Category'),
                Tables\Columns\TextColumn::make('manufacturer')->searchable(),
                Tables\Columns\TextColumn::make('model')->searchable(),
                Tables\Columns\TextColumn::make('submissions_count')->counts('submissions')->label('Evaluations'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workgroup_session_id')
                    ->label('Session')
                    ->options(fn () => WorkgroupSession::pluck('name', 'id')),
            ])
            ->headerActions([
                ExportAction::make('export')->exports([
                    ExcelExport::make('xlsx')->fromTable()->withFilename('candidate-products-' . date('Y-m-d')),
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    ExportBulkAction::make('export_selected'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCandidateProducts::route('/'),
            'create' => Pages\CreateCandidateProduct::route('/create'),
            'view' => Pages\ViewCandidateProduct::route('/{record}'),
            'edit' => Pages\EditCandidateProduct::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }
}

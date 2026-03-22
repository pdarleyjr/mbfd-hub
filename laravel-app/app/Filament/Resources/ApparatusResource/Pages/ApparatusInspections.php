<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class ApparatusInspections extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ApparatusResource::class;

    protected static string $view = 'filament.resources.apparatus-resource.pages.apparatus-inspections';

    public Apparatus|int|string|null $record = null;

    public function mount(int|string|Apparatus $record): void
    {
        if ($record instanceof Apparatus) {
            $this->record = $record;
        } else {
            $this->record = Apparatus::findOrFail($record);
        }
    }

    public function getTitle(): string
    {
        return "Inspections for {$this->record->unit_id}";
    }

    protected function getTableQuery(): Builder
    {
        return ApparatusInspection::query()
            ->where('apparatus_id', $this->record->id)
            ->latest('completed_at');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('operator_name')
                    ->label('Operator')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rank')
                    ->badge(),
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('mileage')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('defects_count')
                    ->label('Issues Found')
                    ->counts('defects')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                Tables\Columns\IconColumn::make('officer_signature')
                    ->label('Officer Signed')
                    ->boolean()
                    ->getStateUsing(fn ($record) => !empty($record->officer_signature)),
            ])
            ->defaultSort('completed_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View Details')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ApparatusInspection $record): string => route('filament.admin.resources.inspections.view', ['record' => $record])),
            ])
            ->emptyStateHeading('No inspections yet')
            ->emptyStateDescription('Start a daily checkout to create an inspection record.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}

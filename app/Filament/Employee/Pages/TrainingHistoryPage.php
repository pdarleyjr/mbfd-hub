<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrainingHistoryPage extends Page implements HasTable
{
    use InteractsWithTable;
    use ResolvesCanonicalEmployee;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'My work';

    protected static ?string $navigationLabel = 'Training History';

    protected static ?string $title = 'Training Division Classes Attended';

    protected static ?string $slug = 'training-history';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.employee.pages.training-history';

    protected function attendanceQuery(): Builder
    {
        return TrainingAttendance::query()
            ->where('employee_profile_id', $this->authenticatedEmployee()->id)
            ->whereNull('voided_at')
            ->with('session.latestVersion')
            ->withExists(['audits as was_corrected' => fn (Builder $query) => $query->where(function (Builder $audit): void {
                $audit->whereIn('action', ['attendance.corrected', 'attendance.resigned'])
                    ->orWhere(fn (Builder $exception) => $exception->where('action', 'attendance.exception')->whereNotNull('before'));
            })]);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => $this->attendanceQuery())->columns([
            Tables\Columns\TextColumn::make('session.course_name')->label('Course')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('session.held_on')->label('Class date')->date()->sortable(),
            Tables\Columns\TextColumn::make('session.classifications')->label('Classification')->badge(),
            Tables\Columns\TextColumn::make('session.location')->label('Location')->placeholder('Not specified'),
            Tables\Columns\TextColumn::make('snapshot.rank')->label('Recorded rank')->toggleable(),
            Tables\Columns\TextColumn::make('snapshot.shift')->label('Recorded shift')->placeholder('Unassigned')->toggleable(),
            Tables\Columns\TextColumn::make('signed_at')->label('Signed (Eastern)')->dateTime('M j, Y g:i A')->timezone('America/New_York')->sortable(),
            Tables\Columns\TextColumn::make('attendance_status')->label('Attendance')->badge()
                ->getStateUsing(fn (TrainingAttendance $record): string => $record->was_corrected ? 'Attendance corrected' : 'Attendance recorded'),
            Tables\Columns\TextColumn::make('certification_status')->label('Certification')->badge()
                ->getStateUsing(fn (TrainingAttendance $record): string => $record->session?->latestVersion?->certified_at ? 'Roster certified' : 'Provisional roster')
                ->color(fn (string $state): string => $state === 'Roster certified' ? 'success' : 'warning'),
            Tables\Columns\TextColumn::make('session.status')->label('Roster state')->badge()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Tables\Filters\SelectFilter::make('classification')->options(array_combine(TrainingCourse::CLASSIFICATIONS, TrainingCourse::CLASSIFICATIONS))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $value) => $q->whereHas('session', fn (Builder $session) => $session->whereJsonContains('classifications', $value)))),
            Tables\Filters\Filter::make('dates')->form([DatePicker::make('from'), DatePicker::make('until')])
                ->query(fn (Builder $query, array $data): Builder => $query->whereHas('session', fn (Builder $session) => $session
                    ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('held_on', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('held_on', '<=', $date)))),
        ])->defaultSort('signed_at', 'desc')->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('No training attendance recorded yet')
            ->emptyStateDescription('Training rosters you sign will appear here. Contact the Training Division if a record needs correction.');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Training\Resources\TrainingSessionResource\Pages;

use App\Filament\Concerns\HasPageTableExportActions;
use App\Filament\Training\Resources\TrainingSessionResource;
use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingPaperAttendance;
use App\Models\Training\TrainingPaperRosterImport;
use App\Models\Training\TrainingSession;
use App\Services\Exports\ExportableTable;
use App\Services\Training\TrainingCourseModuleService;
use App\Services\Training\TrainingRosterService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewTrainingSession extends ViewRecord implements ExportableTable, HasTable
{
    use HasPageTableExportActions;
    use InteractsWithTable;

    protected static string $resource = TrainingSessionResource::class;

    protected static string $view = 'filament.training.pages.view-training-session';

    public function getRecord(): TrainingSession
    {
        $record = parent::getRecord();
        assert($record instanceof TrainingSession);

        return $record;
    }

    public function getTitle(): string
    {
        return $this->getRecord()->course_name.' — '.$this->getRecord()->held_on->format('M j, Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label('Download PDF')->icon('heroicon-o-document-arrow-down')
                ->visible(fn (): bool => $this->getRecord()->attendance_source !== 'paper')
                ->url(fn (): string => route('training.rosters.export', ['session' => $this->getRecord(), 'format' => 'pdf'])),
            Action::make('xlsx')->label('Download Excel')->icon('heroicon-o-table-cells')->color('gray')
                ->visible(fn (): bool => $this->getRecord()->attendance_source !== 'paper')
                ->url(fn (): string => route('training.rosters.export', ['session' => $this->getRecord(), 'format' => 'xlsx'])),
            Action::make('paperSource')->label('Original paper PDF')->icon('heroicon-o-document')
                ->visible(fn (): bool => $this->getRecord()->attendance_source === 'paper')
                ->url(fn (): string => route('training.paper.source', ['import' => TrainingPaperRosterImport::query()->where('training_session_id', $this->getRecord()->id)->value('id') ?? 0])),
            Action::make('paperReview')->label('Review paper entries')->color('gray')
                ->visible(fn (): bool => $this->getRecord()->attendance_source === 'paper')
                ->url(fn (): string => \App\Filament\Training\Resources\TrainingCourseResource::getUrl('paper', ['record' => $this->getRecord()->training_course_id])),
            Action::make('mapModule')->label('Assign module')->color('gray')
                ->visible(fn (): bool => Gate::allows('update', $this->getRecord()->course))
                ->fillForm(fn (): array => ['training_module_id' => $this->getRecord()->training_module_id, 'expected_module_id' => $this->getRecord()->training_module_id, 'expected_revision' => $this->getRecord()->revision])
                ->form([
                    Select::make('training_module_id')->label('Module')->placeholder('Unassigned')
                        ->options(fn (): array => $this->getRecord()->course->modules()->orderBy('sort_order')->pluck('name', 'id')->all()),
                    self::reasonField(), $this->revisionField(), Hidden::make('expected_module_id'),
                ])->action(function (array $data): void {
                    Gate::authorize('update', $this->getRecord()->course);
                    $module = filled($data['training_module_id'] ?? null) ? TrainingModule::findOrFail($data['training_module_id']) : null;
                    $this->mutate(fn () => app(TrainingCourseModuleService::class)->mapSession($this->getRecord(), $module, auth()->user(), $data['reason'], (int) $data['expected_revision'], filled($data['expected_module_id'] ?? null) ? (int) $data['expected_module_id'] : null), 'Module assignment updated');
                }),
            Action::make('nextSession')->label('Create next session')->color('gray')
                ->visible(fn (): bool => Gate::allows('create', TrainingSession::class))
                ->form(TrainingSessionResource::sessionFields())
                ->fillForm(fn (): array => $this->getRecord()->only(['course_name', 'location', 'oic_employee_profile_id', 'classifications']) + ['held_on' => now(\App\Support\HubTimezone::NAME)->toDateString()])
                ->action(function (array $data): void {
                    Gate::authorize('create', TrainingSession::class);
                    $next = app(TrainingRosterService::class)->createSession($this->getRecord()->course, $data + ['training_module_id' => $this->getRecord()->training_module_id], auth()->user());
                    $this->redirect(TrainingSessionResource::getUrl('view', ['record' => $next]));
                }),
            Action::make('launch')->label('Classroom links')->icon('heroicon-o-computer-desktop')
                ->visible(fn (): bool => Gate::allows('manageKiosk', $this->getRecord()))
                ->url(fn (): string => route('training.rosters.launch', ['session' => $this->getRecord()], false)),
            Action::make('editSession')->label('Edit session')->color('gray')
                ->visible(fn (): bool => Gate::allows('update', $this->getRecord()))
                ->fillForm(fn (): array => $this->getRecord()->only(['course_name', 'held_on', 'starts_at', 'location', 'oic_employee_profile_id', 'classifications']) + ['expected_revision' => $this->getRecord()->revision])
                ->form([...TrainingSessionResource::sessionFields(), $this->revisionField()])
                ->action(function (array $data): void {
                    Gate::authorize('update', $this->getRecord());
                    $this->mutate(fn () => app(TrainingRosterService::class)->updateSession($this->getRecord(), $data, auth()->user()), 'Session updated');
                }),
            $this->transitionAction('open', 'Open sign-in', 'open'),
            $this->transitionAction('close', 'Close sign-in', 'closed'),
            $this->transitionAction('reopen', 'Reopen for correction', 'closed', true),
            Action::make('certify')->label('Certify roster')->icon('heroicon-o-check-badge')
                ->visible(fn (): bool => Gate::allows('certify', $this->getRecord()))
                ->disabled(fn (): bool => auth()->user()->employee_profile_id === null)
                ->tooltip(fn (): ?string => auth()->user()->employee_profile_id === null ? 'Your Hub account must be linked to an active employee before certification.' : null)
                ->modalHeading('Certify this training roster')
                ->modalDescription('Review the attendance and downloads first. Your signature certifies this revision. Later changes require an audited reopening; this certified version remains available.')
                ->form([
                    Placeholder::make('oic')->label('Signing officer')->content(fn (): string => $this->signingOfficerLabel()),
                    self::signatureField(),
                    $this->revisionField(),
                ])->action(function (array $data): void {
                    Gate::authorize('certify', $this->getRecord());
                    $this->mutate(fn () => app(TrainingRosterService::class)->certify($this->getRecord(), auth()->user(), (int) auth()->user()->employee_profile_id, $data['signature'], (int) $data['expected_revision']), 'Roster certified');
                }),
            Action::make('addException')->label('Add attendance exception')->color('gray')
                ->visible(fn (): bool => Gate::allows('correct', $this->getRecord()))
                ->modalDescription('For a legitimate attendee whose current assignment is unavailable. Confirm their identity and printed name from department records and collect their own signature. No shift or bid assignment will be invented.')
                ->form([
                    TrainingSessionResource::employeeSelect('employee_profile_id', 'Employee')->required(),
                    TextInput::make('first_name')->label('Confirmed first name')->required()->maxLength(100),
                    TextInput::make('last_name')->label('Confirmed last name')->required()->maxLength(100),
                    self::signatureField(),
                    self::reasonField(),
                    $this->revisionField(),
                ])->action(function (array $data): void {
                    Gate::authorize('correct', $this->getRecord());
                    $this->mutate(fn () => app(TrainingRosterService::class)->addException($this->getRecord(), (int) $data['employee_profile_id'], $data['signature'], auth()->user(), $data['reason'], ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'expected_revision' => (int) $data['expected_revision']]), 'Attendance exception recorded');
                }),
            $this->transitionAction('archive', 'Archive roster', 'archived', true),
        ];
    }

    public function table(Table $table): Table
    {
        if ($this->getRecord()->attendance_source === 'paper') {
            return static::withExportActions($table->query(TrainingPaperAttendance::query()->where('training_session_id', $this->getRecord()->id)->with('employee'))
                ->columns([
                    Tables\Columns\TextColumn::make('employee.name')->label('Verified member')->placeholder('Pending identity review'),
                    Tables\Columns\TextColumn::make('source_page')->label('Original page'),
                    Tables\Columns\TextColumn::make('source_row')->label('Row / region'),
                    Tables\Columns\TextColumn::make('review_status')->label('Paper review')->badge(),
                ])->defaultSort('id')->paginationPageOptions([25, 50, 100]));
        }

        return static::withExportActions($table->query(fn () => TrainingAttendance::query()->where('training_session_id', $this->getRecord()->id))
            ->columns([
                Tables\Columns\TextColumn::make('snapshot.display_name')->label('Employee')->searchable(query: fn ($query, string $search) => $query->where('snapshot->display_name', 'like', '%'.$search.'%')),
                Tables\Columns\TextColumn::make('snapshot.employee_id')->label('Employee ID'),
                Tables\Columns\TextColumn::make('snapshot.rank')->label('Rank'),
                Tables\Columns\TextColumn::make('snapshot.shift')->label('Shift')->placeholder('Unassigned'),
                Tables\Columns\TextColumn::make('signed_at')->label('Signed (Eastern)')->dateTime('M j, Y g:i A')->timezone(\App\Support\HubTimezone::NAME)->sortable(),
                Tables\Columns\TextColumn::make('signature_signed_at')->label('Signature collected (Eastern)')->dateTime('M j, Y g:i:s A')->timezone(\App\Support\HubTimezone::NAME)->toggleable(),
                Tables\Columns\ImageColumn::make('signature_preview')->label('Signature')
                    ->getStateUsing(fn (TrainingAttendance $record): string => route('training.rosters.attendance.signature', ['session' => $this->getRecord(), 'attendance' => $record]))
                    ->height(48)->width(130),
                Tables\Columns\TextColumn::make('voided_at')->label('Voided')->dateTime()->placeholder('Valid'),
            ])->actions([
                Tables\Actions\Action::make('correct')->label('Correct / re-sign')->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => Gate::allows('correct', $this->getRecord()))
                    ->modalDescription('Collect a fresh signature. Re-signing the same employee preserves their original attendance details and date. Changing the employee uses the authoritative assignment for the original attendance date. Previous evidence remains in the audit history; signatures are never transferred to another identity.')
                    ->fillForm(fn (TrainingAttendance $record): array => ['employee_profile_id' => $record->employee_profile_id, 'expected_revision' => $this->getRecord()->revision])
                    ->form([TrainingSessionResource::employeeSelect('employee_profile_id', 'Correct employee')->required(), self::signatureField(), self::reasonField(), $this->revisionField()])
                    ->action(function (TrainingAttendance $record, array $data): void {
                        Gate::authorize('correct', $this->getRecord());
                        $this->mutate(fn () => app(TrainingRosterService::class)->correct($record, ['employee_profile_id' => (int) $data['employee_profile_id'], 'signature' => $data['signature'], 'expected_revision' => (int) $data['expected_revision']], auth()->user(), $data['reason']), 'Attendance corrected');
                    }),
                Tables\Actions\Action::make('void')->label('Void')->color('danger')->requiresConfirmation()
                    ->visible(fn (TrainingAttendance $record): bool => $record->voided_at === null && Gate::allows('correct', $this->getRecord()))
                    ->modalDescription('This entry will be excluded from current attendance and employee history. Prior certified versions and audit evidence are retained.')
                    ->form([self::reasonField(), $this->revisionField()])->action(function (TrainingAttendance $record, array $data): void {
                        Gate::authorize('correct', $this->getRecord());
                        $this->mutate(fn () => app(TrainingRosterService::class)->void($record, auth()->user(), $data['reason'], (int) $data['expected_revision']), 'Attendance voided');
                    }),
            ])->defaultSort('signed_at')->paginationPageOptions([25, 50, 100])->emptyStateHeading('No attendees have signed yet'));
    }

    protected function getViewData(): array
    {
        return [
            'session' => $this->getRecord(),
            'versions' => $this->getRecord()->versions()
                ->where(fn ($query) => $query->whereNotNull('certified_at')->orWhere('snapshot->session->status', 'submitted')->orWhere('revision', $this->getRecord()->revision))
                ->orderByDesc('revision')->get(),
            'audits' => $this->getRecord()->audits()->latest('created_at')->limit(100)->get(),
            'oic' => Employee::find($this->getRecord()->oic_employee_profile_id),
        ];
    }

    private function transitionAction(string $ability, string $label, string $status, bool $needsReason = false): Action
    {
        return Action::make($ability)->label($label)->color('gray')->requiresConfirmation()
            ->visible(fn (): bool => Gate::allows($ability, $this->getRecord()))
            ->form($needsReason ? [self::reasonField(), $this->revisionField()] : [$this->revisionField()])
            ->action(function (array $data) use ($ability, $status): void {
                Gate::authorize($ability, $this->getRecord());
                $this->mutate(fn () => app(TrainingRosterService::class)->transition($this->getRecord(), $status, auth()->user(), $data['reason'] ?? null, (int) $data['expected_revision']), 'Roster status updated');
            });
    }

    private static function signatureField(): ViewField
    {
        return ViewField::make('signature')->view('filament.training.forms.signature-pad')->required()->label('Handwritten signature');
    }

    private function revisionField(): Hidden
    {
        return Hidden::make('expected_revision')->default(fn (): int => $this->getRecord()->revision)->required()->rules(['integer', 'min:1']);
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('Reason (recorded in audit history)')->required()->minLength(5)->maxLength(2000);
    }

    private function signingOfficerLabel(): string
    {
        $employee = Employee::find(auth()->user()->employee_profile_id);

        return $employee ? $employee->name.' — '.$employee->employee_id : 'Your account must be linked to an active employee before you can certify.';
    }

    private function mutate(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()->title('Roster was not changed')->body($exception->validator->errors()->first())->danger()->persistent()->send();
            $path = $this->getMountedTableActionForm()?->getStatePath() ?? $this->getMountedActionForm()?->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path ? $path.'.'.$field : $field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->getRecord()->refresh();
        $this->resetTable();
        Notification::make()->title($message)->success()->send();
    }
}

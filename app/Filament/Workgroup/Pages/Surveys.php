<?php

declare(strict_types=1);

namespace App\Filament\Workgroup\Pages;

use App\Models\User;
use App\Models\WorkgroupSurvey;
use App\Services\Workgroup\SurveyResponseService;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Surveys extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Evaluations / Surveys';

    protected static ?string $navigationLabel = 'Surveys';

    protected static ?string $title = 'Surveys';

    protected static string $view = 'filament-workgroup.pages.surveys';

    public function table(Table $table): Table
    {
        return $table->query($this->surveysQuery())
            ->columns([
                TextColumn::make('title')->wrap()->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('completion')->label('Your response')->getStateUsing(function (WorkgroupSurvey $survey): string {
                    $user = auth()->user();
                    if (! $user instanceof User) {
                        return 'Unavailable';
                    }
                    $participant = app(SurveyResponseService::class)->existingParticipantFor($survey, $user);

                    return $participant?->submitted_at === null ? 'Not submitted' : 'Submitted';
                }),
            ])
            ->actions([
                Action::make('respond')->label(fn (WorkgroupSurvey $survey): string => $survey->isOpen() ? 'Respond' : 'View')->url(fn (WorkgroupSurvey $survey): string => SurveyFormPage::getUrl(['surveyId' => $survey->id])),
                Action::make('results')->label('Results')->visible(fn (WorkgroupSurvey $survey): bool => $this->canManage($survey))->url(fn (WorkgroupSurvey $survey): string => SurveyResultsPage::getUrl(['surveyId' => $survey->id])),
            ]);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WorkgroupContext::class)->current($user) !== null;
    }

    private function surveysQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return WorkgroupSurvey::query()->whereRaw('1 = 0');
        }
        $current = app(WorkgroupContext::class)->current($user);
        if ($current === null) {
            return WorkgroupSurvey::query()->whereRaw('1 = 0');
        }

        return app(WorkgroupAccess::class)->scopeSurveys(WorkgroupSurvey::query(), $user)->where('workgroup_id', $current->id)->orderByDesc('created_at');
    }

    private function canManage(WorkgroupSurvey $survey): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WorkgroupAccess::class)->canManageSurvey($user, $survey);
    }
}

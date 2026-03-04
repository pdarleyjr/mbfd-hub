<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Models\WorkgroupSession;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracking widget showing which official evaluators are missing submissions.
 */
class EvaluatorTrackingWidget extends BaseWidget
{
    protected static ?string $heading = 'Official Evaluator Completion Tracker';
    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        $session = WorkgroupSession::active()->first();

        return $table
            ->query($this->getQuery($session))
            ->columns([
                TextColumn::make('name')->label('Evaluator')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->toggleable(),
                TextColumn::make('submitted_count')
                    ->label('Submitted')
                    ->state(function ($record) use ($session) {
                        if (!$session) return 0;
                        return EvaluationSubmission::where('user_id', $record->id)
                            ->where('status', 'submitted')
                            ->where('session_id', $session->id)
                            ->count();
                    })
                    ->badge()
                    ->color('success'),
                TextColumn::make('missing_count')
                    ->label('Missing')
                    ->state(function ($record) use ($session) {
                        if (!$session) return 0;
                        $totalProducts = CandidateProduct::where('workgroup_session_id', $session->id)->count();
                        $submitted = EvaluationSubmission::where('user_id', $record->id)
                            ->where('status', 'submitted')
                            ->where('session_id', $session->id)
                            ->count();
                        return max(0, $totalProducts - $submitted);
                    })
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                IconColumn::make('is_complete')
                    ->label('Complete')
                    ->state(function ($record) use ($session) {
                        if (!$session) return false;
                        $totalProducts = CandidateProduct::where('workgroup_session_id', $session->id)->count();
                        $submitted = EvaluationSubmission::where('user_id', $record->id)
                            ->where('status', 'submitted')
                            ->where('session_id', $session->id)
                            ->count();
                        return $submitted >= $totalProducts;
                    })
                    ->boolean(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No Official Evaluators')
            ->emptyStateDescription('Assign official evaluators to the active session to track their progress.');
    }

    protected function getQuery(?WorkgroupSession $session): Builder
    {
        if (!$session) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->whereExists(function ($query) use ($session) {
                $query->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('session_user')
                    ->whereColumn('session_user.user_id', 'users.id')
                    ->where('session_user.workgroup_session_id', $session->id)
                    ->where('session_user.is_official_evaluator', true);
            });
    }
}

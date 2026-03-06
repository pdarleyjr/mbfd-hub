<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

/**
 * Widget showing top 2 products (finalists) per rankable category
 * with visual distinction for gold and silver placements.
 * Now uses the universal rubric overall_score field.
 */
class FinalistsWidget extends BaseWidget
{
    public ?WorkgroupSession $session = null;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $session = $this->session ?? WorkgroupSession::active()->first();
        
        return $table
            ->query($this->getQuery($session))
            ->columns([
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->weight('bold')
                    ->wrap(),

                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('manufacturer')
                    ->label('Manufacturer')
                    ->toggleable(),

                TextColumn::make('model')
                    ->label('Model')
                    ->toggleable(),

                TextColumn::make('weighted_score')
                    ->label('Score')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 2) : '—')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        !$state => 'gray',
                        (float) $state >= 80 => 'success',
                        (float) $state >= 60 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('response_count')
                    ->label('Responses')
                    ->sortable()
                    ->badge()
                    ->color('info'),
            ])
            ->defaultSort('weighted_score', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No Finalists Yet')
            ->emptyStateDescription('Rankings will appear once evaluations are submitted.')
            ->emptyStateIcon('heroicon-o-trophy');
    }

    protected function getQuery(?WorkgroupSession $session)
    {
        if (!$session) {
            return CandidateProduct::query()->whereRaw('1 = 0');
        }

        return CandidateProduct::query()
            ->where('workgroup_session_id', $session->id)
            ->whereHas('category', fn($query) => 
                $query->where('is_rankable', true)
            )
            ->with(['category'])
            ->select('candidate_products.*')
            ->addSelect([
                'weighted_score' => EvaluationSubmission::selectRaw('AVG(overall_score)')
                    ->whereColumn('candidate_product_id', 'candidate_products.id')
                    ->where('status', 'submitted')
                    ->whereHas('member', fn($q) => $q->where('count_evaluations', true)),
                'response_count' => EvaluationSubmission::selectRaw('COUNT(*)')
                    ->whereColumn('candidate_product_id', 'candidate_products.id')
                    ->where('status', 'submitted')
                    ->whereHas('member', fn($q) => $q->where('count_evaluations', true)),
            ])
            ->orderBy('category_id')
            ->orderByDesc('weighted_score');
    }

    /**
     * Get finalists (top 2) for a specific category.
     */
    public static function getFinalistsForCategory(EvaluationCategory $category): array
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return [];
        }

        return CandidateProduct::where('workgroup_session_id', $session->id)
            ->where('category_id', $category->id)
            ->withCount(['submissions' => fn($q) => 
                $q->where('status', 'submitted')
            ])
            ->get()
            ->map(function ($product, $index) {
                // Try to get rubric score first, fallback to legacy
                $avgScore = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
                    ->avg('overall_score');
                
                if ($avgScore === null) {
                    // Fallback to legacy calculation
                    $avgScore = DB::table('evaluation_submissions as es')
                        ->join('evaluation_scores as score', 'score.submission_id', '=', 'es.id')
                        ->join('evaluation_criteria as ec', 'ec.id', '=', 'score.criterion_id')
                        ->where('es.candidate_product_id', $product->id)
                        ->where('es.status', 'submitted')
                        ->whereNotNull('score.score')
                        ->avg(DB::raw('score.score * ec.weight'));
                }

                return [
                    'product' => $product,
                    'rank' => $index + 1,
                    'weighted_score' => round($avgScore ?? 0, 2),
                    'response_count' => $product->submissions_count,
                ];
            })
            ->sortByDesc('weighted_score')
            ->take(2)
            ->values()
            ->toArray();
    }

    /**
     * Get all finalists across all rankable categories.
     */
    public static function getAllFinalists(): array
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return [];
        }

        $categories = EvaluationCategory::rankable()->active()->ordered()->get();
        $finalists = [];

        foreach ($categories as $category) {
            $categoryFinalists = self::getFinalistsForCategory($category);
            $finalists[$category->name] = $categoryFinalists;
        }

        return $finalists;
    }
}

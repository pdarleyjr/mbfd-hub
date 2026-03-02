<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Tables\Columns\BadgeColumn;
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
                BadgeColumn::make('rank')
                    ->label('Position')
                    ->colors([
                        'warning' => 1, // Gold
                        'gray' => 2,    // Silver
                    ])
                    ->icons([
                        1 => 'heroicon-o-trophy',
                        2 => 'heroicon-o-star',
                    ])
                    ->width('80px'),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('product.manufacturer')
                    ->label('Manufacturer')
                    ->toggleable(),

                TextColumn::make('product.model')
                    ->label('Model')
                    ->toggleable(),

                TextColumn::make('weighted_score')
                    ->label('Score')
                    ->formatStateUsing(fn ($state) => number_format($state, 2))
                    ->sortable()
                    ->badge()
                    ->color('success'),

                TextColumn::make('response_count')
                    ->label('Responses')
                    ->sortable(),
            ])
            ->defaultSort('category_id')
            ->paginated(false);
    }

    protected function getQuery(?WorkgroupSession $session)
    {
        if (!$session) {
            return CandidateProduct::query()->whereRaw('1 = 0');
        }

        // Get top 2 products per category using the new rubric overall_score
        return CandidateProduct::query()
            ->whereHas('session', fn($query) => 
                $query->where('id', $session->id)
            )
            ->whereHas('category', fn($query) => 
                $query->where('is_rankable', true)
            )
            ->with(['category', 'session'])
            ->withCount(['submissions' => fn($q) => 
                $q->where('status', 'submitted')
            ])
            ->select('candidate_products.*')
            ->addSelect([
                // Use new rubric overall_score if available, fallback to legacy
                'weighted_score' => EvaluationSubmission::selectRaw('COALESCE(
                    AVG(overall_score),
                    COALESCE(AVG(
                        (SELECT SUM(es.score * ec.weight) / NULLIF(SUM(ec.weight), 0)
                        FROM evaluation_scores es
                        JOIN evaluation_criteria ec ON es.criterion_id = ec.id
                        WHERE es.submission_id = evaluation_submissions.id
                        AND es.score IS NOT NULL)
                    ), 0)
                )')
                    ->join('evaluation_submissions', 'evaluation_submissions.candidate_product_id', '=', 'candidate_products.id')
                    ->whereColumn('evaluation_submissions.candidate_product_id', 'candidate_products.id')
                    ->where('evaluation_submissions.status', 'submitted'),
                'response_count' => EvaluationSubmission::selectRaw('COUNT(*)')
                    ->whereColumn('candidate_product_id', 'candidate_products.id')
                    ->where('status', 'submitted'),
            ])
            ->orderByDesc('weighted_score')
            ->orderBy('category_id');
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

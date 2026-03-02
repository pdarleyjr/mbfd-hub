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
 * Widget showing top products per rankable category with weighted scores
 * and response counts. Now uses the universal rubric overall_score field.
 */
class CategoryRankingsWidget extends BaseWidget
{
    public ?WorkgroupSession $session = null;
    public ?EvaluationCategory $category = null;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $session = $this->session ?? WorkgroupSession::active()->first();
        
        return $table
            ->query($this->getQuery($session))
            ->columns([
                TextColumn::make('rank')
                    ->label('Rank')
                    ->width('60px')
                    ->sortable(),

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
                    ->color(fn ($state) => $this->getScoreColor($state)),

                TextColumn::make('response_count')
                    ->label('Responses')
                    ->counts('submissions')
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->toggleable(),
            ])
            ->defaultSort('weighted_score', 'desc')
            ->paginated(false);
    }

    protected function getQuery(?WorkgroupSession $session)
    {
        if (!$session) {
            return CandidateProduct::query()->whereRaw('1 = 0');
        }

        $categoryId = $this->category?->id;

        return CandidateProduct::query()
            ->when($categoryId, fn($query) => 
                $query->where('category_id', $categoryId)
            )
            ->whereHas('session', fn($query) => 
                $query->where('id', $session->id)
            )
            ->whereHas('category', fn($query) => 
                $query->where('is_rankable', true)
            )
            ->with(['category', 'session'])
            ->withCount('submissions')
            ->select('candidate_products.*')
            ->addSelect([
                // Use new rubric overall_score if available, fallback to legacy calculation
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
            ->orderByDesc('weighted_score');
    }

    protected function getScoreColor(float $score): string
    {
        // Score is 0-100 based on new rubric
        if ($score >= 80) {
            return 'success';
        } elseif ($score >= 60) {
            return 'warning';
        } elseif ($score >= 40) {
            return 'danger';
        }
        return 'gray';
    }

    /**
     * Get rankings for a specific category.
     */
    public static function getRankingsForCategory(EvaluationCategory $category, ?int $limit = null): array
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return [];
        }

        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->where('category_id', $category->id)
            ->withCount(['submissions' => fn($q) => 
                $q->where('status', 'submitted')
            ])
            ->get()
            ->map(function ($product) {
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
                    'weighted_score' => round($avgScore ?? 0, 2),
                    'response_count' => $product->submissions_count,
                ];
            })
            ->sortByDesc('weighted_score')
            ->values();

        if ($limit) {
            return $products->take($limit)->toArray();
        }

        return $products->toArray();
    }
}

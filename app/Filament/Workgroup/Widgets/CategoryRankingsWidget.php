<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Widget showing top products per rankable category with weighted scores
 * and response counts.
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
                    ->sortable('weighted_score', descending: true)
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
                'weighted_score' => EvaluationScore::selectRaw('COALESCE(AVG(score * (SELECT weight FROM evaluation_criteria WHERE id = evaluation_scores.criterion_id)), 0)')
                    ->join('evaluation_submissions', 'evaluation_scores.submission_id', '=', 'evaluation_submissions.id')
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
        if ($score >= 8) {
            return 'success';
        } elseif ($score >= 6) {
            return 'warning';
        } elseif ($score >= 4) {
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
                $avgScore = EvaluationScore::whereHas('submission', fn($q) => 
                    $q->where('candidate_product_id', $product->id)
                        ->where('status', 'submitted')
                )
                ->join('evaluation_criteria', 'evaluation_scores.criterion_id', '=', 'evaluation_criteria.id')
                ->selectRaw('COALESCE(AVG(evaluation_scores.score * evaluation_criteria.weight), 0) as weighted_avg')
                ->value('weighted_avg');

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

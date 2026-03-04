<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('name')->label('Product')->searchable()->wrap(),
                TextColumn::make('manufacturer')->label('Manufacturer')->toggleable(),
                TextColumn::make('model')->label('Model')->toggleable(),
                TextColumn::make('category.name')->label('Category')->toggleable(),
                TextColumn::make('avg_score')
                    ->label('Avg Score')
                    ->state(function ($record) {
                        $avg = EvaluationSubmission::where('candidate_product_id', $record->id)
                            ->where('status', 'submitted')
                            ->whereNotNull('overall_score')
                            ->avg('overall_score');
                        return $avg !== null ? number_format($avg, 2) : '-';
                    })
                    ->badge()
                    ->color(fn ($state) => is_numeric($state) ? ($state >= 80 ? 'success' : ($state >= 60 ? 'warning' : 'danger')) : 'gray'),
                TextColumn::make('submissions_count')
                    ->counts('submissions')
                    ->label('Responses'),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }

    protected function getQuery(?WorkgroupSession $session): Builder
    {
        if (!$session) {
            return CandidateProduct::query()->whereRaw('1 = 0');
        }

        return CandidateProduct::query()
            ->where('workgroup_session_id', $session->id)
            ->when($this->category, fn($q) => $q->where('category_id', $this->category->id))
            ->whereHas('category', fn($q) => $q->where('is_rankable', true))
            ->with(['category'])
            ->withCount('submissions');
    }

    public static function getRankingsForCategory(EvaluationCategory $category, ?int $limit = null): array
    {
        $session = WorkgroupSession::active()->first();
        if (!$session) return [];

        $products = CandidateProduct::where('workgroup_session_id', $session->id)
            ->where('category_id', $category->id)
            ->withCount(['submissions' => fn($q) => $q->where('status', 'submitted')])
            ->get()
            ->map(function ($product) {
                $avgScore = EvaluationSubmission::where('candidate_product_id', $product->id)
                    ->where('status', 'submitted')
                    ->avg('overall_score');
                return [
                    'product' => $product,
                    'weighted_score' => round($avgScore ?? 0, 2),
                    'response_count' => $product->submissions_count,
                ];
            })
            ->sortByDesc('weighted_score')
            ->values();

        return $limit ? $products->take($limit)->toArray() : $products->toArray();
    }
}

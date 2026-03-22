<?php

namespace App\Filament\Workgroup\Widgets;

use App\Models\EvaluationCategory;
use App\Models\EvaluationComment;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Widget showing aggregated feedback for non-rankable categories
 * (Training, Instructor, etc.) with comment summaries.
 */
class NonRankableFeedbackWidget extends BaseWidget
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
                    ->wrap(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('comment_count')
                    ->label('Comments')
                    ->counts('comments')
                    ->sortable(),

                TextColumn::make('latest_comment')
                    ->label('Latest Comment')
                    ->wrap()
                    ->limit(100)
                    ->toggleable(),

                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('m/d/Y h:i A')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->paginated(10);
    }

    protected function getQuery(?WorkgroupSession $session)
    {
        if (!$session) {
            return EvaluationSubmission::query()->whereRaw('1 = 0');
        }

        return EvaluationSubmission::query()
            ->where('status', 'submitted')
            ->whereHas('candidateProduct', fn($query) => 
                $query->where('workgroup_session_id', $session->id)
                    ->whereHas('category', fn($q) => 
                        $q->where('is_rankable', false)
                    )
            )
            ->with(['category', 'candidateProduct', 'comments'])
            ->withCount('comments')
            ->addSelect([
                'latest_comment' => EvaluationComment::selectRaw('MAX(comment)')
                    ->whereColumn('submission_id', 'evaluation_submissions.id'),
            ]);
    }

    /**
     * Get aggregated feedback for non-rankable categories.
     */
    public static function getAggregatedFeedback(): array
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return [];
        }

        $categories = EvaluationCategory::where('is_rankable', false)
            ->active()
            ->ordered()
            ->get();

        $feedback = [];

        foreach ($categories as $category) {
            $submissions = EvaluationSubmission::where('status', 'submitted')
                ->whereHas('candidateProduct', fn($q) => 
                    $q->where('workgroup_session_id', $session->id)
                        ->where('category_id', $category->id)
                )
                ->with(['candidateProduct', 'comments'])
                ->get();

            $allComments = [];
            $productsWithFeedback = [];

            foreach ($submissions as $submission) {
                $productsWithFeedback[] = [
                    'product' => $submission->candidateProduct->name,
                    'manufacturer' => $submission->candidateProduct->manufacturer,
                    'model' => $submission->candidateProduct->model,
                    'comment_count' => $submission->comments->count(),
                    'comments' => $submission->comments->pluck('comment')->toArray(),
                ];

                foreach ($submission->comments as $comment) {
                    $allComments[] = $comment->comment;
                }
            }

            $feedback[] = [
                'category' => $category,
                'product_count' => $submissions->count(),
                'total_comments' => count($allComments),
                'products' => $productsWithFeedback,
                'all_comments' => $allComments,
            ];
        }

        return $feedback;
    }

    /**
     * Get all comments for a specific non-rankable category.
     */
    public static function getCommentsForCategory(EvaluationCategory $category): array
    {
        $session = WorkgroupSession::active()->first();
        
        if (!$session) {
            return [];
        }

        return EvaluationComment::query()
            ->whereHas('submission', fn($query) => 
                $query->where('status', 'submitted')
                    ->whereHas('candidateProduct', fn($q) => 
                        $q->where('workgroup_session_id', $session->id)
                            ->where('category_id', $category->id)
                    )
            )
            ->with(['submission.candidateProduct'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}

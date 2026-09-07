<?php

namespace App\Filament\Resources\StationInspectionResource\Pages;

use App\Filament\Resources\StationInspectionResource;
use App\Models\StationInspection;
use App\Models\User;
use App\Services\StationInspectionReviewService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;

class ViewStationInspection extends ViewRecord
{
    protected static string $resource = StationInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('acknowledgeInspection')
                ->label('Review / Acknowledge')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->form([
                    Forms\Components\Textarea::make('review_note')
                        ->label('Review note')
                        ->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->canReview())
                ->action(fn (array $data) => $this->review('reviewed', $data['review_note'] ?? null)),
            Actions\Action::make('needsFollowUp')
                ->label('Needs Follow-up')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->form([
                    Forms\Components\Textarea::make('review_note')
                        ->label('Follow-up note')
                        ->required()
                        ->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->canReview())
                ->action(fn (array $data) => $this->review('needs_follow_up', $data['review_note'])),
        ];
    }

    private function canReview(): bool
    {
        $record = $this->getRecord();

        return $record instanceof StationInspection
            && $record->review_status === 'pending_review'
            && StationInspectionResource::canEdit($record);
    }

    private function review(string $status, ?string $note): void
    {
        $record = $this->getRecord();
        $reviewer = auth()->user();
        abort_unless($record instanceof StationInspection && $reviewer instanceof User && $this->canReview(), 403);

        app(StationInspectionReviewService::class)->review((int) $record->getKey(), $reviewer, $status, $note);
        $record->refresh();
        $this->refreshFormData(['review_status', 'reviewed_by', 'reviewed_at', 'review_note']);
    }
}

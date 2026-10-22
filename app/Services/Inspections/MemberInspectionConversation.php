<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Support\Fleet\InspectionReviewers;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Member-safe conversation projection for the employee portal.
 *
 * The signed-in member only ever receives member-visible messages, even when that
 * member also holds a reviewer role (IssueMessageService returns internal notes to
 * reviewers, so the filter is repeated here). Internal notes, review-event notes and
 * message visibility flags never leave this class.
 */
final class MemberInspectionConversation
{
    public function __construct(
        private readonly EmployeeInspectionHistoryService $history,
        private readonly IssueMessageService $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $detail  EmployeeInspectionHistoryService::detail()
     * @return array{
     *     inspection: array{messages: list<array<string, mixed>>, can_post: bool},
     *     issues: array<int, array{messages: list<array<string, mixed>>, can_post: bool}>,
     *     clarification: array<string, mixed>|null,
     *     rejection_reason: string|null,
     * }
     *
     * @throws AuthorizationException when the viewer does not own the inspection
     */
    public function project(User $viewer, ApparatusInspection $inspection, array $detail): array
    {
        if (! $this->history->owns($viewer, $inspection)) {
            throw new AuthorizationException('You can only view conversations on your own inspections.');
        }

        $authors = [];
        $general = $this->visible($this->messages->threadFor($viewer, $inspection), $viewer, $authors);
        $state = (string) ($detail['acceptance']['state'] ?? 'accepted');
        $fromLogistics = array_values(array_filter($general, static fn (array $message): bool => ! $message['own']));

        $issues = [];
        foreach ($this->defectIds($detail) as $defectId) {
            $defect = ApparatusDefect::query()->find($defectId);
            if ($defect === null) {
                continue;
            }
            try {
                $thread = $this->visible($this->messages->threadFor($viewer, $defect), $viewer, $authors);
            } catch (AuthorizationException) {
                continue;
            }
            $issues[$defectId] = [
                'messages' => $thread,
                'can_post' => $this->messages->canPost($viewer, $defect),
            ];
        }

        $latest = $fromLogistics === [] ? null : $fromLogistics[array_key_last($fromLogistics)];

        return [
            'inspection' => [
                'messages' => $general,
                'can_post' => $this->messages->canPost($viewer, $inspection),
            ],
            'issues' => $issues,
            'clarification' => $state === 'needs_approval' ? $latest : null,
            'rejection_reason' => $state === 'rejected' && $latest !== null ? (string) $latest['body'] : null,
        ];
    }

    /**
     * Defect ids this inspection created or observed, in display order.
     *
     * @param  array<string, mixed>  $detail
     * @return list<int>
     */
    public function defectIds(array $detail): array
    {
        $ids = [];
        foreach (array_merge($detail['findings'] ?? [], $detail['unmatched_defects'] ?? []) as $finding) {
            $id = $finding['follow_up']['defect_id'] ?? null;
            if (is_int($id)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param  Collection<int, ApparatusIssueMessage>  $thread
     * @param  array<int, string>  $authors  author id => label, filled lazily
     * @return list<array<string, mixed>>
     */
    private function visible(Collection $thread, User $viewer, array &$authors): array
    {
        return $thread
            ->filter(static fn (ApparatusIssueMessage $message): bool => $message->visibility === ApparatusIssueMessage::VISIBILITY_MEMBER)
            ->map(function (ApparatusIssueMessage $message) use ($viewer, &$authors): array {
                $own = (int) $message->author_user_id === (int) $viewer->getKey();

                return [
                    'id' => (int) $message->getKey(),
                    'body' => (string) $message->body,
                    'own' => $own,
                    'author' => $own ? 'You' : $this->authorLabel($message, $authors),
                    'at' => CarbonImmutable::instance($message->created_at)->setTimezone(EmployeeInspectionHistoryService::TIMEZONE),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, string> $authors */
    private function authorLabel(ApparatusIssueMessage $message, array &$authors): string
    {
        $id = (int) $message->author_user_id;
        if ($id === 0) {
            return 'Logistics';
        }

        return $authors[$id] ??= $this->labelFor($message->author);
    }

    private function labelFor(?User $author): string
    {
        if ($author === null) {
            return 'Logistics';
        }

        return InspectionReviewers::canReview($author) ? 'Logistics' : 'Crew member';
    }
}

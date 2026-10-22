<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
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

        $general = $this->visible($this->messages->threadFor($viewer, $inspection), $viewer);
        $state = (string) ($detail['acceptance']['state'] ?? 'accepted');
        $fromLogistics = array_values(array_filter($general, static fn (array $message): bool => $message['from_logistics']));

        // One batched, once-authorized read for every issue on the page (no per-finding queries).
        $issues = [];
        foreach ($this->messages->memberThreadsForInspection($viewer, $inspection, $this->defectIds($detail)) as $defectId => $thread) {
            $issues[$defectId] = [
                'messages' => $this->visible($thread, $viewer),
                'can_post' => true,
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
     * @return list<array<string, mixed>>
     */
    private function visible(Collection $thread, User $viewer): array
    {
        return $thread
            ->filter(static fn (ApparatusIssueMessage $message): bool => $message->visibility === ApparatusIssueMessage::VISIBILITY_MEMBER)
            ->map(function (ApparatusIssueMessage $message) use ($viewer): array {
                $own = (int) $message->author_user_id === (int) $viewer->getKey();
                // The audience label is the author's context when they wrote it, never their role today.
                $fromLogistics = $message->isFromLogistics();

                return [
                    'id' => (int) $message->getKey(),
                    'body' => (string) $message->body,
                    'own' => $own,
                    'from_logistics' => $fromLogistics,
                    'author' => $own ? 'You' : ($fromLogistics ? 'Logistics' : 'Crew member'),
                    'at' => CarbonImmutable::instance($message->created_at)->setTimezone(EmployeeInspectionHistoryService::TIMEZONE),
                ];
            })
            ->values()
            ->all();
    }
}

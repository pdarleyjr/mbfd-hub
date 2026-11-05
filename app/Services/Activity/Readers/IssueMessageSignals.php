<?php

declare(strict_types=1);

namespace App\Services\Activity\Readers;

use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Activity\ActivitySignal;
use App\Services\Activity\LatestPerGroup;
use App\Services\Activity\MemberActivityText;
use App\Services\Activity\MemberActivityUpdate;

/**
 * What the department has written to the member on inspection and issue threads (apparatus_issue_messages).
 *
 * Only member-visible rows from Logistics, Captain 5 or the automated assistant are read. Internal notes and
 * other crew members' replies are filtered in the query, so they never reach memory, let alone the page.
 * Two queries cover every target: the newest message to show, and the newest one a person wrote.
 */
final class IssueMessageSignals
{
    private const COLUMNS = ['id', 'apparatus_inspection_id', 'apparatus_defect_id', 'author_user_id', 'author_context', 'body', 'created_at'];

    /**
     * @param  'apparatus_inspection_id'|'apparatus_defect_id'  $column
     * @param  list<int>  $ids
     * @return array<int, ActivitySignal> keyed by target id
     */
    public function forTargets(User $user, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $shown = ApparatusIssueMessage::query()
            ->memberVisible()
            ->whereIn('author_context', [...ApparatusIssueMessage::STAFF_CONTEXTS, ApparatusIssueMessage::CONTEXT_ASSISTANT])
            ->whereIn($column, $ids);
        $people = (clone $shown)->fromStaff()
            ->where(fn ($author) => $author->whereNull('author_user_id')->orWhere('author_user_id', '<>', $user->getKey()));

        $signals = [];
        foreach (LatestPerGroup::newest($shown, $column)->get(self::COLUMNS) as $message) {
            $signals[(int) $message->getAttribute($column)] = new ActivitySignal($this->update($user, $message));
        }
        foreach (LatestPerGroup::newest($people, $column)->get(['id', $column, 'created_at']) as $message) {
            $target = (int) $message->getAttribute($column);
            $signals[$target] = ($signals[$target] ?? ActivitySignal::none())
                ->merge(new ActivitySignal(null, MemberActivityText::moment($message->created_at)));
        }

        return $signals;
    }

    private function update(User $user, ApparatusIssueMessage $message): MemberActivityUpdate
    {
        $assistant = $message->isFromAssistant();
        $own = ! $assistant && $message->author_user_id !== null && (int) $message->author_user_id === (int) $user->getKey();
        $author = match (true) {
            $assistant => MemberActivityText::assistantLabel(),
            $own => 'You',
            $message->isFromCaptain5() => 'Captain 5',
            default => MemberActivityText::DEPARTMENT,
        };

        return new MemberActivityUpdate(
            MemberActivityText::excerpt((string) $message->body),
            $author,
            MemberActivityText::moment($message->created_at),
            $assistant,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Workgroups;

use App\Models\User;
use App\Models\WorkgroupSession;
use Illuminate\Http\Request;

final class WorkgroupReportSessionResolver
{
    public function __construct(
        private readonly WorkgroupAccess $workgroupAccess,
        private readonly WorkgroupContext $workgroupContext,
    ) {}

    public function resolve(Request $request): WorkgroupSession
    {
        $user = $request->user();
        abort_unless($user instanceof User, 404);
        $workgroup = $this->workgroupContext->requireCurrent($user);

        $rawSessionId = $request->query('session_id');
        abort_unless(is_string($rawSessionId) || is_int($rawSessionId), 404);

        $sessionId = filter_var($rawSessionId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        abort_unless($sessionId !== false, 404);

        $session = $this->workgroupAccess
            ->scopeSessions(WorkgroupSession::query(), $user)
            ->with('workgroup')
            ->where('workgroup_id', $workgroup->id)
            ->whereKey($sessionId)
            ->first();

        abort_unless($session instanceof WorkgroupSession, 404);
        abort_unless($session->workgroup !== null, 404);

        return $session;
    }
}

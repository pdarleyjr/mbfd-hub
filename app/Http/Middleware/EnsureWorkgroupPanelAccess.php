<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Workgroups\WorkgroupAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkgroupPanelAccess
{
    public function __construct(private readonly WorkgroupAccess $workgroupAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $this->workgroupAccess->canEnterPanel($user), 404);

        return $next($request);
    }
}

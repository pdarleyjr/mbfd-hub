<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Workgroups\WorkgroupAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGlobalWorkgroupAccess
{
    public function __construct(private readonly WorkgroupAccess $workgroupAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $this->workgroupAccess->isGlobalViewer($user), 404);

        return $next($request);
    }
}

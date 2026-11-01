<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DepartmentUpdate;
use App\Models\User;
use App\Services\Home\HomeActivity;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function __invoke(Request $request, ApplicationAccessRegistry $applications, HomeActivity $activity): View
    {
        $user = auth('web')->user();
        // Counted after the response is sent. The card is read-only data: if it cannot be read it is simply not drawn.
        $counted = $activity->recordVisit($request);
        $summary = $activity->summary(includingThisVisit: $counted);

        return view('welcome', [
            'departmentUpdates' => DepartmentUpdate::query()->forHomepage()->get(),
            'applicationStates' => $applications->states($user),
            'homeActivity' => $summary,
            'homeSignedIn' => $summary !== null && $user instanceof User && $activity->canSeeSignIns($user)
                ? $activity->signedInCount()
                : null,
        ]);
    }
}

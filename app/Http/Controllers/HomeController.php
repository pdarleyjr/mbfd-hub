<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DepartmentUpdate;
use App\Services\Home\HomeActivity;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function __invoke(Request $request, ApplicationAccessRegistry $applications, HomeActivity $activity): View
    {
        // Counted after the response is sent. Administrators read the numbers on the Admin Overview.
        $activity->recordVisit($request);

        return view('welcome', [
            'departmentUpdates' => DepartmentUpdate::query()->forHomepage()->get(),
            'applicationStates' => $applications->states(auth('web')->user()),
        ]);
    }
}

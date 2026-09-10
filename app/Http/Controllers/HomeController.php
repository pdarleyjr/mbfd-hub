<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DepartmentUpdate;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Contracts\View\View;

final class HomeController extends Controller
{
    public function __invoke(ApplicationAccessRegistry $applications): View
    {
        return view('welcome', [
            'departmentUpdates' => DepartmentUpdate::query()->forHomepage()->get(),
            'applicationStates' => $applications->states(auth('web')->user()),
        ]);
    }
}

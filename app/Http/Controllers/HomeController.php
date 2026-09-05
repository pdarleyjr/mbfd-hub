<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DepartmentUpdate;
use Illuminate\Contracts\View\View;

final class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome', [
            'departmentUpdates' => DepartmentUpdate::query()->forHomepage()->get(),
        ]);
    }
}

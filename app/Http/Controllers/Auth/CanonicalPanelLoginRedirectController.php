<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class CanonicalPanelLoginRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('login');
    }
}

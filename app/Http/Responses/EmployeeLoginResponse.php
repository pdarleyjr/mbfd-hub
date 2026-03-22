<?php

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;

class EmployeeLoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        return redirect()->route('filament.employee.pages.dashboard');
    }
}

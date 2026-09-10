<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class PasswordChangeRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Password change required.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'password_change_required',
        ], 403, ['Cache-Control' => 'no-store, private']);
    }
}

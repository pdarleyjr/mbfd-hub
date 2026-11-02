<?php

declare(strict_types=1);

use App\Http\Controllers\Training\CommandBoardSandboxController;
use App\Http\Middleware\CanonicalHostRedirect;
use Illuminate\Support\Facades\Route;

/*
| Training Tools hosted by the Hub (docs/training/command-board-sandbox.md).
|
| Command Board Sandbox: any signed-in Hub member who may sign in, the same audience as the Home Quick Access card
| that links here. It is not under /training, which is the Training Filament panel. There are no parameters and the
| query string is ignored; the page's own assets are static files under public/training-tools/command-board/assets.
*/
Route::get('/training-tools/command-board', CommandBoardSandboxController::class)
    ->middleware([CanonicalHostRedirect::class, 'auth:web'])
    ->name('training.command-board');

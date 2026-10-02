<?php

declare(strict_types=1);

use App\Http\Middleware\CanonicalHostRedirect;
use Illuminate\Support\Facades\Route;
use Mbfd\PolicyLibrary\Http\Controllers\AccessController;
use Mbfd\PolicyLibrary\Http\Controllers\AssetController;
use Mbfd\PolicyLibrary\Http\Controllers\CanonicalLoginBridge;
use Mbfd\PolicyLibrary\Http\Controllers\ViewerController;
use Mbfd\PolicyLibrary\Http\Controllers\ViewerErrorController;
use Mbfd\PolicyLibrary\Http\Middleware\EnsureLibraryAdmin;
use Mbfd\PolicyLibrary\Http\Middleware\LibraryAuthenticate;
use Mbfd\PolicyLibrary\Http\Middleware\LibrarySecurity;
use Mbfd\PolicyLibrary\Http\Middleware\ViewerGate;

Route::domain(config('policy-library.domain'))->middleware(['web', LibrarySecurity::class])->name('policy-library.')->group(function (): void {
    Route::get('/login', [CanonicalLoginBridge::class, 'show'])->withoutMiddleware(CanonicalHostRedirect::class)->name('login');
    Route::post('/login', [CanonicalLoginBridge::class, 'store'])->withoutMiddleware(CanonicalHostRedirect::class)->name('login.store');
    Route::middleware(LibraryAuthenticate::class)->group(function (): void {
        Route::get('/access', [AccessController::class, 'show'])->name('access');
        Route::post('/access', [AccessController::class, 'store'])->name('access.store');
        Route::middleware(ViewerGate::class)->group(function (): void {
            Route::get('/', [ViewerController::class, 'index'])->name('viewer');
            Route::get('/api/manuals', [ViewerController::class, 'manuals'])->name('manuals');
            Route::get('/api/search', [ViewerController::class, 'search'])->middleware('throttle:60,1')->name('search');
            Route::get('/api/manuals/{slug}/tree', [ViewerController::class, 'tree'])->name('tree');
            Route::get('/api/nodes/{node}/document', [ViewerController::class, 'document'])->name('document');
            Route::get('/assets/{uuid}', [AssetController::class, 'show'])->whereUuid('uuid')->name('asset');
            Route::post('/api/viewer-errors', [ViewerErrorController::class, 'store'])->middleware('throttle:12,1')->name('viewer-errors');
        });
        Route::get('/manage/revisions/{uuid}/preview', [AssetController::class, 'preview'])->whereUuid('uuid')->middleware(EnsureLibraryAdmin::class)->name('preview');
    });
});

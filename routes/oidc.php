<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/openid-configuration', [OidcController::class, 'discovery'])->name('oidc.discovery');
Route::get('/oauth/jwks', [OidcController::class, 'jwks'])->name('oidc.jwks');
Route::get('/oauth/authorize', [OidcController::class, 'authorize'])->middleware(['web', 'auth:web', 'throttle:30,1'])->name('oidc.authorize');
Route::post('/oauth/token', [OidcController::class, 'token'])->middleware('throttle:60,1')->name('oidc.token');
// Outer source-IP abuse ceiling; authenticated per-session limit is enforced
// only after cryptographic and canonical-identity validation in the controller.
Route::get('/oauth/userinfo', [OidcController::class, 'userinfo'])->middleware('throttle:6000,1')->name('oidc.userinfo');

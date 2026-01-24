<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/daily', function () {
    return response()->file(public_path('daily/index.html'));
});

Route::get('/daily/{any?}', function ($any = '') {
    $path = public_path("daily/$any");
    if (!empty($any) && str_contains($any, '.') && file_exists($path)) {
        return response()->file($path);
    }
    return response()->file(public_path('daily/index.html'));
})->where('any', '.*');


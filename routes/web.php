<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/daily', function () {
    return response()->file(public_path('daily/index.html'));
});

Route::get('/daily/{any}', function () {
    return response()->file(public_path('daily/index.html'));
})->where('any', '.*');


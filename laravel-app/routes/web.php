<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/daily', function () {
    return view('daily-checkout');
});

Route::get('/daily/{any}', function () {
    return view('daily-checkout');
})->where('any', '.*');

Route::get('/admin', function () {
    return redirect('/admin');
});

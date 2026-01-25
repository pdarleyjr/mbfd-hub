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
})->where('any', '(?!assets/).*');

Route::get('/__version', function() {
    $sha = cache()->remember('build_sha', 60, fn() => trim(shell_exec('git rev-parse HEAD') ?? 'unknown'));
    $branch = cache()->remember('build_branch', 60, fn() => trim(shell_exec('git rev-parse --abbrev-ref HEAD') ?? 'unknown'));
    $buildTime = cache()->remember('build_time', 60, fn() => now()->toIso8601String());
    
    return response()->json([
        'git_sha' => $sha,
        'branch' => $branch,
        'build_time' => $buildTime,
    ]);
});


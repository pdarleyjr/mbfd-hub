<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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

Route::get('/daily', function () {
    return response()->file(public_path('daily/index.html'));
});

Route::get('/daily/{any?}', function ($any = '') {
    // If the request is for a static file, serve it directly
    if (!empty($any)) {
        $path = public_path("daily/$any");
        
        // Check if it's a file request (has extension) and exists
        if (str_contains($any, '.') && file_exists($path) && is_file($path)) {
            // Get the file extension to determine MIME type
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            
            // Set appropriate MIME types
            $mimeTypes = [
                'js' => 'application/javascript',
                'css' => 'text/css',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                'webmanifest' => 'application/manifest+json',
                'map' => 'application/json',
            ];
            
            $mimeType = $mimeTypes[$extension] ?? mime_content_type($path);
            
            return response()->file($path, [
                'Content-Type' => $mimeType,
            ]);
        }
    }
    
    // For all other routes, serve the SPA entry point
    return response()->file(public_path('daily/index.html'));
})->where('any', '.*');


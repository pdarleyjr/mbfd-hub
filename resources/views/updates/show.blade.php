<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $departmentUpdate->title }} | MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-hub-canvas text-hub-ink antialiased">
    <x-hub-header :back-href="route('updates.index')" back-label="All updates" max-width="max-w-4xl" />
    <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        @include('updates._card', ['update' => $departmentUpdate, 'compact' => false])
    </main>
    @include('components.hub-support-widget')
</body>
</html>

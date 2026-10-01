<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Report an Issue · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header :back-href="route('hub-support.index')" back-label="My Reports" max-width="max-w-xl" />
    <main id="main" class="hub-page__main mx-auto max-w-xl px-4 py-8 sm:py-12">
        <h1 class="text-2xl font-bold">Report an Issue</h1>
        <form action="{{ route('hub-support.store') }}" method="post" enctype="multipart/form-data" class="mt-6 space-y-5 hub-panel p-5 shadow-sm sm:p-7">
            @csrf
            <input type="hidden" name="client_submission_id" value="{{ old('client_submission_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="page_path" value="{{ old('page_path', parse_url(url()->previous(), PHP_URL_PATH) ?: '/') }}">
            <div>
                <label for="description" class="block font-semibold">What went wrong?</label>
                <textarea id="description" name="description" required maxlength="10000" rows="7" @error('description') aria-invalid="true" aria-describedby="description-error" @enderror placeholder="Tell us what happened.&#10;&#10;Example: I tapped Submit but nothing happened." class="mt-2 w-full rounded-lg border border-hub-border-strong bg-hub-surface-muted p-3 text-base placeholder:text-hub-ink-secondary focus:border-hub-blue focus:outline-none focus:ring-2 focus:ring-hub-focus">{{ old('description') }}</textarea>
                @error('description') <p id="description-error" role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="attachments" class="font-semibold text-hub-blue">+ Add screenshot or file</label>
                <input id="attachments" type="file" name="attachments[]" accept="image/png,image/jpeg,application/pdf" multiple @if($errors->has('attachments') || $errors->has('attachments.*')) aria-invalid="true" aria-describedby="attachments-errors" @endif class="mt-2 block w-full text-sm">
                <div id="attachments-errors">
                    @error('attachments') <p role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @enderror
                    @foreach($errors->get('attachments.*') as $messages)
                        @foreach($messages as $message) <p role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @endforeach
                    @endforeach
                </div>
            </div>
            <p class="text-sm text-hub-muted">We'll automatically include the page and available app information that may help us find the problem.</p>
            <div class="flex justify-end gap-3">
                <a href="{{ \App\Support\HubNavigation::backUrl(route('hub-support.index')) }}" class="inline-flex min-h-11 items-center rounded-lg px-4 py-3 font-semibold text-hub-muted hover:bg-hub-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus">Cancel</a>
                <button type="submit" class="min-h-11 rounded-lg bg-hub-blue px-5 py-3 font-semibold text-white hover:bg-hub-blue-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-hub-focus focus-visible:ring-offset-2">Send</button>
            </div>
        </form>
    </main>
</body>
</html>

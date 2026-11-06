<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Report an Issue · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" class="hub-page min-h-screen bg-hub-canvas text-hub-ink">
    <a href="#main" class="hub-skip-link">Skip to content</a>
    <x-hub-header :back-href="route('hub-support.index')" back-label="My Reports" max-width="max-w-xl" />
    <main id="main" class="hub-page__main mx-auto max-w-xl px-4 py-6 sm:px-6 sm:py-8">
        <x-hub.page-header title="Report an Issue" class="!mb-4" />
        <form action="{{ route('hub-support.store') }}" method="post" enctype="multipart/form-data" class="space-y-5 hub-panel p-4 sm:p-6 [&>div:first-of-type]:!mt-0">
            @csrf
            <input type="hidden" name="client_submission_id" value="{{ old('client_submission_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="page_path" value="{{ old('page_path', parse_url(url()->previous(), PHP_URL_PATH) ?: '/') }}">
            <div>
                <label for="description" class="block font-semibold">What went wrong?</label>
                <textarea id="description" name="description" required maxlength="10000" rows="7" @error('description') aria-invalid="true" aria-describedby="description-error" @enderror placeholder="Tell us what happened.&#10;&#10;Example: I tapped Submit but nothing happened." class="mt-2 w-full rounded-lg border border-hub-border-strong bg-hub-surface-muted p-3 text-base placeholder:text-hub-ink-secondary focus:border-hub-blue focus:outline-none focus:ring-2 focus:ring-hub-focus">{{ old('description') }}</textarea>
                @error('description') <p id="description-error" role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="attachments" class="font-semibold">+ Add screenshot or file</label> <span class="text-sm text-hub-ink-secondary">(optional)</span>
                <input id="attachments" type="file" name="attachments[]" accept="image/png,image/jpeg,application/pdf" multiple @if($errors->has('attachments') || $errors->has('attachments.*')) aria-invalid="true" aria-describedby="attachments-errors" @endif class="mt-2 block w-full text-sm text-hub-ink-secondary file:mr-3 file:min-h-11 file:cursor-pointer file:rounded-lg file:border file:border-solid file:border-hub-border-strong file:bg-white file:px-4 file:text-sm file:font-semibold file:text-hub-ink hover:file:bg-hub-surface-muted">
                <div id="attachments-errors">
                    @error('attachments') <p role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @enderror
                    @foreach($errors->get('attachments.*') as $messages)
                        @foreach($messages as $message) <p role="alert" class="text-sm text-hub-danger">{{ $message }}</p> @endforeach
                    @endforeach
                </div>
            </div>
            <p class="text-sm text-hub-ink-secondary">We'll automatically include the page and available app information that may help us find the problem.</p>
            <div class="flex justify-end gap-2 border-t border-hub-border-soft pt-4">
                <a href="{{ \App\Support\HubNavigation::backUrl(route('hub-support.index')) }}" class="hub-btn hub-btn--tertiary">Cancel</a>
                <button type="submit" class="hub-btn hub-btn--primary min-w-28">Send</button>
            </div>
        </form>
    </main>
</body>
</html>

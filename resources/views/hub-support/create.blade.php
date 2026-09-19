<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Report an Issue · MBFD Hub</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900">
    <main class="mx-auto max-w-xl px-4 py-8 sm:py-14">
        <a href="{{ url()->previous() }}" class="text-sm font-semibold text-red-700">← Back to MBFD Hub</a>
        <h1 class="mt-6 text-2xl font-bold">Report an Issue</h1>
        <form action="{{ route('hub-support.store') }}" method="post" enctype="multipart/form-data" class="mt-6 space-y-5 rounded-xl border border-neutral-200 bg-white p-5 sm:p-7">
            @csrf
            <input type="hidden" name="client_submission_id" value="{{ old('client_submission_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="page_path" value="{{ old('page_path', parse_url(url()->previous(), PHP_URL_PATH) ?: '/') }}">
            <div>
                <label for="description" class="block font-semibold">What went wrong?</label>
                <textarea id="description" name="description" required maxlength="10000" rows="7" placeholder="Tell us what happened.&#10;&#10;Example: I tapped Submit but nothing happened." class="mt-2 w-full rounded-lg border border-neutral-300 bg-neutral-50 p-3 text-base">{{ old('description') }}</textarea>
                @error('description') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="attachments" class="font-semibold text-red-700">+ Add screenshot or file</label>
                <input id="attachments" type="file" name="attachments[]" accept="image/png,image/jpeg,application/pdf" multiple class="mt-2 block w-full text-sm">
                @error('attachments') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <p class="text-sm text-neutral-600">We’ll automatically include the page you’re on and safe technical details that may help us find the problem.</p>
            <div class="flex justify-end gap-3">
                <a href="{{ url()->previous() }}" class="rounded-lg px-4 py-3">Cancel</a>
                <button type="submit" class="rounded-lg bg-red-700 px-5 py-3 font-semibold text-white">Send</button>
            </div>
        </form>
    </main>
</body>
</html>

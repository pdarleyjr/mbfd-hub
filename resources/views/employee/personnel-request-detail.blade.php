<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $request->request_number }} · MBFD Employee Portal</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" data-hub-portal="employee" class="min-h-screen bg-white text-hub-ink">
    <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:py-10">
        <x-hub-header back-href="/employee/my-requests" back-label="Back to My Requests" />

        <article class="mt-5 overflow-hidden rounded-md border border-hub-border bg-white">
            <header class="border-b border-hub-border bg-white px-5 py-5 sm:px-7">
                <p class="hub-portal-eyebrow">{{ $request->type->label() }}</p>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-2xl font-bold text-hub-ink">{{ $request->request_number }}</h1>
                    <span class="hub-tag hub-tag--info">{{ $request->status->label() }}</span>
                </div>
                <p class="mt-2 text-sm text-hub-ink-secondary">Submitted by {{ $request->requester_rank }} {{ $request->requester_name }} on {{ $request->created_at->format('M j, Y \a\t g:i A') }}</p>
            </header>

            <div class="grid gap-7 px-5 py-6 sm:px-7 lg:grid-cols-[1fr_0.8fr]">
                <section>
                    <h2 class="text-lg font-semibold">Requested items</h2>
                    <div class="mt-3 divide-y divide-hub-border rounded-xl border border-hub-border">
                        @foreach($request->items as $item)
                            <div class="p-4">
                                <p class="font-bold">{{ $item->item_name }} <span class="font-normal text-hub-ink-secondary">× {{ $item->quantity }}</span></p>
                                <p class="mt-1 text-sm text-hub-ink-secondary">
                                    @if($item->size) Size {{ $item->size }} @endif
                                    @if($item->reason) · Reason: {{ str($item->reason)->title() }} @endif
                                </p>
                            </div>
                        @endforeach
                    </div>

                    @if(filled(data_get($request->metadata, 'member_note')))
                        <div class="mt-6 rounded-xl border border-hub-border p-4">
                            <h2 class="font-semibold">Your notes for Support Services</h2>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-hub-ink-secondary">{{ data_get($request->metadata, 'member_note') }}</p>
                        </div>
                    @endif

                    @if(in_array($request->status, [\App\Enums\PersonnelRequestStatus::NeedsInformation, \App\Enums\PersonnelRequestStatus::Acknowledged], true) && filled($request->information_requested))
                        <div class="mt-6 rounded-xl border-2 border-amber-300 bg-amber-50 p-5">
                            <h2 class="font-bold text-amber-950">{{ $request->status === \App\Enums\PersonnelRequestStatus::NeedsInformation ? 'Information needed' : 'Requested information received' }}</h2>
                            <p class="mt-2 text-sm leading-6 text-amber-950">{{ $request->employee_response }}</p>
                            <form method="post" action="{{ route('employee.personnel-requests.respond', $request) }}" class="mt-4 space-y-3">
                                @csrf
                                <label class="block font-semibold" for="response">Your response</label>
                                <textarea id="response" name="response" rows="4" required maxlength="4000" class="w-full rounded-xl border-hub-border" placeholder="Provide the requested explanation, case number, or other details.">{{ old('response') }}</textarea>
                                @error('response') <p class="text-sm font-semibold text-red-700">{{ $message }}</p> @enderror
                                <button class="min-h-12 rounded-xl bg-hub-blue px-5 font-bold text-white hover:bg-hub-blue-strong focus:outline-none focus:ring-2 focus:ring-hub-focus focus:ring-offset-2">{{ $request->status === \App\Enums\PersonnelRequestStatus::NeedsInformation ? 'Send response' : 'Send additional response' }}</button>
                            </form>
                            @if(collect($request->information_requested)->intersect(['police_report', 'damage_photo', 'other'])->isNotEmpty())
                                <form method="post" action="{{ route('employee.personnel-requests.attachments.store', $request) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                                    @csrf
                                    <label class="block font-semibold" for="document_type">Document type</label>
                                    <select id="document_type" name="document_type" class="min-h-12 w-full rounded-xl border-hub-border">
                                        @foreach($request->information_requested as $type)
                                            <option value="{{ $type }}">{{ str($type)->replace('_', ' ')->title() }}</option>
                                        @endforeach
                                    </select>
                                    <label class="block font-semibold" for="attachment">PDF, JPEG, or PNG (maximum 10 MB)</label>
                                    <input id="attachment" name="attachment" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="block min-h-12 w-full rounded-xl border border-hub-border bg-white p-3">
                                    @error('attachment') <p class="text-sm font-semibold text-red-700">{{ $message }}</p> @enderror
                                    <button class="min-h-12 rounded-xl bg-hub-blue px-5 font-bold text-white hover:bg-hub-blue-strong focus:outline-none focus:ring-2 focus:ring-hub-focus focus:ring-offset-2">Upload securely</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </section>

                <section>
                    <h2 class="text-lg font-semibold">Request history</h2>
                    <ol class="mt-4 border-l-2 border-blue-200 pl-5">
                        @foreach($request->updates as $update)
                            <li class="relative pb-6 before:absolute before:-left-[1.62rem] before:top-1 before:h-3 before:w-3 before:rounded-full before:bg-hub-blue">
                                <p class="font-bold">{{ $update->status->label() }}</p>
                                @if($update->employee_visible_note)<p class="mt-1 text-sm leading-6 text-hub-ink-secondary">{{ $update->employee_visible_note }}</p>@endif
                                <time class="mt-1 block text-xs text-hub-ink-secondary">{{ $update->created_at->format('M j, Y · g:i A') }}</time>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        </article>
    </main>
    @include('components.hub-support-widget')
</body>
</html>

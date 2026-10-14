<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <x-hub.head />
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $request->request_number }} · MBFD Employee Portal</title>
    @vite('resources/css/app.css')
</head>
<body data-hub-ui="2" data-hub-portal="employee" class="min-h-screen bg-hub-canvas text-hub-ink">
    <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:py-10">
        <x-hub-header back-href="/employee/my-requests" back-label="Back to My Requests" />
        @if(session('status'))
            <p class="hub-well mt-4 p-3 text-sm" role="status">{{ session('status') }}</p>
        @endif

        <article class="hub-panel mt-5 overflow-hidden">
            <header class="hub-panel__header-block px-5 py-5 sm:px-7">
                <p class="hub-portal-eyebrow">{{ $request->type->label() }}</p>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-2xl font-bold text-hub-ink">{{ $request->request_number }}</h1>
                    <span class="{{ $request->status->tagClass() }}" data-request-status>{{ $request->status->label() }}</span>
                </div>
                <p class="mt-2 text-sm text-hub-ink-secondary">Submitted by {{ $request->requester_rank }} {{ $request->requester_name }} on {{ $request->created_at->format('M j, Y \a\t g:i A') }}</p>
            </header>

            <div class="grid gap-7 px-5 py-6 sm:px-7 lg:grid-cols-[1fr_0.8fr]">
                <section>
                    <h2 class="text-lg font-semibold">Requested items</h2>
                    <div class="hub-well mt-3 divide-y divide-hub-border">
                        @foreach($request->items as $item)
                            @php
                                $arrived = (int) $item->arrived_quantity;
                                $issued = (int) $item->fulfilled_quantity;
                                $itemMessages = $request->updates->filter(fn ($update) => (int) data_get($update->metadata, 'item_id') === $item->id && filled($update->employee_visible_note));
                            @endphp
                            <div class="p-4" id="item-{{ $item->id }}" data-request-item="{{ $item->id }}">
                                <p class="font-bold">{{ $item->item_name }} <span class="font-normal text-hub-ink-secondary">× {{ $item->quantity }}</span></p>
                                <p class="mt-1 text-sm text-hub-ink-secondary">
                                    @if($item->size) Size {{ $item->size }} @endif
                                    @if($item->reason) · Reason: {{ str($item->reason)->title() }} @endif
                                </p>
                                <p class="mt-2"><span class="{{ $item->memberProgressTagClass() }}">{{ $item->memberProgressLabel() }}</span></p>
                                <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                                    <div><dt class="text-hub-ink-secondary">Requested</dt><dd class="font-semibold">{{ $item->quantity }}</dd></div>
                                    <div><dt class="text-hub-ink-secondary">Arrived</dt><dd class="font-semibold" data-arrived-quantity>{{ $arrived }}</dd></div>
                                    <div><dt class="text-hub-ink-secondary">Issued</dt><dd class="font-semibold" data-issued-quantity>{{ $issued }}</dd></div>
                                </dl>
                                @if($itemMessages->isNotEmpty())
                                    <details class="mt-2">
                                        <summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold text-hub-blue">Item updates ({{ $itemMessages->count() }})</summary>
                                        @foreach($itemMessages as $itemMessage)
                                            <p class="mt-2 whitespace-pre-wrap text-sm text-hub-ink-secondary">{{ $itemMessage->employee_visible_note }}</p>
                                            <time class="text-xs text-hub-ink-secondary">{{ $itemMessage->created_at->format('M j, Y · g:i A') }}</time>
                                        @endforeach
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if(filled(data_get($request->metadata, 'member_note')))
                        <div class="hub-well mt-6 p-4">
                            <h2 class="font-semibold">Your notes for Support Services</h2>
                            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-hub-ink-secondary">{{ data_get($request->metadata, 'member_note') }}</p>
                        </div>
                    @endif

                    @if(!$request->isArchived() && in_array($request->status, [\App\Enums\PersonnelRequestStatus::NeedsInformation, \App\Enums\PersonnelRequestStatus::Acknowledged], true) && filled($request->information_requested))
                        <div class="hub-callout hub-callout--warning mt-6 p-5">
                            <h2 class="font-bold">{{ $request->status === \App\Enums\PersonnelRequestStatus::NeedsInformation ? 'Information needed' : 'Requested information received' }}</h2>
                            <p class="mt-2 text-sm leading-6">{{ $request->employee_response }}</p>
                            @if(collect($request->information_requested)->intersect(['police_report', 'damage_photo', 'other'])->isNotEmpty())
                                <form method="post" action="{{ route('employee.personnel-requests.attachments.store', $request) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                                    @csrf
                                    <label class="block font-semibold" for="document_type">Document type</label>
                                    <select id="document_type" name="document_type" class="min-h-12 w-full rounded-lg border-hub-border bg-hub-surface">
                                        @foreach($request->information_requested as $type)
                                            <option value="{{ $type }}">{{ str($type)->replace('_', ' ')->title() }}</option>
                                        @endforeach
                                    </select>
                                    <label class="block font-semibold" for="attachment">PDF, JPEG, or PNG (maximum 10 MB)</label>
                                    <input id="attachment" name="attachment" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="block min-h-12 w-full rounded-lg border border-hub-border bg-hub-surface p-3" @error('attachment') aria-invalid="true" aria-describedby="attachment-error" @enderror>
                                    @error('attachment') <p id="attachment-error" class="text-sm font-semibold text-hub-danger">{{ $message }}</p> @enderror
                                    <button class="hub-btn hub-btn--primary hub-btn--field">Upload securely</button>
                                </form>
                            @endif
                        </div>
                    @endif
                    @if(!$request->isArchived() && !$request->status->isTerminal())
                        <form method="post" action="{{ route('employee.personnel-requests.respond', $request) }}" class="mt-6 space-y-3" id="member-message">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                            <h2 class="font-semibold">Message Support Services</h2>
                            <label class="block text-sm font-semibold" for="message-item">About</label>
                            <select id="message-item" name="item_id" class="min-h-12 w-full rounded-lg border-hub-border bg-hub-surface" @error('item_id') aria-invalid="true" aria-describedby="message-item-error" @enderror>
                                <option value="">Entire order</option>
                                @foreach($request->items as $messageItem)
                                    <option value="{{ $messageItem->id }}" @selected((string) old('item_id') === (string) $messageItem->id)>{{ $messageItem->item_name }}</option>
                                @endforeach
                            </select>
                            @error('item_id') <p id="message-item-error" class="text-sm font-semibold text-hub-danger">{{ $message }}</p> @enderror
                            <label class="block text-sm font-semibold" for="response">Your response</label>
                            <textarea id="response" name="response" rows="3" required maxlength="4000" class="w-full rounded-lg border-hub-border bg-hub-surface" @error('response') aria-invalid="true" aria-describedby="response-error" @enderror>{{ old('response') }}</textarea>
                            @error('response') <p id="response-error" class="text-sm font-semibold text-hub-danger">{{ $message }}</p> @enderror
                            @error('idempotency_key') <p class="text-sm font-semibold text-hub-danger">{{ $message }}</p> @enderror
                            <button class="hub-btn hub-btn--primary hub-btn--field">{{ $request->status === \App\Enums\PersonnelRequestStatus::NeedsInformation ? 'Send response' : 'Send message' }}</button>
                        </form>
                    @endif
                </section>

                <section>
                    <h2 class="text-lg font-semibold">Request history</h2>
                    <ol class="hub-timeline mt-4 pl-5">
                        @foreach($request->updates as $update)
                            @continue($update->event === 'note_added' && !filled($update->employee_visible_note))
                            @continue($update->event === 'items_fulfilled' && !filled($update->employee_visible_note))
                            <li class="relative pb-6 before:absolute before:-left-[1.62rem] before:top-1 before:h-3 before:w-3 before:rounded-full before:bg-hub-blue">
                                @php $historyItem = $request->items->firstWhere('id', (int) data_get($update->metadata, 'item_id')); @endphp
                                <p class="font-bold">{{ $historyItem ? str($update->event)->replace('_', ' ')->title() : $update->status->label() }}</p>
                                @if($historyItem)<p class="text-sm font-semibold text-hub-ink-secondary">{{ $historyItem->item_name }}</p>@endif
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

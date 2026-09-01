<x-filament-panels::page>
    {{-- Identity bar --}}
    <div class="ep-id-bar">
        <div class="ep-id-left">
            <span class="ep-id-name">{{ $user->name }}</span>
            <span class="ep-id-sep">·</span>
            <span class="ep-id-rank">{{ $user->rank ?? '' }}</span>
        </div>
        <div class="ep-id-right">
            <span class="ep-id-label">Employee ID</span>
            <span class="ep-id-number">{{ $user->employee_id ?? 'Not assigned' }}</span>
        </div>
    </div>

    {{-- Open Bid Console CTA --}}
    @if(!empty($bidConsoleUrl))
        <div class="mt-6 rounded-xl border border-red-600 bg-gradient-to-br from-red-50 to-white p-5 dark:from-red-950 dark:to-slate-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-red-800 dark:text-red-200">Open Bid Console</h2>
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                        Continue with your canonical MBFD Hub session. The Bid console does not receive your Hub password.
                        When it&rsquo;s your turn the console shows your eligible positions and the AI advisory.
                    </p>
                </div>
                <a
                    href="{{ $bidConsoleUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex h-10 items-center rounded-md bg-red-700 px-4 text-sm font-semibold text-white shadow hover:bg-red-600"
                >
                    Open Bid Console →
                </a>
            </div>
        </div>
    @endif

    {{-- Certifications panel --}}
    <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-baseline justify-between">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                Certifications on file
            </h2>
            @if(!empty($lastUpdated))
                <span class="text-xs text-slate-500">Synced {{ $lastUpdated }}</span>
            @endif
        </div>

        @if($fetchError)
            <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ $fetchError }}</p>
        @elseif(empty($certifications))
            <div class="mt-4 rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-400">
                No certifications are on file in the bid system for this employee ID yet.
                If something is missing, ask a bid administrator to update your record.
            </div>
        @else
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                These are read-only. Bid administrators maintain this list — if something is wrong, ask an admin to fix it.
            </p>
            <ul class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($certifications as $cert)
                    <li class="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <span aria-hidden class="mt-0.5 text-green-600">✓</span>
                        <span class="text-slate-800 dark:text-slate-100">{{ $cert }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>

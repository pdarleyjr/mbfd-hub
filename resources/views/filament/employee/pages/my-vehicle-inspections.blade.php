<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <x-filament::section>
        <p class="text-sm text-gray-600">
            Daily Checkouts you submitted, newest first. This is a read-only record: use View for the full checklist and
            follow-up status, or PDF for a printable copy. Review status shows whether an officer has approved the
            submission. Inspections still queued on a device appear here after that device syncs.
        </p>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>

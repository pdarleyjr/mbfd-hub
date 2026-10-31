<x-filament-panels::page>
    {{-- Compose Email moved into the Email Center. mount() redirects; this shows only if the redirect is blocked. --}}
    <p>Writing email now happens in the Email Center.</p>
    <p><a class="underline" href="{{ \App\Filament\Admin\Pages\EmailCenter::getUrl() }}">Open the Email Center</a></p>
</x-filament-panels::page>

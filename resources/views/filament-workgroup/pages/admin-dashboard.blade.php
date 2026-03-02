{{-- Admin Dashboard View --}}
<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats Overview --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($this->getWidgets() as $widget)
                @if($widget instanceof \App\Filament\Workgroup\Widgets\WorkgroupAdminStatsWidget)
                    {{ \Filament\Support\Facades\FilamentView::renderWidget($widget) }}
                @endif
            @endforeach
        </div>

        {{-- Session Progress --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($this->getWidgets() as $widget)
                @if($widget instanceof \App\Filament\Workgroup\Widgets\SessionProgressWidget)
                    {{ \Filament\Support\Facades\FilamentView::renderWidget($widget) }}
                @endif
            @endforeach
        </div>

        {{-- Quick Links --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-filament::card>
                <x-filament::card.heading>
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-trophy class="w-5 h-5 text-warning" />
                        <span>View Results</span>
                    </div>
                </x-filament::card.heading>
                <x-filament::card.content>
                    <p class="text-sm text-gray-600 mb-4">
                        View detailed rankings, finalists, and export evaluation results.
                    </p>
                    <x-filament::button
                        :href="\App\Filament\Workgroup\Pages\SessionResultsPage::getUrl()"
                        color="primary"
                    >
                        Go to Results
                    </x-filament::button>
                </x-filament::card.content>
            </x-filament::card>

            <x-filament::card>
                <x-filament::card.heading>
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-users class="w-5 h-5 text-primary" />
                        <span>Manage Sessions</span>
                    </div>
                </x-filament::card.heading>
                <x-filament::card.content>
                    <p class="text-sm text-gray-600 mb-4">
                        Create and manage evaluation sessions, add products, and configure categories.
                    </p>
                    <x-filament::button
                        :href="route('filament.workgroups.resources.workgroup-sessions.index')"
                        color="gray"
                    >
                        Manage Sessions
                    </x-filament::button>
                </x-filament::card.content>
            </x-filament::card>
        </div>
    </div>
</x-filament-panels::page>

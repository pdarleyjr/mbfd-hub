<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Profile Information</x-slot>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-500">Name</p>
                    <p class="font-medium">{{ auth()->user()->name }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Email</p>
                    <p class="font-medium">{{ auth()->user()->email }}</p>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
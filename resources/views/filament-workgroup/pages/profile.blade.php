<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    <x-filament::section>
        <x-slot name="heading">Profile Information</x-slot>
        <dl class="wg-context">
            <div class="wg-context__item">
                <dt>Name</dt>
                <dd><span class="wg-context__value">{{ auth()->user()->name }}</span></dd>
            </div>
            <div class="wg-context__item">
                <dt>Email</dt>
                <dd><span class="wg-context__value">{{ auth()->user()->email }}</span></dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>

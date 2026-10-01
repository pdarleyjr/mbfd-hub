<x-filament-panels::page data-hub-ui="2" data-hub-portal="workgroups">
    <form wire:submit.prevent="submitEvaluation">
        {{ $this->form }}

        @if(!$isReadOnly)
            <div class="wg-evaluation-actions mt-6 flex flex-wrap gap-3">
                <x-filament::button wire:click="setAllHighest" color="warning" type="button">
                    Set All to Highest
                </x-filament::button>
                <x-filament::button wire:click="saveDraft" color="gray" type="button">
                    Save as Draft
                </x-filament::button>
                <x-filament::button wire:click="submitEvaluation" color="success" type="button">
                    Submit Evaluation
                </x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>

"<x-filament-panels::page>
    @php
        $form = $this->form;
    @endphp

    <div class="fi-simple-page">
        <div class="fi-simple-page-content">
            <div class="fi-simple-page-content-frame">
                <header class="fi-simple-page-header">
                    <h1 class="fi-simple-page-title">
                        Evaluate Product
                    </h1>
                    @if($product)
                        <p class="fi-simple-page-subtitle">
                            {{ $product->category->name ?? '' }} - {{ $product->session->name ?? '' }}
                        </p>
                    @endif
                </header>

                @if($isReadOnly)
                    <div class="fi-notification fi-notification-success mb-4">
                        <div class="fi-notification-content">
                            <p class="fi-notification-title">Evaluation Submitted</p>
                            <p class="fi-notification-description">This evaluation has already been submitted and cannot be modified.</p>
                        </div>
                    </div>
                @endif

                <form wire:submit.prevent="submitEvaluation">
                    {{ $this->form->fields() }}

                    @if(!$isReadOnly)
                        <div class="fi-form-actions mt-6">
                            <x-filament::button wire:click="saveDraft" color="gray">
                                Save as Draft
                            </x-filament::button>

                            <x-filament::button wire:click="submitEvaluation" color="success" :disabled="!$canSubmit">
                                Submit Evaluation
                            </x-filament::button>
                        </div>
                    @endif
                </form>
            </div>
        </div>
    </div>
</x-filament-panels::page>" 

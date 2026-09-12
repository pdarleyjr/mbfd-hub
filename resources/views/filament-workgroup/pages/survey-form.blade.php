<x-filament-panels::page>
    @if ($survey)
        <div class="max-w-4xl space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h1 class="text-xl font-semibold text-gray-950">{{ $survey->title }}</h1>
                <p class="mt-2 text-sm text-gray-700">{{ $survey->description }}</p>
                @if ($survey->is_anonymous)
                    <p class="mt-3 rounded-md bg-blue-50 p-3 text-sm text-blue-900">Responses are de-identified in reports. Completion may be tracked to enforce one response, but answers are not shown with your identity. Demographics are optional and only reported for sufficiently large groups.</p>
                @endif
                <p class="mt-3 text-sm font-medium text-gray-700" aria-live="polite">Progress: {{ $this->answeredQuestionCount() }} / {{ $this->questionCount() }} questions answered</p>
            </section>

            @foreach ($survey->questions as $question)
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" wire:key="survey-question-{{ $question->id }}">
                    <h2 class="font-semibold text-gray-950">{{ $question->position }}. {{ $question->prompt }}</h2>
                    @if ($question->help_text)<p class="mt-1 text-sm text-gray-600">{{ $question->help_text }}</p>@endif
                    <div class="mt-4 space-y-3" @if($submitted) aria-disabled="true" @endif>
                        @if ($question->type === 'single')
                            @foreach ($question->configuration['options'] as $option)
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 text-sm"><input type="radio" wire:model="answers.{{ $question->id }}" value="{{ $option['key'] }}" @disabled($submitted)><span>{{ $option['label'] }}</span></label>
                            @endforeach
                        @elseif ($question->type === 'multi')
                            @php($allowsOther = (bool) ($question->configuration['allow_other_text'] ?? false))
                            @php($exclusive = $question->configuration['exclusive_option'] ?? null)
                            @if($allowsOther)
                                <div x-data="{ answer: $wire.entangle('answers.{{ $question->id }}').live, maximum: {{ $question->configuration['max_selections'] }} }">
                                    <p class="mb-2 text-sm text-gray-600" x-text="`${(answer?.selections || []).length} / {{ $question->configuration['max_selections'] }} selected`"></p>
                                    <p class="mb-2 text-sm text-amber-700" x-show="(answer?.selections || []).length >= maximum">Maximum selected. Clear one choice to select another.</p>
                                    @foreach ($question->configuration['options'] as $option)
                                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 text-sm"><input type="checkbox" x-model="answer.selections" value="{{ $option['key'] }}" x-bind:disabled="{{ $submitted ? 'true' : 'false' }} || (!(answer?.selections || []).includes('{{ $option['key'] }}') && (answer?.selections || []).length >= maximum)"><span>{{ $option['label'] }}</span></label>
                                    @endforeach
                                    @php($other = collect($question->configuration['options'])->firstWhere('label', 'Other'))
                                    @if($other)
                                        <label class="mt-3 block text-sm font-medium">Other explanation<textarea x-model="answer.other_text" class="mt-1 block w-full rounded-md border-gray-300" rows="3" maxlength="1000" @disabled($submitted) x-bind:disabled="!(answer?.selections || []).includes('{{ $other['key'] }}')"></textarea></label>
                                    @endif
                                </div>
                            @else
                                <div x-data="{ selections: $wire.entangle('answers.{{ $question->id }}').live }">
                                    @if(is_int($question->configuration['max_selections'] ?? null))<p class="mb-2 text-sm text-gray-600" x-text="`${(selections || []).length} / {{ $question->configuration['max_selections'] }} selected`"></p>@endif
                                    @foreach ($question->configuration['options'] as $option)
                                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 text-sm"><input type="checkbox" x-model="selections" value="{{ $option['key'] }}" @change="if ($event.target.checked && '{{ $exclusive }}') { selections = '{{ $option['key'] }}' === '{{ $exclusive }}' ? ['{{ $exclusive }}'] : selections.filter((value) => value !== '{{ $exclusive }}') }" @disabled($submitted)><span>{{ $option['label'] }}</span></label>
                                    @endforeach
                                </div>
                            @endif
                        @elseif ($question->type === 'matrix')
                            @foreach ($question->configuration['rows'] as $row)
                                <div class="rounded-lg border border-gray-200 p-3"><p class="mb-2 text-sm font-medium">{{ $row['label'] }}</p><div class="grid gap-2 sm:grid-cols-3">@foreach($question->configuration['options'] as $option)<label class="flex gap-2 text-sm"><input type="radio" wire:model="answers.{{ $question->id }}.{{ $row['key'] }}" value="{{ $option['key'] }}" @disabled($submitted)>{{ $option['label'] }}</label>@endforeach</div></div>
                            @endforeach
                        @elseif ($question->type === 'compound')
                            @foreach ($question->configuration['parts'] as $part)
                                <div class="rounded-lg border border-gray-200 p-3"><p class="mb-2 text-sm font-medium">{{ $part['label'] }}</p>@foreach($part['options'] as $option)<label class="mr-4 inline-flex gap-2 text-sm"><input type="radio" wire:model="answers.{{ $question->id }}.{{ $part['key'] }}" value="{{ $option['key'] }}" @disabled($submitted)>{{ $option['label'] }}</label>@endforeach</div>
                            @endforeach
                        @endif
                    </div>
                    @error('answers.'.$question->id)<p class="mt-2 text-sm text-danger-600">{{ $message }}</p>@enderror
                    @foreach($errors->get('answers.'.$question->id.'.*') as $message)<p class="mt-2 text-sm text-danger-600">{{ $message }}</p>@endforeach
                </section>
            @endforeach

            @if (count($survey->demographic_fields ?? []) > 0)
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><h2 class="font-semibold">Optional demographics</h2><div class="mt-3 grid gap-3 sm:grid-cols-3">@foreach($survey->demographic_fields as $field)<label class="text-sm font-medium">{{ $field['label'] }}<select wire:model="demographics.{{ $field['key'] }}" class="mt-1 block w-full rounded-md border-gray-300" @disabled($submitted)><option value="">Prefer not to answer</option>@foreach($field['options'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>@error('demographics.'.$field['key'])<span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>@enderror</label>@endforeach</div></section>
            @endif

            @unless($submitted)
                <div class="flex justify-end gap-3"><x-filament::button color="gray" wire:click="saveDraft">Save draft</x-filament::button><x-filament::button x-on:click.prevent="if (confirm('Review your answers before submitting. Submitted responses cannot be changed. Submit now?')) { $wire.submit() }">Submit survey</x-filament::button></div>
            @endunless
        </div>
    @endif
</x-filament-panels::page>

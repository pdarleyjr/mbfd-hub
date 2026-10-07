<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <div class="uo-builder" x-init="window.matchMedia('(max-width: 1023px)').matches && $store.sidebar.close(); $nextTick(() => observeCatalog())" x-on:resize.window.debounce.100ms="observeCatalog()" x-data="{
        mobileActionVisible: false,
        catalogObserver: null,
        observeCatalog() {
            this.catalogObserver?.disconnect();
            const nav = this.$refs.categoryNav;
            const top = Number.parseFloat(getComputedStyle(nav).top) || 0;
            this.catalogObserver = new IntersectionObserver(([entry]) => {
                this.mobileActionVisible = entry.boundingClientRect.top <= top;
            }, { rootMargin: '-' + top + 'px 0px 0px' });
            this.catalogObserver.observe(this.$refs.catalogStart);
        },
        destroy() { this.catalogObserver?.disconnect(); },
        imageTrigger: null,
        openImage(src, label, trigger) {
            this.imageTrigger = trigger;
            this.$refs.largeImage.src = src;
            this.$refs.largeImage.alt = label;
            this.$refs.imageTitle.textContent = label;
            this.$refs.viewer.showModal();
        },
        closeImage() { this.$refs.viewer.close(); },
        restoreImageFocus() { this.imageTrigger?.focus(); }
    }">
        @if($submittedRequestNumber)
            <div class="uo-success" role="status" data-order-success>
                <x-heroicon-o-check-circle aria-hidden="true" />
                <div><strong>Uniform request submitted · {{ $submittedRequestNumber }}</strong>
                    <p>Support Services can now review your order. <a href="/employee/my-requests/{{ $submittedRequestId }}">View your request</a></p>
                </div>
            </div>
        @endif

        <section class="uo-assignment" aria-labelledby="uo-assignment-title" data-entitlement-profile="{{ $context['profile'] }}" data-marine="{{ $context['marine'] ? 'true' : 'false' }}">
            <div class="uo-assignment-identity">
                <p class="uo-eyebrow">{{ $context['term_label'] ? $context['term_label'].' Assignment' : 'Your assignment' }}</p>
                <h2 id="uo-assignment-title">{{ $context['assignment_label'] }}</h2>
                <p>{{ collect([
                    data_get($context, 'assignment_snapshot.position_label'),
                    data_get($context, 'assignment_snapshot.shift_label'),
                    data_get($context, 'assignment_snapshot.station_label'),
                ])->filter()->implode(' · ') ?: $member->rank }}</p>
                <span class="uo-chip">{{ $context['profile_label'] }}</span>
                @if($context['marine']) <span class="uo-chip">Marine allocation added</span> @endif
            </div>
            <div class="uo-assignment-rules">
                @if($context['profile'] === 'day_other')
                    <h3>Day / Administrative Uniform Rule</h3>
                    <p>{{ $context['message'] }}</p>
                @else
                    <h3>Your standard annual allocation</h3>
                    <ul class="uo-allowance-list">
                        <li><strong>{{ $context['allowances']['dress_shirts'] }}</strong> Dress uniform</li>
                        <li><strong>{{ $context['allowances']['polos'] }}</strong> Work uniform sets</li>
                        <li><strong>{{ $context['allowances']['jumpsuits'] }}</strong> Jumpsuits</li>
                        <li><strong>{{ $context['allowances']['belts'] }}</strong> Work belt</li>
                        <li><strong>{{ $context['allowances']['tshirts'] }}</strong> T-shirts</li>
                        <li><strong>{{ $context['allowances']['footwear'] }}</strong> Pair footwear</li>
                    </ul>
                    <p class="uo-exchange-rule">1 unused jumpsuit = {{ config('uniform_orders.swap.rate') }} additional polo + {{ config('uniform_orders.swap.rate') }} additional 5.11 pant.</p>
                @endif
            </div>
        </section>

        <p class="uo-image-disclaimer"><x-heroicon-o-information-circle aria-hidden="true" /><span>Product images are for demonstration purposes only. Shirt color, embroidery, badge/brass color, rank markings and other rank- or assignment-specific details will be adjusted to your actual rank and position.</span></p>

        <form wire:submit="submit" class="uo-order-layout" novalidate>
            <div class="uo-catalog">
                @if($errors->any())
                    <div class="uo-input-errors" role="alert" tabindex="-1">
                        <strong>Check your order details</strong>
                        <p>Correct the fields shown below, then submit again. Your selections are still here.</p>
                        @error('data.items') <p>{{ $message }}</p> @enderror
                        @error('data.idempotency_key') <p>{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="uo-catalog-start" x-ref="catalogStart" aria-hidden="true" wire:key="uniform-catalog-start"></div>
                <nav class="uo-category-nav" aria-label="Uniform categories" x-ref="categoryNav">
                    <select class="uo-category-jump" aria-label="Jump to a section" x-on:change="document.getElementById($event.target.value)?.scrollIntoView({ block: 'start' }); $event.target.value = ''">
                        <option value="">Jump to a section…</option>
                        @foreach($categories as $category => $categoryLabel)
                            <option value="uo-section-{{ $category }}">{{ $category === 'marine' && $context['marine'] ? 'Your Marine Allocation' : $categoryLabel }}</option>
                        @endforeach
                        <option value="uo-notes">Notes for Support Services</option>
                        <option value="uo-summary-heading">Review order</option>
                    </select>
                    @foreach($categories as $category => $categoryLabel)
                        <a href="#uo-section-{{ $category }}">{{ $category === 'marine' && $context['marine'] ? 'Marine allocation' : $categoryLabel }}</a>
                    @endforeach
                </nav>
                @foreach($categories as $category => $categoryLabel)
                    <section class="uo-category" id="uo-section-{{ $category }}" aria-labelledby="uo-heading-{{ $category }}">
                        <header class="uo-category-heading">
                            <h2 id="uo-heading-{{ $category }}">{{ $categoryLabel }}</h2>
                            @if($category === 'marine')
                                <p>@if($context['marine']) Additional annual allowance: {{ $context['allowances']['marine_shorts'] }} shorts · {{ $context['allowances']['marine_ss'] }} short sleeve shirts · {{ $context['allowances']['marine_ls'] }} long sleeve shirts · {{ $context['allowances']['marine_shoes'] }} pair boating shoes. @else Available to request; outside your standard assignment allocation. @endif</p>
                            @elseif($category === 'work')
                                <p>A work set is one polo and one pair of pants. Mix short and long sleeves.</p>
                            @elseif($category === 'dress')
                                <p>Choose your shirt sleeve preference. Rank-specific details are handled by Support Services.</p>
                            @endif
                        </header>
                        <div class="uo-product-grid">
                            @foreach($products as $code => $product)
                                @continue($product['category'] !== $category)
                                @php
                                    $path = 'data.items.'.$code;
                                    $group = $product['group'];
                                    $isOutside = ($context['profile'] !== 'day_other' && (($summary['allowed'][$group] ?? 0) === 0));
                                    $badge = $product['frequency'] === 'every_3_years' ? 'Every 3 years' : ($category === 'marine' && $context['marine'] ? 'Marine allocation' : ($isOutside ? 'Outside standard allocation' : ($context['profile'] === 'day_other' ? 'Available to request' : 'Standard allocation')));
                                @endphp
                                <article class="uo-product" id="uo-product-{{ $code }}" data-product="{{ $code }}" wire:key="uniform-product-{{ $code }}" x-data="{ quantity: $wire.entangle(@js($path.'.quantity')).live }" :class="{ 'uo-product-selected': Number(quantity) > 0 }">
                                    <div class="uo-product-top">
                                        @if($product['image'])
                                            <button type="button" class="uo-thumbnail" aria-label="Enlarge {{ $product['label'] }} image" x-on:click="openImage(@js(asset($product['image'])), @js($product['label']), $event.currentTarget)">
                                                <img src="{{ asset($product['thumbnail']) }}" alt="{{ $product['label'] }} demonstration" width="160" height="160" loading="lazy" decoding="async">
                                                <span class="uo-image-hint"><x-heroicon-o-magnifying-glass-plus aria-hidden="true" /> View</span>
                                            </button>
                                        @else
                                            <div class="uo-thumbnail uo-no-photo" aria-label="{{ $product['label'] }}; product photo not yet available">
                                                <x-heroicon-o-shopping-bag aria-hidden="true" /><span>Photo coming soon</span>
                                            </div>
                                        @endif
                                        <div class="uo-product-description">
                                            <span class="uo-chip {{ $isOutside ? 'uo-chip-advisory' : '' }}">{{ $badge }}</span>
                                            <h3>{{ $product['label'] }}</h3>
                                            <div class="uo-quantity">
                                                <label for="uo-qty-{{ $code }}">Quantity</label>
                                                <div class="uo-stepper">
                                                    <button type="button" aria-label="Remove one {{ $product['label'] }}" x-on:click="quantity = Math.max(0, Number(quantity || 0) - 1)" :disabled="Number(quantity) <= 0">−</button>
                                                    <input id="uo-qty-{{ $code }}" type="number" min="0" max="{{ config('uniform_orders.quantity_max') }}" step="1" inputmode="numeric" x-model.number="quantity" aria-invalid="{{ $errors->has($path.'.quantity') ? 'true' : 'false' }}" aria-describedby="uo-qty-error-{{ $code }}">
                                                    <button type="button" aria-label="Add one {{ $product['label'] }}" x-on:click="quantity = Math.min({{ config('uniform_orders.quantity_max') }}, Number(quantity || 0) + 1)">+</button>
                                                </div>
                                            </div>
                                            @error($path.'.quantity') <p class="uo-error" id="uo-qty-error-{{ $code }}">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                    @if($product['fields'])
                                        <div class="uo-product-fields" x-show="Number(quantity) > 0" x-cloak>
                                            @foreach($product['fields'] as $field)
                                                @php $fieldPath = $path.'.metadata.'.$field['key']; $fieldId = 'uo-'.$code.'-'.$field['key']; @endphp
                                                <div class="uo-field">
                                                    <label for="{{ $fieldId }}">{{ $field['label'] }} @if(!($field['required'] ?? true)) <small>(optional)</small> @endif</label>
                                                    @if($field['type'] === 'select')
                                                        <select id="{{ $fieldId }}" wire:model.live="{{ $fieldPath }}" aria-invalid="{{ $errors->has($fieldPath) ? 'true' : 'false' }}" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error">
                                                            <option value="">Select…</option>
                                                            @foreach($field['options'] as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
                                                        </select>
                                                    @else
                                                        <input id="{{ $fieldId }}" type="{{ $field['type'] }}" wire:model.live.debounce.350ms="{{ $fieldPath }}" @if($field['type'] === 'number') inputmode="{{ ($field['step'] ?? 1) < 1 ? 'decimal' : 'numeric' }}" min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="{{ $field['step'] ?? 1 }}" @else maxlength="{{ $field['max_length'] ?? 60 }}" @endif aria-invalid="{{ $errors->has($fieldPath) ? 'true' : 'false' }}" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error">
                                                    @endif
                                                    @if($field['help'] ?? null) <small id="{{ $fieldId }}-help">{{ $field['help'] }}</small> @endif
                                                    @error($fieldPath) <p class="uo-error" id="{{ $fieldId }}-error">{{ $message }}</p> @enderror
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($product['help']) <p class="uo-product-helper" x-show="Number(quantity) > 0" x-cloak>{{ $product['help'] }}</p> @endif
                                    @if($product['frequency'] === 'every_3_years') <p class="uo-product-helper">Provided every 3 years. Issue history has not been verified; Support Services will confirm when due.</p> @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
                <section class="uo-notes" id="uo-notes" aria-labelledby="uo-notes-label">
                    <label id="uo-notes-label" for="uo-member-note">Notes for Support Services <small>(optional)</small></label>
                    <p id="uo-notes-help">Use this area for replacement needs, special sizing, assignment changes, additional quantities, or anything Support Services should know.</p>
                    <textarea id="uo-member-note" wire:model="data.member_note" rows="4" maxlength="{{ config('uniform_orders.note_max') }}" aria-describedby="uo-notes-help uo-note-error"></textarea>
                    @error('data.member_note') <p class="uo-error" id="uo-note-error">{{ $message }}</p> @enderror
                </section>
            </div>

            <aside class="uo-summary-column">
                <section class="uo-summary" aria-labelledby="uo-summary-heading" data-order-summary>
                    <header><p class="uo-eyebrow">YOUR SELECTION</p><h2 id="uo-summary-heading">Order summary <span>{{ $summary['selected_total'] }}</span></h2></header>
                    <div class="uo-summary-content">
                        @forelse($selectedItems as $code => $item)
                            <a class="uo-summary-item" href="#uo-product-{{ $code }}" aria-label="Edit {{ $products[$code]['label'] ?? $code }}, quantity {{ $item['quantity'] }}"><span>{{ $products[$code]['label'] ?? $code }}</span><strong>× {{ $item['quantity'] }} <small>Edit</small></strong></a>
                        @empty
                            <p class="uo-summary-empty">Add a quantity to any item to start your order. Sizing appears when you select it.</p>
                        @endforelse
                        @if($context['profile'] !== 'day_other' || $context['marine'])
                            <div class="uo-counters">
                                <p><strong>Standard allowance</strong></p>
                                @foreach(app(\App\Services\PersonnelRequests\UniformOrderCatalog::class)->groupLabels() as $group => $label)
                                    @continue(!isset($summary['allowed'][$group]) || ($summary['allowed'][$group] === 0 && ($summary['quantities'][$group] ?? 0) === 0))
                                    <div class="uo-counter {{ ($summary['quantities'][$group] ?? 0) > $summary['allowed'][$group] ? 'uo-counter-advisory' : '' }}"><span>{{ $label }}</span><strong>{{ $summary['quantities'][$group] ?? 0 }} / {{ $summary['allowed'][$group] }}</strong></div>
                                @endforeach
                                @if($context['profile'] !== 'day_other') <div class="uo-counter"><span>Complete work sets</span><strong>{{ $summary['work_sets']['selected'] }} / {{ $summary['work_sets']['allowed'] }}</strong></div> @endif
                            </div>
                            @if($context['profile'] !== 'day_other') <div class="uo-swap" data-swap-credits><strong>Jumpsuit Swap Credits: {{ $summary['swaps'] }}</strong><p>Work-set allowance: {{ $summary['work_sets']['allowed'] }}</p><small>Based on the jumpsuits in this order. Prior issues are reviewed by Support Services.</small></div> @endif
                        @endif
                        @if($summary['warnings'])
                            <div class="uo-advisories" data-order-warnings>
                                <strong>For Support Services review</strong>
                                <p>This selection is outside your standard annual allocation. You may still submit it for Support Services review. Add a note below if additional context would be helpful.</p>
                                <ul>@foreach($summary['warnings'] as $warning) <li>{{ $warning }}</li> @endforeach</ul>
                            </div>
                        @endif
                        <p class="uo-review-note">Support Services reviews every request. Your current assignment and order details are saved with your request.</p>
                        <button type="submit" class="uo-submit" wire:loading.attr="disabled" wire:target="submit"><span wire:loading.remove wire:target="submit">Submit Uniform Request</span><span wire:loading wire:target="submit">Submitting…</span><x-heroicon-o-arrow-right aria-hidden="true" /></button>
                    </div>
                </section>
                <section class="uo-recent" aria-labelledby="uo-recent-heading">
                    <header><h2 id="uo-recent-heading">Recent requests</h2><a href="/employee/my-requests">View all</a></header>
                    @forelse($recentRequests as $request)
                        <a class="uo-recent-request" href="/employee/my-requests/{{ $request->public_id }}"><strong>{{ $request->request_number }}</strong><span>{{ $request->items_count }} item(s) · {{ $request->created_at->format('M j, Y') }}</span><small>{{ $request->status->label() }}</small></a>
                    @empty
                        <p>Your submitted uniform requests will appear here.</p>
                    @endforelse
                    <p class="uo-workflow-help">Structural firefighting PPE is handled by an authorized officer through the Personnel Equipment Request workflow.</p>
                </section>
            </aside>
            <div class="uo-mobile-action" x-show="mobileActionVisible" x-cloak><a href="#uo-summary-heading">{{ $summary['selected_total'] }} selected · Review order <x-heroicon-o-arrow-down aria-hidden="true" /></a><button type="submit" wire:loading.attr="disabled" wire:target="submit"><span wire:loading.remove wire:target="submit">Submit request</span><span wire:loading wire:target="submit">Submitting…</span></button></div>
        </form>

        <dialog class="uo-image-viewer" x-ref="viewer" aria-labelledby="uo-image-title" wire:ignore x-on:keydown.tab.prevent="$refs.imageClose.focus()" x-on:click="if ($event.target === $refs.viewer) closeImage()" x-on:close="restoreImageFocus()">
            <div class="uo-viewer-content">
                <header><h2 id="uo-image-title" x-ref="imageTitle"></h2><button type="button" x-ref="imageClose" x-on:click="closeImage()" aria-label="Close product image" autofocus><x-heroicon-o-x-mark aria-hidden="true" /> Close</button></header>
                <img x-ref="largeImage" alt="" width="1450" height="1450">
                <p>Demonstration image. Your rank and assignment details will be applied to your order.</p>
            </div>
        </dialog>
    </div>
</x-filament-panels::page>

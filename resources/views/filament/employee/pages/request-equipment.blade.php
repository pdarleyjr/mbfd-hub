<x-filament-panels::page data-hub-ui="2" data-hub-portal="employee">
    <div class="uo-builder" x-init="window.matchMedia('(max-width: 1279px)').matches && $store.sidebar.close()" x-on:resize.window="desktop = window.matchMedia('(min-width: 1024px)').matches; if (desktop) cartOpen = false" x-data="{
        desktop: window.matchMedia('(min-width: 1024px)').matches,
        cartOpen: false,
        get itemCount() {
            return Object.values(this.$wire.data.items || {}).reduce((total, item) => total + Math.max(0, Math.floor(Number(item.quantity) || 0)), 0);
        },
        openCart() { this.cartOpen = true; },
        closeCart(restoreFocus = true) {
            this.cartOpen = false;
            if (restoreFocus && !this.desktop) this.$nextTick(() => this.$refs.cartButton.focus());
        },
        editItem(code) {
            this.closeCart(false);
            this.$nextTick(() => {
                const product = document.getElementById('uo-product-' + code);
                product?.scrollIntoView({ block: 'start' });
                const field = product?.querySelector('.uo-product-fields input, .uo-product-fields select') || product?.querySelector('.uo-stepper input');
                field?.focus({ preventScroll: true });
            });
        },
        showErrors() {
            const invalid = this.$root.querySelector('[aria-invalid=true]');
            if (invalid?.closest('#uo-cart')) { if (!this.desktop) this.openCart(); }
            else this.closeCart(false);
            this.$nextTick(() => {
                const target = invalid || this.$root.querySelector('.uo-input-errors');
                target?.scrollIntoView({ block: 'center' });
                target?.focus({ preventScroll: true });
            });
        },
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
            <div class="uo-success" role="status" tabindex="-1" data-order-success x-init="closeCart(false); $nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })" wire:key="uniform-success-{{ $submittedRequestNumber }}">
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
            <details class="uo-assignment-rules" wire:ignore.self>
                <summary>Annual allocation</summary>
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
                    </ul>
                    <p class="uo-exchange-rule">1 unused jumpsuit = {{ config('uniform_orders.swap.rate') }} additional polo + {{ config('uniform_orders.swap.rate') }} additional 5.11 pant.</p>
                @endif
            </details>
        </section>

        <p class="uo-image-disclaimer"><x-heroicon-o-information-circle aria-hidden="true" /><span>Product images are for demonstration purposes only. Shirt color, embroidery, badge/brass color, rank markings and other rank- or assignment-specific details will be adjusted to your actual rank and position.</span></p>

        <form wire:submit="submit" x-on:submit="if ($el.querySelector('.uo-input-errors')) showErrors()" x-on:keydown.enter="if ($event.target.matches('input')) $event.preventDefault()" class="uo-order-layout" novalidate>
            <div class="uo-toolbar" wire:ignore.self :inert="cartOpen && !desktop">
                <p class="uo-shopping-help"><strong>Choose your uniforms</strong><span>Add items and sizes, then submit your order.</span></p>
                <button type="button" class="uo-cart-button" x-ref="cartButton" aria-label="Open cart" aria-controls="uo-cart" aria-haspopup="dialog" :aria-expanded="cartOpen.toString()" x-on:click="openCart()">
                    <x-heroicon-o-shopping-cart aria-hidden="true" /><span>Cart</span><span class="uo-cart-count" data-cart-count x-text="itemCount" aria-live="polite" aria-atomic="true">{{ $summary['selected_total'] }}</span>
                </button>
            </div>
            <div class="uo-catalog" wire:ignore.self :inert="cartOpen && !desktop">
                @if($errors->any())
                    <div class="uo-input-errors" role="alert" tabindex="-1" x-init="$nextTick(() => showErrors())" wire:key="uniform-errors-{{ md5(json_encode($errors->messages())) }}">
                        <strong>Check your order details</strong>
                        <p>Correct the fields shown below, then submit again. Your selections are still here.</p>
                        @error('data.items') <p>{{ $message }}</p> @enderror
                        @error('data.idempotency_key') <p>{{ $message }}</p> @enderror
                    </div>
                @endif
                @foreach(($context['marine'] ? $categories : ['work' => $categories['work']] + $categories) as $category => $categoryLabel)
                    <section class="uo-category" id="uo-section-{{ $category }}" data-category="{{ $category }}" aria-labelledby="uo-heading-{{ $category }}">
                        <header class="uo-category-heading">
                            <h2 id="uo-heading-{{ $category }}">{{ $categoryLabel }}</h2>
                            @if($category === 'marine')
                                <p>@if($context['marine']) Annual allowance: {{ $context['allowances']['marine_shorts'] }} shorts · {{ $context['allowances']['marine_ss'] }} short sleeve shirts · {{ $context['allowances']['marine_ls'] }} long sleeve shirts · {{ $context['allowances']['marine_shoes'] }} pair boating shoes. @else Available by request for Support Services review. @endif</p>
                            @elseif($category === 'work')
                                <p>1 work set = 1 polo + 1 pair of pants.</p>
                            @endif
                        </header>
                        <div class="uo-product-grid">
                            @foreach($products as $code => $product)
                                @continue($product['category'] !== $category)
                                @php
                                    $path = 'data.items.'.$code;
                                    $group = $product['group'];
                                    $isOutside = ($context['profile'] !== 'day_other' && (($summary['allowed'][$group] ?? 0) === 0));
                                    $badge = $product['frequency'] === 'every_3_years' ? 'Every 3 years' : ($isOutside ? 'By request' : null);
                                    $quantityMax = $product['quantity_max'] ?? config('uniform_orders.quantity_max');
                                    $jacketBlocked = $code === 'jacket' && !$jacketEligibility['can_order'];
                                    $jacketStyle = data_get($data, 'items.jacket.metadata.jacket_style');
                                    $jacketAsset = $code === 'jacket' && is_string($jacketStyle) ? data_get(config('uniform_orders.jacket_styles'), $jacketStyle.'.asset') : null;
                                    if ($jacketAsset) {
                                        $product['image'] = 'images/uniforms/'.$jacketAsset.'-large.webp';
                                        $product['thumbnail'] = 'images/uniforms/'.$jacketAsset.'-thumb.webp';
                                    }
                                @endphp
                                <article class="uo-product {{ !$product['image'] ? 'uo-product-no-photo' : '' }}" id="uo-product-{{ $code }}" data-product="{{ $code }}" wire:key="uniform-product-{{ $code }}" x-data="{ quantity: $wire.entangle(@js($path.'.quantity')).live }" :class="{ 'uo-product-selected': Number(quantity) > 0 }">
                                    <div class="uo-product-top">
                                        @if($product['image'])
                                            <button type="button" class="uo-thumbnail" wire:key="uniform-thumbnail-{{ $code }}" aria-label="Enlarge {{ $product['label'] }} image" data-image="{{ asset($product['image']) }}" data-label="{{ $product['label'] }}" x-on:click="openImage($event.currentTarget.dataset.image, $event.currentTarget.dataset.label, $event.currentTarget)">
                                                <img src="{{ asset($product['thumbnail']) }}" alt="{{ $product['label'] }} demonstration" width="160" height="160" loading="lazy" decoding="async">
                                                <span class="uo-image-hint"><x-heroicon-o-magnifying-glass-plus aria-hidden="true" /> View</span>
                                            </button>
                                        @endif
                                        <div class="uo-product-description">
                                            @if($badge)<span class="uo-chip">{{ $badge }}</span>@endif
                                            <h3>{{ $product['label'] }}</h3>
                                            <div class="uo-quantity">
                                                <label for="uo-qty-{{ $code }}">Quantity</label>
                                                <div class="uo-stepper">
                                                    <button type="button" aria-label="Remove one {{ $product['label'] }}" x-on:click="quantity = Math.max(0, Number(quantity || 0) - 1)" :disabled="@js($jacketBlocked) || Number(quantity) <= 0">−</button>
                                                    <input id="uo-qty-{{ $code }}" type="number" min="0" max="{{ $quantityMax }}" step="1" inputmode="numeric" x-model.number="quantity" @disabled($jacketBlocked) aria-invalid="{{ $errors->has($path.'.quantity') ? 'true' : 'false' }}" aria-describedby="uo-qty-error-{{ $code }}">
                                                    <button type="button" aria-label="Add one {{ $product['label'] }}" x-on:click="quantity = Math.min({{ $quantityMax }}, Number(quantity || 0) + 1)" :disabled="@js($jacketBlocked) || Number(quantity) >= {{ $quantityMax }}">+</button>
                                                </div>
                                            </div>
                                            @error($path.'.quantity') <p class="uo-error" id="uo-qty-error-{{ $code }}">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                    @if($code === 'jacket')
                                        <p class="uo-product-helper">{{ $jacketEligibility['reason'] }}</p>
                                        <fieldset class="uo-jacket-options" aria-describedby="uo-jacket-jacket_style-error">
                                            <legend>Choose one jacket style</legend>
                                            @foreach(config('uniform_orders.jacket_styles') as $styleCode => $style)
                                                <label class="uo-jacket-option">
                                                    <img src="{{ asset('images/uniforms/'.$style['asset'].'-thumb.webp') }}" alt="{{ $style['label'] }} demonstration" width="160" height="160" loading="lazy" decoding="async">
                                                    <span><input type="radio" name="jacket_style" aria-label="{{ $style['label'] }}" aria-invalid="{{ $errors->has('data.items.jacket.metadata.jacket_style') ? 'true' : 'false' }}" value="{{ $styleCode }}" wire:model.live="data.items.jacket.metadata.jacket_style" x-on:change="quantity = 1" @disabled($jacketBlocked)>{{ $style['label'] }}</span>
                                                </label>
                                            @endforeach
                                            @error('data.items.jacket.metadata.jacket_style') <p class="uo-error" id="uo-jacket-jacket_style-error">{{ $message }}</p> @enderror
                                        </fieldset>
                                    @endif
                                    @if($product['fields'])
                                        <div class="uo-product-fields" x-show="Number(quantity) > 0" x-cloak>
                                            @foreach($product['fields'] as $field)
                                                @continue($code === 'jacket' && $field['key'] === 'jacket_style')
                                                @php $fieldPath = $path.'.metadata.'.$field['key']; $fieldId = 'uo-'.$code.'-'.$field['key']; @endphp
                                                <div class="uo-field" wire:key="uniform-field-{{ $code }}-{{ $field['key'] }}">
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
                                    @if($product['frequency'] === 'every_3_years' && $code !== 'jacket') <p class="uo-product-helper">Provided every 3 years. Support Services will confirm when due.</p> @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="uo-summary-column" id="uo-cart" wire:ignore.self x-show="desktop || cartOpen" x-cloak :role="desktop ? 'complementary' : 'dialog'" :aria-modal="desktop ? null : 'true'" aria-labelledby="uo-summary-heading" x-trap.inert.noscroll.noreturn="cartOpen && !desktop" x-on:keydown.escape.prevent.stop="closeCart()">
                <section class="uo-summary" aria-labelledby="uo-summary-heading" data-order-summary>
                    <header><h2 id="uo-summary-heading">Your cart</h2><button type="button" class="uo-cart-close" aria-label="Close cart" x-on:click="closeCart()"><x-heroicon-o-x-mark aria-hidden="true" /></button></header>
                    <div class="uo-summary-content">
                        @forelse($selectedItems as $code => $item)
                            @php
                                $selectedLabel = $products[$code]['label'] ?? $code;
                                $selectedStyle = data_get($item, 'metadata.jacket_style');
                                if ($code === 'jacket' && is_string($selectedStyle)) {
                                    $selectedLabel = data_get(config('uniform_orders.jacket_styles'), $selectedStyle.'.label', $selectedLabel);
                                }
                            @endphp
                            <div class="uo-cart-item" wire:key="uniform-cart-item-{{ $code }}">
                                <a class="uo-summary-item" href="#uo-product-{{ $code }}" x-on:click.prevent="editItem(@js($code))" aria-label="Edit {{ $selectedLabel }}, quantity {{ $item['quantity'] }}"><span>{{ $selectedLabel }}<small>
                                    @foreach($products[$code]['fields'] ?? [] as $field)
                                        @php $value = data_get($item, 'metadata.'.$field['key']); @endphp
                                        @if(is_scalar($value) && $value !== '' && $field['key'] !== 'jacket_style')<span>{{ $field['label'] }}: {{ $field['options'][$value] ?? $value }}</span>@endif
                                    @endforeach
                                </small></span><strong>× {{ $item['quantity'] }} <small>Edit</small></strong></a>
                                <button type="button" class="uo-cart-remove" aria-label="Remove {{ $selectedLabel }} from cart" x-on:click="$wire.set(@js('data.items.'.$code.'.quantity'), 0)"><x-heroicon-o-trash aria-hidden="true" /></button>
                            </div>
                        @empty
                            <p class="uo-summary-empty">Add a quantity to any item to start your order. Sizing appears when you select it.</p>
                        @endforelse
                        @if($context['profile'] !== 'day_other' || $context['marine'])
                            <details class="uo-allocation-details" wire:ignore.self>
                                <summary>Allocation details</summary>
                            <div class="uo-counters">
                                <p><strong>Standard allowance</strong></p>
                                @foreach(app(\App\Services\PersonnelRequests\UniformOrderCatalog::class)->groupLabels() as $group => $label)
                                    @continue(in_array($group, ['footwear', 'class_a_coats', 'uniform_shirts'], true))
                                    @continue(!isset($summary['allowed'][$group]) || ($summary['allowed'][$group] === 0 && ($summary['quantities'][$group] ?? 0) === 0))
                                    <div class="uo-counter {{ ($summary['quantities'][$group] ?? 0) > $summary['allowed'][$group] ? 'uo-counter-advisory' : '' }}"><span>{{ $label }}</span><strong>{{ $summary['quantities'][$group] ?? 0 }} / {{ $summary['allowed'][$group] }}</strong></div>
                                @endforeach
                                @if($context['profile'] !== 'day_other') <div class="uo-counter"><span>Complete work sets</span><strong>{{ $summary['work_sets']['selected'] }} / {{ $summary['work_sets']['allowed'] }}</strong></div> @endif
                            </div>
                            @if($context['profile'] !== 'day_other') <div class="uo-swap" data-swap-credits><strong>Jumpsuit Swap Credits: {{ $summary['swaps'] }}</strong><p>Work-set allowance: {{ $summary['work_sets']['allowed'] }}</p><small>Based on the jumpsuits in this order. Prior issues are reviewed by Support Services.</small></div> @endif
                            </details>
                        @endif
                        @if($summary['warnings'])
                            <div class="uo-advisories" data-order-warnings>
                                <strong>For Support Services review</strong>
                                <p>This selection is outside your standard annual allocation. You may still submit it for Support Services review.</p>
                                <ul>@foreach($summary['warnings'] as $warning) <li>{{ $warning }}</li> @endforeach</ul>
                            </div>
                        @endif
                        <details class="uo-notes" id="uo-notes" wire:ignore.self x-ref="notes">
                            <summary>Notes for Support Services <small>(optional)</small></summary>
                            <label class="sr-only" for="uo-member-note">Notes for Support Services (optional)</label>
                            <p id="uo-notes-help">Replacement needs, special sizing, or additional context.</p>
                            <textarea id="uo-member-note" wire:model="data.member_note" rows="2" maxlength="{{ config('uniform_orders.note_max') }}" aria-invalid="{{ $errors->has('data.member_note') ? 'true' : 'false' }}" aria-describedby="uo-notes-help uo-note-error"></textarea>
                            @error('data.member_note') <p class="uo-error" id="uo-note-error" x-init="$refs.notes.open = true; if (!desktop) openCart()">{{ $message }}</p> @enderror
                        </details>
                        <p class="uo-review-note">Support Services reviews your request. Assignment and sizing details are saved automatically.</p>
                    </div>
                    <footer class="uo-checkout">
                        <p class="uo-cart-total"><span>Total items</span><strong data-cart-total x-text="itemCount">{{ $summary['selected_total'] }}</strong></p>
                        <button type="submit" class="uo-submit" aria-label="Submit Uniform Request" wire:loading.attr="disabled" wire:target="submit"><span wire:loading.remove wire:target="submit">Submit order</span><span wire:loading wire:target="submit">Submitting…</span><x-heroicon-o-arrow-right aria-hidden="true" /></button>
                    </footer>
                </section>
                <details class="uo-recent" wire:ignore.self>
                    <summary>Recent requests</summary>
                    <header><a href="/employee/my-requests">View all requests</a></header>
                    @forelse($recentRequests as $request)
                        <a class="uo-recent-request" href="/employee/my-requests/{{ $request->public_id }}"><strong>{{ $request->request_number }}</strong><span>{{ $request->items_count }} item(s) · {{ $request->created_at->format('M j, Y') }}</span><small>{{ $request->status->label() }}</small></a>
                    @empty
                        <p>Your submitted uniform requests will appear here.</p>
                    @endforelse
                    <p class="uo-workflow-help">Structural firefighting PPE is handled by an authorized officer through the Personnel Equipment Request workflow.</p>
                </details>
            </div>
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

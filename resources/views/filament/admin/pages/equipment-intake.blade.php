<x-filament-panels::page>
    {{-- Tab Switcher --}}
    <div x-data="{ activeTab: 'scan' }" class="space-y-6">
        {{-- Tab Navigation --}}
        <div class="flex gap-2 border-b border-gray-200 pb-0">
            <button
                @click="activeTab = 'scan'"
                :class="activeTab === 'scan'
                    ? 'border-primary-500 text-primary-600 font-semibold'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="px-4 py-3 text-sm border-b-2 transition-colors flex items-center gap-2"
            >
                <x-heroicon-o-camera class="w-5 h-5" />
                <span>AI Camera Scan</span>
            </button>
            <button
                @click="activeTab = 'bulk'"
                :class="activeTab === 'bulk'
                    ? 'border-primary-500 text-primary-600 font-semibold'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="px-4 py-3 text-sm border-b-2 transition-colors flex items-center gap-2"
            >
                <x-heroicon-o-table-cells class="w-5 h-5" />
                <span>Bulk / Manual Entry</span>
            </button>
        </div>

        {{-- ============================================================ --}}
        {{-- MODE A: AI Camera Scan --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'scan'" x-cloak>
            <div
                x-data="equipmentScanner()"
                class="space-y-6"
            >
                {{-- Camera Capture Section --}}
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-primary-50 text-primary-600">
                            <x-heroicon-o-camera class="w-6 h-6" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-950">Capture Equipment Photo</h3>
                            <p class="text-sm text-gray-500">Take a photo of the equipment label/nameplate. AI will extract brand, model, and serial number.</p>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-4">
                        {{-- Camera Input --}}
                        <label
                            class="relative flex flex-col items-center justify-center w-full max-w-md aspect-video rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 hover:bg-gray-100 cursor-pointer transition group"
                            :class="{ 'border-primary-400 bg-primary-50': imagePreview }"
                        >
                            <template x-if="!imagePreview && !processing">
                                <div class="flex flex-col items-center gap-2 text-gray-400 group-hover:text-gray-500">
                                    <x-heroicon-o-camera class="w-12 h-12" />
                                    <span class="text-sm font-medium">Tap to capture photo</span>
                                    <span class="text-xs">or select from gallery</span>
                                </div>
                            </template>

                            <template x-if="processing">
                                <div class="flex flex-col items-center gap-3 text-primary-600">
                                    <svg class="animate-spin w-10 h-10" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span class="text-sm font-medium">Analyzing with AI...</span>
                                </div>
                            </template>

                            <template x-if="imagePreview && !processing">
                                <img :src="imagePreview" class="w-full h-full object-contain rounded-lg" alt="Captured equipment" />
                            </template>

                            <input
                                type="file"
                                accept="image/*"
                                capture="environment"
                                class="sr-only"
                                @change="handleCapture($event)"
                                x-ref="cameraInput"
                            />
                        </label>

                        {{-- Re-capture button --}}
                        <template x-if="imagePreview && !processing">
                            <button
                                type="button"
                                @click="resetCapture()"
                                class="text-sm text-gray-500 hover:text-primary-600 underline"
                            >
                                Retake Photo
                            </button>
                        </template>
                    </div>

                    {{-- Error Display --}}
                    <template x-if="scanError">
                        <div class="mt-4 p-3 rounded-lg bg-danger-50 text-danger-700 text-sm flex items-start gap-2">
                            <x-heroicon-o-exclamation-triangle class="w-5 h-5 flex-shrink-0 mt-0.5" />
                            <span x-text="scanError"></span>
                        </div>
                    </template>
                </div>

                {{-- Parsed Results Form --}}
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                    <h3 class="text-base font-semibold text-gray-950 mb-4">Asset Details</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Brand --}}
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">Brand / Manufacturer</label>
                            <input
                                type="text"
                                wire:model="scan_brand"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                                placeholder="e.g. Scott, MSA, Motorola"
                            />
                        </div>

                        {{-- Model --}}
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">Model</label>
                            <input
                                type="text"
                                wire:model="scan_model"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                                placeholder="e.g. Air-Pak X3 Pro"
                            />
                        </div>

                        {{-- Serial --}}
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">Serial Number</label>
                            <input
                                type="text"
                                wire:model="scan_serial"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                                placeholder="e.g. SN-2024-00123"
                            />
                        </div>

                        {{-- Location (Required) --}}
                        <div>
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">
                                Location <span class="text-danger-600">*</span>
                            </label>
                            <select
                                wire:model="scan_location"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                                required
                            >
                                <option value="">Select location...</option>
                                @foreach($this->locations as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Notes --}}
                    <div class="mt-4">
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">Notes (optional)</label>
                        <textarea
                            wire:model="scan_notes"
                            rows="2"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                            placeholder="Any additional notes about this equipment..."
                        ></textarea>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="mt-6 flex flex-col sm:flex-row gap-3">
                        <button
                            type="button"
                            wire:click="approveAndSave"
                            wire:loading.attr="disabled"
                            class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 bg-primary-600 text-white hover:bg-primary-500 focus:ring-primary-500 disabled:opacity-50"
                        >
                            <x-heroicon-o-check-circle class="w-5 h-5" />
                            <span wire:loading.remove wire:target="approveAndSave">Approve & Save to Snipe-IT</span>
                            <span wire:loading wire:target="approveAndSave">Saving...</span>
                        </button>

                        <button
                            type="button"
                            wire:click="resetScanForm"
                            class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors focus:outline-none bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50"
                        >
                            <x-heroicon-o-arrow-path class="w-5 h-5" />
                            Clear Form
                        </button>
                    </div>
                </div>

                {{-- Success Message --}}
                @if($scan_success)
                    <div class="p-4 rounded-lg bg-success-50 text-success-700 text-sm flex items-center gap-2">
                        <x-heroicon-o-check-circle class="w-5 h-5" />
                        {{ $scan_success }}
                    </div>
                @endif
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- MODE B: Bulk / Manual Entry --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'bulk'" x-cloak>
            <div class="space-y-6">
                {{-- Location Selector --}}
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                    <h3 class="text-base font-semibold text-gray-950 mb-4">Bulk Import — Consumables & Tools</h3>
                    <p class="text-sm text-gray-500 mb-4">Quickly log items that don't require serial numbers or photographs. Select a location, then add items to the grid below.</p>

                    <div class="max-w-xs">
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">
                            Location <span class="text-danger-600">*</span>
                        </label>
                        <select
                            wire:model="bulk_location"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                        >
                            <option value="">Select location...</option>
                            @foreach($this->locations as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Rapid Entry Grid --}}
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-6">
                    <h3 class="text-base font-semibold text-gray-950 mb-4">Items</h3>

                    {{-- Table Header (desktop) --}}
                    <div class="hidden sm:grid sm:grid-cols-12 gap-2 mb-2 text-xs font-medium text-gray-500 uppercase tracking-wider px-1">
                        <div class="col-span-4">Item Name</div>
                        <div class="col-span-2">Qty</div>
                        <div class="col-span-3">Category</div>
                        <div class="col-span-2">Notes</div>
                        <div class="col-span-1"></div>
                    </div>

                    {{-- Item Rows --}}
                    <div class="space-y-3">
                        @foreach($bulk_items as $index => $item)
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start p-3 sm:p-1 rounded-lg bg-gray-50 sm:bg-transparent">
                                {{-- Item Name --}}
                                <div class="sm:col-span-4">
                                    <label class="sm:hidden text-xs font-medium text-gray-500 mb-1 block">Item Name</label>
                                    <input
                                        type="text"
                                        wire:model="bulk_items.{{ $index }}.name"
                                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                        placeholder="e.g. Flashlight batteries"
                                    />
                                </div>

                                {{-- Quantity --}}
                                <div class="sm:col-span-2">
                                    <label class="sm:hidden text-xs font-medium text-gray-500 mb-1 block">Qty</label>
                                    <input
                                        type="number"
                                        wire:model="bulk_items.{{ $index }}.quantity"
                                        min="1"
                                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                    />
                                </div>

                                {{-- Category --}}
                                <div class="sm:col-span-3">
                                    <label class="sm:hidden text-xs font-medium text-gray-500 mb-1 block">Category</label>
                                    <select
                                        wire:model="bulk_items.{{ $index }}.category"
                                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                    >
                                        <option value="">—</option>
                                        <option value="Consumable">Consumable</option>
                                        <option value="Tool">Tool</option>
                                        <option value="PPE">PPE</option>
                                        <option value="Medical">Medical Supply</option>
                                        <option value="Hose">Hose / Fitting</option>
                                        <option value="Electronics">Electronics</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                {{-- Notes --}}
                                <div class="sm:col-span-2">
                                    <label class="sm:hidden text-xs font-medium text-gray-500 mb-1 block">Notes</label>
                                    <input
                                        type="text"
                                        wire:model="bulk_items.{{ $index }}.notes"
                                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                        placeholder="Optional"
                                    />
                                </div>

                                {{-- Remove --}}
                                <div class="sm:col-span-1 flex items-center justify-end sm:justify-center">
                                    @if(count($bulk_items) > 1)
                                        <button
                                            type="button"
                                            wire:click="removeBulkRow({{ $index }})"
                                            class="text-gray-400 hover:text-danger-600 transition p-1"
                                            title="Remove row"
                                        >
                                            <x-heroicon-o-x-circle class="w-5 h-5" />
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Add Row + Submit --}}
                    <div class="mt-4 flex flex-col sm:flex-row gap-3">
                        <button
                            type="button"
                            wire:click="addBulkRow"
                            class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50"
                        >
                            <x-heroicon-o-plus class="w-5 h-5" />
                            Add Row
                        </button>

                        <button
                            type="button"
                            wire:click="submitBulkItems"
                            wire:loading.attr="disabled"
                            class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors bg-primary-600 text-white hover:bg-primary-500 focus:ring-primary-500 disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-up-tray class="w-5 h-5" />
                            <span wire:loading.remove wire:target="submitBulkItems">Submit All to Snipe-IT</span>
                            <span wire:loading wire:target="submitBulkItems">Submitting...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Alpine.js: AI Camera Scanner Component --}}
    @push('scripts')
    <script>
        function equipmentScanner() {
            return {
                imagePreview: null,
                processing: false,
                scanError: null,

                async handleCapture(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    // Show preview
                    this.imagePreview = URL.createObjectURL(file);
                    this.processing = true;
                    this.scanError = null;

                    try {
                        // Convert to base64
                        const base64 = await this.fileToBase64(file);

                        // Hit Cloudflare Vision Worker
                        const response = await fetch('https://vision-agent.pdarleyjr.workers.dev', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ image: base64 }),
                        });

                        if (!response.ok) {
                            throw new Error(`Vision API returned ${response.status}`);
                        }

                        const data = await response.json();

                        // Send parsed data to Livewire
                        @this.processVisionResult(
                            data.brand || '',
                            data.model || '',
                            data.serial || ''
                        );

                        this.processing = false;
                    } catch (err) {
                        this.processing = false;
                        this.scanError = 'AI scan failed: ' + err.message + '. You can fill in the fields manually.';
                        @this.handleScanError(err.message);
                    }
                },

                fileToBase64(file) {
                    return new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.onload = () => {
                            // Remove data:image/...;base64, prefix
                            const base64 = reader.result.split(',')[1];
                            resolve(base64);
                        };
                        reader.onerror = reject;
                        reader.readAsDataURL(file);
                    });
                },

                resetCapture() {
                    this.imagePreview = null;
                    this.processing = false;
                    this.scanError = null;
                    this.$refs.cameraInput.value = '';
                },
            };
        }
    </script>
    @endpush
</x-filament-panels::page>

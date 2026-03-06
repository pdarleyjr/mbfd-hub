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
                            <h3 class="text-base font-semibold text-gray-950">Capture Equipment Photos</h3>
                            <p class="text-sm text-gray-500">Take one or more photos of the equipment. AI will analyze all images together for better results.</p>
                        </div>
                    </div>

                    {{-- Photo Thumbnails --}}
                    <template x-if="imagePreviews.length > 0">
                        <div class="flex flex-wrap gap-3 mb-4">
                            <template x-for="(preview, idx) in imagePreviews" :key="idx">
                                <div class="relative group">
                                    <img :src="preview" class="w-24 h-24 object-cover rounded-lg ring-1 ring-gray-200" />
                                    <button
                                        type="button"
                                        @click="removeImage(idx)"
                                        class="absolute -top-2 -right-2 w-6 h-6 bg-danger-500 text-white rounded-full flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition"
                                    >&times;</button>
                                    <div class="absolute bottom-1 right-1 bg-black/60 text-white text-xs px-1.5 rounded" x-text="'#' + (idx + 1)"></div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="flex flex-col sm:flex-row items-center gap-3">
                        {{-- Take Photo (Camera) --}}
                        <label class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors bg-primary-600 text-white hover:bg-primary-500 cursor-pointer">
                            <x-heroicon-o-camera class="w-5 h-5" />
                            <span>Take Photo</span>
                            <input
                                type="file"
                                accept="image/*"
                                capture="environment"
                                class="sr-only"
                                @change="handleCapture($event)"
                                x-ref="cameraInput"
                            />
                        </label>

                        {{-- Choose from Gallery --}}
                        <label class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 cursor-pointer">
                            <x-heroicon-o-photo class="w-5 h-5" />
                            <span>Choose from Gallery</span>
                            <input
                                type="file"
                                accept="image/*"
                                multiple
                                class="sr-only"
                                @change="handleGallerySelect($event)"
                                x-ref="galleryInput"
                            />
                        </label>

                        {{-- Analyze Button --}}
                        <template x-if="imagePreviews.length > 0 && !processing">
                            <button
                                type="button"
                                @click="analyzeAllImages()"
                                class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors bg-success-600 text-white hover:bg-success-500"
                            >
                                <x-heroicon-o-sparkles class="w-5 h-5" />
                                <span>Analyze <span x-text="imagePreviews.length"></span> Photo(s) with AI</span>
                            </button>
                        </template>

                        {{-- Clear All --}}
                        <template x-if="imagePreviews.length > 0 && !processing">
                            <button
                                type="button"
                                @click="resetCapture()"
                                class="text-sm text-gray-500 hover:text-danger-600 underline"
                            >Clear All</button>
                        </template>
                    </div>

                    {{-- Processing Indicator --}}
                    <template x-if="processing">
                        <div class="mt-4 flex items-center gap-3 text-primary-600">
                            <svg class="animate-spin w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span class="text-sm font-medium">Analyzing <span x-text="imageFiles.length"></span> image(s) with AI...</span>
                        </div>
                    </template>

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
                        <div x-data="{ showNewLocation: false }">
                            <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">
                                Location <span class="text-danger-600">*</span>
                            </label>
                            <div x-show="!showNewLocation">
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
                                <button type="button" @click="showNewLocation = true" class="mt-1 text-xs text-primary-600 hover:underline">+ Create new location</button>
                            </div>
                            <div x-show="showNewLocation" class="space-y-2">
                                <input
                                    type="text"
                                    wire:model="new_location_name"
                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                                    placeholder="Enter new location name..."
                                />
                                <div class="flex gap-2">
                                    <button type="button" wire:click="createNewLocation" class="text-xs text-success-600 hover:underline">Save Location</button>
                                    <button type="button" @click="showNewLocation = false" class="text-xs text-gray-500 hover:underline">Cancel</button>
                                </div>
                            </div>
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

                    <div class="max-w-xs" x-data="{ showNewBulkLocation: false }">
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950">
                            Location <span class="text-danger-600">*</span>
                        </label>
                        <div x-show="!showNewBulkLocation">
                            <select
                                wire:model="bulk_location"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                            >
                                <option value="">Select location...</option>
                                @foreach($this->locations as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <button type="button" @click="showNewBulkLocation = true" class="mt-1 text-xs text-primary-600 hover:underline">+ Create new location</button>
                        </div>
                        <div x-show="showNewBulkLocation" class="space-y-2">
                            <input
                                type="text"
                                wire:model="new_location_name"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 text-sm"
                                placeholder="Enter new location name..."
                            />
                            <div class="flex gap-2">
                                <button type="button" wire:click="createNewLocation" class="text-xs text-success-600 hover:underline">Save Location</button>
                                <button type="button" @click="showNewBulkLocation = false" class="text-xs text-gray-500 hover:underline">Cancel</button>
                            </div>
                        </div>
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
                imagePreviews: [],
                imageFiles: [],
                processing: false,
                scanError: null,

                handleCapture(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    this.addImage(file);
                    event.target.value = '';
                },

                handleGallerySelect(event) {
                    const files = Array.from(event.target.files);
                    files.forEach(file => this.addImage(file));
                    event.target.value = '';
                },

                addImage(file) {
                    this.imagePreviews.push(URL.createObjectURL(file));
                    this.imageFiles.push(file);
                    this.scanError = null;
                },

                removeImage(idx) {
                    URL.revokeObjectURL(this.imagePreviews[idx]);
                    this.imagePreviews.splice(idx, 1);
                    this.imageFiles.splice(idx, 1);
                },

                async analyzeAllImages() {
                    if (this.imageFiles.length === 0) return;
                    this.processing = true;
                    this.scanError = null;

                    try {
                        // Convert all images to base64
                        const base64Images = await Promise.all(
                            this.imageFiles.map(file => this.fileToBase64(file))
                        );

                        // Send all images to the Vision Worker
                        // The worker accepts { image: string } for single or { images: string[] } for multiple
                        const payload = base64Images.length === 1
                            ? { image: base64Images[0] }
                            : { images: base64Images };

                        const response = await fetch('https://vision-agent.pdarleyjr.workers.dev', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
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
                            const base64 = reader.result.split(',')[1];
                            resolve(base64);
                        };
                        reader.onerror = reject;
                        reader.readAsDataURL(file);
                    });
                },

                resetCapture() {
                    this.imagePreviews.forEach(url => URL.revokeObjectURL(url));
                    this.imagePreviews = [];
                    this.imageFiles = [];
                    this.processing = false;
                    this.scanError = null;
                    if (this.$refs.cameraInput) this.$refs.cameraInput.value = '';
                    if (this.$refs.galleryInput) this.$refs.galleryInput.value = '';
                },
            };
        }
    </script>
    @endpush
</x-filament-panels::page>

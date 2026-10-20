{{--
  Equipment Intake — Mobile-first Blade view
  Tested on: smartphone (portrait/landscape), tablet, desktop
  Rules:
    - NO violet-* classes (not in Filament CSS purge)
    - NO bare SVG heroicons for decorative large icons (size them with the .ei-icon classes)
    - All touch targets min 48px height
    - File inputs use capture="environment" for camera, multiple for gallery
--}}
<x-filament-panels::page>
    {{-- Tab Switcher --}}
    <div x-data="{ activeTab: 'scan' }" class="ei-page space-y-5">
        {{-- Tab Navigation --}}
        <div class="ei-tabs">
            <button type="button" @click="activeTab = 'scan'"
                :class="activeTab === 'scan' ? 'ei-tab-btn active' : 'ei-tab-btn'">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" /></svg>
                <span>AI Camera Scan</span>
            </button>
            <button type="button" @click="activeTab = 'bulk'"
                :class="activeTab === 'bulk' ? 'ei-tab-btn active' : 'ei-tab-btn'">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h1.5C5.496 19.5 6 18.996 6 18.375m-3.75.125V8.25A2.25 2.25 0 014.5 6h15A2.25 2.25 0 0121.75 8.25v10.125m-18.375 1.125c0 .621.504 1.125 1.125 1.125M20.25 19.5h-1.5A1.125 1.125 0 0117.625 18.375m3.375-10.125V7.5A.75.75 0 0020.25 6.75H3.75A.75.75 0 003 7.5v.75m0 0h18m-18 0v10.125" /></svg>
                <span>Manual Entry</span>
            </button>
            <button type="button" @click="activeTab = 'ai_bulk'"
                :class="activeTab === 'ai_bulk' ? 'ei-tab-btn active' : 'ei-tab-btn'">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" /></svg>
                <span>AI Bulk Import</span>
            </button>
        </div>

        {{-- ============================================================ --}}
        {{-- MODE A: AI Camera Scan --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'scan'" x-cloak>
            <div x-data="equipmentScanner()" @equipment-saved.window="resetCapture()" class="space-y-5">

                {{-- Camera Capture Card --}}
                <div class="ei-card">
                    <h3 class="ei-title ei-title--tight">Capture Equipment Photos</h3>
                    <p class="ei-lede">Take up to 3 photos of the same tool (e.g. one of the tool body, one of the data plate). AI will combine them for best results.</p>

                    {{-- Thumbnail strip --}}
                    <template x-if="imagePreviews.length > 0">
                        <div class="flex flex-wrap gap-3 mb-4">
                            <template x-for="(preview, idx) in imagePreviews" :key="idx">
                                <div class="ei-thumb-wrap">
                                    <img :src="preview" class="ei-thumb" alt="" />
                                    <button type="button" @click="removeImage(idx)"
                                        class="ei-thumb-remove"
                                        aria-label="Remove photo">&times;</button>
                                    <span class="ei-thumb-badge" x-text="'#'+(idx+1)"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Action Buttons --}}
                    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        {{-- Take Photo — mobile camera --}}
                        <label class="ei-btn-primary" :class="imageFiles.length >= 3 ? 'opacity-50 pointer-events-none' : ''">
                            <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" /></svg>
                            <span x-text="imageFiles.length === 0 ? 'Take Photo' : 'Add Another Photo'"></span>
                            <input type="file" accept="image/*" capture="environment" class="ei-file-input" @change="handleCapture($event)" x-ref="cameraInput" />
                        </label>

                        {{-- Choose from Gallery --}}
                        <label class="ei-btn-secondary" :class="imageFiles.length >= 3 ? 'opacity-50 pointer-events-none' : ''">
                            <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                            <span>Choose from Gallery</span>
                            <input type="file" accept="image/*" multiple class="ei-file-input" @change="handleGallerySelect($event)" x-ref="galleryInput" />
                        </label>

                        {{-- Analyze --}}
                        <template x-if="imagePreviews.length > 0 && !processing">
                            <button type="button" @click="analyzeAllImages()" class="ei-btn-primary ei-btn-success">
                                <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                                Analyze <span x-text="imagePreviews.length"></span> Photo(s) with AI
                            </button>
                        </template>

                        {{-- Clear --}}
                        <template x-if="imagePreviews.length > 0 && !processing">
                            <button type="button" @click="resetCapture()" class="ei-btn-secondary ei-btn-muted">Clear All</button>
                        </template>
                    </div>

                    {{-- Processing --}}
                    <template x-if="processing">
                        <div class="ei-processing">
                            <svg class="animate-spin ei-icon ei-icon--lg" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle opacity="0.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path opacity="0.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span class="text-sm font-medium">Analyzing <span x-text="imageFiles.length"></span> image(s) with AI…</span>
                        </div>
                    </template>

                    {{-- Error --}}
                    <template x-if="scanError">
                        <div class="ei-alert ei-alert--danger mt-4">
                            <svg class="ei-icon ei-icon--sm" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            <span x-text="scanError"></span>
                        </div>
                    </template>
                </div>

                {{-- Asset Details Form --}}
                <div class="ei-card">
                    <h3 class="ei-title ei-title--loose">Asset Details</h3>
                    {{-- Item Type Selector --}}
                    <div class="mb-4">
                        <label class="ei-label" for="ei-scan-type">Item Type</label>
                        <select id="ei-scan-type" wire:model.live="scan_type"
                            class="ei-control ei-control--narrow">
                            <option value="hardware">Hardware Asset (tracked, has serial)</option>
                            <option value="accessory">Accessory (consumable add-on)</option>
                            <option value="consumable">Consumable (disposable supply)</option>
                            <option value="component">Component (part of a larger asset)</option>
                        </select>
                        <p class="mt-1 text-xs ei-help">Determines which Snipe-IT endpoint the item is saved to.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="ei-label" for="ei-scan-brand">Brand / Manufacturer</label>
                            <input type="text" id="ei-scan-brand" :value="aiField_brand" @input="aiField_brand=$event.target.value; $wire.set('scan_brand',$event.target.value)"
                                class="ei-control" placeholder="e.g. Scott, MSA, Motorola" />
                        </div>
                        <div>
                            <label class="ei-label" for="ei-scan-model">Model</label>
                            <input type="text" id="ei-scan-model" :value="aiField_model" @input="aiField_model=$event.target.value; $wire.set('scan_model',$event.target.value)"
                                class="ei-control" placeholder="e.g. Air-Pak X3 Pro" />
                        </div>
                        <div>
                            <label class="ei-label" for="ei-scan-serial">Serial Number</label>
                            <input type="text" id="ei-scan-serial" :value="aiField_serial" @input="aiField_serial=$event.target.value; $wire.set('scan_serial',$event.target.value)"
                                class="ei-control" placeholder="e.g. SN-2024-00123" />
                        </div>
                        <div>
                            <label class="ei-label" for="ei-scan-item-name">Item / Device Name <span class="ei-help text-xs">(AI-suggested)</span></label>
                            <input type="text" id="ei-scan-item-name" :value="aiField_item_name" @input="aiField_item_name=$event.target.value"
                                class="ei-control" placeholder="e.g. 18 inch chainsaw, hydraulic spreader" />
                        </div>
                        <div>
                            <label class="ei-label" for="ei-scan-category">Category <span class="ei-help text-xs">(AI-suggested)</span></label>
                            <input type="text" id="ei-scan-category" :value="aiField_category" @input="aiField_category=$event.target.value"
                                class="ei-control" placeholder="e.g. Saw, Rescue Tool, SCBA, Fan" />
                        </div>
                        <div x-data="{ showNewLoc: false }">
                            <label class="ei-label" for="ei-scan-location">
                                Location <span class="ei-required">*</span>
                            </label>
                            <div x-show="!showNewLoc">
                                <select id="ei-scan-location" wire:model="scan_location"
                                    class="ei-control" required>
                                    <option value="">Select location...</option>
                                    @foreach($this->locations as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" @click="showNewLoc = true" class="ei-link">+ New location</button>
                            </div>
                            <div x-show="showNewLoc" class="space-y-2">
                                <input type="text" aria-label="New location name" wire:model="new_location_name"
                                    class="ei-control" placeholder="New location name..." />
                                <div class="flex gap-3">
                                    <button type="button" wire:click="createNewLocation" class="ei-link ei-link--success">Save</button>
                                    <button type="button" @click="showNewLoc = false" class="ei-link ei-link--muted">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="ei-label" for="ei-scan-notes">Notes (optional)</label>
                        <textarea id="ei-scan-notes" wire:model="scan_notes" rows="2"
                            class="ei-control"
                            placeholder="Any additional notes..."></textarea>
                    </div>
                    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <button type="button"
                            @click="isSaving=true; $wire.approveAndSaveWithData(aiField_brand, aiField_model, aiField_serial, aiField_item_name, aiField_category).then(()=>{isSaving=false})"
                            :disabled="isSaving"
                            class="ei-btn-primary">
                            <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span x-show="!isSaving">Approve &amp; Save to Snipe-IT</span>
                            <span x-show="isSaving">Saving&#x2026;</span>
                        </button>
                        <button type="button" wire:click="resetScanForm" class="ei-btn-secondary">
                            <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            Clear Form
                        </button>
                    </div>
                </div>

                @if($scan_success)
                    <div class="ei-alert ei-alert--success">
                        <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        {{ $scan_success }}
                    </div>
                @endif
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- MODE B: Bulk / Manual Entry --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'bulk'" x-cloak>
            <div class="space-y-5">
                {{-- Location --}}
                <div class="ei-card">
                    <h3 class="ei-title ei-title--tight">Manual Bulk Entry</h3>
                    <p class="ei-lede">For items without photos or serial numbers. Select location then fill the grid.</p>
                    <div x-data="{ showNewLoc2: false }">
                        <label class="ei-label" for="ei-bulk-location">Location <span class="ei-required">*</span></label>
                        <div x-show="!showNewLoc2">
                            <select id="ei-bulk-location" wire:model="bulk_location"
                                class="ei-control ei-control--narrow">
                                <option value="">Select location...</option>
                                @foreach($this->locations as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <button type="button" @click="showNewLoc2 = true" class="ei-link">+ New location</button>
                        </div>
                        <div x-show="showNewLoc2" class="space-y-2">
                            <input type="text" aria-label="New location name" wire:model="new_location_name"
                                class="ei-control ei-control--narrow" placeholder="New location name..." />
                            <div class="flex gap-3">
                                <button type="button" wire:click="createNewLocation" class="ei-link ei-link--success">Save</button>
                                <button type="button" @click="showNewLoc2 = false" class="ei-link ei-link--muted">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Grid --}}
                <div class="ei-card">
                    <h3 class="ei-title ei-title--loose">Items</h3>

                    {{-- Desktop header --}}
                    <div class="ei-bulk-head">
                        <div>Item Name</div><div>Qty</div><div>Category</div><div>Notes</div><div></div>
                    </div>

                    <div class="space-y-3">
                        @foreach($bulk_items as $index => $item)
                            <div class="ei-bulk-row">
                                <div class="ei-bulk-grid">
                                    <div class="ei-bulk-cell">
                                        <label class="ei-label ei-label--sm sm:hidden">Item Name</label>
                                        <input type="text" aria-label="Item name for item {{ $index + 1 }}" wire:model="bulk_items.{{ $index }}.name"
                                            class="ei-control" placeholder="e.g. Flashlight batteries" />
                                    </div>
                                    <div class="ei-bulk-cell">
                                        <label class="ei-label ei-label--sm sm:hidden">Qty</label>
                                        <input type="number" aria-label="Qty for item {{ $index + 1 }}" wire:model="bulk_items.{{ $index }}.quantity" min="1"
                                            class="ei-control" />
                                    </div>
                                    <div class="ei-bulk-cell">
                                        <label class="ei-label ei-label--sm sm:hidden">Category</label>
                                        <select wire:model="bulk_items.{{ $index }}.category" aria-label="Category for item {{ $index + 1 }}"
                                            class="ei-control">
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
                                    <div class="ei-bulk-cell">
                                        <label class="ei-label ei-label--sm sm:hidden">Notes</label>
                                        <input type="text" aria-label="Notes for item {{ $index + 1 }}" wire:model="bulk_items.{{ $index }}.notes"
                                            class="ei-control" placeholder="Optional" />
                                    </div>
                                    <div class="ei-bulk-actions">
                                        @if(count($bulk_items) > 1)
                                            <button type="button" wire:click="removeBulkRow({{ $index }})"
                                                class="ei-icon-btn" title="Remove" aria-label="Remove row">
                                                <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                        <button type="button" wire:click="addBulkRow" class="ei-btn-secondary">
                            <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Add Row
                        </button>
                        <button type="button" wire:click="submitBulkItems" wire:loading.attr="disabled" class="ei-btn-primary">
                            <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                            <span wire:loading.remove wire:target="submitBulkItems">Submit All to Snipe-IT</span>
                            <span wire:loading wire:target="submitBulkItems">Submitting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- MODE C: AI Bulk Import --}}
        {{-- ============================================================ --}}
        <div x-show="activeTab === 'ai_bulk'" x-cloak>
            <div x-data="aiBulkScanner()" class="space-y-5">

                {{-- Upload Section --}}
                <div class="ei-card">
                    <h3 class="ei-title ei-title--tight">AI Bulk Photo Import</h3>
                    <p class="ei-lede">
                        Select up to 20 photos of <strong>different</strong> tools from your camera roll or gallery.
                        The AI will analyze each photo and fill in Brand, Model &amp; Serial for you to review.
                    </p>

                    {{-- Big upload zone — always visible --}}
                    <label class="ei-upload-zone" :class="totalImages > 0 && doneImages < totalImages ? 'pointer-events-none opacity-75' : ''">
                        <svg class="ei-icon ei-icon--hero ei-icon--muted" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                        </svg>
                        <div>
                            <p class="ei-upload-title">Tap to select equipment photos</p>
                            <p class="ei-upload-hint">Up to 20 photos • All image formats supported</p>
                        </div>
                        <input type="file" accept="image/*" multiple class="ei-file-input" @change="startBulkAnalysis($event)" x-ref="bulkInput" />
                    </label>

                    {{-- Progress --}}
                    <div x-show="totalImages > 0" class="mt-4">
                        <div class="ei-progress-head">
                            <span class="ei-text-secondary" x-text="`Analyzing ${doneImages} of ${totalImages} photos…`"></span>
                            <span x-show="doneImages === totalImages && totalImages > 0" class="ei-done">✓ Done</span>
                        </div>
                        <div class="ei-progress-bar">
                            <div class="ei-progress-fill" :style="`width:${totalImages > 0 ? Math.round((doneImages/totalImages)*100) : 0}%`"></div>
                        </div>
                    </div>
                </div>

                {{-- Global Location bar (shows when results exist) --}}
                @if(count($ai_bulk_items) > 0)
                    <div class="ei-card">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div x-data="{ showNewLoc3: false }" class="ei-loc-field">
                                <label class="ei-label" for="ei-ai-bulk-location">
                                    Bulk Location <span class="ei-required">*</span>
                                </label>
                                <div x-show="!showNewLoc3">
                                    <select id="ei-ai-bulk-location" wire:model="ai_bulk_global_location"
                                        class="ei-control">
                                        <option value="">Select location...</option>
                                        @foreach($this->locations as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" @click="showNewLoc3 = true" class="ei-link">+ New location</button>
                                </div>
                                <div x-show="showNewLoc3" class="space-y-2">
                                    <input type="text" aria-label="New location name" wire:model="new_location_name"
                                        class="ei-control" placeholder="New location name..." />
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="createNewLocation" class="ei-link ei-link--success">Save</button>
                                        <button type="button" @click="showNewLoc3 = false" class="ei-link ei-link--muted">Cancel</button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" wire:click="applyGlobalLocationToBulk" class="ei-btn-secondary">
                                <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 16.5v-9m0 0l-3 3m3-3l3 3m6 3v-9m0 0l-3 3m3-3l3 3" /></svg>
                                Apply to All Rows
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Results Grid --}}
                @if(count($ai_bulk_items) > 0)
                    <div class="ei-card">
                        <div class="ei-results-head">
                            <h3 class="ei-title">Review &amp; Edit ({{ count($ai_bulk_items) }} items)</h3>
                            <button type="button" wire:click="resetAiBulk" class="ei-link ei-link--muted">Clear All</button>
                        </div>

                        <div class="space-y-4">
                            @foreach($ai_bulk_items as $idx => $row)
                                <div class="ei-result">
                                    {{-- Row header: thumbnail + error or fields --}}
                                    @if(!empty($row['error']))
                                        <div class="ei-alert ei-alert--danger">
                                            <svg class="ei-icon ei-icon--xs" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                            <span>{{ $row['error'] }}</span>
                                        </div>
                                    @else
                                        {{-- Thumbnail + fields layout --}}
                                        <div class="flex gap-3 items-start mb-3">
                                            @if(!empty($row['thumbnail']))
                                                <img src="{{ $row['thumbnail'] }}" class="ei-result__thumb" alt="" />
                                            @else
                                                <div class="ei-result__thumb ei-result__thumb--empty">
                                                    <svg class="ei-icon ei-icon--xl ei-icon--muted" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z" /></svg>
                                                </div>
                                            @endif
                                            <div class="flex-1 min-w-0">
                                                <div class="ei-result__index">Item #{{ $idx + 1 }}</div>
                                                <div class="ei-result__name">
                                                    {{ !empty($row['brand']) ? $row['brand'] : '—' }}
                                                    {{ !empty($row['model']) ? '· '.$row['model'] : '' }}
                                                </div>
                                                <div class="ei-result__meta">{{ !empty($row['serial']) ? 'S/N: '.$row['serial'] : 'No serial' }}</div>
                                            </div>
                                            <button type="button" wire:click="removeAiBulkRow({{ $idx }})"
                                                class="ei-icon-btn" title="Remove" aria-label="Remove item">
                                                <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>

                                        {{-- Editable fields --}}
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="ei-label ei-label--sm" for="ei-ai-brand-{{ $idx }}">Brand</label>
                                                <input type="text" id="ei-ai-brand-{{ $idx }}" wire:model="ai_bulk_items.{{ $idx }}.brand"
                                                    class="ei-control ei-control--compact" />
                                            </div>
                                            <div>
                                                <label class="ei-label ei-label--sm" for="ei-ai-model-{{ $idx }}">Model</label>
                                                <input type="text" id="ei-ai-model-{{ $idx }}" wire:model="ai_bulk_items.{{ $idx }}.model"
                                                    class="ei-control ei-control--compact" />
                                            </div>
                                            <div>
                                                <label class="ei-label ei-label--sm" for="ei-ai-serial-{{ $idx }}">Serial #</label>
                                                <input type="text" id="ei-ai-serial-{{ $idx }}" wire:model="ai_bulk_items.{{ $idx }}.serial"
                                                    class="ei-control ei-control--compact" />
                                            </div>
                                            <div>
                                                <label class="ei-label ei-label--sm">Category</label>
                                                <select wire:model="ai_bulk_items.{{ $idx }}.category" aria-label="Category for scanned item {{ $idx + 1 }}"
                                                    class="ei-control ei-control--compact">
                                                    <option value="General">General</option>
                                                    <option value="Tool">Tool</option>
                                                    <option value="PPE">PPE</option>
                                                    <option value="Electronics">Electronics</option>
                                                    <option value="Hose">Hose / Fitting</option>
                                                    <option value="Medical">Medical Supply</option>
                                                    <option value="Other">Other</option>
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="ei-label ei-label--sm">Location <span class="ei-required">*</span></label>
                                                <select wire:model="ai_bulk_items.{{ $idx }}.location_id" aria-label="Location for scanned item {{ $idx + 1 }}"
                                                    class="ei-control ei-control--compact">
                                                    <option value="">Select location...</option>
                                                    @foreach($this->locations as $id => $name)
                                                        <option value="{{ $id }}">{{ $name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                            <button type="button" wire:click="submitAiBulkItems" wire:loading.attr="disabled" class="ei-btn-primary">
                                <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                                <span wire:loading.remove wire:target="submitAiBulkItems">Submit All to Snipe-IT</span>
                                <span wire:loading wire:target="submitAiBulkItems">Submitting…</span>
                            </button>
                            <button type="button" wire:click="resetAiBulk" class="ei-btn-secondary">
                                <svg class="ei-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                Start Over
                            </button>
                        </div>
                    </div>
                @endif

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
                aiField_brand: @js($scan_brand ?? ''),
                aiField_model: @js($scan_model ?? ''),
                aiField_serial: @js($scan_serial ?? ''),
                aiField_item_name: @js($scan_item_name ?? ''),
                aiField_category: @js($scan_category ?? ''),
                isSaving: false,

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

                        // Set Alpine state immediately (instant DOM update, no Livewire latency)
                        this.aiField_brand     = data.brand     || '';
                        this.aiField_model     = data.model     || '';
                        this.aiField_serial    = data.serial    || '';
                        this.aiField_item_name = data.item_name || '';
                        this.aiField_category  = data.category  || '';

                        this.processing = false;

                        // Show status message
                        const extracted = [];
                        if (data.brand)  extracted.push('Brand: ' + data.brand);
                        if (data.model)  extracted.push('Model: ' + data.model);
                        if (data.serial) extracted.push('Serial: ' + data.serial);

                        if (extracted.length > 0) {
                            this.scanStatus = '\u2705 AI extracted: ' + extracted.join(' · ') + ' (confidence: ' + (data.confidence || 'low') + '). Review the fields below, select a Location, and click Approve & Save.';
                            this.scanStatusType = 'success';
                        } else {
                            this.scanStatus = '\u26a0\ufe0f AI analyzed the photo but could not read equipment labels (confidence: ' + (data.confidence || 'low') + '). ' + (data.notes ? 'Notes: ' + data.notes + '. ' : '') + 'Please fill in the fields manually.';
                            this.scanStatusType = 'warn';
                        }

                    } catch (err) {
                        this.processing = false;
                        this.scanError = 'AI scan failed: ' + err.message + '. You can fill in the fields manually.';
                        this.scanStatus = '\u274c Scan failed: ' + (err.message || 'Unknown error') + '. Fill in the form manually.';
                        this.scanStatusType = 'error';
                        this.$wire.handleScanError(err.message || 'Unknown error');
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
                    this.scanStatus = '';
                    this.aiField_brand     = '';
                    this.aiField_model     = '';
                    this.aiField_serial    = '';
                    this.aiField_item_name = '';
                    this.aiField_category  = '';
                    this.isSaving = false;
                    if (this.$refs.cameraInput) this.$refs.cameraInput.value = '';
                    if (this.$refs.galleryInput) this.$refs.galleryInput.value = '';
                },
            };
        }

        // -----------------------------------------------------------------------
        // AI Bulk Scanner — Mode C
        // -----------------------------------------------------------------------
        function aiBulkScanner() {
            return {
                totalImages: 0,
                doneImages: 0,

                async startBulkAnalysis(event) {
                    const files = Array.from(event.target.files).slice(0, 20);
                    if (!files.length) return;

                    this.totalImages = files.length;
                    this.doneImages  = 0;

                    // Reset the file input so the same file can be re-selected
                    event.target.value = '';

                    // Process sequentially to avoid hammering the Worker and
                    // to keep Livewire calls ordered
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        const thumbnail = URL.createObjectURL(file);

                        try {
                            const base64 = await this.fileToBase64(file);

                            const response = await fetch('https://vision-agent.pdarleyjr.workers.dev', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ image: base64 }),
                            });

                            if (!response.ok) throw new Error(`Vision API ${response.status}`);

                            const data = await response.json();

                            await this.$wire.aiBulkAddResult(
                                data.brand || 'Unknown',
                                data.model || 'Unknown',
                                data.serial || 'Unknown',
                                thumbnail,
                                -1
                            );
                        } catch (err) {
                            await this.$wire.aiBulkRowError(
                                'Scan failed: ' + err.message,
                                -1
                            );
                        }

                        this.doneImages++;
                    }
                },

                fileToBase64(file) {
                    return new Promise((resolve, reject) => {
                        // Compress before sending: canvas rescale to max 1024px
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const img = new Image();
                            img.onload = () => {
                                const MAX = 1024;
                                let w = img.width, h = img.height;
                                if (w > MAX || h > MAX) {
                                    if (w >= h) { h = Math.round(h * MAX / w); w = MAX; }
                                    else        { w = Math.round(w * MAX / h); h = MAX; }
                                }
                                const canvas = document.createElement('canvas');
                                canvas.width = w; canvas.height = h;
                                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                                // Return raw base64 (no data-URI prefix)
                                resolve(canvas.toDataURL('image/jpeg', 0.80).split(',')[1]);
                            };
                            img.onerror = reject;
                            img.src = e.target.result;
                        };
                        reader.onerror = reject;
                        reader.readAsDataURL(file);
                    });
                },
            };
        }
    </script>
    @endpush
</x-filament-panels::page>

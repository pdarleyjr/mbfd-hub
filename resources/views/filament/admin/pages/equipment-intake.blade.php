{{--
  Equipment Intake — Mobile-first Blade view
  Tested on: smartphone (portrait/landscape), tablet, desktop
  Rules:
    - NO violet-* classes (not in Filament CSS purge)
    - NO bare SVG heroicons for decorative large icons (use inline style for sizing)
    - All touch targets min 48px height
    - File inputs use capture="environment" for camera, multiple for gallery
--}}
<x-filament-panels::page>
    {{-- Scoped inline styles for items not in Tailwind purge --}}
    <style>
        .ei-upload-zone {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 1rem;
            padding: 2rem 1rem; text-align: center;
            border: 2px dashed #e5e7eb; border-radius: 0.75rem;
            background: #f9fafb; cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .ei-upload-zone:hover { border-color: #6366f1; background: #eef2ff; }
        .ei-upload-zone svg { width: 3rem; height: 3rem; color: #9ca3af; }
        .ei-btn-primary {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 0.5rem; padding: 0.875rem 1.5rem; font-size: 0.9375rem;
            font-weight: 600; border-radius: 0.5rem; border: none; cursor: pointer;
            min-height: 48px; width: 100%; background: #4f46e5; color: #fff;
            transition: background 0.15s; text-align: center;
        }
        .ei-btn-primary:hover { background: #4338ca; }
        .ei-btn-primary:active { background: #3730a3; }
        .ei-btn-secondary {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 0.5rem; padding: 0.875rem 1.5rem; font-size: 0.9375rem;
            font-weight: 600; border-radius: 0.5rem; border: 1px solid #d1d5db;
            cursor: pointer; min-height: 48px; width: 100%; background: #fff;
            color: #374151; transition: background 0.15s; text-align: center;
        }
        .ei-btn-secondary:hover { background: #f9fafb; }
        .ei-tab-btn {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.75rem 1rem; font-size: 0.875rem; font-weight: 500;
            border-bottom: 2px solid transparent; color: #6b7280;
            background: none; border-left: none; border-right: none; border-top: none;
            cursor: pointer; white-space: nowrap; transition: color 0.15s, border-color 0.15s;
            min-height: 48px;
        }
        .ei-tab-btn.active { border-bottom-color: #4f46e5; color: #4f46e5; font-weight: 600; }
        .ei-tab-btn svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
        .ei-progress-bar { height: 0.5rem; background: #e5e7eb; border-radius: 9999px; overflow: hidden; }
        .ei-progress-fill { height: 100%; background: #4f46e5; border-radius: 9999px; transition: width 0.3s; }
        @media (min-width: 640px) {
            .ei-btn-primary, .ei-btn-secondary { width: auto; }
        }
    </style>

    {{-- Tab Switcher --}}
    <div x-data="{ activeTab: 'scan' }" class="space-y-5">
        {{-- Tab Navigation --}}
        <div class="flex border-b border-gray-200 overflow-x-auto -mb-px">
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
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1">Capture Equipment Photos</h3>
                    <p class="text-sm text-gray-500 mb-4">Take up to 3 photos of the same tool (e.g. one of the tool body, one of the data plate). AI will combine them for best results.</p>

                    {{-- Thumbnail strip --}}
                    <template x-if="imagePreviews.length > 0">
                        <div class="flex flex-wrap gap-3 mb-4">
                            <template x-for="(preview, idx) in imagePreviews" :key="idx">
                                <div class="relative">
                                    <img :src="preview" style="width:80px;height:80px;object-fit:cover;border-radius:0.5rem;display:block;" />
                                    <button type="button" @click="removeImage(idx)"
                                        style="position:absolute;top:-8px;right:-8px;width:22px;height:22px;background:#ef4444;color:#fff;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:bold;line-height:1;"
                                        aria-label="Remove photo">&times;</button>
                                    <span style="position:absolute;bottom:4px;right:4px;background:rgba(0,0,0,0.6);color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;" x-text="'#'+(idx+1)"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Action Buttons --}}
                    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        {{-- Take Photo — mobile camera --}}
                        <label class="ei-btn-primary" style="cursor:pointer;" :class="imageFiles.length >= 3 ? 'opacity-50 pointer-events-none' : ''">
                            <svg style="width:20px;height:20px;flex-shrink:0;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" /></svg>
                            <span x-text="imageFiles.length === 0 ? 'Take Photo' : 'Add Another Photo'"></span>
                            <input type="file" accept="image/*" capture="environment" style="display:none;" @change="handleCapture($event)" x-ref="cameraInput" />
                        </label>

                        {{-- Choose from Gallery --}}
                        <label class="ei-btn-secondary" style="cursor:pointer;" :class="imageFiles.length >= 3 ? 'opacity-50 pointer-events-none' : ''">
                            <svg style="width:20px;height:20px;flex-shrink:0;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                            <span>Choose from Gallery</span>
                            <input type="file" accept="image/*" multiple style="display:none;" @change="handleGallerySelect($event)" x-ref="galleryInput" />
                        </label>

                        {{-- Analyze --}}
                        <template x-if="imagePreviews.length > 0 && !processing">
                            <button type="button" @click="analyzeAllImages()" class="ei-btn-primary" style="background:#16a34a;">
                                <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                                Analyze <span x-text="imagePreviews.length"></span> Photo(s) with AI
                            </button>
                        </template>

                        {{-- Clear --}}
                        <template x-if="imagePreviews.length > 0 && !processing">
                            <button type="button" @click="resetCapture()" class="ei-btn-secondary" style="color:#6b7280;">Clear All</button>
                        </template>
                    </div>

                    {{-- Processing --}}
                    <template x-if="processing">
                        <div class="mt-4 flex items-center gap-3" style="color:#4f46e5;">
                            <svg class="animate-spin" style="width:24px;height:24px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle style="opacity:0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path style="opacity:0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span class="text-sm font-medium">Analyzing <span x-text="imageFiles.length"></span> image(s) with AI…</span>
                        </div>
                    </template>

                    {{-- Error --}}
                    <template x-if="scanError">
                        <div class="mt-4 p-3 rounded-lg text-sm flex items-start gap-2" style="background:#fef2f2;color:#dc2626;">
                            <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            <span x-text="scanError"></span>
                        </div>
                    </template>
                </div>

                {{-- Asset Details Form --}}
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Asset Details</h3>
                    {{-- Item Type Selector --}}
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Item Type</label>
                        <select wire:model.live="scan_type"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base sm:max-w-xs"
                            style="min-height:44px;">
                            <option value="hardware">Hardware Asset (tracked, has serial)</option>
                            <option value="accessory">Accessory (consumable add-on)</option>
                            <option value="consumable">Consumable (disposable supply)</option>
                            <option value="component">Component (part of a larger asset)</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Determines which Snipe-IT endpoint the item is saved to.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Brand / Manufacturer</label>
                            <input type="text" wire:model="scan_brand"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                style="min-height:44px;" placeholder="e.g. Scott, MSA, Motorola" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Model</label>
                            <input type="text" wire:model="scan_model"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                style="min-height:44px;" placeholder="e.g. Air-Pak X3 Pro" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Serial Number</label>
                            <input type="text" wire:model="scan_serial"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                                style="min-height:44px;" placeholder="e.g. SN-2024-00123" />
                        </div>
                        <div x-data="{ showNewLoc: false }">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Location <span style="color:#dc2626;">*</span>
                            </label>
                            <div x-show="!showNewLoc">
                                <select wire:model="scan_location"
                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                    style="min-height:44px;" required>
                                    <option value="">Select location...</option>
                                    @foreach($this->locations as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" @click="showNewLoc = true" class="mt-1 text-sm" style="color:#4f46e5;">+ New location</button>
                            </div>
                            <div x-show="showNewLoc" class="space-y-2">
                                <input type="text" wire:model="new_location_name"
                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                    style="min-height:44px;" placeholder="New location name..." />
                                <div class="flex gap-3">
                                    <button type="button" wire:click="createNewLocation" class="text-sm font-medium" style="color:#16a34a;">Save</button>
                                    <button type="button" @click="showNewLoc = false" class="text-sm" style="color:#6b7280;">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                        <textarea wire:model="scan_notes" rows="2"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                            placeholder="Any additional notes..."></textarea>
                    </div>
                    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <button type="button" wire:click="approveAndSave" wire:loading.attr="disabled" class="ei-btn-primary">
                            <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span wire:loading.remove wire:target="approveAndSave">Approve & Save to Snipe-IT</span>
                            <span wire:loading wire:target="approveAndSave">Saving…</span>
                        </button>
                        <button type="button" wire:click="resetScanForm" class="ei-btn-secondary">
                            <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            Clear Form
                        </button>
                    </div>
                </div>

                @if($scan_success)
                    <div class="p-4 rounded-lg text-sm flex items-center gap-2" style="background:#f0fdf4;color:#16a34a;">
                        <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
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
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1">Manual Bulk Entry</h3>
                    <p class="text-sm text-gray-500 mb-4">For items without photos or serial numbers. Select location then fill the grid.</p>
                    <div x-data="{ showNewLoc2: false }">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Location <span style="color:#dc2626;">*</span></label>
                        <div x-show="!showNewLoc2">
                            <select wire:model="bulk_location"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="max-width:320px;min-height:44px;">
                                <option value="">Select location...</option>
                                @foreach($this->locations as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <button type="button" @click="showNewLoc2 = true" class="mt-1 text-sm" style="color:#4f46e5;">+ New location</button>
                        </div>
                        <div x-show="showNewLoc2" class="space-y-2">
                            <input type="text" wire:model="new_location_name"
                                class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                style="max-width:320px;min-height:44px;" placeholder="New location name..." />
                            <div class="flex gap-3">
                                <button type="button" wire:click="createNewLocation" class="text-sm font-medium" style="color:#16a34a;">Save</button>
                                <button type="button" @click="showNewLoc2 = false" class="text-sm" style="color:#6b7280;">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Grid --}}
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Items</h3>

                    {{-- Desktop header --}}
                    <div class="hidden sm:grid gap-2 mb-2 text-xs font-semibold text-gray-500 uppercase tracking-wider px-1" style="grid-template-columns: 2fr 80px 1fr 1fr 40px;">
                        <div>Item Name</div><div>Qty</div><div>Category</div><div>Notes</div><div></div>
                    </div>

                    <div class="space-y-3">
                        @foreach($bulk_items as $index => $item)
                            <div class="rounded-lg p-3 sm:p-0" style="background:#f9fafb;" class="sm:background-transparent">
                                <div class="grid grid-cols-1 sm:gap-2 sm:items-center" style="@media(min-width:640px){grid-template-columns: 2fr 80px 1fr 1fr 40px;}">
                                    <div class="mb-2 sm:mb-0">
                                        <label class="sm:hidden block text-xs font-medium text-gray-500 mb-1">Item Name</label>
                                        <input type="text" wire:model="bulk_items.{{ $index }}.name"
                                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                            style="min-height:44px;" placeholder="e.g. Flashlight batteries" />
                                    </div>
                                    <div class="mb-2 sm:mb-0">
                                        <label class="sm:hidden block text-xs font-medium text-gray-500 mb-1">Qty</label>
                                        <input type="number" wire:model="bulk_items.{{ $index }}.quantity" min="1"
                                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                            style="min-height:44px;" />
                                    </div>
                                    <div class="mb-2 sm:mb-0">
                                        <label class="sm:hidden block text-xs font-medium text-gray-500 mb-1">Category</label>
                                        <select wire:model="bulk_items.{{ $index }}.category"
                                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:44px;">
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
                                    <div class="mb-2 sm:mb-0">
                                        <label class="sm:hidden block text-xs font-medium text-gray-500 mb-1">Notes</label>
                                        <input type="text" wire:model="bulk_items.{{ $index }}.notes"
                                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                            style="min-height:44px;" placeholder="Optional" />
                                    </div>
                                    <div class="flex justify-end sm:justify-center">
                                        @if(count($bulk_items) > 1)
                                            <button type="button" wire:click="removeBulkRow({{ $index }})"
                                                style="color:#9ca3af;padding:8px;border:none;background:none;cursor:pointer;" title="Remove">
                                                <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                        <button type="button" wire:click="addBulkRow" class="ei-btn-secondary">
                            <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Add Row
                        </button>
                        <button type="button" wire:click="submitBulkItems" wire:loading.attr="disabled" class="ei-btn-primary">
                            <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
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
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-1">AI Bulk Photo Import</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Select up to 20 photos of <strong>different</strong> tools from your camera roll or gallery.
                        The AI will analyze each photo and fill in Brand, Model &amp; Serial for you to review.
                    </p>

                    {{-- Big upload zone — always visible --}}
                    <label class="ei-upload-zone" style="cursor:pointer;" :class="totalImages > 0 && doneImages < totalImages ? 'pointer-events-none opacity-75' : ''">
                        <svg style="width:48px;height:48px;color:#9ca3af;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                        </svg>
                        <div>
                            <p class="text-base font-semibold text-gray-700">Tap to select equipment photos</p>
                            <p class="text-sm text-gray-500 mt-1">Up to 20 photos • All image formats supported</p>
                        </div>
                        <input type="file" accept="image/*" multiple style="display:none;" @change="startBulkAnalysis($event)" x-ref="bulkInput" />
                    </label>

                    {{-- Progress --}}
                    <div x-show="totalImages > 0" class="mt-4">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span class="text-sm text-gray-600" x-text="`Analyzing ${doneImages} of ${totalImages} photos…`"></span>
                            <span x-show="doneImages === totalImages && totalImages > 0" class="text-sm font-semibold" style="color:#16a34a;">✓ Done</span>
                        </div>
                        <div class="ei-progress-bar">
                            <div class="ei-progress-fill" :style="`width:${totalImages > 0 ? Math.round((doneImages/totalImages)*100) : 0}%`"></div>
                        </div>
                    </div>
                </div>

                {{-- Global Location bar (shows when results exist) --}}
                @if(count($ai_bulk_items) > 0)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div x-data="{ showNewLoc3: false }" class="flex-1" style="max-width:360px;">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Bulk Location <span style="color:#dc2626;">*</span>
                                </label>
                                <div x-show="!showNewLoc3">
                                    <select wire:model="ai_bulk_global_location"
                                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:44px;">
                                        <option value="">Select location...</option>
                                        @foreach($this->locations as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" @click="showNewLoc3 = true" class="mt-1 text-sm" style="color:#4f46e5;">+ New location</button>
                                </div>
                                <div x-show="showNewLoc3" class="space-y-2">
                                    <input type="text" wire:model="new_location_name"
                                        class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base"
                                        style="min-height:44px;" placeholder="New location name..." />
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="createNewLocation" class="text-sm font-medium" style="color:#16a34a;">Save</button>
                                        <button type="button" @click="showNewLoc3 = false" class="text-sm" style="color:#6b7280;">Cancel</button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" wire:click="applyGlobalLocationToBulk" class="ei-btn-secondary">
                                <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 16.5v-9m0 0l-3 3m3-3l3 3m6 3v-9m0 0l-3 3m3-3l3 3" /></svg>
                                Apply to All Rows
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Results Grid --}}
                @if(count($ai_bulk_items) > 0)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-6">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                            <h3 class="text-base font-semibold text-gray-900">Review &amp; Edit ({{ count($ai_bulk_items) }} items)</h3>
                            <button type="button" wire:click="resetAiBulk" class="text-sm" style="color:#9ca3af;">Clear All</button>
                        </div>

                        <div class="space-y-4">
                            @foreach($ai_bulk_items as $idx => $row)
                                <div class="rounded-xl p-3 sm:p-4 ring-1" style="background:#f9fafb;ring-color:#f3f4f6;">
                                    {{-- Row header: thumbnail + error or fields --}}
                                    @if(!empty($row['error']))
                                        <div style="display:flex;align-items:center;gap:8px;color:#dc2626;background:#fef2f2;padding:8px 12px;border-radius:8px;font-size:14px;">
                                            <svg style="width:16px;height:16px;flex-shrink:0;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                                            <span>{{ $row['error'] }}</span>
                                        </div>
                                    @else
                                        {{-- Thumbnail + fields layout --}}
                                        <div class="flex gap-3 items-start mb-3">
                                            @if(!empty($row['thumbnail']))
                                                <img src="{{ $row['thumbnail'] }}" style="width:64px;height:64px;object-fit:cover;border-radius:8px;flex-shrink:0;border:1px solid #e5e7eb;" />
                                            @else
                                                <div style="width:64px;height:64px;background:#e5e7eb;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                                                    <svg style="width:28px;height:28px;color:#9ca3af;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z" /></svg>
                                                </div>
                                            @endif
                                            <div class="flex-1 min-w-0">
                                                <div class="text-xs font-semibold text-gray-500 mb-1">Item #{{ $idx + 1 }}</div>
                                                <div class="text-sm font-medium text-gray-800">
                                                    {{ !empty($row['brand']) ? $row['brand'] : '—' }}
                                                    {{ !empty($row['model']) ? '· '.$row['model'] : '' }}
                                                </div>
                                                <div class="text-xs text-gray-500">{{ !empty($row['serial']) ? 'S/N: '.$row['serial'] : 'No serial' }}</div>
                                            </div>
                                            <button type="button" wire:click="removeAiBulkRow({{ $idx }})"
                                                style="color:#9ca3af;border:none;background:none;cursor:pointer;padding:4px;flex-shrink:0;" title="Remove">
                                                <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>

                                        {{-- Editable fields --}}
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 mb-1">Brand</label>
                                                <input type="text" wire:model="ai_bulk_items.{{ $idx }}.brand"
                                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:40px;" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 mb-1">Model</label>
                                                <input type="text" wire:model="ai_bulk_items.{{ $idx }}.model"
                                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:40px;" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 mb-1">Serial #</label>
                                                <input type="text" wire:model="ai_bulk_items.{{ $idx }}.serial"
                                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:40px;" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                                                <select wire:model="ai_bulk_items.{{ $idx }}.category"
                                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:40px;">
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
                                                <label class="block text-xs font-medium text-gray-500 mb-1">Location <span style="color:#dc2626;">*</span></label>
                                                <select wire:model="ai_bulk_items.{{ $idx }}.location_id"
                                                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-base" style="min-height:40px;">
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
                                <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                                <span wire:loading.remove wire:target="submitAiBulkItems">Submit All to Snipe-IT</span>
                                <span wire:loading wire:target="submitAiBulkItems">Submitting…</span>
                            </button>
                            <button type="button" wire:click="resetAiBulk" class="ei-btn-secondary">
                                <svg style="width:20px;height:20px;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
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

                        // Send parsed data to Livewire — MUST await so DOM patches apply
                        await this.$wire.processVisionResult(
                            data.brand || '',
                            data.model || '',
                            data.serial || '',
                            data.notes || ''
                        );

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

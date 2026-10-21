<style>
    .personnel-highlight-gray > td, td.personnel-cell-highlight-gray { background-color: #f3f4f6; color: #111827; }
    .personnel-highlight-info > td, td.personnel-cell-highlight-info { background-color: #eff6ff; color: #111827; }
    .personnel-highlight-success > td, td.personnel-cell-highlight-success { background-color: #f0fdf4; color: #111827; }
    .personnel-highlight-warning > td, td.personnel-cell-highlight-warning { background-color: #fffbeb; color: #111827; }
    .personnel-highlight-danger > td, td.personnel-cell-highlight-danger { background-color: #fef2f2; color: #111827; }
    .dark .personnel-highlight-gray > td, .dark td.personnel-cell-highlight-gray { background-color: #1f2937; color: #f9fafb; }
    .dark .personnel-highlight-info > td, .dark td.personnel-cell-highlight-info { background-color: #172554; color: #dbeafe; }
    .dark .personnel-highlight-success > td, .dark td.personnel-cell-highlight-success { background-color: #052e16; color: #dcfce7; }
    .dark .personnel-highlight-warning > td, .dark td.personnel-cell-highlight-warning { background-color: #451a03; color: #fef3c7; }
    .dark .personnel-highlight-danger > td, .dark td.personnel-cell-highlight-danger { background-color: #450a0a; color: #fee2e2; }
</style>
@foreach (['conflict', 'employee', 'column', 'value', 'table', 'settings'] as $personnelErrorKey)
    @error($personnelErrorKey)
        <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="alert">{{ $message }}</div>
    @enderror
@endforeach

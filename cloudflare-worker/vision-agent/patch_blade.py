#!/usr/bin/env python3
"""
Surgical patch to fix @this -> $wire in equipment-intake.blade.php
Also adds status messages to the Alpine.js scanner component.
"""

import re

BLADE = '/root/mbfd-hub/resources/views/filament/admin/pages/equipment-intake.blade.php'

with open(BLADE, 'r', encoding='utf-8') as f:
    content = f.read()

orig = content

# Fix 1: Replace @this.processVisionResult with $wire equivalent
# The current code: @this.processVisionResult( data.brand || '', data.model || '', data.serial || '' );
# New code: await this.$wire.processVisionResult( data.brand || '', data.model || '', data.serial || '', data.notes || '' );
content = content.replace(
    "@this.processVisionResult(\n                            data.brand || '',\n                            data.model || '',\n                            data.serial || ''\n                        );",
    "await this.$wire.processVisionResult(\n                            data.brand || '',\n                            data.model || '',\n                            data.serial || '',\n                            data.notes || ''\n                        );"
)

# Fix 2: Replace @this.handleScanError
content = content.replace(
    "@this.handleScanError(err.message);",
    "this.$wire.handleScanError(err.message || 'Unknown error');"
)

# Fix 3: Replace @this.aiBulkAddResult
content = content.replace("await @this.aiBulkAddResult(", "await this.$wire.aiBulkAddResult(")

# Fix 4: Replace @this.aiBulkRowError
content = content.replace("await @this.aiBulkRowError(", "await this.$wire.aiBulkRowError(")

# Fix 5: Add scanStatus property to the equipmentScanner Alpine component
# Find: "imageFiles: [],\n                processing: false,"
# Replace with: "imageFiles: [],\n                processing: false,\n                scanStatus: '',\n                scanStatusType: 'info',"
content = content.replace(
    "imageFiles: [],\n                processing: false,\n                scanError: null,",
    "imageFiles: [],\n                processing: false,\n                scanError: null,\n                scanStatus: '',\n                scanStatusType: 'info',"
)

# Fix 6: After the $wire.processVisionResult call, add status update code
# Find the section after await this.$wire.processVisionResult and add a status message
# Look for "this.processing = false;" right after the wire call
old_after_wire = """                        await this.$wire.processVisionResult(
                            data.brand || '',
                            data.model || '',
                            data.serial || '',
                            data.notes || ''
                        );

                        this.processing = false;
                    } catch (err) {"""

new_after_wire = """                        await this.$wire.processVisionResult(
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
                            this.scanStatus = '✅ AI extracted: ' + extracted.join(' · ') + ' (confidence: ' + (data.confidence || 'low') + '). Review the fields below, select a Location, and click Approve & Save.';
                            this.scanStatusType = 'success';
                        } else {
                            this.scanStatus = '⚠️ AI analyzed the photo but could not read equipment labels (confidence: ' + (data.confidence || 'low') + '). ' + (data.notes ? 'Notes: ' + data.notes + '. ' : '') + 'Please fill in the fields manually.';
                            this.scanStatusType = 'warn';
                        }

                    } catch (err) {"""

content = content.replace(old_after_wire, new_after_wire)

# Fix 7: Update the catch block to show error status  
content = content.replace(
    "this.processing = false;\n                        this.scanError = 'AI scan failed: ' + err.message + '. You can fill in the fields manually.';\n                        this.$wire.handleScanError",
    "this.processing = false;\n                        this.scanError = 'AI scan failed: ' + err.message + '. You can fill in the fields manually.';\n                        this.scanStatus = '❌ Scan failed: ' + (err.message || 'Unknown error') + '. Fill in the form manually.';\n                        this.scanStatusType = 'error';\n                        this.$wire.handleScanError"
)

# Fix 8: Add status display to the blade template after the processing indicator
# Find the scan error template and add status before it
STATUS_DISPLAY = '''
                    {{-- Status message (non-processing) --}}
                    <template x-if="!processing && scanStatus">
                        <div class="mt-4 p-3 rounded-lg text-sm font-medium"
                            :style="scanStatusType==='success' ? 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;'
                                  : scanStatusType==='warn'    ? 'background:#fffbeb;color:#92400e;border:1px solid #fde68a;'
                                  : scanStatusType==='error'   ? 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca;'
                                  :                             'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;'">
                            <span x-text="scanStatus"></span>
                        </div>
                    </template>
'''

# Insert status display before the scan error template
content = content.replace(
    '                    {{-- Error --}}\n                    <template x-if="scanError">',
    STATUS_DISPLAY + '\n                    {{-- Error --}}\n                    <template x-if="scanError">'
)

assert content != orig, "No changes were made! Check the patch logic."

with open(BLADE, 'w', encoding='utf-8') as f:
    f.write(content)

print("SUCCESS: blade file patched")

# Verify
import subprocess
result = subprocess.run(['python3', '-c', f"import subprocess; r = subprocess.run(['grep', '-c', 'wire', '{BLADE}'], capture_output=True, text=True); print('wire count:', r.stdout.strip())"], capture_output=True, text=True)
print(result.stdout)

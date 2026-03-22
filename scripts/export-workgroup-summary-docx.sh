#!/usr/bin/env bash
# ============================================================
# export-workgroup-summary-docx.sh
# Converts the MBFD Workgroup Summary Blade report to .docx
# Run from /root/mbfd-hub or pass MBFD_ROOT as env var
# ============================================================
set -euo pipefail

MBFD_ROOT="${MBFD_ROOT:-/root/mbfd-hub}"
BLADE_SRC="${MBFD_ROOT}/resources/views/workgroup/workgroup-summary.blade.php"
IMG_DIR="${MBFD_ROOT}/public/images/workgroup-summary"
EXPORT_DIR="${MBFD_ROOT}/Work_Group/report_exports/workgroup-summary"
SOURCE_DIR="${EXPORT_DIR}/source"
TEMPLATE_DIR="${EXPORT_DIR}/templates"
DIST_DIR="${EXPORT_DIR}/dist"
LOG_DIR="${EXPORT_DIR}/logs"
EXPORT_HTML="${SOURCE_DIR}/workgroup-summary-export.html"
REF_DOC="${TEMPLATE_DIR}/mbfd-workgroup-reference.docx"
OUTPUT_DOCX="${DIST_DIR}/MBFD_Workgroup_Summary.docx"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOG_FILE="${LOG_DIR}/export_${TIMESTAMP}.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "${LOG_FILE}"; }

log "=== MBFD Workgroup Summary DOCX Export ==="
log "Source blade: ${BLADE_SRC}"

# 1. Verify source exists
if [ ! -f "${BLADE_SRC}" ]; then
    log "ERROR: Blade source not found at ${BLADE_SRC}"
    exit 1
fi

# 2. Verify images exist
IMG_COUNT=$(ls "${IMG_DIR}"/*.png 2>/dev/null | wc -l)
log "Found ${IMG_COUNT} images in ${IMG_DIR}"
if [ "${IMG_COUNT}" -eq 0 ]; then
    log "WARNING: No images found. DOCX will have broken images."
fi

# 3. Ensure export directories exist
mkdir -p "${SOURCE_DIR}" "${TEMPLATE_DIR}" "${DIST_DIR}" "${LOG_DIR}"

# 4. Generate export-ready HTML
# - Replace /images/workgroup-summary/ paths with absolute local paths
# - Remove the print FAB button section (lines with print-fab through closing)
log "Generating export-ready HTML..."

sed \
    -e "s|src=\"/images/workgroup-summary/|src=\"${IMG_DIR}/|g" \
    -e '/<div class="print-fab">/,/<\/div>/d' \
    "${BLADE_SRC}" > "${EXPORT_HTML}"

log "Export HTML written to ${EXPORT_HTML}"

# 5. Generate reference doc if missing
if [ ! -f "${REF_DOC}" ]; then
    log "Generating reference docx template..."
    pandoc -o "${REF_DOC}" --print-default-data-file reference.docx
fi

# 6. Convert HTML to DOCX
log "Converting to DOCX via Pandoc..."
pandoc \
    -f html \
    -t docx \
    --reference-doc="${REF_DOC}" \
    --resource-path="${IMG_DIR}:${MBFD_ROOT}/public" \
    -o "${OUTPUT_DOCX}" \
    "${EXPORT_HTML}"

# 7. Validate output
if [ ! -f "${OUTPUT_DOCX}" ]; then
    log "ERROR: DOCX was not created!"
    exit 1
fi

DOCX_SIZE=$(stat -c%s "${OUTPUT_DOCX}")
log "DOCX created: ${OUTPUT_DOCX} (${DOCX_SIZE} bytes)"

if [ "${DOCX_SIZE}" -lt 5000 ]; then
    log "WARNING: DOCX is suspiciously small (${DOCX_SIZE} bytes). Check for issues."
fi

log "=== Export complete ==="
echo ""
echo "Output: ${OUTPUT_DOCX}"
echo "Size: ${DOCX_SIZE} bytes"

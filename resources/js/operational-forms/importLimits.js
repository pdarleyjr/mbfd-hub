// Pure, dependency-free preflight validation for the F-ROC AI import uploader.
// Kept as a plain ES module so it can be unit-tested with `node --test`
// without a TypeScript compilation step, and imported directly by the
// Operational Forms React application.

/**
 * @typedef {Object} FrocImportConfig
 * @property {string[]} accepted_extensions
 * @property {number} upload_max_bytes
 * @property {number} upload_max_megabytes
 * @property {number} max_extracted_bytes
 * @property {number} max_extracted_megabytes
 * @property {number} max_zip_entries
 */

/**
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes < 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  const rounded = value >= 100 || Number.isInteger(value) ? Math.round(value) : Math.round(value * 10) / 10;
  return `${rounded} ${units[unit]}`;
}

/**
 * @param {File} file
 * @param {FrocImportConfig} config
 * @returns {{ status: 'accepted' | 'unsupported' | 'too_large' | 'empty', message: string, media_note?: string }}
 */
export function validateImportFile(file, config) {
  if (!file) {
    return { status: 'empty', message: 'Choose a WhatsApp .zip or .txt file to analyze.' };
  }

  if (file.size === 0) {
    return { status: 'empty', message: 'The selected file is empty. Choose a WhatsApp export that contains a chat.' };
  }

  const name = (file.name || '').toLowerCase();
  const extension = name.includes('.') ? name.slice(name.lastIndexOf('.')).toLowerCase() : '';
  const accepted = (config.accepted_extensions || ['.zip', '.txt']).map((value) => value.toLowerCase());

  if (!accepted.includes(extension)) {
    return {
      status: 'unsupported',
      message:
        'This importer analyzes WhatsApp ZIP exports and plain-text TXT files. PDF, Word, image, and video files are not supported.',
    };
  }

  if (file.size > config.upload_max_bytes) {
    return {
      status: 'too_large',
      message: `This file is ${formatBytes(file.size)}. The AI importer accepts ZIP or TXT files up to ${config.upload_max_megabytes} MB.`,
    };
  }

  const mediaNote =
    extension === '.zip'
      ? `${formatBytes(file.size)} selected — accepted. Only text from the WhatsApp export will be analyzed. Photos, videos, audio, and other media will be ignored.`
      : `${formatBytes(file.size)} selected — accepted. Only the chat text will be analyzed.`;

  return { status: 'accepted', message: mediaNote, media_note: mediaNote };
}

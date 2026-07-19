import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ics214Values } from './modules/ics214.mjs';
import { frocValues } from './modules/froc-firefighter.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(scriptDirectory, '..', '..');

export function resolveForm(type, version) {
  if (type === 'ics_214' && version === '1.0') {
    const directory = path.join(root, 'resources', 'forms', 'ics-214', '1.0');
    return { directory, values: (data, mapping) => ics214Values(data, mapping) };
  }
  if (type === 'froc_log_001_ff' && version === '11') {
    const directory = path.join(root, 'resources', 'forms', 'froc-log-001-ff', '11');
    return { directory, values: frocValues };
  }
  throw new Error('Unsupported controlled form type or version.');
}

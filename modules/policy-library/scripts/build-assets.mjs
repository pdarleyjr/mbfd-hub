import { cp, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
for (const name of ['cmaps', 'standard_fonts', 'wasm']) {
    await cp(path.join(root, 'node_modules/pdfjs-dist', name), path.join(root, 'public', name), { recursive: true });
}
await mkdir(path.join(root, 'public', 'images'), { recursive: true });
await cp(path.join(root, 'resources', 'images', 'mbfd-logo.png'), path.join(root, 'public', 'images', 'mbfd-logo.png'));
await cp(path.join(root, 'node_modules', 'pdfjs-dist', 'LICENSE'), path.join(root, 'public', 'PDFJS-LICENSE.txt'));
await cp(path.join(root, 'public'), path.join(root, '..', '..', 'public', 'vendor', 'policy-library'), { recursive: true });

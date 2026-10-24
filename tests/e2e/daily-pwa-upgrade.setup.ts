import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DAILY_PWA_DIST_RELATIVE } from './support/daily-pwa-origin';

/**
 * Build the Daily app once, into a directory only this suite uses (set DAILY_PWA_UPGRADE_SKIP_BUILD=1
 * to reuse an existing build).
 */
export default function buildDaily(): void {
  const dist = resolve(process.cwd(), DAILY_PWA_DIST_RELATIVE);
  if (process.env.DAILY_PWA_UPGRADE_SKIP_BUILD === '1' && existsSync(resolve(dist, 'index.html'))) return;

  const result = spawnSync('npm', ['run', 'build'], {
    cwd: resolve(process.cwd(), 'resources/js/daily-checkout'),
    env: {
      ...process.env,
      // Relative to resources/js/daily-checkout (see vite.config.js).
      DAILY_CHECKOUT_OUT_DIR: `../../../${DAILY_PWA_DIST_RELATIVE}`,
      VITE_SENTRY_DSN: '',
      VITE_SENTRY_RELEASE: '',
    },
    encoding: 'utf8',
    shell: process.platform === 'win32',
    timeout: 240_000,
  });
  if (result.status !== 0) {
    throw new Error(`Building the Daily app failed:\n${result.stdout}\n${result.stderr}`);
  }
}

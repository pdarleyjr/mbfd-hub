/**
 * Generate a small dev fixture: 50 members × 30 days.
 *
 * Same as stress-fixture with tiny defaults — handy for local poking.
 */
import { spawnSync } from 'node:child_process';

const result = spawnSync(
  'tsx',
  [
    'scripts/stress-fixture.ts',
    '--members', '50',
    '--days', '30',
    '--out', 'fixtures/seed-dev.csv',
  ],
  { stdio: 'inherit' },
);
process.exit(result.status ?? 0);

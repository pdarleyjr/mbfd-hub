import { execFileSync } from 'node:child_process';
import { protectedUiEnvironment } from '../../playwright.protected-ui.config';
import dailyRosterSetup from './daily-roster.setup';
import { localPhpBinary } from './support/test-environment';

export default async function setup() {
  await dailyRosterSetup();
  execFileSync(localPhpBinary('PROTECTED_UI_E2E_PHP'), ['tests/e2e/support/daily-roster-fixture.php', 'outlook'], {
    cwd: process.cwd(), stdio: 'inherit', env: protectedUiEnvironment,
  });
}

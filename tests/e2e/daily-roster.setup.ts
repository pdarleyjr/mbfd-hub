import { execFileSync } from 'node:child_process';
import { protectedUiEnvironment } from '../../playwright.protected-ui.config';
import protectedUiSetup from './protected-ui.setup';
import { localPhpBinary } from './support/test-environment';

export default async function setup() {
  await protectedUiSetup();
  execFileSync(localPhpBinary('PROTECTED_UI_E2E_PHP'), ['tests/e2e/support/daily-roster-fixture.php', 'seed'], {
    cwd: process.cwd(), stdio: 'inherit', env: protectedUiEnvironment,
  });
}

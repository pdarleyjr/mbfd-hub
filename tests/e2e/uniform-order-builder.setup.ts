import { execFileSync } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';
import { uniformOrderEnvironment } from '../../playwright.uniform-order-builder.config';
import { localPhpBinary } from './support/test-environment';

export default function globalSetup(): void {
  closeSync(openSync(uniformOrderEnvironment.DB_DATABASE, 'a'));
  const php = localPhpBinary('UNIFORM_ORDER_E2E_PHP');
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: uniformOrderEnvironment };
  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\PersonnelRequestsE2ESeeder', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\BidAssignmentsE2ESeeder', '--force'], options);
  execFileSync(php, ['artisan', 'filament:assets'], options);
}

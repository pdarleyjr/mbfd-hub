import { execFileSync } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';
import { resolve } from 'node:path';

export default function globalSetup() {
  const php = process.env.PERSONNEL_REQUESTS_E2E_PHP ?? 'php';
  const database = process.env.PERSONNEL_REQUESTS_E2E_DATABASE ?? resolve(process.cwd(), 'database/personnel_requests_e2e.sqlite');
  closeSync(openSync(database, 'a'));
  const options = {
    cwd: process.cwd(),
    stdio: 'inherit' as const,
    env: {
      ...process.env,
      APP_ENV: 'testing',
      APP_KEY: process.env.APP_KEY ?? 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: database,
      QUEUE_CONNECTION: 'sync',
      SESSION_DRIVER: 'file',
    },
  };

  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\PersonnelRequestsE2ESeeder', '--force'], options);
}

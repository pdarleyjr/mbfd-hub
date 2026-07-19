import { execFileSync } from 'node:child_process';
import { openSync, closeSync } from 'node:fs';
import { resolve } from 'node:path';

export default function globalSetup() {
  const php = process.env.OPERATIONAL_FORMS_E2E_PHP ?? 'php';
  const database = process.env.OPERATIONAL_FORMS_E2E_DATABASE ?? resolve(process.cwd(), 'database/operational_forms_e2e.sqlite');
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
    },
  };

  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\OperationalFormsE2ESeeder', '--force'], options);
}

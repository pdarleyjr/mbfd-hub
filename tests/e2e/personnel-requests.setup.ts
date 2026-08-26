import { execFileSync } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  environmentValue,
  isolatedSqliteDatabasePath,
  localPhpBinary,
  sanitizedTestEnvironment,
} from './support/test-environment';

export default function globalSetup() {
  const php = localPhpBinary('PERSONNEL_REQUESTS_E2E_PHP');
  const database = isolatedSqliteDatabasePath('PERSONNEL_REQUESTS_E2E_DATABASE', 'personnel_requests_e2e.sqlite');
  closeSync(openSync(database, 'a'));
  const options = {
    cwd: process.cwd(),
    stdio: 'inherit' as const,
    env: sanitizedTestEnvironment({
      APP_ENV: 'testing',
      APP_KEY: disposableTestAppKey,
      APP_URL: 'http://127.0.0.1:8018',
      BROADCAST_DRIVER: 'log',
      CACHE_STORE: 'array',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: database,
      FILESYSTEM_DISK: 'local',
      MAIL_MAILER: 'array',
      PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD: environmentValue('PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD') ?? '',
      PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD: environmentValue('PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD') ?? '',
      PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD: environmentValue('PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD') ?? '',
      PRIVATE_FILESYSTEM_DISK: 'local',
      QUEUE_CONNECTION: 'sync',
      SESSION_DRIVER: 'file',
    }),
  };

  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\PersonnelRequestsE2ESeeder', '--force'], options);
}

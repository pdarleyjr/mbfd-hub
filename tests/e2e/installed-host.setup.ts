import { execFileSync } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  isolatedSqliteDatabasePath,
  localPhpBinary,
  sanitizedTestEnvironment,
} from './support/test-environment';

/** Disposable Laravel instance answering on two same-site hosts, like www.mbfdhub.com and mbfdhub.com. */
export const INSTALLED_HOST_PORT = 8044;
export const PRIMARY_ORIGIN = `http://www.mbfd.localhost:${INSTALLED_HOST_PORT}`;
export const APEX_ORIGIN = `http://mbfd.localhost:${INSTALLED_HOST_PORT}`;

export const installedHostEnvironment = () => {
  const database = isolatedSqliteDatabasePath('INSTALLED_HOST_E2E_DATABASE', 'installed_host_e2e.sqlite');
  closeSync(openSync(database, 'a'));

  return sanitizedTestEnvironment({
    APP_ENV: 'testing',
    APP_KEY: disposableTestAppKey,
    APP_URL: PRIMARY_ORIGIN,
    BROADCAST_DRIVER: 'log',
    CACHE_STORE: 'array',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    FILESYSTEM_DISK: 'local',
    FROC_IMPORT_FORCE_FALLBACK: 'true',
    MAIL_MAILER: 'array',
    PRIVATE_FILESYSTEM_DISK: 'local',
    QUEUE_CONNECTION: 'sync',
    SESSION_DOMAIN: '.mbfd.localhost',
    SESSION_DRIVER: 'file',
    SESSION_SECURE_COOKIE: 'false',
  });
};

export default function globalSetup() {
  const php = localPhpBinary('INSTALLED_HOST_E2E_PHP');
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: installedHostEnvironment() };

  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\OperationalFormsE2ESeeder', '--force'], options);
  // Git-ignored; production publishes these at image build. The form tests need Filament's real scripts.
  execFileSync(php, ['artisan', 'filament:assets'], options);
}

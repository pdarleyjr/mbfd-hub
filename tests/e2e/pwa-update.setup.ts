import { execFileSync } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';
import {
  disposableTestAppKey,
  isolatedSqliteDatabasePath,
  localPhpBinary,
  sanitizedTestEnvironment,
} from './support/test-environment';

/**
 * Disposable Laravel instance for the PWA update-flow specs: a throwaway SQLite
 * database seeded with the same local-only fixture members as Operational Forms
 * (one member, one admin). Never touches a real database or integration.
 */
export default function globalSetup() {
  const php = localPhpBinary('PWA_UPDATE_E2E_PHP');
  const database = isolatedSqliteDatabasePath('PWA_UPDATE_E2E_DATABASE', 'pwa_update_e2e.sqlite');
  closeSync(openSync(database, 'a'));
  const options = {
    cwd: process.cwd(),
    stdio: 'inherit' as const,
    env: sanitizedTestEnvironment({
      APP_ENV: 'testing',
      APP_KEY: disposableTestAppKey,
      APP_URL: 'http://127.0.0.1:8031',
      BROADCAST_DRIVER: 'log',
      CACHE_STORE: 'array',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: database,
      FILESYSTEM_DISK: 'local',
      FROC_IMPORT_FORCE_FALLBACK: 'true',
      MAIL_MAILER: 'array',
      PRIVATE_FILESYSTEM_DISK: 'local',
      QUEUE_CONNECTION: 'sync',
      SESSION_DRIVER: 'file',
    }),
  };

  execFileSync(php, ['artisan', 'migrate:fresh', '--force'], options);
  execFileSync(php, ['artisan', 'db:seed', '--class=Database\\Seeders\\OperationalFormsE2ESeeder', '--force'], options);
}

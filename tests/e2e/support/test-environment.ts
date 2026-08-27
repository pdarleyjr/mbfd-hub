import { isAbsolute, relative, resolve } from 'node:path';

export const disposableTestAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

const loopbackHosts = new Set(['127.0.0.1', 'localhost', '::1']);

const integrationEnvironmentPrefixes = [
  'AWS_',
  'CLOUDFLARE_',
  'DISPLAY_',
  'GOOGLE_',
  'LIVEKIT_',
  'OLLAMA_',
  'PULSEPOINT_',
  'R2_',
  'REVERB_',
  'SENTRY_',
  'SNIPEIT_',
  'VAPID_',
  'WEBPUSH_',
  'WORKGROUP_AI_',
];

const integrationEnvironmentSuffix = /(?:_DSN|_ENDPOINT|_KEY|_PASSWORD|_SECRET|_TOKEN|_URI|_URL)$/;

export function environmentValue(name: string): string | undefined
{
  return process.env[name];
}

export function loopbackBaseUrl(name: string, fallback: string, alternateName?: string): string
{
  const value = environmentValue(name) ?? (alternateName === undefined ? undefined : environmentValue(alternateName)) ?? fallback;
  const url = new URL(value);

  if (url.protocol !== 'http:' || ! loopbackHosts.has(url.hostname.toLowerCase()) || url.username !== '' || url.password !== '') {
    throw new Error(`${name} must be an unauthenticated HTTP URL on localhost or 127.0.0.1.`);
  }

  return url.toString().replace(/\/$/, '');
}

export function isolatedSqliteDatabasePath(name: string, filename: string): string
{
  const databaseDirectory = resolve(process.cwd(), 'database');
  const path = resolve(environmentValue(name) ?? resolve(databaseDirectory, filename));
  const pathWithinDatabaseDirectory = relative(databaseDirectory, path);

  if (
    pathWithinDatabaseDirectory === ''
    || pathWithinDatabaseDirectory.startsWith('..')
    || isAbsolute(pathWithinDatabaseDirectory)
    || ! path.endsWith('.sqlite')
  ) {
    throw new Error(`${name} must name a .sqlite file inside this worktree's database directory.`);
  }

  return path;
}

export function localPhpBinary(name: string): string
{
  return environmentValue(name) ?? 'php';
}

export function sanitizedTestEnvironment(overrides: Record<string, string>): Record<string, string>
{
  const environment: Record<string, string> = {};

  for (const [name, value] of Object.entries(process.env)) {
    if (value === undefined || isIntegrationEnvironmentVariable(name)) {
      continue;
    }

    environment[name] = value;
  }

  return {
    ...environment,
    ...overrides,
  };
}

function isIntegrationEnvironmentVariable(name: string): boolean
{
  return integrationEnvironmentPrefixes.some((prefix) => name.startsWith(prefix))
    || integrationEnvironmentSuffix.test(name)
    || name === 'APP_KEY'
    || name === 'APP_URL'
    || name === 'BROADCAST_DRIVER'
    || name === 'FILESYSTEM_DISK'
    || name === 'MAIL_MAILER'
    || name === 'PRIVATE_FILESYSTEM_DISK'
    || name === 'QUEUE_CONNECTION'
    || name === 'SESSION_DRIVER';
}

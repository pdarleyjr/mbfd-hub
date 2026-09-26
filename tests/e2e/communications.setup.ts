import { execFileSync } from 'node:child_process';
import { communicationsEnvironment } from '../../playwright.communications.config';

export default function setup() {
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: communicationsEnvironment };
  const extensions = process.platform === 'win32' ? ['-d', 'extension=sodium'] : [];
  execFileSync('php', [...extensions, 'artisan', 'migrate:fresh', '--force', '--quiet'], options);
  execFileSync('php', [...extensions, 'artisan', 'db:seed', '--class=Database\\Seeders\\CommunicationsE2ESeeder', '--force', '--quiet'], options);
}

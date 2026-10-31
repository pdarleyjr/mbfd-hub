import { execFileSync } from 'node:child_process';
import { emailCenterEnvironment } from '../../playwright.email-center.config';

export default function setup() {
  const options = { cwd: process.cwd(), stdio: 'inherit' as const, env: emailCenterEnvironment };
  const extensions = process.platform === 'win32' ? ['-d', 'extension=sodium'] : [];
  execFileSync('php', [...extensions, 'artisan', 'migrate:fresh', '--force', '--quiet'], options);
  execFileSync('php', [...extensions, 'artisan', 'db:seed', '--class=Database\\Seeders\\EmailCenterE2ESeeder', '--force', '--quiet'], options);
}

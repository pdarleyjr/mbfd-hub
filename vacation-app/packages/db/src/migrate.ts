import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { getDb } from './client';

const connectionString = process.env.DATABASE_URL ?? process.env.DATABASE_URL_HOST;
if (!connectionString) {
  console.error('DATABASE_URL or DATABASE_URL_HOST must be set');
  process.exit(1);
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const migrationsFolder = resolve(__dirname, '..', 'migrations');

const { db, close } = getDb(connectionString);

try {
  console.log(`Running migrations from ${migrationsFolder}…`);
  await migrate(db, { migrationsFolder });
  console.log('Migrations complete.');
} catch (err) {
  console.error('Migration failed:', err);
  process.exitCode = 1;
} finally {
  await close();
}

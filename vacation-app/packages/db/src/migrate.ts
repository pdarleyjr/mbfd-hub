import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { getDb } from './client';

const connectionString = process.env.DATABASE_URL_HOST ?? process.env.DATABASE_URL;
if (!connectionString) {
  console.error('DATABASE_URL or DATABASE_URL_HOST must be set');
  process.exit(1);
}

const { db, close } = getDb(connectionString);

try {
  console.log('Running migrations…');
  await migrate(db, { migrationsFolder: './migrations' });
  console.log('Migrations complete.');
} catch (err) {
  console.error('Migration failed:', err);
  process.exitCode = 1;
} finally {
  await close();
}

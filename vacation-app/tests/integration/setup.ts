import { PostgreSqlContainer, type StartedPostgreSqlContainer } from '@testcontainers/postgresql';
import { getDb, type Database } from '@mbfd-vacation/db';
import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { resolve } from 'node:path';

export type TestEnv = {
  container: StartedPostgreSqlContainer;
  db: Database;
  close: () => Promise<void>;
};

export async function startTestPostgres(): Promise<TestEnv> {
  const container = await new PostgreSqlContainer('postgres:16-alpine')
    .withDatabase('vacation_test')
    .withUsername('vacation')
    .withPassword('test')
    .start();

  const url = container.getConnectionUri();
  const { db, close } = getDb(url);

  await migrate(db, {
    migrationsFolder: resolve(__dirname, '../../packages/db/migrations'),
  });

  return {
    container,
    db,
    close: async () => {
      await close();
      await container.stop();
    },
  };
}

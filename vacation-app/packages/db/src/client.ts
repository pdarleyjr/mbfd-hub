import { drizzle, type NodePgDatabase } from 'drizzle-orm/node-postgres';
import pg from 'pg';
import * as schema from './schema/index';

export type Database = NodePgDatabase<typeof schema>;

/**
 * Build a Drizzle client for the vacation database.
 *
 * Pass an explicit connection string when constructing — every app reads its
 * own env vars (this package is environment-agnostic).
 */
export function getDb(connectionString: string, opts?: { maxPool?: number }): {
  db: Database;
  pool: pg.Pool;
  close: () => Promise<void>;
} {
  const pool = new pg.Pool({
    connectionString,
    max: opts?.maxPool ?? 10,
    idleTimeoutMillis: 30_000,
    connectionTimeoutMillis: 5_000,
  });
  const db = drizzle(pool, { schema });
  return {
    db,
    pool,
    close: async () => {
      await pool.end();
    },
  };
}

export { schema };

import { getDb } from '@mbfd-vacation/db';
import { getEnv } from './env.js';

const env = getEnv();
const { db, pool, close } = getDb(env.DATABASE_URL, { maxPool: 20 });

export { db, pool, close };

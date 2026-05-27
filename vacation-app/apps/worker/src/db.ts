import { getDb } from '@mbfd-vacation/db';
import { getEnv } from './env';

const env = getEnv();
export const { db, pool, close } = getDb(env.DATABASE_URL, { maxPool: 10 });

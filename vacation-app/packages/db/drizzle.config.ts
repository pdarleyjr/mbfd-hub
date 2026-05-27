import { defineConfig } from 'drizzle-kit';

const url = process.env.DATABASE_URL_HOST ?? process.env.DATABASE_URL;
if (!url) {
  throw new Error('DATABASE_URL or DATABASE_URL_HOST must be set for drizzle-kit');
}

export default defineConfig({
  schema: './src/schema/index.ts',
  out: './migrations',
  dialect: 'postgresql',
  dbCredentials: { url },
  verbose: true,
  strict: true,
});

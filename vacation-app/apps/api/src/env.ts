import { z } from 'zod';

const EnvSchema = z.object({
  NODE_ENV: z.enum(['development', 'production', 'test']).default('production'),
  LOG_LEVEL: z.string().default('info'),
  API_PORT: z.coerce.number().int().min(1).max(65535).default(3001),

  DATABASE_URL: z.string().url(),
  REDIS_URL: z.string().url(),

  R2_ENDPOINT: z.string().url(),
  R2_ACCESS_KEY_ID: z.string().min(1),
  R2_SECRET_ACCESS_KEY: z.string().min(1),
  R2_BUCKET: z.string().min(1),
  R2_PREFIX: z.string().default('vacation/imports/'),

  PIN_AUDIT_WEBHOOK_SECRET: z.string().min(16),

  /**
   * Shared secret between the Cloudflare PIN-gate Worker and this API.
   * The Worker injects this as `X-Origin-Token` on every proxied request;
   * the originGuard middleware rejects anything missing it. Closes the
   * vacation-origin.mbfdhub.com bypass.
   */
  ORIGIN_SHARED_TOKEN: z.string().min(16),

  MAX_UPLOAD_BYTES: z.coerce.number().int().positive().default(1_073_741_824),
  MAX_TELESTAFF_ROWS: z.coerce.number().int().positive().default(5_000_000),
});

export type Env = z.infer<typeof EnvSchema>;

let cached: Env | null = null;
export function getEnv(): Env {
  if (cached) return cached;
  const parsed = EnvSchema.safeParse(process.env);
  if (!parsed.success) {
    console.error('Invalid environment:', parsed.error.flatten().fieldErrors);
    process.exit(1);
  }
  cached = parsed.data;
  return cached;
}

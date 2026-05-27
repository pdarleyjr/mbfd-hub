import { GetObjectCommand, S3Client } from '@aws-sdk/client-s3';
import type { Readable } from 'node:stream';
import { getEnv } from './env';

const env = getEnv();

export const r2 = new S3Client({
  region: 'auto',
  endpoint: env.R2_ENDPOINT,
  credentials: {
    accessKeyId: env.R2_ACCESS_KEY_ID,
    secretAccessKey: env.R2_SECRET_ACCESS_KEY,
  },
});

export async function getStream(key: string): Promise<Readable> {
  const out = await r2.send(new GetObjectCommand({ Bucket: env.R2_BUCKET, Key: key }));
  if (!out.Body) throw new Error(`r2 object missing: ${key}`);
  return out.Body as Readable;
}

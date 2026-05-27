import { GetObjectCommand, S3Client } from '@aws-sdk/client-s3';
import { Upload } from '@aws-sdk/lib-storage';
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
  forcePathStyle: false,
});

export function r2Key(localPath: string): string {
  return `${env.R2_PREFIX}${localPath}`.replace(/^\/+/, '');
}

/**
 * Stream an upload to R2 using multipart. Returns the final ETag.
 */
export async function putStream(
  key: string,
  body: Readable,
  contentType: string,
): Promise<{ etag: string | undefined }> {
  const upload = new Upload({
    client: r2,
    params: {
      Bucket: env.R2_BUCKET,
      Key: key,
      Body: body,
      ContentType: contentType,
    },
    queueSize: 4,
    partSize: 5 * 1024 * 1024,
    leavePartsOnError: false,
  });
  const result = await upload.done();
  return { etag: result.ETag };
}

export async function getStream(key: string): Promise<Readable> {
  const { Body } = await r2.send(
    new GetObjectCommand({ Bucket: env.R2_BUCKET, Key: key }),
  );
  if (!Body) throw new Error(`R2 object missing: ${key}`);
  return Body as Readable;
}

export function r2Bucket(): string {
  return env.R2_BUCKET;
}

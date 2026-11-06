/*
 * Shrink a member's photo on the phone before it is uploaded (Daily Checkout and
 * Take Home inspections).
 *
 * A 12-48 MP camera original (3-15 MB, often HEIC on iPhone) becomes a JPEG with a
 * long edge of at most 2048 px at quality 0.8, usually 300-700 KB. Decoding honours
 * the EXIF rotation, and drawing to a canvas drops every piece of metadata (GPS,
 * camera, time). Safari decodes HEIC natively, so iPhone photos convert here too.
 * When the browser cannot decode the file (HEIC in some desktop browsers) the
 * original is returned unchanged and the server converts it off the request path.
 */

export const PHOTO_MAX_EDGE = 2048;
export const PHOTO_JPEG_QUALITY = 0.8;

export interface PreparedPhoto {
  blob: Blob;
  filename: string;
  /** False when the original is sent as is (the browser could not decode it). */
  compressed: boolean;
  width?: number;
  height?: number;
}

export interface PreparePhotoOptions {
  maxEdge?: number;
  quality?: number;
}

const PHOTO_EXTENSIONS = /\.(jpe?g|png|webp|gif|heic|heif)$/i;
const PHOTO_TYPES = new Set([
  'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif',
  'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
]);

/** A photo format the Hub stores. iOS and some Android browsers give a HEIC file no usable type, so the name decides then. */
export function isLikelyPhoto(file: Pick<File, 'type' | 'name'>): boolean {
  const type = (file.type || '').toLowerCase();
  if (PHOTO_TYPES.has(type)) return true;

  return (type === '' || type === 'application/octet-stream') && PHOTO_EXTENSIONS.test(file.name || '');
}

/** The canvas size for a photo: never larger than the original, long edge at most maxEdge. */
export function targetSize(width: number, height: number, maxEdge: number = PHOTO_MAX_EDGE): { width: number; height: number } {
  if (!(width > 0) || !(height > 0) || !(maxEdge > 0)) return { width: 0, height: 0 };
  const scale = Math.min(1, maxEdge / Math.max(width, height));

  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

interface DecodedPhoto {
  source: CanvasImageSource;
  width: number;
  height: number;
  release: () => void;
}

async function decode(file: Blob): Promise<DecodedPhoto> {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

      return { source: bitmap, width: bitmap.width, height: bitmap.height, release: () => bitmap.close() };
    } catch {
      // Older Safari: fall back to an <img>, which also applies the EXIF rotation.
    }
  }
  const url = URL.createObjectURL(file);
  try {
    const image = new Image();
    image.decoding = 'async';
    await new Promise<void>((resolve, reject) => {
      image.onload = () => resolve();
      image.onerror = () => reject(new Error('decode'));
      image.src = url;
    });

    return { source: image, width: image.naturalWidth, height: image.naturalHeight, release: () => URL.revokeObjectURL(url) };
  } catch (error) {
    URL.revokeObjectURL(url);
    throw error;
  }
}

function baseName(file: File): string {
  return (file.name || 'photo').replace(/\.[^.]+$/, '') || 'photo';
}

/** Resize and re-encode; never throws (the original comes back when it cannot be converted here). */
export async function preparePhoto(file: File, options: PreparePhotoOptions = {}): Promise<PreparedPhoto> {
  const maxEdge = options.maxEdge ?? PHOTO_MAX_EDGE;
  const quality = options.quality ?? PHOTO_JPEG_QUALITY;
  let decoded: DecodedPhoto | null = null;
  try {
    decoded = await decode(file);
    const size = targetSize(decoded.width, decoded.height, maxEdge);
    if (size.width === 0) throw new Error('decode');
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('canvas');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, size.width, size.height);
    context.imageSmoothingQuality = 'high';
    context.drawImage(decoded.source, 0, 0, size.width, size.height);
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    // Release the backing store at once: iOS keeps a small total canvas budget.
    canvas.width = 0;
    canvas.height = 0;
    if (!blob || blob.size === 0) throw new Error('encode');

    return { blob, filename: `${baseName(file)}.jpg`, compressed: true, width: size.width, height: size.height };
  } catch {
    return { blob: file, filename: file.name || 'photo', compressed: false };
  } finally {
    decoded?.release();
  }
}

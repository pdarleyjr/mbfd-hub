import {
    ORIENTATION_PROBE_JPEG_BASE64, browserAppliesExifOrientation, browserDecodesNatively, checkSelection, encodeWithPlan, encodingPlan, fitWithin,
    orientationPlan, readJpegOrientation,
} from './narcoticsImage.js';
import type { PreparedPhoto } from './narcoticsTypes';

/**
 * Browser-side photo preparation: stand the picture upright, bound the long edge, drop all metadata and
 * encode the smallest clear format. The pure decisions live in narcoticsImage.js (unit tested); this file
 * is the thin canvas wrapper around them.
 *
 * Re-drawing the pixels onto a canvas is what removes EXIF and GPS: the encoded file contains the picture
 * and nothing else. If this browser cannot decode or encode the file (HEIC on a desktop, an exotic format)
 * the original is sent instead and the server re-encodes it, which strips the metadata there.
 */
export interface PrepareLimits {
    acceptedMimeTypes: string[];
    maxEdge: number;
    minEdge: number;
    /** The server's upload ceiling; an original larger than this cannot be the fallback. */
    maxUploadBytes: number;
}

export class PhotoProblem extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'PhotoProblem';
    }
}

type Source = ImageBitmap | HTMLImageElement;

async function decode(blob: Blob): Promise<Source> {
    if (typeof createImageBitmap === 'function') return createImageBitmap(blob);
    const url = URL.createObjectURL(blob);
    try {
        const image = new Image();
        image.decoding = 'async';
        image.src = url;
        await image.decode();
        return image;
    } finally {
        // The decoded image no longer needs the URL.
        URL.revokeObjectURL(url);
    }
}

const sizeOf = (source: Source): { width: number; height: number } => (
    'naturalWidth' in source ? { width: source.naturalWidth, height: source.naturalHeight } : { width: source.width, height: source.height }
);

function release(source: Source): void {
    if ('close' in source && typeof source.close === 'function') source.close();
}

let autoOrientation: Promise<boolean> | null = null;

/** Whether this browser already stands an EXIF-rotated JPEG upright when it decodes it (learned once, with a 730-byte probe). */
export function browserStandsPhotosUpright(): Promise<boolean> {
    autoOrientation ??= (async () => {
        try {
            const bytes = Uint8Array.from(atob(ORIENTATION_PROBE_JPEG_BASE64), (character) => character.charCodeAt(0));
            const source = await decode(new Blob([bytes], { type: 'image/jpeg' }));
            const { width, height } = sizeOf(source);
            release(source);
            return browserAppliesExifOrientation(width, height) as boolean;
        } catch {
            return false;
        }
    })();
    return autoOrientation;
}

function toBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => {
        try { canvas.toBlob((blob) => resolve(blob), type, quality); } catch { resolve(null); }
    });
}

const isJpeg = (file: File) => file.type === 'image/jpeg' || /\.jpe?g$/i.test(file.name);

async function originalOrientation(file: File): Promise<number> {
    if (!isJpeg(file)) return 1;
    try {
        return readJpegOrientation(await file.slice(0, 131072).arrayBuffer()) as number;
    } catch {
        return 1;
    }
}

function original(file: File, limits: PrepareLimits): PreparedPhoto {
    if (file.size > limits.maxUploadBytes) {
        throw new PhotoProblem('This photo could not be prepared on this device and it is too large to send as it is. Use "Take photo" instead.');
    }
    return { blob: file, type: file.type || 'image/jpeg', width: 0, height: 0, processed: false, name: file.name || 'photo' };
}

export async function preparePhoto(file: File, limits: PrepareLimits): Promise<PreparedPhoto> {
    const check = checkSelection(file, { acceptedMimeTypes: limits.acceptedMimeTypes, maxUploadBytes: limits.maxUploadBytes }) as { ok: boolean; message?: string };
    if (!check.ok) throw new PhotoProblem(check.message ?? 'Choose or take a photo first.');

    let source: Source | null = null;
    try {
        try {
            source = await decode(file);
        } catch {
            // A JPEG, PNG or WebP every browser reads that this one cannot is damaged. HEIC and the like are the server's job.
            if (browserDecodesNatively(file)) throw new PhotoProblem('That photo could not be read. Take it again or choose another.');
            throw new Error('undecodable');
        }
        const stored = sizeOf(source);
        if (stored.width < 1 || stored.height < 1) throw new Error('empty');

        // Rotate by hand only when the browser did not already (see browserStandsPhotosUpright).
        const orientation = !(await browserStandsPhotosUpright()) ? await originalOrientation(file) : 1;
        const upright = orientationPlan(orientation, stored.width, stored.height) as { width: number; height: number; matrix: number[] };
        if (Math.max(upright.width, upright.height) < limits.minEdge) {
            throw new PhotoProblem('That photo is too small to read. Take it again closer to what you are checking.');
        }

        const fit = fitWithin(upright.width, upright.height, limits.maxEdge) as { width: number; height: number; scale: number };
        const canvas = document.createElement('canvas');
        canvas.width = fit.width;
        canvas.height = fit.height;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('canvas');
        context.fillStyle = '#ffffff'; // transparency (PNG) becomes white, like the server does
        context.fillRect(0, 0, fit.width, fit.height);
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        const [a, b, c, d, e, f] = upright.matrix;
        context.setTransform(a * fit.scale, b * fit.scale, c * fit.scale, d * fit.scale, e * fit.scale, f * fit.scale);
        context.drawImage(source as CanvasImageSource, 0, 0);

        const encoded = await encodeWithPlan((type: string, quality: number) => toBlob(canvas, type, quality), encodingPlan(limits.acceptedMimeTypes)) as
            { blob: Blob; type: string; quality: number } | null;
        canvas.width = 0;
        canvas.height = 0;
        if (!encoded) return original(file, limits);

        return { blob: encoded.blob, type: encoded.type, width: fit.width, height: fit.height, processed: true, name: file.name || 'photo' };
    } catch (error) {
        if (error instanceof PhotoProblem) throw error;
        // This browser cannot read or write the file: the server will.
        return original(file, limits);
    } finally {
        if (source) release(source);
    }
}

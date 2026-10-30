// Pure, dependency-free logic for the Narcotics check photo (docs/narcotics-check.md section 4).
// A plain ES module with JSDoc types so it is unit-tested with `node --test`
// (tests/Node/operational-forms/narcotics-image.test.mjs) without a browser or a TypeScript step.
//
// What this file protects:
//  - the photo is stood upright from its EXIF orientation exactly once (never twice, never not at all);
//  - the long edge is bounded, and a small photo is never enlarged;
//  - metadata is dropped by re-drawing the pixels (a canvas re-encode carries no EXIF or GPS);
//  - the smallest clear format wins: WebP when this browser really encodes it, otherwise JPEG, with a
//    gentle quality ladder that only steps down when the file is still too heavy;
//  - the busy guard follows the work: dirty -> saving -> saved/failed, and a failed upload stays unsafe.

export const FORM_KEY = 'narcotics-check';
export const DEFAULT_MAX_EDGE = 3200;
export const DEFAULT_MIN_EDGE = 480;
export const DEFAULT_NOTE_MAX = 500;
/** The encoded photo should be about this heavy at most; above it the quality ladder steps down. */
export const TARGET_BYTES = 3 * 1024 * 1024;
/** A source this large is not worth decoding in a browser tab; send the original or ask for a smaller one. */
export const MAX_SOURCE_BYTES = 60 * 1024 * 1024;
export const BUSY_REASON = 'narcotics-check';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** @param {unknown} value */
export function isUuid(value) {
    return typeof value === 'string' && UUID_PATTERN.test(value);
}

/** A fresh submission id. The server dedupes on it, so a retry or a double tap can never send two checks. */
export function newSubmissionId() {
    const crypto = globalThis.crypto;
    if (crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (crypto && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
    else for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// ---------------------------------------------------------------- orientation

/**
 * The EXIF Orientation (1-8) of a JPEG, read from the first bytes. 1 (upright) for anything unreadable.
 * Mirrors App\Services\Narcotics\ExifOrientation on the server.
 * @param {ArrayBuffer | Uint8Array} input
 * @returns {number}
 */
export function readJpegOrientation(input) {
    const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
    const limit = Math.min(bytes.length, 131072);
    if (limit < 4 || bytes[0] !== 0xff || bytes[1] !== 0xd8) return 1;

    let offset = 2;
    while (offset + 4 <= limit) {
        if (bytes[offset] !== 0xff) return 1;
        const marker = bytes[offset + 1];
        if (marker === 0xff) { offset += 1; continue; }
        if (marker === 0xda || marker === 0xd9) return 1;
        const size = (bytes[offset + 2] << 8) | bytes[offset + 3];
        if (size < 2) return 1;
        if (marker === 0xe1 && bytes[offset + 4] === 0x45 && bytes[offset + 5] === 0x78 && bytes[offset + 6] === 0x69
            && bytes[offset + 7] === 0x66 && bytes[offset + 8] === 0 && bytes[offset + 9] === 0) {
            return orientationFromTiff(bytes, offset + 10, Math.min(size - 8, limit - (offset + 10)));
        }
        offset += 2 + size;
    }
    return 1;
}

/**
 * @param {Uint8Array} bytes
 * @param {number} start
 * @param {number} length
 */
function orientationFromTiff(bytes, start, length) {
    if (length < 14) return 1;
    const first = bytes[start];
    const second = bytes[start + 1];
    const little = first === 0x49 && second === 0x49 ? true : first === 0x4d && second === 0x4d ? false : null;
    if (little === null) return 1;
    const short = (at) => (little ? bytes[start + at] | (bytes[start + at + 1] << 8) : (bytes[start + at] << 8) | bytes[start + at + 1]);
    const long = (at) => (little
        ? (bytes[start + at] | (bytes[start + at + 1] << 8) | (bytes[start + at + 2] << 16) | (bytes[start + at + 3] << 24)) >>> 0
        : ((bytes[start + at] << 24) | (bytes[start + at + 1] << 16) | (bytes[start + at + 2] << 8) | bytes[start + at + 3]) >>> 0);

    if (short(2) !== 42) return 1;
    const directory = long(4);
    if (directory < 8 || directory + 2 > length) return 1;
    const count = short(directory);
    for (let index = 0; index < count; index++) {
        const entry = directory + 2 + index * 12;
        if (entry + 12 > length) return 1;
        if (short(entry) === 0x0112) {
            const value = short(entry + 8);
            return value >= 1 && value <= 8 ? value : 1;
        }
    }
    return 1;
}

/**
 * The size of the upright picture and the canvas transform [a, b, c, d, e, f] that draws the stored
 * (sideways) pixels upright: ctx.setTransform(...matrix); ctx.drawImage(source, 0, 0).
 * Orientations 5-8 swap width and height.
 * @param {number} orientation EXIF 1-8
 * @param {number} width stored width
 * @param {number} height stored height
 * @returns {{ width: number, height: number, matrix: [number, number, number, number, number, number] }}
 */
export function orientationPlan(orientation, width, height) {
    switch (orientation) {
        case 2: return { width, height, matrix: [-1, 0, 0, 1, width, 0] };
        case 3: return { width, height, matrix: [-1, 0, 0, -1, width, height] };
        case 4: return { width, height, matrix: [1, 0, 0, -1, 0, height] };
        case 5: return { width: height, height: width, matrix: [0, 1, 1, 0, 0, 0] };
        case 6: return { width: height, height: width, matrix: [0, 1, -1, 0, height, 0] };
        case 7: return { width: height, height: width, matrix: [0, -1, -1, 0, height, width] };
        case 8: return { width: height, height: width, matrix: [0, -1, 1, 0, 0, width] };
        default: return { width, height, matrix: [1, 0, 0, 1, 0, 0] };
    }
}

/**
 * A 2x1 JPEG that carries EXIF orientation 6. A browser that stands it upright reports 1x2. Used once
 * to learn whether this browser already applies EXIF orientation when it decodes a photo, so the
 * rotation is never applied twice (or skipped).
 */
export const ORIENTATION_PROBE_JPEG_BASE64 = '/9j/4QAiRXhpZgAASUkqAAgAAAABABIBAwABAAAABgAAAAAAAAD/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAQACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5Ciiivpz50//2Q==';

/**
 * Whether decoding a probe of orientation 6 already came out upright (1 wide, 2 tall).
 * @param {number} width decoded width of ORIENTATION_PROBE_JPEG_BASE64
 * @param {number} height decoded height
 */
export function browserAppliesExifOrientation(width, height) {
    return width === 1 && height === 2;
}

// ----------------------------------------------------------------------- size

/**
 * Fit inside a long-edge cap. Never enlarges.
 * @param {number} width
 * @param {number} height
 * @param {number} maxEdge
 * @returns {{ width: number, height: number, scale: number }}
 */
export function fitWithin(width, height, maxEdge) {
    const longest = Math.max(width, height);
    if (!(longest > 0) || !(maxEdge > 0) || longest <= maxEdge) return { width, height, scale: 1 };
    const scale = maxEdge / longest;
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)), scale };
}

/**
 * A quick look at the chosen file before any work is spent on it.
 * @param {{ name?: string, type?: string, size?: number }} file
 * @param {{ acceptedMimeTypes?: string[], maxUploadBytes?: number }} [server]
 * @returns {{ ok: true } | { ok: false, message: string }}
 */
export function checkSelection(file, server = {}) {
    if (!file || typeof file.size !== 'number') return { ok: false, message: 'Choose or take a photo first.' };
    if (file.size === 0) return { ok: false, message: 'That photo is empty. Take it again.' };
    const type = String(file.type || '').toLowerCase();
    const name = String(file.name || '').toLowerCase();
    const looksLikeImage = type.startsWith('image/') || /\.(jpe?g|png|webp|heic|heif)$/.test(name);
    if (!looksLikeImage) return { ok: false, message: 'That file is not a photo. Use "Take photo" or choose a JPEG, PNG, WebP or iPhone photo.' };
    if (file.size > MAX_SOURCE_BYTES) return { ok: false, message: 'That photo is too large. Use "Take photo" instead.' };
    return { ok: true };
}

/**
 * Whether every browser reads this kind of file itself. A file of such a type that the browser then
 * cannot decode is damaged: say so now, instead of previewing a broken picture and failing at the server.
 * HEIC and anything unfamiliar are left to the server, which re-encodes them.
 * @param {{ type?: string, name?: string }} file
 */
export function browserDecodesNatively(file) {
    const type = String(file?.type || '').toLowerCase();
    return type === 'image/jpeg' || type === 'image/png' || type === 'image/webp' || type === 'image/gif';
}

// ------------------------------------------------------------------- encoding

/**
 * The encodings to try, best first. WebP only when the server reads it. AVIF is deliberately not
 * attempted: it is slow to encode on station tablets and phones, and the server may not read it.
 * @param {string[]} accepted MIME types the server lists in its form definition
 * @returns {Array<{ type: string, qualities: number[] }>}
 */
export function encodingPlan(accepted) {
    const list = Array.isArray(accepted) && accepted.length > 0 ? accepted : ['image/jpeg'];
    const plan = [];
    if (list.includes('image/webp')) plan.push({ type: 'image/webp', qualities: [0.86, 0.8, 0.74] });
    plan.push({ type: 'image/jpeg', qualities: [0.9, 0.84, 0.78] });
    return plan;
}

/**
 * Runs the plan with an injected encoder `toBlob(type, quality) -> Promise<Blob | null>`.
 * A browser that cannot encode a type silently returns PNG (or null): that attempt is skipped, so the
 * format named in the result is always the format of the bytes. Quality steps down only while the file
 * is still over `targetBytes`; the last rung is accepted as is (never an endless loop, never mush).
 * @param {(type: string, quality: number) => Promise<{ type: string, size: number } | null>} toBlob
 * @param {Array<{ type: string, qualities: number[] }>} plan
 * @param {number} [targetBytes]
 * @returns {Promise<{ blob: { type: string, size: number }, type: string, quality: number } | null>}
 */
export async function encodeWithPlan(toBlob, plan, targetBytes = TARGET_BYTES) {
    for (const step of plan) {
        let kept = null;
        for (let index = 0; index < step.qualities.length; index++) {
            const quality = step.qualities[index];
            const blob = await toBlob(step.type, quality);
            if (!blob || blob.type !== step.type || !(blob.size > 0)) break; // this browser cannot write this type
            kept = { blob, type: step.type, quality };
            if (blob.size <= targetBytes) return kept;
        }
        if (kept) return kept;
    }
    return null;
}

/** A file name for the encoded photo; the server ignores it, a person might see it in a download. */
export function photoFileName(type) {
    return type === 'image/webp' ? 'narcotics-check.webp' : 'narcotics-check.jpg';
}

// ------------------------------------------------------------------ busy guard

/**
 * The form's state, as far as the update banner is concerned:
 *  - empty   : nothing chosen or typed -> safe
 *  - dirty   : a photo, a note, a seal number or a rescue unit the member picked exists only in this tab -> NOT safe
 *  - sending : the upload is in flight -> NOT safe
 *  - failed  : the server did not confirm -> NOT safe (the photo is still only here)
 *  - saved   : the server accepted it -> safe
 * @typedef {'empty' | 'dirty' | 'sending' | 'failed' | 'saved'} FormPhase
 */

/**
 * `hasUnit` / `hasSeal` are true once the member has picked or typed a rescue unit / seal number themselves;
 * a value that was only prefilled (answering a rejected check) is not work yet.
 * @param {{ hasPhoto: boolean, note: string, hasUnit?: boolean, hasSeal?: boolean, sending: boolean, failed: boolean, saved: boolean }} state
 * @returns {FormPhase}
 */
export function formPhase(state) {
    if (state.saved) return 'saved';
    if (state.sending) return 'sending';
    const hasWork = state.hasPhoto || state.note.trim() !== '' || state.hasUnit === true || state.hasSeal === true;
    if (state.failed && hasWork) return 'failed';
    if (hasWork) return 'dirty';
    return 'empty';
}

/** @param {FormPhase} phase */
export function isUnsafeToRefresh(phase) {
    return phase === 'dirty' || phase === 'sending' || phase === 'failed';
}

// -------------------------------------------------------------- server answers

/**
 * What to tell the member about a failed send, and whether sending the same thing again is sensible.
 * `network` is true when the request never got an answer (the outcome is unknown, so it is safe to
 * retry with the SAME submission id).
 * @param {{ status: number, code: string | null, message: string, network: boolean }} failure
 * @returns {{ message: string, retry: boolean, newPhoto: boolean }}
 */
export function describeFailure(failure) {
    if (failure.network || failure.status === 0) {
        return { message: 'The connection dropped, so Captain 5 may not have it yet. Your photo is still here. Tap Send again.', retry: true, newPhoto: false };
    }
    if (failure.status === 401 || failure.status === 419) {
        return { message: 'Your sign-in has ended. Sign in again, then come back and send it. Your photo is only on this screen, so do not close it.', retry: false, newPhoto: false };
    }
    if (failure.status === 413) {
        return { message: 'That photo is too large to send. Take it again with "Take photo".', retry: false, newPhoto: true };
    }
    if (failure.status === 429) {
        return { message: failure.message || 'Too many tries in a row. Wait a minute, then tap Send again.', retry: true, newPhoto: false };
    }
    if (failure.status >= 500) {
        return { message: 'The server had a problem and nothing was sent. Your photo is still here. Tap Send again in a moment.', retry: true, newPhoto: false };
    }
    if (failure.status === 422 || failure.status === 409) {
        const photoProblem = typeof failure.code === 'string' && failure.code.startsWith('photo_');
        return { message: failure.message || 'That could not be sent.', retry: false, newPhoto: photoProblem };
    }
    return { message: failure.message || 'That could not be sent. Try again.', retry: true, newPhoto: false };
}

/** The confirmation shown only after the server has durably accepted the check. */
export const SENT_MESSAGE = 'Narcotics check sent to Captain 5.';

// ------------------------------------------------------------ view addressing

/** @typedef {import('./narcoticsTypes').NarcoticsView} NarcoticsView */

/**
 * The view named by the server's deep link ({view, check}).
 * @param {{ view?: string | null, check?: string | null } | null | undefined} link
 * @returns {NarcoticsView | null}
 */
export function viewFromDeepLink(link) {
    if (!link || !link.view) return null;
    if (link.view === 'detail' && isUuid(link.check)) return { name: 'detail', id: String(link.check).toLowerCase() };
    if (link.view === 'mine') return { name: 'mine' };
    if (link.view === 'form') return { name: 'form' };
    return null;
}

/**
 * The view named by a query string (?form=narcotics-check[&view=mine|&check=<uuid>]), or null when the
 * address is about something else.
 * @param {string} search
 * @returns {NarcoticsView | null}
 */
export function viewFromSearch(search) {
    const params = new URLSearchParams(search);
    if (params.get('form') !== FORM_KEY) return null;
    const check = (params.get('check') || '').toLowerCase();
    if (isUuid(check)) return { name: 'detail', id: check };
    return params.get('view') === 'mine' ? { name: 'mine' } : { name: 'form' };
}

/**
 * The query string for a view ('' for the Forms library).
 * @param {NarcoticsView | null} view
 */
export function searchForView(view) {
    if (!view) return '';
    if (view.name === 'detail') return `?form=${FORM_KEY}&check=${view.id}`;
    if (view.name === 'mine') return `?form=${FORM_KEY}&view=mine`;
    return `?form=${FORM_KEY}`;
}

/**
 * @param {string} template an endpoint with the __UUID__ placeholder
 * @param {string} placeholder
 * @param {string} id
 */
export function urlFor(template, placeholder, id) {
    return template.split(placeholder).join(encodeURIComponent(id));
}

/** A readable size for the "photo ready" line: "1.4 MB", "820 KB". */
export function formatBytes(bytes) {
    if (!(bytes > 0)) return '0 KB';
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

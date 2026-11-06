import { isLikelyPhoto } from '../../../shared/photo-compression.ts';

/**
 * The one rule for a photo a member attaches to an inspection answer or a concern: any phone photo
 * (JPEG, PNG, WebP, GIF or iPhone HEIC). It is shrunk on the phone to about half a megabyte before it
 * is sent, so only an original the phone cannot convert has to fit the server's 25 MB limit.
 */
export const MAX_PHOTO_BYTES = 25 * 1024 * 1024;
export const PHOTO_REJECTED_MESSAGE = 'Choose a photo smaller than 25 MB. Your inspection answers are still saved.';
export const PHOTO_FORMAT_MESSAGE = 'Choose a photo (JPEG, PNG, WebP, GIF or iPhone HEIC). Your inspection answers are still saved.';
export const PHOTO_UNREADABLE_MESSAGE = 'The image could not be read. Choose it again.';

/** Null when the file can be attached; otherwise the sentence to show. */
export const photoProblem = (file: Pick<File, 'type' | 'size' | 'name'>): string | null => {
  if (!isLikelyPhoto(file)) return PHOTO_FORMAT_MESSAGE;

  return file.size > MAX_PHOTO_BYTES ? PHOTO_REJECTED_MESSAGE : null;
};

/** The photo as a data URL (stretcher reports still preview this way). */
export const readPhotoFile = (file: File): Promise<string> => new Promise((resolve, reject) => {
  const reader = new FileReader();
  reader.onload = () => resolve(String(reader.result));
  reader.onerror = () => reject(new Error(PHOTO_UNREADABLE_MESSAGE));
  reader.readAsDataURL(file);
});

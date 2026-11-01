/** The one rule for a photo a member attaches to an inspection answer or a concern: an image under 5 MB. */
export const MAX_PHOTO_BYTES = 5_000_000;
export const PHOTO_REJECTED_MESSAGE = 'Choose an image smaller than 5 MB. Your inspection answers are still saved.';
export const PHOTO_FORMAT_MESSAGE = 'Choose a JPEG, PNG, WebP or GIF photo. Your inspection answers are still saved.';
export const PHOTO_UNREADABLE_MESSAGE = 'The image could not be read. Choose it again.';

/** What the server stores (Base64Image). Anything else would be refused after the member has signed. */
const STORABLE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

/** Null when the file can be attached; otherwise the sentence to show. */
export const photoProblem = (file: Pick<File, 'type' | 'size'>): string | null => {
  if (!file.type.startsWith('image/') || file.size > MAX_PHOTO_BYTES) return PHOTO_REJECTED_MESSAGE;

  return STORABLE_TYPES.has(file.type.toLowerCase()) ? null : PHOTO_FORMAT_MESSAGE;
};

/** The photo as a data URL, the form the checkout payload carries. */
export const readPhotoFile = (file: File): Promise<string> => new Promise((resolve, reject) => {
  const reader = new FileReader();
  reader.onload = () => resolve(String(reader.result));
  reader.onerror = () => reject(new Error(PHOTO_UNREADABLE_MESSAGE));
  reader.readAsDataURL(file);
});

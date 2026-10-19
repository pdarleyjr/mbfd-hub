import type { ReactNode } from 'react';
import { House } from 'lucide-react';
import type { ImageSize } from '../types';

interface StationCardMediaProps {
  /** Image URL, or null to render the placeholder. */
  src: string | null;
  alt: string;
  /** Intrinsic size; reserves the box before the image loads so the card never shifts. */
  size?: ImageSize | null;
  /** Badges overlaid on the photo (number label, inactive tag). */
  children?: ReactNode;
}

/**
 * Media area shared by every card on the stations list (StationCard and
 * Command300Card) so all of them frame their photo identically: natural
 * aspect on narrow screens, a fixed-height cover crop from xl up.
 */
export default function StationCardMedia({ src, alt, size, children }: StationCardMediaProps) {
  return (
    <div className={`relative overflow-hidden border-b border-hub-glass-border bg-hub-n-100 ${src ? 'xl:h-48 2xl:h-56' : 'h-40 sm:h-48 2xl:h-56'}`}>
      {src ? (
        <img
          src={src}
          alt={alt}
          width={size?.width}
          height={size?.height}
          className="h-auto w-full object-contain xl:h-full xl:object-cover"
          loading="lazy"
          decoding="async"
        />
      ) : (
        <div className="flex h-full items-center justify-center">
          <House className="h-12 w-12 text-hub-n-400" strokeWidth={1.5} aria-hidden="true" />
        </div>
      )}
      {children}
    </div>
  );
}

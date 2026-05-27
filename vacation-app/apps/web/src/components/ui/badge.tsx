import * as React from 'react';
import { cn } from '@/lib/utils';

type Props = React.HTMLAttributes<HTMLSpanElement> & {
  tone?: 'neutral' | 'brand' | 'success' | 'warning' | 'admin';
};

export function Badge({ className, tone = 'neutral', ...props }: Props): React.JSX.Element {
  const toneStyles: Record<NonNullable<Props['tone']>, string> = {
    neutral: 'bg-stone-100 text-stone-800 border-stone-200',
    brand: 'bg-brand-50 text-brand-700 border-brand-700/20',
    success: 'bg-green-50 text-green-700 border-green-700/20',
    warning: 'bg-amber-50 text-amber-700 border-amber-700/20',
    admin: 'bg-admin-850 text-white border-admin-700',
  };
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold uppercase tracking-wide',
        toneStyles[tone],
        className,
      )}
      {...props}
    />
  );
}

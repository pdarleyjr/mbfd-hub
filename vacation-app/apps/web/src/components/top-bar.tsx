'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { cn } from '@/lib/utils';

const nav = [
  { href: '/board', label: 'Board' },
  { href: '/grant', label: 'Grant' },
  { href: '/import', label: 'Import' },
  { href: '/import/runs', label: 'Runs' },
  { href: '/admin/rules', label: 'Rules' },
];

export function TopBar(): React.JSX.Element {
  const pathname = usePathname() ?? '/';
  return (
    <header className="sticky top-0 z-40 w-full border-b border-stone-200 bg-admin-850 text-white">
      <div className="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 px-safe">
        <Link href="/board" className="flex items-center gap-2">
          <span className="font-display text-lg font-bold tracking-tight">
            MBFD Vacation
          </span>
          <span className="rounded bg-brand-700 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider">
            V1
          </span>
        </Link>
        <nav className="flex items-center gap-1 sm:gap-3">
          {nav.map((item) => {
            const active =
              pathname === item.href ||
              (item.href !== '/board' && pathname.startsWith(item.href));
            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                  active
                    ? 'bg-white/10 text-white'
                    : 'text-stone-200 hover:bg-white/5 hover:text-white',
                )}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
      </div>
    </header>
  );
}

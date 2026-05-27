import type { Metadata, Viewport } from 'next';
import { TopBar } from '@/components/top-bar';
import { Providers } from '@/components/providers';
import './globals.css';

export const metadata: Metadata = {
  title: 'MBFD Vacation Selection',
  description: 'Miami Beach Fire Department — vacation board (PIN-gated).',
  robots: { index: false, follow: false },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#1e293b',
};

// Every page is dynamic — there's no point pre-rendering an admin board
// that depends entirely on runtime DB state. Avoids Next 15 attempting to
// fetch /api/* during the build phase.
export const dynamic = 'force-dynamic';
export const revalidate = 0;

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>): React.JSX.Element {
  return (
    <html lang="en">
      <body>
        <Providers>
          <TopBar />
          <main className="mx-auto w-full max-w-7xl px-4 px-safe pb-safe pt-6">
            {children}
          </main>
        </Providers>
      </body>
    </html>
  );
}

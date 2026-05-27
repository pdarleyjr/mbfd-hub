import Link from 'next/link';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { UploadCloud } from 'lucide-react';

export function EmptyState(): React.JSX.Element {
  return (
    <Card className="border-dashed">
      <CardContent className="flex flex-col items-center gap-4 py-16 text-center">
        <div className="rounded-full bg-stone-100 p-3 text-stone-600">
          <UploadCloud className="h-7 w-7" aria-hidden />
        </div>
        <div>
          <h2 className="font-display text-xl font-semibold">No data imported yet</h2>
          <p className="mt-2 max-w-sm text-sm text-stone-600">
            Upload your first Telestaff export and the vacation board will populate
            with every member, leave entry, and code.
          </p>
        </div>
        <Button asChild>
          <Link href="/import">Go to Import →</Link>
        </Button>
      </CardContent>
    </Card>
  );
}

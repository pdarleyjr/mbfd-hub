import { useCallback } from 'react';
import { useSearchParams } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import MasterInventoryTab from './captain5/ems/master/MasterInventoryTab';
import { useEmsAccess } from './captain5/ems/useEmsAccess';
import { EmptyState, LoadError, SkeletonRows } from './ui/StateViews';

/**
 * Master Supply Room Inventory on its own page (`/daily/supply-room`), for administrators who reach it from the Admin
 * panel's EMS menu. It is the same tab as on Captain 5, behind the same `/ems/api/meta` check, with the Monday count
 * at `?count=1`. "Back to Admin" is a plain link that leaves in the same window.
 */
export default function SupplyRoomPage() {
  const ems = useEmsAccess();
  const [params, setParams] = useSearchParams();
  const counting = params.get('count') === '1';

  const setCounting = useCallback((open: boolean) => {
    setParams((previous) => {
      const next = new URLSearchParams(previous);
      if (open) next.set('count', '1'); else next.delete('count');
      return next;
    }, { replace: !open, preventScrollReset: true });
  }, [setParams]);

  return (
    <div data-hub-ui="2" className="space-y-5 font-hub" data-testid="supply-room-page">
      <header className="space-y-1">
        <a href="/admin" data-testid="back-to-admin" className="-ml-1 inline-flex min-h-11 items-center gap-1.5 rounded-hub-control px-1 text-sm font-semibold text-hub-blue-strong underline underline-offset-2">
          <ArrowLeft className="h-4 w-4 flex-none" aria-hidden="true" />
          Back to Admin
        </a>
        <h1 className="font-heading text-2xl font-bold text-hub-ink sm:text-3xl">Master Supply Room Inventory</h1>
      </header>

      <section className="hub-panel p-4 sm:p-5" aria-label="Supply room inventory">
        {ems.status === 'loading' && <SkeletonRows rows={5} />}
        {ems.status === 'error' && <LoadError title="The Master Supply Room Inventory could not be loaded" message={ems.error} onRetry={ems.reload} />}
        {ems.status === 'denied' && (
          <div data-testid="supply-room-denied">
            <EmptyState title="This account cannot open the supply room inventory" body="Ask an administrator for access if you need it." />
          </div>
        )}
        {ems.status === 'ready' && <MasterInventoryTab meta={ems.meta} counting={counting} onCounting={setCounting} onForbidden={ems.deny} standalone />}
      </section>
    </div>
  );
}

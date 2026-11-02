import CountSupplyRoom from './CountSupplyRoom';
import MasterInventoryView from './MasterInventoryView';
import type { EmsMeta } from '../types';

export interface MasterInventoryTabProps {
  meta: EmsMeta;
  /** True while the Monday count form is open (the page keeps this in the address as `count=1`). */
  counting: boolean;
  onCounting: (open: boolean) => void;
  onForbidden: () => void;
  /** On the standalone Admin page, which carries its own page heading. */
  standalone?: boolean;
}

/**
 * The Master Supply Room Inventory tab: the browsing table, or the Monday count form in its place while `counting`.
 * Leaving the count form remounts the table, which reads the newest numbers.
 */
export default function MasterInventoryTab({ meta, counting, onCounting, onForbidden, standalone = false }: MasterInventoryTabProps) {
  if (counting) return <CountSupplyRoom onExit={() => onCounting(false)} onForbidden={onForbidden} />;
  return <MasterInventoryView canStart={meta.inventory?.edit ?? meta.permissions.act} onCounting={onCounting} onForbidden={onForbidden} showTitle={!standalone} />;
}

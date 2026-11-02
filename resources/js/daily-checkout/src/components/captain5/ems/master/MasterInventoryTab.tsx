import CountSupplyRoom from './CountSupplyRoom';
import MasterInventoryView from './MasterInventoryView';
import type { EmsMeta } from '../types';

export interface MasterInventoryTabProps {
  meta: EmsMeta;
  /** True while the Monday count form is open (the page keeps this in the address as `count=1`). */
  counting: boolean;
  onCounting: (open: boolean) => void;
  onForbidden: () => void;
}

/**
 * The Master Supply Room Inventory tab: the browsing table, or the Monday count form in its place while `counting`.
 * Leaving the count form remounts the table, which reads the newest numbers.
 */
export default function MasterInventoryTab({ meta, counting, onCounting, onForbidden }: MasterInventoryTabProps) {
  if (counting) return <CountSupplyRoom onExit={() => onCounting(false)} onForbidden={onForbidden} />;
  return <MasterInventoryView canStart={meta.permissions.act} onCounting={onCounting} onForbidden={onForbidden} />;
}

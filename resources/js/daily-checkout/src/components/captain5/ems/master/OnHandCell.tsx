import { deductedDisplay, formatQty, unitWord } from './logic';
import type { MasterItem } from './types';

/** The one stock figure: the number in big type, the unit small, and a red minus when stock left since the last count. */
export default function OnHandCell({ item }: { item: MasterItem }) {
  if (item.on_hand === null) return <span className="text-sm font-semibold text-hub-n-600" data-testid="on-hand">Not counted</span>;
  const unit = unitWord(item, item.on_hand);
  const deducted = deductedDisplay(item);
  return (
    <span className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0" data-testid="on-hand">
      <span className="font-heading text-xl font-bold tabular-nums leading-7 text-hub-ink" data-testid="on-hand-number">{formatQty(item.on_hand)}</span>
      {unit !== '' && <span className="text-[13px] leading-5 text-hub-n-600">{unit}</span>}
      {deducted && (
        <span className="text-base font-bold tabular-nums text-hub-danger" data-testid="on-hand-deducted">
          <span aria-hidden="true">{deducted.text}</span>
          <span className="sr-only">{deducted.label}</span>
        </span>
      )}
    </span>
  );
}

import RecentUpdates from './RecentUpdates';
import UpdateComposer from './UpdateComposer';
import { useDepartmentUpdates } from './useDepartmentUpdates';
import type { UpdateOrigin } from './types';

interface Props {
  origin: UpdateOrigin;
  /** Takes the member to Messages, for the private alternative. */
  onOpenMessages?: () => void;
}

export const DEPARTMENT_UPDATES_EXPLANATION = 'Department Updates appear on the MBFD Hub home page for everyone in the department. For private messages to administrators, Captain 5 and 300, use Messages.';

/**
 * Its own section, clearly apart from Messages: whatever is posted here is public to the whole
 * department. Mounted only while its tab is open.
 */
export default function DepartmentUpdatesPanel({ origin, onOpenMessages }: Props) {
  const recent = useDepartmentUpdates(origin);
  return (
    <div className="space-y-6" data-testid="department-updates">
      <section className="space-y-3" aria-labelledby={`${origin}-post-update`}>
        <div>
          <h3 id={`${origin}-post-update`} className="font-heading text-lg font-bold text-hub-ink">Post to Department Updates</h3>
          <p className="mt-1 max-w-prose text-sm text-hub-ink" data-testid="department-updates-about">
            {DEPARTMENT_UPDATES_EXPLANATION}
          </p>
          <p className="mt-1 max-w-prose text-[13px] leading-5 text-hub-n-600" data-testid="department-updates-byline">
            Your post shows &ldquo;Posted by&rdquo; followed by your rank and name. This is filled in automatically from your account.
          </p>
          {onOpenMessages && (
            <button type="button" className="hub-btn hub-btn--tertiary !min-h-11 mt-1" onClick={onOpenMessages}>Go to Messages</button>
          )}
        </div>
        <UpdateComposer origin={origin} onPosted={recent.added} />
      </section>
      <RecentUpdates origin={origin} updates={recent.updates} status={recent.status} error={recent.error} onRetry={() => void recent.reload()} onChanged={recent.replaced} />
    </div>
  );
}

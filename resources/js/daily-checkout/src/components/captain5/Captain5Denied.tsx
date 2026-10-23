import { Link } from 'react-router';
import { Lock } from 'lucide-react';
import DailyPageNav from '../DailyPageNav';

/** Shown instead of the Captain 5 workspace when the server answers 403. Nothing protected is rendered. */
export default function Captain5Denied() {
  return (
    <div data-hub-ui="2" className="space-y-6 font-hub" data-testid="captain5-access-denied">
      <DailyPageNav fallback="/stations" />
      <section className="hub-panel flex flex-col items-center gap-2 px-4 py-8 text-center sm:px-6" aria-labelledby="captain5-denied-heading">
        <Lock className="h-6 w-6 text-hub-n-600" aria-hidden="true" />
        <h1 id="captain5-denied-heading" className="font-heading text-xl font-bold text-hub-ink">Captain 5 is not available to your account</h1>
        <p className="max-w-prose text-sm text-hub-n-600">
          Your account does not have access to the Captain 5 EMS Operations workspace, so its issues, handoff notes and messages are closed to you.
          Your station tools are not affected and still work as usual.
        </p>
        <Link to="/stations" className="hub-btn hub-btn--primary mt-2 !min-h-11">Back to stations</Link>
      </section>
    </div>
  );
}

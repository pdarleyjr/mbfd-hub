import { Link } from 'react-router';
import { Lock } from 'lucide-react';
import DailyPageNav from '../DailyPageNav';

/** Shown instead of the 300 Command page when the account is not permitted to use it. */
export default function AccessDenied() {
  return (
    <div data-hub-ui="2" className="space-y-6 font-hub" data-testid="command-access-denied">
      <DailyPageNav fallback="/stations" />
      <section className="hub-panel flex flex-col items-center gap-2 px-4 py-8 text-center sm:px-6" aria-labelledby="command-denied-heading">
        <Lock className="h-6 w-6 text-hub-n-600" aria-hidden="true" />
        <h1 id="command-denied-heading" className="font-heading text-xl font-bold text-hub-ink">300 Command is not available to your account</h1>
        <p className="max-w-prose text-sm text-hub-n-600">
          Your account does not have access to 300 Command, so its messages and readiness overview are closed to you.
          Your station tools are not affected and still work as usual.
        </p>
        <Link to="/stations" className="hub-btn hub-btn--primary mt-2 !min-h-11">Back to stations</Link>
      </section>
    </div>
  );
}

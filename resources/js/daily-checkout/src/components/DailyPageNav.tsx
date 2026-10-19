import { useLocation, useNavigate } from 'react-router';
import { PageNav } from '../../../hub-ui/PageNav';
import { contextualBackLabel, contextualBackPath } from '../utils/contextualBack';

interface DailyPageNavProps {
  /** Logical parent (router path without the /daily basename) used when there is no valid return_to. */
  fallback?: string;
  className?: string;
}

/**
 * Back + Hub Home for Daily pages. Back is a validated return_to, else the
 * logical parent, and never resolves to the page it is on.
 */
export default function DailyPageNav({ fallback = '/stations', className }: DailyPageNavProps) {
  const navigate = useNavigate();
  const location = useLocation();
  const destination = contextualBackPath(location.search, fallback, window.location.origin, `/daily${location.pathname}`);

  return (
    <PageNav
      className={className}
      backLabel={contextualBackLabel(destination)}
      onBack={() => destination.startsWith('/daily/') || destination === '/daily'
        ? navigate(destination.slice('/daily'.length) || '/', { replace: true })
        : window.location.assign(destination)}
    />
  );
}

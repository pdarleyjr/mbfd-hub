import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { useCallback } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { contextualBackLabel, contextualBackPath } from '../utils/contextualBack';
import { HubBack } from '../../../hub-ui/HubShell';

interface PreviousPageButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onClick' | 'type'> {
  children?: ReactNode;
  fallback?: string;
  contextual?: boolean;
}

export function usePreviousPage(fallback = '/stations') {
  const navigate = useNavigate();

  return useCallback(() => {
    const historyState = window.history.state as { idx?: unknown } | null;

    if (typeof historyState?.idx === 'number' && historyState.idx > 0) {
      navigate(-1);
      return;
    }

    navigate(fallback, { replace: true });
  }, [fallback, navigate]);
}

export default function PreviousPageButton({
  children = 'Back to previous page',
  fallback = '/stations',
  contextual = false,
  className,
  ...buttonProps
}: PreviousPageButtonProps) {
  const goToPreviousPage = usePreviousPage(fallback);
  const navigate = useNavigate();
  const location = useLocation();
  const destination = contextual ? contextualBackPath(location.search, fallback, window.location.origin, `/daily${location.pathname}`) : '';
  const label = contextualBackLabel(destination);

  if (contextual) return (
    <HubBack
      {...buttonProps}
      aria-label={label}
      className={`inline-flex min-h-11 min-w-11 items-center gap-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus ${className ?? ''}`}
      onClick={() => destination.startsWith('/daily/') || destination === '/daily'
        ? navigate(destination.slice('/daily'.length) || '/', { replace: true })
        : window.location.assign(destination)}
    >
      <span className="sm:hidden" aria-hidden="true">Back</span>
      <span className="hidden sm:inline" aria-hidden="true">{label}</span>
    </HubBack>
  );

  return (
    <button type="button" onClick={goToPreviousPage} className={className} {...buttonProps}>
      {children}
    </button>
  );
}

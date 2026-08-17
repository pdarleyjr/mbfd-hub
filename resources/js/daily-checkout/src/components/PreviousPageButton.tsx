import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { useCallback } from 'react';
import { useNavigate } from 'react-router';

interface PreviousPageButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onClick' | 'type'> {
  children?: ReactNode;
  fallback?: string;
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
  ...buttonProps
}: PreviousPageButtonProps) {
  const goToPreviousPage = usePreviousPage(fallback);

  return (
    <button type="button" onClick={goToPreviousPage} {...buttonProps}>
      {children}
    </button>
  );
}

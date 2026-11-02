import { useCallback } from 'react';
import { useSearchParams } from 'react-router';
import { clampIndex, sectionIndex, sectionParam } from './logic';
import type { MasterSection } from './types';

/**
 * The current shelf lives in the address (`shelf=`), so a refresh keeps the place and the back button steps back
 * through shelves. Other parameters (tab, count) are kept as they are.
 */
export function useShelfParam(sections: readonly MasterSection[]) {
  const [params, setParams] = useSearchParams();
  const index = sectionIndex(sections, params.get('shelf'));

  const setIndex = useCallback((next: number, push = true) => {
    const section = sections[clampIndex(next, sections.length)];
    if (!section) return;
    setParams((previous) => {
      const updated = new URLSearchParams(previous);
      updated.set('shelf', sectionParam(section));
      return updated;
    }, { replace: !push, preventScrollReset: true });
  }, [sections, setParams]);

  /** A link target for the same page pointing at a shelf. */
  const hrefFor = useCallback((code: string | null) => {
    const updated = new URLSearchParams(params);
    updated.set('shelf', code ?? 'none');
    return `?${updated.toString()}`;
  }, [params]);

  return { index, setIndex, hrefFor };
}

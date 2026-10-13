/*
 * The primary button as it must compute on every Hub surface: Blade (.hub-btn--primary),
 * Filament (.fi-btn.fi-color-primary) and Operational Forms (Fluent Button appearance="primary").
 * Fine pointer values; touch raises the height to 44px. One set of constants, asserted per surface.
 */
export const PRIMARY_BUTTON = {
  background: 'rgb(30, 78, 140)',
  color: 'rgb(255, 255, 255)',
  borderTopLeftRadius: '8px',
  height: 40,
} as const;

export interface ComputedButton {
  background: string;
  color: string;
  borderTopLeftRadius: string;
  height: number;
}

/** Reads the computed values the contract covers from an element, in the page. */
export function readButton(element: Element): ComputedButton {
  const style = getComputedStyle(element);
  return {
    background: style.backgroundColor,
    color: style.color,
    borderTopLeftRadius: style.borderTopLeftRadius,
    height: element.getBoundingClientRect().height,
  };
}

import { expect, type Page } from '@playwright/test';

/** The document does not scroll sideways and no rendered element crosses the right edge of the viewport. */
export async function expectNoHorizontalOverflow(page: Page, scope = 'body'): Promise<void> {
  const report = await page.evaluate((selector) => {
    const root = document.documentElement;
    const viewport = root.clientWidth;
    const escaped: string[] = [];
    document.querySelectorAll(`${selector} *`).forEach(element => {
      const style = getComputedStyle(element);
      if (style.position === 'fixed' || style.visibility === 'hidden' || style.clipPath !== 'none') return;
      if (element.closest('[hidden], dialog:not([open]), .sr-only')) return;
      // The inside of a closed <details> is not rendered, though it still reports a box.
      if (element.closest('details:not([open])') && !element.closest('details:not([open]) > summary')) return;
      const rect = element.getBoundingClientRect();
      if (rect.width > 1 && rect.height > 1 && (rect.right > viewport + 1 || rect.left < -1)) {
        escaped.push(`${element.tagName.toLowerCase()}.${String(element.getAttribute('class')).slice(0, 60)} right=${Math.round(rect.right)}`);
      }
    });
    return {
      viewport,
      documentWidth: root.scrollWidth,
      bodyWidth: document.body.scrollWidth,
      escaped: escaped.slice(0, 10),
    };
  }, scope);

  expect(report.documentWidth, `document width at viewport ${report.viewport}`).toBeLessThanOrEqual(report.viewport);
  expect(report.bodyWidth, `body width at viewport ${report.viewport}`).toBeLessThanOrEqual(report.viewport);
  expect(report.escaped, `elements past the edge at viewport ${report.viewport}`).toEqual([]);
}

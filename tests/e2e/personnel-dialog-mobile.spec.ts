import { test, expect, Locator, Page } from '@playwright/test';

async function openAction(page: Page, name: string) {
    const button = page.getByRole('button', { name, exact: true }).first();
    await expect.poll(() => button.evaluate(element => {
        let component: Element | null = element;
        while (component && !component.hasAttribute('wire:id')) component = component.parentElement;
        const modals = Array.from(component?.querySelectorAll('[data-fi-modal-id]') ?? []);
        return typeof (element as unknown as { _x_marker?: number })._x_marker === 'number' && modals.length > 0
            && modals.every(modal => Object.hasOwn((modal as unknown as { _x_attributeCleanups?: Record<string, unknown> })._x_attributeCleanups ?? {}, 'x-on:open-modal.window'));
    })).toBe(true);
    await button.click();
    const modal = page.locator('.fi-modal-open .fi-modal-window:visible').first();
    await expect(modal).toHaveCSS('opacity', '1');
    return modal;
}

async function assertInitialBounds(modal: Locator, capped = true) {
    // These reads and intersection checks never scroll or focus the form.
    const geometry = await modal.evaluate(element => {
        const rect = element.getBoundingClientRect();
        const viewport = window.visualViewport;
        return { x: rect.x, y: rect.y, right: rect.right, bottom: rect.bottom,
            width: viewport?.width ?? innerWidth, height: viewport?.height ?? innerHeight };
    });
    expect(geometry.x).toBeGreaterThanOrEqual(-1);
    expect(geometry.right).toBeLessThanOrEqual(geometry.width + 1);
    expect(geometry.y).toBeGreaterThanOrEqual(-1);
    if (capped) expect(geometry.bottom).toBeLessThanOrEqual(geometry.height + 1);
    await expect(modal.getByRole('heading').first()).toBeInViewport({ ratio: 1 });
    return geometry;
}

async function scrollToControl(page: Page, modal: Locator, control: Locator) {
    if (page.context().browser()?.browserType().name() === 'webkit') {
        // Playwright does not expose mobile WebKit drag gestures or wheel input.
        // Browser scrolling is allowed only after the strict initial, non-scrolling bounds assertion.
        await control.scrollIntoViewIfNeeded();
        await expect(control).toBeInViewport();
        return;
    }
    for (let attempt = 0; attempt < 15; attempt++) {
        const target = await control.boundingBox();
        const window = await modal.boundingBox();
        const viewport = page.viewportSize();
        if (!target || !window || !viewport) throw new Error('A native modal control is missing.');
        const footer = await modal.locator('.fi-modal-footer').boundingBox();
        const top = Math.max(window.y, 0) + 8;
        const bottom = Math.min(window.y + window.height, viewport.height,
            footer && footer.y > top && footer.y < viewport.height && !await control.evaluate(element => Boolean(element.closest('.fi-modal-footer'))) ? footer.y : viewport.height) - 8;
        if (target.y >= top && target.y + target.height <= bottom) return;
        const direction = target.y < top ? -1 : 1;
        const x = Math.min(viewport.width - 12, window.x + window.width - 12);
        const y = Math.min(viewport.height - 70, Math.max(80, (top + bottom) / 2));
        await page.mouse.move(x, y);
        await page.mouse.wheel(0, direction * 300);
        // Wait for the trusted scroll input to change control geometry, not an arbitrary delay.
        await expect.poll(async () => (await control.boundingBox())?.y).not.toBe(target.y);
    }
    throw new Error('Native scrolling did not bring the modal control into view.');
}

test('long personnel dialogs open within the phone viewport before focus or automatic scrolling', async ({ page }, info) => {
    const originalViewport = page.viewportSize()!;
    await page.goto('/login');
    const login = await page.request.post('/login', { form: {
        _token: await page.locator('input[name="_token"]').inputValue(),
        employee_id: 'personnel-admin@example.test', password: 'synthetic-personnel-password',
    }, maxRedirects: 0 });
    expect(login.status()).toBe(302);
    await page.goto('/admin/custom-personnel-tables');
    const create = await openAction(page, 'Create Table');
    const initialBounds = await assertInitialBounds(create);
    await info.attach('create-initial-bounds.json', { body: JSON.stringify(initialBounds), contentType: 'application/json' });
    await page.screenshot({ path: info.outputPath('create-initial.png') });
    const name = `Mobile viewport ${info.project.name}`;
    await create.getByRole('textbox', { name: /^Table name/ }).fill(name);
    await create.getByRole('combobox', { name: /^Choose personnel/ }).selectOption('filter');
    await expect(create.getByText('Member filters', { exact: true })).toBeVisible();
    const addColumn = create.getByRole('button', { name: 'Add tracking column', exact: true });
    await scrollToControl(page, create, addColumn);
    await addColumn.click();
    const columnName = create.getByRole('textbox', { name: /^Column name/ });
    await scrollToControl(page, create, columnName);
    await columnName.fill('Acknowledged');
    // A reduced layout viewport exercises short-window/PWA resize layout; it is not a physical keyboard simulation.
    await page.setViewportSize({ width: originalViewport.width, height: 320 });
    const shortBounds = await create.boundingBox();
    expect(shortBounds!.y).toBeGreaterThanOrEqual(-1);
    expect(shortBounds!.y + shortBounds!.height).toBeLessThanOrEqual(321);
    const submit = create.getByRole('button', { name: 'Create Table', exact: true });
    await scrollToControl(page, create, submit);
    await page.screenshot({ path: info.outputPath('create-short-viewport.png') });
    await submit.click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    await page.setViewportSize(originalViewport);
    const columns = await openAction(page, 'Columns');
    await assertInitialBounds(columns);
    await page.screenshot({ path: info.outputPath('configure-columns.png') });
    const cancelColumns = columns.getByRole('button', { name: 'Cancel', exact: true });
    await scrollToControl(page, columns, cancelColumns);
    await cancelColumns.click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
    const exportModal = await openAction(page, 'Export');
    await assertInitialBounds(exportModal);
    await page.screenshot({ path: info.outputPath('export-short-modal.png') });
    await exportModal.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
    await page.setViewportSize({ width: originalViewport.height, height: originalViewport.width });
    await page.goto('/admin/custom-personnel-tables');
    const landscape = await openAction(page, 'Create Table');
    await assertInitialBounds(landscape, originalViewport.height < 640);
    await page.screenshot({ path: info.outputPath('create-landscape.png') });
    const cancelLandscape = landscape.getByRole('button', { name: 'Cancel', exact: true });
    await scrollToControl(page, landscape, cancelLandscape);
    await cancelLandscape.click();
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0);
});

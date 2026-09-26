import { expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import type { InspectionSubmission } from '../../../resources/js/daily-checkout/src/types';

export const mobileApparatus = [
  { designation: 'E2', name: 'Engine 2', slug: 'engine-2', type: 'engine', checklistType: 'engine2' },
  { designation: 'E1', name: 'Engine 1', slug: 'engine-1', type: 'engine', checklistType: 'engine' },
  { designation: 'L1', name: 'Ladder 1', slug: 'ladder-1', type: 'ladder', checklistType: 'ladder1' },
  { designation: 'R3', name: 'Rescue 3', slug: 'rescue-3', type: 'rescue', checklistType: 'rescue' },
  { designation: 'FB6', name: 'Fire Boat 6', slug: 'fire-boat-6', type: 'fireboat', checklistType: 'fireboat6' },
] as const;

/** Local authenticated-identity/API fixture; this does not prove production authentication. */
export async function mobileInspectionFixture(page: Page, model: typeof mobileApparatus[number]) {
  const checklist = JSON.parse(readFileSync(`storage/checklists/${model.checklistType}_checklist.json`, 'utf8'));
  const inspectionDate = '2026-09-21';
  const version = 'e'.repeat(64);
  const vehicle = { ...model, id: 102, vehicle_number: 'TEST-102', status: 'In Service', current_engine_hours: 1250, current_miles: 42518 };
  const submissions: InspectionSubmission[] = [];
  let userId = 401;
  if (checklist.schema_version === 2) await page.clock.setFixedTime(new Date(`${inspectionDate}T13:00:00Z`));
  await page.route('**/images/mbfd_logo_new.png', route => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/me/context') return route.fulfill({ json: {
      identity: { user_id: userId, has_personnel_profile: true },
      personnel: { employee_profile_id: userId + 100, employee_number: `TEST-${userId + 100}`, name: userId === 401 ? 'Browser Member' : 'Other Browser Member', rank: 'Captain' },
      offline: { security_version: 1 }, session: { authenticated: true },
    } });
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [vehicle] });
    const contract = { inspection_date: inspectionDate, checklist_version: version, checklist_type: model.checklistType, checklist, due_tasks: [], open_findings: [] };
    if (path.endsWith('/checklist')) return route.fulfill({ json: contract });
    if (path.endsWith('/inspection-sessions')) return route.fulfill({ status: 201, json: { ...contract, inspection_session: {
      id: '11111111-2222-4333-8444-555555555555', token: 'd'.repeat(64),
      issued_at: `${inspectionDate}T12:00:00Z`, expires_at: `${inspectionDate}T23:00:00Z`,
      duty_date: inspectionDate, checklist_template_id: checklist.template_id,
      checklist_template_version: checklist.template_version, checklist_hash: version,
      due_tasks: [], due_tasks_hash: 'f'.repeat(64), replay_key: '66666666-7777-4888-8999-aaaaaaaaaaaa',
    } } });
    if (path === '/api/public/apparatuses/102/inspections' && route.request().method() === 'POST') {
      submissions.push(route.request().postDataJSON());
      return route.fulfill({ status: 201, json: { success: true, review_status: 'approved' } });
    }
    if (path === '/api/public/inspection-revisions') return route.fulfill({ json: { data: [] } });
    if (path.includes('service')) return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API: ${path}` } });
  });
  await page.goto(`/daily/vehicle-inspections/${vehicle.slug}`);
  await expect(page.getByRole('heading', { name: vehicle.name, exact: true })).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
  return { checklist, submissions, setUserId: (id: number) => { userId = id; } };
}

export async function measureInspection(page: Page) {
  return page.evaluate(() => {
    const box = (selector: string) => {
      const element = document.querySelector(selector);
      if (!element) return null;
      const rect = element.getBoundingClientRect();
      return { top: rect.top, documentTop: rect.top + scrollY, bottom: rect.bottom, width: rect.width, height: rect.height, visible: rect.width > 0 && rect.height > 0 };
    };
    const firstAction = box('.equipment-pass');
    const actionBar = box('.inspection-action-bar');
    const usableViewport = innerHeight - (actionBar?.height ?? 0);
    return {
      viewport: { width: innerWidth, height: innerHeight }, pageHeight: document.documentElement.scrollHeight,
      scrollY, authorityHeader: box('.inspection-authority'), areaSelector: box('.inspection-navigation'),
      apparatusImage: box('.apparatus-blueprint'), equipmentRow: box('.equipment-row'),
      bottomBar: actionBar, navigationTabs: box('nav[aria-label="Inspection workspace"]'), firstAction,
      firstActionViewportHeights: firstAction ? firstAction.documentTop / innerHeight : null,
      // Reproducible estimate: a swipe advances 75% of the viewport above the fixed bar.
      estimatedSwipesFromTop: firstAction ? Math.max(0, Math.ceil((firstAction.documentTop + firstAction.height - usableViewport) / (usableViewport * .75))) : null,
      swipeDefinition: 'ceil(max(0, action bottom - usable viewport) / (0.75 * usable viewport)); estimated, not a physical gesture count',
    };
  });
}

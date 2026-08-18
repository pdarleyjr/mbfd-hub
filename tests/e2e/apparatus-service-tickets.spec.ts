import { expect, test } from '@playwright/test';

const now = '2026-08-18T15:00:00.000Z';
const apparatus = {
  id: 10,
  station_id: 1,
  unit_id: 'E1',
  name: 'Engine 1',
  type: 'engine',
  vehicle_number: '1001',
  designation: 'E1',
  slug: 'engine-1',
  status: 'In Service',
};
const station = {
  id: 1,
  station_number: 1,
  name: 'Station 1',
  address: '1051 Jefferson Avenue',
  city: 'Miami Beach',
  state: 'FL',
  zip_code: '33139',
  phone: '',
  is_active: true,
  rooms: [],
  apparatuses: [apparatus],
  assigned_apparatus_count: 1,
  assigned_personnel_count: 14,
  dorm_beds_count: 14,
  assigned_units: ['E1'],
  staffing_known: true,
};
const openTicket = {
  id: 700,
  ticket_number: 'AST-2026-000700',
  apparatus_id: 10,
  station_id: 1,
  unit_designation: 'E1',
  origin: 'station',
  category: 'repair_mechanical',
  title: 'Air leak at rear brake chamber',
  priority: 'urgent',
  status: 'acknowledged',
  is_open: true,
  current_public_response: 'Fleet has reviewed the report.',
  created_at: now,
  updated_at: now,
  updates: [
    { id: 1, status: 'submitted', public_note: 'Service ticket submitted.', created_at: now },
    { id: 2, status: 'acknowledged', public_note: 'Fleet has reviewed the report.', created_at: now },
  ],
};
const completedTicket = {
  ...openTicket,
  id: 699,
  ticket_number: 'AST-2026-000699',
  title: 'Prior PM service',
  category: 'preventive_maintenance',
  priority: 'routine',
  status: 'completed',
  is_open: false,
  current_public_response: 'Preventive maintenance completed.',
  updates: [],
};
const scheduledTicket = {
  ...openTicket,
  id: 701,
  ticket_number: 'AST-2026-000701',
  title: 'Routine pump service',
  service_type: 'PMA',
  priority: 'routine',
  status: 'scheduled',
  scheduled_for: '2026-08-26T13:00:00.000Z',
  scheduled_location: 'Fire Fleet',
  expected_return_at: '2026-08-26T17:00:00.000Z',
  current_public_response: 'Bring E1 to Fleet after morning lineup.',
};

test('station Service / Repair tab shows an open badge, safe history, and touch-sized actions', async ({ page }) => {
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/api/public/stations/1') return route.fulfill({ json: station });
    if (url.pathname === '/api/public/stations/1/apparatus-inspections') return route.fulfill({ json: { inspections: [] } });
    if (url.pathname === '/api/public/stations/1/requests') return route.fulfill({ json: { data: [] } });
    if (url.pathname === '/api/public/stations/1/service-tickets') {
      return route.fulfill({ json: { data: url.searchParams.get('scope') === 'all' ? [openTicket, completedTicket] : [openTicket] } });
    }
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${url.pathname}` } });
  });

  await page.goto('/daily/stations/1');
  const serviceTab = page.getByRole('button', { name: /Service \/ Repair/ });
  await expect(serviceTab).toContainText('1');
  expect((await serviceTab.boundingBox())?.height).toBeGreaterThanOrEqual(48);
  await serviceTab.click();

  await expect(page.getByText('AST-2026-000700 · E1')).toBeVisible();
  await expect(page.getByText('Air leak at rear brake chamber')).toBeVisible();
  await expect(page.getByText('Latest update: Fleet has reviewed the report.', { exact: true })).toBeVisible();
  await expect(page.getByText('Prior PM service')).toHaveCount(0);
  const allHistory = page.getByRole('button', { name: 'All history' });
  expect((await allHistory.boundingBox())?.height).toBeGreaterThanOrEqual(48);
  await allHistory.click();
  await expect(page.getByText('Prior PM service')).toBeVisible();

  await expect(page.getByRole('link', { name: 'Apparatus Service' })).toHaveAttribute('href', /\/employee\/apparatus-service-request\?station_id=1/);
  await page.getByRole('button', { name: 'Apparatus' }).click();
  await expect(page.getByRole('link', { name: 'Report Service Need' })).toHaveAttribute('href', /apparatus_id=10/);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('service notice failure never blocks the inspection checklist', async ({ page }) => {
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [apparatus] });
    if (path === '/api/public/apparatuses/10/checklist') return route.fulfill({ json: { checklist: { compartments: [{ id: 'cab', name: 'Cab', items: [{ id: 'radio', name: 'Radio', status: 'Present' }] }] } } });
    if (path === '/api/public/apparatuses/10/service-notices') return route.fulfill({ status: 503, json: { error: 'Unavailable' } });
    if (path === '/api/public/employees/list') return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/vehicle-inspections/engine-1');
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Engine 1' })).toBeVisible();
  await expect(page.getByText('Live Fleet service notices are temporarily unavailable.')).toBeVisible();
  await expect(page.getByText('Officer Information')).toBeVisible();
  await expect(page.getByText('Inspection Data Unavailable')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('checkout with no active ticket shows no notice and remains usable', async ({ page }) => {
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [apparatus] });
    if (path === '/api/public/apparatuses/10/checklist') return route.fulfill({ json: { checklist: { compartments: [] } } });
    if (path === '/api/public/apparatuses/10/service-notices') return route.fulfill({ json: { data: [] } });
    if (path === '/api/public/employees/list') return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/vehicle-inspections/engine-1');
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Engine 1' })).toBeVisible();
  await expect(page.getByRole('heading', { name: /service notice/i })).toHaveCount(0);
  await expect(page.getByText('Officer Information')).toBeVisible();
});

test('open service notice is shown without redefining operational status', async ({ page }) => {
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [apparatus] });
    if (path === '/api/public/apparatuses/10/checklist') return route.fulfill({ json: { checklist: { compartments: [] } } });
    if (path === '/api/public/apparatuses/10/service-notices') return route.fulfill({ json: { data: [openTicket] } });
    if (path === '/api/public/employees/list') return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/vehicle-inspections/engine-1');
  await expect(page.getByRole('heading', { name: 'Open service notice for this unit' })).toBeVisible();
  await expect(page.getByText('Operational status: In Service')).toBeVisible();
  await expect(page.getByText('A ticket does not by itself change the unit operational status.')).toBeVisible();
});

test('scheduled checkout notice shows the verified appointment details', async ({ page }) => {
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [apparatus] });
    if (path === '/api/public/apparatuses/10/checklist') return route.fulfill({ json: { checklist: { compartments: [] } } });
    if (path === '/api/public/apparatuses/10/service-notices') return route.fulfill({ json: { data: [scheduledTicket] } });
    if (path === '/api/public/employees/list') return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/vehicle-inspections/engine-1');
  await expect(page.getByText('Service scheduled', { exact: true })).toBeVisible();
  await expect(page.getByText('PMA · AST-2026-000701', { exact: true })).toBeVisible();
  await expect(page.getByText('Scheduled at Fire Fleet', { exact: false })).toBeVisible();
  await expect(page.getByText('Bring E1 to Fleet after morning lineup.')).toBeVisible();
});

test('official out-of-service state is unmistakable when an active ticket exists', async ({ page }) => {
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [{ ...apparatus, status: 'Out of Service' }] });
    if (path === '/api/public/apparatuses/10/checklist') return route.fulfill({ json: { checklist: { compartments: [] } } });
    if (path === '/api/public/apparatuses/10/service-notices') return route.fulfill({ json: { data: [openTicket] } });
    if (path === '/api/public/employees/list') return route.fulfill({ json: [] });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/vehicle-inspections/engine-1');
  await expect(page.getByRole('heading', { name: 'Unit out of service' })).toBeVisible();
  await expect(page.getByText('Operational status: Out of Service')).toBeVisible();
  await expect(page.getByText('Refer to AST-2026-000700.')).toBeVisible();
});

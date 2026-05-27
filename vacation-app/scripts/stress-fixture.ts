/**
 * Generate a synthetic Telestaff-style CSV for stress testing.
 *
 * Usage:
 *   tsx scripts/stress-fixture.ts --members 300 --days 365 --out fixtures/stress.csv
 *
 * Defaults: 300 members, 365 days, fixtures/telestaff-stress-{members}-{days}.csv
 */
import { createWriteStream, mkdirSync } from 'node:fs';
import { argv, exit } from 'node:process';

const args = new Map<string, string>();
for (let i = 2; i < argv.length; i++) {
  const tok = argv[i];
  if (tok?.startsWith('--')) {
    args.set(tok.slice(2), argv[i + 1] ?? '');
    i++;
  }
}

const members = Number(args.get('members') ?? 300);
const days = Number(args.get('days') ?? 365);
const out = args.get('out') ?? `fixtures/telestaff-stress-${members}-${days}.csv`;

if (!Number.isFinite(members) || members < 1) {
  console.error('--members must be a positive integer');
  exit(1);
}
if (!Number.isFinite(days) || days < 1) {
  console.error('--days must be a positive integer');
  exit(1);
}

mkdirSync('fixtures', { recursive: true });
const w = createWriteStream(out, { encoding: 'utf8' });

const RANKS = ['DC', 'CAPT', 'LT', 'FF', 'PROB'];
const SHIFTS = ['A', 'B', 'C'];
const ADAY = ['A1', 'A2', 'A3', 'A4'];
const CODES: { code: string; desc: string; weight: number }[] = [
  { code: 'V',  desc: 'Vacation',           weight: 30 },
  { code: 'FH', desc: 'Floating Holiday',   weight: 10 },
  { code: 'AH', desc: 'Alternate Holiday',  weight: 5 },
  { code: 'EF', desc: 'Emergency Floater',  weight: 2 },
  { code: 'A',  desc: 'A-Day',              weight: 35 },
  { code: 'S',  desc: 'Sick',               weight: 8 },
  { code: 'HO', desc: 'Holiday Off',        weight: 3 },
];
const TOTAL_WEIGHT = CODES.reduce((s, c) => s + c.weight, 0);

function pick<T>(arr: T[], i: number): T {
  return arr[i % arr.length] as T;
}

function chooseCode(rand: number): { code: string; desc: string } {
  let acc = 0;
  for (const c of CODES) {
    acc += c.weight;
    if (rand * TOTAL_WEIGHT < acc) return c;
  }
  return CODES[CODES.length - 1] as { code: string; desc: string };
}

const lastNames = ['Smith', 'Jones', 'Lopez', 'Garcia', 'Martinez', 'Brown', 'Davis', 'Wilson', 'Anderson', 'Taylor', 'Thomas', 'Moore', 'Jackson', 'Harris', 'Clark', 'Lewis', 'Walker', 'Hall', 'Allen', 'Young'];
const firstNames = ['John', 'Mike', 'David', 'Chris', 'Jose', 'Luis', 'Carlos', 'Daniel', 'Mark', 'Anthony', 'Eric', 'Brian', 'Kevin', 'Steven', 'Paul', 'Matthew', 'Robert', 'James', 'William', 'Thomas'];

w.write('Emp ID,Last Name,First Name,Rank,Shift,A-Day Group,Hire Date,Start DateTime,End DateTime,Work Code,Description\n');

const startDate = new Date('2026-01-01T08:00:00');
let rowCount = 0;

// roughly 25% of days get a leave entry per member (mix of A-days + V + FH etc)
for (let m = 0; m < members; m++) {
  const emp = String(10000 + m);
  const last = pick(lastNames, m);
  const first = pick(firstNames, m * 7);
  const rank = pick(RANKS, m);
  const shift = pick(SHIFTS, m);
  const aday = pick(ADAY, m);
  const hire = new Date(2010 + (m % 15), m % 12, 1 + (m % 27)).toISOString().slice(0, 10);

  for (let d = 0; d < days; d++) {
    // ~30% chance there's an entry on this day for this member
    if (((m * 31 + d * 17) % 100) >= 30) continue;
    const day = new Date(startDate);
    day.setDate(day.getDate() + d);
    const code = chooseCode((m * 13 + d * 19) % 1000 / 1000);

    // Full 24-hour entry — emit BOTH AM (08:00-20:00) and PM (20:00-08:00 next day) rows
    for (const half of [0, 1]) {
      const start = new Date(day);
      start.setHours(half === 0 ? 8 : 20, 0, 0, 0);
      const end = new Date(start);
      end.setHours(end.getHours() + 12);
      w.write(
        `${emp},${last},${first},${rank},${shift},${aday},${hire},${start.toISOString()},${end.toISOString()},${code.code},${code.desc}\n`,
      );
      rowCount++;
    }
  }
}

w.end(() => {
  console.log(`Wrote ${rowCount.toLocaleString()} rows to ${out}`);
});

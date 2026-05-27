import dayjs from 'dayjs';
import type { Readable } from 'node:stream';
// xlsx-stream-reader is a CommonJS module
// eslint-disable-next-line @typescript-eslint/no-var-requires
import XlsxStreamReader from 'xlsx-stream-reader';

export type ParsedRow = Record<string, string | number | null>;

const EXCEL_EPOCH = dayjs('1899-12-30');

/**
 * Convert an Excel date serial to an ISO datetime. The integer part is
 * the day offset from 1899-12-30; the fractional part encodes time of
 * day. We add the integer days then the fractional millisecond remainder
 * so a serial like 45678.5 round-trips as midday rather than midnight.
 */
function excelSerialToISO(serial: number): string {
  const days = Math.floor(serial);
  const msInDay = (serial - days) * 86_400_000;
  return EXCEL_EPOCH.add(days, 'day').add(Math.round(msInDay), 'millisecond').toISOString();
}

/**
 * Heuristic: an Excel cell carrying an integer-ish value that could be a
 * date serial. We only convert if a sibling header column name looks like
 * a date column. The caller passes the header list.
 */
function isLikelyDateColumn(header: string): boolean {
  const h = header.toLowerCase();
  return /date|start|end|time|datetime|when/.test(h);
}

/**
 * Stream-parse an XLSX from a Readable. Yields header + rows from the first
 * worksheet.
 *
 * xlsx-stream-reader emits `worksheet` events per sheet; we operate on the
 * first sheet only (matches the Telestaff export format).
 */
export function parseXlsx(source: Readable): AsyncGenerator<{
  rowIndex: number;
  row: ParsedRow;
  headers: string[];
}> {
  return (async function* () {
    const reader = new XlsxStreamReader();
    const events: Array<
      | { type: 'row'; row: string[] }
      | { type: 'end' }
      | { type: 'error'; err: Error }
    > = [];
    let waiter: (() => void) | null = null;
    const push = (e: typeof events[number]): void => {
      events.push(e);
      const w = waiter;
      waiter = null;
      w?.();
    };

    reader.on('worksheet', (ws: {
      id: number;
      on: (evt: string, fn: (...a: unknown[]) => void) => void;
      process: () => void;
    }) => {
      if (ws.id !== 1) return;
      ws.on('row', (row: unknown) => {
        const r = row as { values?: unknown[] };
        const values = (r.values ?? []).map((v) => {
          if (v === undefined || v === null) return '';
          if (typeof v === 'object' && 'value' in (v as Record<string, unknown>)) {
            return String((v as { value: unknown }).value ?? '');
          }
          return String(v);
        });
        // xlsx-stream-reader uses 1-based array; index 0 is empty
        push({ type: 'row', row: values.slice(1) });
      });
      ws.on('end', () => push({ type: 'end' }));
      ws.process();
    });
    reader.on('error', (err: Error) => push({ type: 'error', err }));
    reader.on('end', () => push({ type: 'end' }));

    source.pipe(reader);

    let headers: string[] = [];
    let i = 0;
    while (true) {
      while (events.length === 0) {
        await new Promise<void>((resolve) => {
          waiter = resolve;
        });
      }
      const event = events.shift();
      if (!event) continue;
      if (event.type === 'error') throw event.err;
      if (event.type === 'end') return;
      if (headers.length === 0) {
        headers = event.row;
        continue;
      }
      const obj: ParsedRow = {};
      for (let c = 0; c < headers.length; c++) {
        const key = headers[c] ?? `col_${c}`;
        const raw = event.row[c] ?? '';
        if (raw === '') {
          obj[key] = null;
          continue;
        }
        const num = Number(raw);
        if (!Number.isNaN(num) && isLikelyDateColumn(key) && num > 10_000 && num < 100_000) {
          obj[key] = excelSerialToISO(num);
        } else {
          obj[key] = raw;
        }
      }
      yield { rowIndex: i, row: obj, headers };
      i++;
    }
  })();
}

export async function readXlsxHeader(source: Readable): Promise<string[]> {
  const gen = parseXlsx(source);
  const first = await gen.next();
  if (first.done) return [];
  return first.value.headers;
}

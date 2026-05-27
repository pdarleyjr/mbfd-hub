/**
 * SAX-streamed parser for SpreadsheetML 2003 (Excel XML) workbooks.
 *
 * Telestaff's "Export All Records" report emits this format with the
 * .xml extension. The schema we care about (per row) is a `ss:Row` whose
 * children are `ss:Cell` elements; each Cell contains a `ss:Data`
 * element typed via `ss:Type` (String, Number, DateTime, Boolean). Cells
 * may carry an `ss:Index` attribute that jumps the column pointer
 * forward (so we must respect 1-based column indexing and pad missing
 * columns with empty values).
 *
 * The first non-empty Row in the first Worksheet supplies the header
 * list. Subsequent rows are yielded as `{ rowIndex, row, headers }`
 * dictionaries shaped the same as our other parsers so the downstream
 * worker code is unchanged.
 */
import type { Readable } from 'node:stream';
import sax from 'sax';

export type ParsedRow = Record<string, string | number | null>;

type Event =
  | { type: 'row'; row: Array<string | number | null> }
  | { type: 'end' }
  | { type: 'error'; err: Error };

const SS = 'urn:schemas-microsoft-com:office:spreadsheet';

export function parseSpreadsheetXml(source: Readable): AsyncGenerator<{
  rowIndex: number;
  row: ParsedRow;
  headers: string[];
}> {
  return (async function* () {
    const events: Event[] = [];
    let waiter: (() => void) | null = null;
    let ended = false;

    const push = (e: Event): void => {
      events.push(e);
      const w = waiter;
      waiter = null;
      w?.();
    };

    // strict=true so namespaces and case are preserved (SpreadsheetML uses
    // PascalCase tags inside the urn:…:office:spreadsheet namespace).
    const parser = sax.createStream(true, { trim: false, xmlns: true });

    // Parser state
    let inTable = false;
    let inRow = false;
    let inCell = false;
    let inData = false;

    // sheetSeen=true means we've already processed the first worksheet; we
    // ignore any subsequent worksheets (matches the xlsx parser semantics).
    let activeWorksheet = false;
    let sheetSeen = false;

    let columnIndex = 0;
    let currentCellType: string | null = null;
    let dataBuffer = '';
    let currentRow: Array<string | number | null> = [];

    const localName = (qn: string): string => {
      const i = qn.indexOf(':');
      return i >= 0 ? qn.slice(i + 1) : qn;
    };

    const isSpreadsheetNs = (
      tag: { uri?: string } & { ns?: Record<string, string>; name: string },
    ): boolean => {
      // sax with xmlns=true exposes `uri` on each tag.
      return (tag.uri ?? '') === SS || (tag.uri ?? '') === '';
    };

    parser.on('opentag', (tag: { name: string; attributes: Record<string, sax.QualifiedAttribute | string>; uri?: string }) => {
      const name = localName(tag.name);

      if (name === 'Worksheet' && isSpreadsheetNs(tag)) {
        if (sheetSeen) return;
        activeWorksheet = true;
        return;
      }
      if (!activeWorksheet) return;

      if (name === 'Table') {
        inTable = true;
        return;
      }
      if (!inTable) return;

      if (name === 'Row') {
        inRow = true;
        currentRow = [];
        columnIndex = 0;
        return;
      }
      if (!inRow) return;

      if (name === 'Cell') {
        inCell = true;
        // ss:Index is 1-based; honor jumps and pad earlier columns with null.
        const attrs = tag.attributes;
        const idxAttr =
          (attrs['ss:Index'] as sax.QualifiedAttribute | undefined)?.value ??
          (attrs['Index'] as sax.QualifiedAttribute | undefined)?.value;
        if (idxAttr) {
          const targetCol = Number(idxAttr) - 1; // convert to 0-based
          while (columnIndex < targetCol) {
            currentRow.push(null);
            columnIndex++;
          }
        }
        currentCellType = null;
        return;
      }
      if (!inCell) return;

      if (name === 'Data') {
        inData = true;
        const attrs = tag.attributes;
        const t =
          (attrs['ss:Type'] as sax.QualifiedAttribute | undefined)?.value ??
          (attrs['Type'] as sax.QualifiedAttribute | undefined)?.value ??
          'String';
        currentCellType = t;
        dataBuffer = '';
      }
    });

    parser.on('text', (text: string) => {
      if (inData) dataBuffer += text;
    });

    parser.on('cdata', (text: string) => {
      if (inData) dataBuffer += text;
    });

    parser.on('closetag', (qname: string) => {
      const name = localName(qname);

      if (!activeWorksheet) return;

      if (name === 'Data' && inData) {
        inData = false;
        return;
      }

      if (name === 'Cell' && inCell) {
        let value: string | number | null = dataBuffer;
        if (currentCellType === 'Number') {
          const n = Number(dataBuffer);
          value = Number.isFinite(n) ? n : dataBuffer;
        } else if (dataBuffer === '') {
          value = null;
        }
        currentRow.push(value);
        columnIndex++;
        inCell = false;
        currentCellType = null;
        dataBuffer = '';
        return;
      }

      if (name === 'Row' && inRow) {
        push({ type: 'row', row: currentRow });
        inRow = false;
        return;
      }

      if (name === 'Table' && inTable) {
        inTable = false;
        return;
      }

      if (name === 'Worksheet' && activeWorksheet) {
        activeWorksheet = false;
        sheetSeen = true;
        return;
      }
    });

    parser.on('error', (err: Error) => {
      push({ type: 'error', err });
      // sax stops on error unless we resume; we don't need more events.
      try {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (parser as unknown as { _parser: { error: unknown; resume: () => void } })._parser.error =
          null;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (parser as unknown as { _parser: { resume: () => void } })._parser.resume();
      } catch {
        /* nothing more to do */
      }
    });

    parser.on('end', () => {
      ended = true;
      push({ type: 'end' });
    });

    source.on('error', (err: Error) => push({ type: 'error', err }));
    source.pipe(parser);

    let headers: string[] = [];
    let i = 0;

    while (true) {
      while (events.length === 0 && !ended) {
        await new Promise<void>((resolve) => {
          waiter = resolve;
        });
      }
      const event = events.shift();
      if (!event) {
        if (ended) return;
        continue;
      }
      if (event.type === 'error') throw event.err;
      if (event.type === 'end') return;

      // First non-empty row is the header.
      if (headers.length === 0) {
        headers = event.row.map((v, idx) => (v == null ? `col_${idx}` : String(v)));
        continue;
      }

      const obj: ParsedRow = {};
      for (let c = 0; c < headers.length; c++) {
        const key = headers[c] ?? `col_${c}`;
        const raw = event.row[c];
        obj[key] = raw === '' ? null : (raw ?? null);
      }
      yield { rowIndex: i, row: obj, headers };
      i++;
    }
  })();
}

export async function readSpreadsheetXmlHeader(source: Readable): Promise<string[]> {
  const gen = parseSpreadsheetXml(source);
  const first = await gen.next();
  if (first.done) return [];
  return first.value.headers;
}

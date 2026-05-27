import { parse } from 'csv-parse';
import type { Readable } from 'node:stream';

export type ParsedRow = Record<string, string>;

/**
 * Stream-parse a CSV. Yields one record per row.
 *
 * Handles BOM, CRLF, quoted commas. Headers are taken from the first row.
 * Empty rows are skipped.
 */
export async function* parseCsv(source: Readable): AsyncGenerator<{
  rowIndex: number;
  row: ParsedRow;
}> {
  const parser = source.pipe(
    parse({
      bom: true,
      columns: true,
      skip_empty_lines: true,
      relax_column_count: true,
      trim: true,
    }),
  );

  let i = 0;
  for await (const record of parser) {
    const r = record as ParsedRow;
    yield { rowIndex: i, row: r };
    i++;
  }
}

/**
 * Read just the header row without consuming the whole file. Used by
 * preview to give the mapping UI something to render quickly.
 */
export async function readCsvHeader(source: Readable): Promise<string[]> {
  const parser = source.pipe(
    parse({ bom: true, to_line: 1, trim: true }),
  );
  for await (const record of parser) {
    if (Array.isArray(record)) {
      const headers: string[] = [];
      for (const cell of record) {
        if (typeof cell === 'string') headers.push(cell);
      }
      return headers;
    }
  }
  return [];
}

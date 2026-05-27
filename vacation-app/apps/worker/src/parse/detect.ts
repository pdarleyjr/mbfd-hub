import type { Readable } from 'node:stream';
import { parseCsv } from './csv';
import { parseXlsx } from './xlsx';
import { parseSpreadsheetXml } from './spreadsheet-xml';
import type { ParsedRow } from './csv';

export type FileKind = 'csv' | 'xlsx' | 'spreadsheet-xml';

export function detectKindFromName(fileName: string): FileKind | null {
  const lower = fileName.toLowerCase();
  if (lower.endsWith('.csv')) return 'csv';
  if (lower.endsWith('.xlsx') || lower.endsWith('.xlsm')) return 'xlsx';
  if (lower.endsWith('.xml')) return 'spreadsheet-xml';
  return null;
}

/**
 * Unified row iterator that hides the kind. Yields rows for CSV, XLSX, or
 * SpreadsheetML 2003 XML sources.
 */
export async function* iterRows(
  kind: FileKind,
  source: Readable,
): AsyncGenerator<{ rowIndex: number; row: ParsedRow; headers: string[] }> {
  let headers: string[] = [];
  if (kind === 'csv') {
    for await (const { rowIndex, row } of parseCsv(source)) {
      if (headers.length === 0) headers = Object.keys(row);
      yield { rowIndex, row, headers };
    }
  } else if (kind === 'xlsx') {
    for await (const ev of parseXlsx(source)) {
      yield { rowIndex: ev.rowIndex, row: ev.row as ParsedRow, headers: ev.headers };
    }
  } else {
    for await (const ev of parseSpreadsheetXml(source)) {
      yield {
        rowIndex: ev.rowIndex,
        row: ev.row as ParsedRow,
        headers: ev.headers,
      };
    }
  }
}

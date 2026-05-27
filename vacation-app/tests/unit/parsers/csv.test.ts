import { describe, expect, it } from 'vitest';
import { Readable } from 'node:stream';
import { parseCsv } from '../../../apps/worker/src/parse/csv.js';

function fromString(s: string): Readable {
  return Readable.from(Buffer.from(s, 'utf8'));
}

describe('parseCsv', () => {
  it('parses a simple CSV', async () => {
    const csv = 'a,b,c\n1,2,3\n4,5,6\n';
    const rows: Record<string, string>[] = [];
    for await (const { row } of parseCsv(fromString(csv))) {
      rows.push(row);
    }
    expect(rows).toEqual([
      { a: '1', b: '2', c: '3' },
      { a: '4', b: '5', c: '6' },
    ]);
  });

  it('handles quoted commas and CRLF', async () => {
    const csv = 'name,note\r\n"Smith, J","hi, there"\r\n"Jones","ok"\r\n';
    const rows: Record<string, string>[] = [];
    for await (const { row } of parseCsv(fromString(csv))) {
      rows.push(row);
    }
    expect(rows[0]).toEqual({ name: 'Smith, J', note: 'hi, there' });
    expect(rows[1]).toEqual({ name: 'Jones', note: 'ok' });
  });

  it('skips empty lines', async () => {
    const csv = 'a,b\n1,2\n\n3,4\n';
    const rows: Record<string, string>[] = [];
    for await (const { row } of parseCsv(fromString(csv))) {
      rows.push(row);
    }
    expect(rows.length).toBe(2);
  });

  it('handles UTF-8 BOM', async () => {
    const csv = '﻿a,b\n1,2\n';
    const rows: Record<string, string>[] = [];
    for await (const { row } of parseCsv(fromString(csv))) {
      rows.push(row);
    }
    expect(rows[0]).toEqual({ a: '1', b: '2' });
  });
});

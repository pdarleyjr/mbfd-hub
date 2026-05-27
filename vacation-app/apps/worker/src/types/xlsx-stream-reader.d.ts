declare module 'xlsx-stream-reader' {
  import type { Writable } from 'node:stream';

  interface WorksheetEvents {
    row: (row: { values?: unknown[] }) => void;
    end: () => void;
  }

  interface Worksheet {
    id: number;
    name: string;
    on<E extends keyof WorksheetEvents>(event: E, handler: WorksheetEvents[E]): void;
    on(event: string, handler: (...args: unknown[]) => void): void;
    process: () => void;
  }

  interface ReaderEvents {
    worksheet: (ws: Worksheet) => void;
    error: (err: Error) => void;
    end: () => void;
  }

  class XlsxStreamReader extends Writable {
    on<E extends keyof ReaderEvents>(event: E, handler: ReaderEvents[E]): this;
    on(event: string, handler: (...args: unknown[]) => void): this;
    pipe(...args: unknown[]): unknown;
  }

  export default XlsxStreamReader;
}

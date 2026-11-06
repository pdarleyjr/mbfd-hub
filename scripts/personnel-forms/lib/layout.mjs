// Text placement for official-form overlays. Pure functions: no file or PDF writes here.

const WIN_ANSI_EXTRA = new Set('€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ');
const REPLACEMENTS = { '‐': '-', '‑': '-', '‒': '-', '−': '-', ' ': ' ', ' ': ' ', ' ': ' ', '…': '…' };

/** Standard PDF Helvetica can print WinAnsi only; anything else becomes its closest plain character. */
export function toWinAnsi(value) {
  let result = '';
  for (const raw of String(value ?? '').replace(/\r\n?/g, '\n').replace(/\t/g, '    ')) {
    const character = REPLACEMENTS[raw] ?? raw;
    const code = character.codePointAt(0);
    if (character === '\n' || (code >= 0x20 && code <= 0x7e) || (code >= 0xa0 && code <= 0xff) || WIN_ANSI_EXTRA.has(character)) {
      result += character;
      continue;
    }
    if (code < 0x20) continue;
    const plain = character.normalize('NFKD').replace(/[̀-ͯ]/g, '');
    result += /^[\x20-\x7e]+$/.test(plain) ? plain : '?';
  }
  return result;
}

function widthOf(font, text, size) {
  return font.widthOfTextAtSize(text, size);
}

/** Breaks text into lines no wider than the widths given (one width per available line, the last repeats). */
export function wrap(font, text, size, widths) {
  return wrapDetailed(font, text, size, widths).map((line) => line.text);
}

/** Like wrap, but each line also says whether it ends a paragraph, so leftover text can be rebuilt exactly. */
export function wrapDetailed(font, text, size, widths) {
  const lines = [];
  const paragraphs = toWinAnsi(text).split('\n');
  for (const paragraph of paragraphs) {
    const words = paragraph.split(/ +/).filter((word) => word !== '');
    if (words.length === 0) {
      lines.push({ text: '', paraEnd: true });
      continue;
    }
    let current = '';
    for (let word of words) {
      const limit = widths[Math.min(lines.length, widths.length - 1)];
      const candidate = current === '' ? word : `${current} ${word}`;
      if (widthOf(font, candidate, size) <= limit) {
        current = candidate;
        continue;
      }
      if (current !== '') {
        lines.push({ text: current, paraEnd: false });
        current = '';
      }
      // A single word wider than the line is split so nothing is ever drawn outside its space.
      while (widthOf(font, word, size) > widths[Math.min(lines.length, widths.length - 1)]) {
        const lineLimit = widths[Math.min(lines.length, widths.length - 1)];
        let cut = word.length - 1;
        while (cut > 1 && widthOf(font, word.slice(0, cut), size) > lineLimit) cut -= 1;
        lines.push({ text: word.slice(0, cut), paraEnd: false, split: true });
        word = word.slice(cut);
      }
      current = word;
    }
    lines.push({ text: current, paraEnd: true });
  }
  while (lines.length > 1 && lines[lines.length - 1].text === '') lines.pop();
  return lines;
}

function rebuild(lines) {
  let text = '';
  for (const line of lines) {
    text += line.text;
    text += line.paraEnd ? '\n' : (line.split ? '' : ' ');
  }
  return text.trim();
}

function slots(field, size) {
  if (field.type === 'text') return [{ x: field.x, baseline: field.baseline, width: field.width }];
  if (field.type === 'lines') return field.lines;
  if (field.type === 'box') {
    const leading = field.leading ?? size * 1.3;
    const count = Math.max(1, Math.floor((field.height - size * 0.25) / leading));
    return Array.from({ length: count }, (_, index) => ({ x: field.x, baseline: field.top + size + index * leading, width: field.width }));
  }
  throw new Error(`Unsupported layout field type ${field.type}.`);
}

const CONTINUED = ' (continued on attached page)';

/**
 * Places text in a field. Tries the field's size, then smaller sizes down to the minimum. When the text still
 * does not fit, the visible part ends with a continuation note and `overflow` is true so the caller prints the
 * full text on a continuation page.
 */
export function layoutField(field, text, font, minimumSize) {
  if (field.type === 'paragraph') {
    return { lines: wrap(font, text, field.size, [field.width]).map((line) => ({ text: line, size: field.size })), overflow: false };
  }
  const preferred = field.size ?? 10;
  const smallest = Math.min(preferred, field.min_size ?? minimumSize);
  for (let size = preferred; size >= smallest - 0.001; size -= 0.5) {
    const available = slots(field, size);
    const lines = wrap(font, text, size, available.map((slot) => slot.width));
    if (lines.length <= available.length) {
      return { lines: lines.map((line, index) => ({ text: line, size, ...available[index] })), overflow: false };
    }
  }
  const size = preferred;
  const available = slots(field, size);
  const all = wrapDetailed(font, text, size, available.map((slot) => slot.width));
  const last = available.length - 1;
  const visible = all.slice(0, available.length).map((line) => line.text);
  const limit = available[last].width - widthOf(font, CONTINUED, size);
  const full = all[last].text;
  let ending = full;
  while (ending !== '' && widthOf(font, ending, size) > limit) {
    const space = ending.lastIndexOf(' ');
    ending = space > 0 ? ending.slice(0, space) : ending.slice(0, -1);
  }
  visible[last] = `${ending.trimEnd()}${CONTINUED}`;
  // Only what was not printed goes onto the continuation page, so nothing is shown twice.
  const remainder = rebuild([{ text: full.slice(ending.length).trimStart(), paraEnd: all[last].paraEnd }, ...all.slice(available.length)]);
  return { lines: visible.map((line, index) => ({ text: line, size, ...available[index] })), overflow: true, remainder };
}

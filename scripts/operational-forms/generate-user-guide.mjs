import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const output = resolve(root, 'public/documents/MBFD_Operational_Forms_User_Guide.pdf');
const pdf = await PDFDocument.create();
const page = pdf.addPage([612, 792]);
const regular = await pdf.embedFont(StandardFonts.Helvetica);
const bold = await pdf.embedFont(StandardFonts.HelveticaBold);
const navy = rgb(20 / 255, 35 / 255, 58 / 255);
const red = rgb(180 / 255, 35 / 255, 24 / 255);
const blue = rgb(15 / 255, 108 / 255, 189 / 255);
const slate = rgb(82 / 255, 96 / 255, 109 / 255);
const pale = rgb(244 / 255, 246 / 255, 248 / 255);
const green = rgb(22 / 255, 101 / 255, 52 / 255);
const margin = 48;
const width = 612 - margin * 2;

function wrap(text, font, size, maxWidth) {
  const words = text.split(/\s+/);
  const lines = [];
  let line = '';
  for (const word of words) {
    const next = line ? `${line} ${word}` : word;
    if (font.widthOfTextAtSize(next, size) <= maxWidth) {
      line = next;
    } else {
      if (line) lines.push(line);
      line = word;
    }
  }
  if (line) lines.push(line);
  return lines;
}

function text(value, x, y, options = {}) {
  page.drawText(value, {
    x,
    y,
    font: options.font ?? regular,
    size: options.size ?? 10,
    color: options.color ?? navy,
  });
}

function paragraph(value, x, y, maxWidth, options = {}) {
  const size = options.size ?? 9.5;
  const leading = options.leading ?? 13;
  for (const line of wrap(value, options.font ?? regular, size, maxWidth)) {
    text(line, x, y, { ...options, size });
    y -= leading;
  }
  return y;
}

page.drawRectangle({ x: 0, y: 720, width: 612, height: 72, color: navy });
page.drawRectangle({ x: 0, y: 714, width: 612, height: 6, color: red });
text('MBFD OPERATIONAL FORMS', margin, 757, { font: bold, size: 10, color: rgb(1, 1, 1) });
text('Quick User Guide', margin, 733, { font: bold, size: 23, color: rgb(1, 1, 1) });
text('F-ROC Daily Activity Report', 388, 739, { font: bold, size: 9, color: rgb(.84, .89, .95) });

let y = 687;
const steps = [
  ['Go to the MBFD Hub', 'Open www.mbfdhub.com. Scroll to the bottom of the homepage and select the Operational Forms card.'],
  ['Sign in', 'Enter your Employee ID and password. The Forms library opens under your employee account.'],
  ['Start the F-ROC', 'Under Start a form, find F-ROC Daily Activity Report and select Create form.'],
  ['Complete each section', 'Enter General information and Team members. Add Labor, Equipment, Mileage, Materials, and Certification details that apply. Leave sections blank when they do not apply.'],
  ['Use AI-assisted import (optional)', 'In General information, open Optional: Import activity notes with AI. Enter the unit designation, then paste notes or upload a WhatsApp .txt/.zip export. Select Analyze and add to form. Review every added or estimated value and correct anything needed.'],
  ['Save and review', 'The form autosaves. You can also select Save now. Move through every section and confirm names, dates, 24-hour times, mileage, totals, and certification.'],
  ['Complete the form', 'Select Generate PDF. Correct any highlighted missing information, then generate again. When ready, use View / print or Download. A generated PDF marks the record Completed; later edits return it to Draft until a new PDF is generated.'],
];

for (let index = 0; index < steps.length; index += 1) {
  const [heading, body] = steps[index];
  page.drawCircle({ x: margin + 11, y: y + 1, size: 11, color: index === 6 ? green : blue });
  const number = String(index + 1);
  text(number, margin + 11 - bold.widthOfTextAtSize(number, 10) / 2, y - 2.5, { font: bold, size: 10, color: rgb(1, 1, 1) });
  text(heading, margin + 31, y + 2, { font: bold, size: 11, color: navy });
  y = paragraph(body, margin + 31, y - 13, width - 31, { size: 9.3, leading: 12.5, color: slate });
  y -= 11;
}

page.drawRectangle({ x: margin, y: 74, width, height: 55, color: pale, borderColor: rgb(.72, .77, .82), borderWidth: .7 });
text('Important', margin + 14, 108, { font: bold, size: 10, color: red });
paragraph('AI suggestions are editable assistance—not final approval. Verify every value before generating the PDF. Never paste your password into the activity-notes box.', margin + 14, 93, width - 28, { size: 9.2, leading: 12, color: navy });

page.drawLine({ start: { x: margin, y: 48 }, end: { x: 612 - margin, y: 48 }, thickness: .6, color: rgb(.72, .77, .82) });
text('Miami Beach Fire Department · MBFD Support Hub', margin, 31, { font: bold, size: 8.5, color: navy });
text('www.mbfdhub.com', 471, 31, { font: bold, size: 8.5, color: blue });

pdf.setTitle('MBFD Operational Forms Quick User Guide');
pdf.setAuthor('Miami Beach Fire Department');
pdf.setSubject('How to complete an F-ROC Daily Activity Report in MBFD Hub');
pdf.setCreator('MBFD Support Hub');
pdf.setProducer('pdf-lib');
pdf.setCreationDate(new Date('2026-07-19T12:00:00-04:00'));
pdf.setModificationDate(new Date('2026-07-19T12:00:00-04:00'));

await mkdir(dirname(output), { recursive: true });
await writeFile(output, await pdf.save({ useObjectStreams: false }));
console.log(output);

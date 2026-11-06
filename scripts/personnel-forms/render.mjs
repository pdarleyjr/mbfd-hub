#!/usr/bin/env node
// Draws validated values onto an official PDF form at measured positions.
//
// The City forms are kept byte-for-byte as their templates (only their unusable AcroForm widgets were
// removed), so the finished document keeps the original layout, headings, notices and page size. Text that
// does not fit its printed lines is never clipped: the form shows the first part and the full text is
// printed on clearly labelled continuation pages added after the original pages.

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { PDFArray, PDFDocument, PDFName, StandardFonts, rgb } from 'pdf-lib';
import { sha256 } from '../operational-forms/lib/checksums.mjs';
import { layoutField, toWinAnsi } from './lib/layout.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const FORMS = {
  vehicle_accident_report: 'resources/forms/vehicle-accident-report/2000-02-08',
  supervisors_report_of_accident: 'resources/forms/supervisors-report-of-accident/1.0',
  interoffice_memorandum: 'resources/forms/interoffice-memorandum/1.0',
};

function argument(name) {
  const index = process.argv.indexOf(name);
  if (index === -1 || !process.argv[index + 1]) throw new Error(`Missing required argument ${name}.`);
  return process.argv[index + 1];
}

const formKey = argument('--form');
const inputPath = argument('--input');
const outputPath = argument('--output');
if (!Object.hasOwn(FORMS, formKey)) throw new Error('Unknown personnel form.');
const directory = path.join(root, FORMS[formKey]);

const templateBytes = fs.readFileSync(path.join(directory, 'template.pdf'));
const layoutBytes = fs.readFileSync(path.join(directory, 'layout.json'));
const manifest = JSON.parse(fs.readFileSync(path.join(directory, 'manifest.json'), 'utf8'));
if (sha256(templateBytes) !== manifest.template_sha256) throw new Error('Controlled template checksum mismatch.');
if (sha256(layoutBytes) !== manifest.layout_sha256) throw new Error('Controlled layout checksum mismatch.');
const layout = JSON.parse(layoutBytes.toString('utf8'));
const input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
const values = input.values ?? {};
const images = input.images ?? {};
const meta = input.meta ?? {};

const pdf = await PDFDocument.load(templateBytes, { updateMetadata: false });
if (pdf.catalog.has(PDFName.of('AcroForm'))) throw new Error('Controlled template must not contain form fields.');
const font = await pdf.embedFont(StandardFonts.Helvetica);
const bold = await pdf.embedFont(StandardFonts.HelveticaBold);
const ink = rgb(0.05, 0.1, 0.35);
const overflow = [];
// Lifts entries a little above the printed rule so descenders do not sit on the line.
const TEXT_RAISE = 1.2;

for (const field of layout.fields) {
  const page = pdf.getPage(field.page - 1);
  const height = page.getHeight();
  if (field.type === 'image') {
    const encoded = images[field.key];
    if (!encoded) continue;
    const image = await pdf.embedPng(Buffer.from(encoded, 'base64'));
    const scale = Math.min(field.width / image.width, field.height / image.height);
    const width = image.width * scale;
    const imageHeight = image.height * scale;
    page.drawImage(image, { x: field.x, y: height - field.bottom, width, height: imageHeight });
    continue;
  }
  if (field.type === 'mark') {
    if (Array.isArray(field.box)) {
      // The official checkbox outline (originally drawn by the form's widget), printed whether or not it is ticked.
      const [x0, y0, x1, y1] = field.box;
      page.drawRectangle({ x: x0, y: height - y1, width: x1 - x0, height: y1 - y0, borderColor: rgb(0, 0, 0), borderWidth: 0.75 });
    }
    if (values[field.key] === true) {
      page.drawText('X', { x: field.x, y: height - field.baseline, size: field.size ?? 11, font: bold, color: ink });
    }
    continue;
  }
  const text = values[field.key];
  if (text === undefined || text === null || String(text).trim() === '') continue;
  const placed = layoutField(field, String(text), font, layout.min_size ?? 6.5);
  for (const line of placed.lines) {
    page.drawText(line.text, { x: line.x, y: height - line.baseline + TEXT_RAISE, size: line.size, font, color: ink });
  }
  if (placed.overflow) overflow.push({ label: field.label ?? field.key, text: placed.remainder });
}

if (overflow.length > 0) addContinuationPages(overflow);

for (const page of pdf.getPages()) {
  const annotations = page.node.lookupMaybe(PDFName.of('Annots'), PDFArray);
  if (annotations?.size() === 0) page.node.delete(PDFName.of('Annots'));
}
pdf.setTitle(toWinAnsi(meta.title ?? manifest.display_name));
pdf.setSubject('Official form completed in the MBFD Hub from validated structured data');
pdf.setProducer(`MBFD Personnel Forms overlay renderer ${manifest.renderer_contract_version}`);
pdf.setCreator('MBFD Hub');

const output = await pdf.save({ useObjectStreams: false, addDefaultPage: false });
const check = await PDFDocument.load(output, { updateMetadata: false });
const pages = check.getPages();
if (pages.length < manifest.expected_page_count) throw new Error('Generated PDF lost an original page.');
manifest.expected_page_sizes.forEach((expected, index) => {
  const { width, height } = pages[index].getSize();
  if (Math.abs(width - expected[0]) > 0.01 || Math.abs(height - expected[1]) > 0.01) {
    throw new Error(`Generated PDF page ${index + 1} dimensions are invalid.`);
  }
});
if (check.catalog.has(PDFName.of('AcroForm'))) throw new Error('Generated PDF contains form fields.');
fs.writeFileSync(outputPath, output);
process.stdout.write(JSON.stringify({
  bytes: output.length,
  pdf_sha256: sha256(output),
  page_count: pages.length,
  continuation_pages: pages.length - manifest.expected_page_count,
  overflow_fields: overflow.map((item) => item.label),
}));

function addContinuationPages(items) {
  const size = 10;
  const leading = 13.5;
  const margin = 54;
  const width = 612 - margin * 2;
  let page = null;
  let y = 0;
  let number = 0;
  const newPage = () => {
    number += 1;
    page = pdf.addPage([612, 792]);
    y = 792 - margin;
    page.drawText(toWinAnsi(`${manifest.display_name} - Continuation page ${number}`), { x: margin, y, size: 12, font: bold });
    y -= 16;
    const reference = [meta.reference ? `Reference ${meta.reference}` : null, meta.subject ? `Re: ${meta.subject}` : null].filter(Boolean).join('   ');
    if (reference) {
      page.drawText(toWinAnsi(reference), { x: margin, y, size: 9, font });
      y -= 12;
    }
    page.drawText('This page continues entries that did not fit on the original form. It is part of this document.', { x: margin, y, size: 9, font });
    y -= 22;
  };
  newPage();
  for (const item of items) {
    if (y < margin + leading * 3) newPage();
    page.drawText(toWinAnsi(`${item.label} (continued)`), { x: margin, y, size: size + 0.5, font: bold });
    y -= leading;
    const lines = layoutField({ type: 'paragraph', width, size }, item.text, font, size).lines;
    for (const line of lines) {
      if (y < margin) newPage();
      page.drawText(line.text, { x: margin, y, size, font, color: ink });
      y -= leading;
    }
    y -= leading / 2;
  }
}

#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { PDFDocument, PDFArray, PDFName, StandardFonts, rgb } from 'pdf-lib';
import { sha256 } from './lib/checksums.mjs';
import { fillTextField } from './lib/field-fitting.mjs';
import { resolveForm } from './registry.mjs';
import { validateOutput } from './validate-output.mjs';

function argument(name) {
  const index = process.argv.indexOf(name);
  if (index === -1 || !process.argv[index + 1]) throw new Error(`Missing required argument ${name}.`);
  return process.argv[index + 1];
}

const type = argument('--form');
const version = argument('--version');
const inputPath = argument('--input');
const outputPath = argument('--output');
const registered = resolveForm(type, version);
const templatePath = path.join(registered.directory, 'template.pdf');
const mappingPath = path.join(registered.directory, 'mapping.json');
const manifestPath = path.join(registered.directory, 'manifest.json');

const templateBytes = fs.readFileSync(templatePath);
const mappingBytes = fs.readFileSync(mappingPath);
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const mapping = JSON.parse(mappingBytes.toString('utf8'));
const input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));

if (sha256(templateBytes) !== manifest.template_sha256) throw new Error('Controlled template checksum mismatch.');
if (sha256(mappingBytes) !== manifest.mapping_sha256) throw new Error('Controlled mapping checksum mismatch.');
if (mapping.fields.length !== manifest.expected_field_count) throw new Error('Controlled mapping field count mismatch.');

const pdfDocument = await PDFDocument.load(templateBytes, { updateMetadata: false });
const form = pdfDocument.getForm();
const actualNames = form.getFields().map((field) => field.getName());
if (actualNames.length !== manifest.expected_field_count) throw new Error('Controlled template field count mismatch.');
const actualSet = new Set(actualNames);
for (const specification of mapping.fields) {
  if (!actualSet.has(specification.name)) throw new Error(`Controlled template field missing: ${specification.name}`);
}

const font = await pdfDocument.embedFont(StandardFonts.Helvetica);
const { values, calculatedTotals } = registered.values(input.data, mapping);
for (const specification of mapping.fields) {
  const raw = values[specification.name];
  const value = specification.input_type === 'boolean_mark' ? (raw ? 'X' : '') : raw;
  const field = fillTextField(form, font, specification, value);

  const insetLeft = Number(specification.appearance_inset_left ?? 0);
  if (insetLeft > 0 && String(value ?? '') !== '') {
    for (const widget of field.acroField.getWidgets()) {
      const rectangle = widget.getRectangle();
      widget.setRectangle({
        x: rectangle.x + insetLeft,
        y: rectangle.y,
        width: Math.max(1, rectangle.width - insetLeft),
        height: rectangle.height,
      });
    }
  }

  if (specification.rendering?.requires_background_knockout_when_value_changes && String(value ?? '') !== '') {
    const rectangle = specification.rect_pdf;
    pdfDocument.getPage(specification.page - 1).drawRectangle({
      x: rectangle[0] + 0.75,
      y: rectangle[1] + 0.75,
      width: Math.max(1, rectangle[2] - rectangle[0] - 1.5),
      height: Math.max(1, rectangle[3] - rectangle[1] - 1.5),
      color: rgb(1, 1, 1),
    });
    for (const widget of field.acroField.getWidgets()) {
      widget.getOrCreateAppearanceCharacteristics().setBackgroundColor([1, 1, 1]);
    }
  }
}

form.updateFieldAppearances(font);
form.flatten();
for (const page of pdfDocument.getPages()) {
  const annotations = page.node.lookupMaybe(PDFName.of('Annots'), PDFArray);
  if (annotations?.size() === 0) page.node.delete(PDFName.of('Annots'));
}
pdfDocument.catalog.delete(PDFName.of('AcroForm'));

pdfDocument.setTitle(manifest.display_name);
pdfDocument.setSubject('Controlled operational form generated from validated structured data');
pdfDocument.setProducer('MBFD Operational Forms pdf-lib generator 1.0.0');
pdfDocument.setCreator('MBFD Support Hub');

const outputBytes = await pdfDocument.save({
  useObjectStreams: false,
  addDefaultPage: false,
  updateFieldAppearances: false,
});
const validation = await validateOutput(outputBytes, manifest);
fs.writeFileSync(outputPath, outputBytes);

process.stdout.write(JSON.stringify({
  bytes: outputBytes.length,
  pdf_sha256: sha256(outputBytes),
  page_count: validation.pageCount,
  page_sizes: validation.pageSizes,
  remaining_form_fields: validation.remainingFormFields,
  remaining_annotations: validation.remainingAnnotations,
  calculated_totals: calculatedTotals,
}));

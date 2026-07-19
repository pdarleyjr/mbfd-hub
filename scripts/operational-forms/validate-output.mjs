import { PDFArray, PDFDocument, PDFName } from 'pdf-lib';

export async function validateOutput(bytes, manifest) {
  if (Buffer.from(bytes).subarray(0, 5).toString('ascii') !== '%PDF-') throw new Error('Generated output is not a PDF.');
  const document = await PDFDocument.load(bytes, { updateMetadata: false });
  const pages = document.getPages();
  if (pages.length !== manifest.expected_page_count) throw new Error('Generated PDF page count is invalid.');

  const pageSizes = pages.map((page, index) => {
    const { width, height } = page.getSize();
    const expected = manifest.expected_page_sizes[index];
    if (Math.abs(width - expected[0]) > 0.01 || Math.abs(height - expected[1]) > 0.01) {
      throw new Error(`Generated PDF page ${index + 1} dimensions are invalid.`);
    }
    return [width, height];
  });

  const hasAcroForm = document.catalog.has(PDFName.of('AcroForm'));
  const remainingFormFields = hasAcroForm ? document.getForm().getFields().length : 0;
  if (hasAcroForm) throw new Error('Generated PDF still contains an AcroForm dictionary.');

  const remainingAnnotations = pages.reduce((count, page) => {
    const annotations = page.node.lookupMaybe(PDFName.of('Annots'), PDFArray);
    return count + (annotations?.size() ?? 0);
  }, 0);
  if (remainingAnnotations !== 0) throw new Error('Generated PDF still contains widget annotations.');

  return { pageCount: pages.length, pageSizes, remainingFormFields, remainingAnnotations };
}

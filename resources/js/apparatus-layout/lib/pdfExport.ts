import type Konva from 'konva';

/**
 * Export the Konva stage as a landscape 11×17 PDF.
 * Composes a high-res canvas export (pixelRatio 2-3) onto a printable PDF page.
 */
export async function exportLayoutPdf(
  stage: Konva.Stage,
  apparatusName: string,
  sideName: string,
): Promise<void> {
  // PDF generation is an infrequent operator action; keep pdf-lib out of the
  // initial apparatus-layout bundle and load it only when Export is pressed.
  const { PDFDocument, rgb } = await import('pdf-lib');

  // 11×17 landscape in points (1 inch = 72 points)
  const PAGE_WIDTH = 17 * 72;  // 1224
  const PAGE_HEIGHT = 11 * 72; // 792

  // Export stage as high-res PNG
  const dataUrl = stage.toDataURL({
    pixelRatio: 2,
    mimeType: 'image/png',
  });

  // Convert data URL to Uint8Array
  const response = await fetch(dataUrl);
  const imageBytes = new Uint8Array(await response.arrayBuffer());

  // Create PDF
  const pdfDoc = await PDFDocument.create();
  const page = pdfDoc.addPage([PAGE_WIDTH, PAGE_HEIGHT]);

  // Embed the PNG
  const pngImage = await pdfDoc.embedPng(imageBytes);
  const pngDims = pngImage.scale(1);

  // Scale to fit page with margins
  const margin = 36; // 0.5 inch
  const availWidth = PAGE_WIDTH - margin * 2;
  const availHeight = PAGE_HEIGHT - margin * 2 - 40; // extra for title

  const scale = Math.min(availWidth / pngDims.width, availHeight / pngDims.height);
  const scaledWidth = pngDims.width * scale;
  const scaledHeight = pngDims.height * scale;

  // Center on page
  const xOffset = margin + (availWidth - scaledWidth) / 2;
  const yOffset = margin + (availHeight - scaledHeight) / 2;

  page.drawImage(pngImage, {
    x: xOffset,
    y: yOffset,
    width: scaledWidth,
    height: scaledHeight,
  });

  // Draw title
  page.drawText(`${apparatusName} — ${sideName} View`, {
    x: margin,
    y: PAGE_HEIGHT - margin,
    size: 16,
    color: rgb(0.1, 0.1, 0.15),
  });

  // Draw timestamp
  page.drawText(`Generated: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}`, {
    x: margin,
    y: margin - 10,
    size: 8,
    color: rgb(0.5, 0.5, 0.5),
  });

  // Save and download
  const pdfBytes = await pdfDoc.save();
  const blob = new Blob([pdfBytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = url;
  link.download = `${apparatusName.replace(/[^a-zA-Z0-9]/g, '_')}_${sideName}_layout.pdf`;
  link.click();

  URL.revokeObjectURL(url);
}

import { TextAlignment } from 'pdf-lib';

const alignments = {
  left: TextAlignment.Left,
  center: TextAlignment.Center,
  right: TextAlignment.Right,
};

export function fillTextField(form, font, spec, input) {
  const field = form.getTextField(spec.name);
  const value = input === undefined || input === null ? '' : String(input).replace(/\r\n?/g, '\n').trim();
  const mappedMax = Number(spec.max_length ?? spec.validation?.max_length ?? spec.font?.maximum_characters ?? 0);
  if (mappedMax > 0 && value.length > mappedMax) {
    throw new Error(`Text exceeds the controlled capacity for ${spec.name}.`);
  }

  const multiline = Boolean(spec.multiline ?? spec.font?.multiline);
  const alignment = spec.alignment ?? spec.font?.alignment ?? 'left';
  const preferred = Number(spec.font_size ?? spec.font?.font_size_pt ?? 9);
  const minimum = Number(spec.minimum_font_size ?? spec.font?.minimum_font_size_pt ?? Math.min(preferred, 5.5));
  const rectangle = spec.rect_pdf ?? spec.rect_pdf_points_bottom_left;

  if (multiline) field.enableMultiline();
  field.setAlignment(alignments[alignment] ?? TextAlignment.Left);

  let size = preferred;
  if (!multiline && value && rectangle) {
    const available = Math.max(1, rectangle[2] - rectangle[0] - 4);
    const measured = font.widthOfTextAtSize(value, size);
    if (measured > available) size = Math.floor((size * available / measured) * 10) / 10;
    if (size < minimum || font.widthOfTextAtSize(value, Math.max(size, minimum)) > available) {
      throw new Error(`Text does not fit ${spec.name} at its approved minimum font size.`);
    }
  }

  field.setFontSize(Math.max(size, minimum));
  field.setText(value);
  return field;
}

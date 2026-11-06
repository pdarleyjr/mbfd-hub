# Removes the unusable AcroForm widgets from the supplied official PDFs and saves classic-xref templates.
# Usage: python -I clean_templates.py <dir with the original PDFs> <output dir>
# Run with the explicit project interpreter (see docs/personnel-forms-accidents-documents.md); never by file association.
import sys, fitz
src, out = sys.argv[1], sys.argv[2]
for n in ["vehicle_accident_report","supervisors_report_of_accident","memo_form"]:
    d=fitz.open(f"{src}/{n}.pdf")
    for p in d:
        for w in list(p.widgets()): p.delete_widget(w)
    cat = d.pdf_catalog()
    if d.xref_get_key(cat,"AcroForm")[0] != "null": d.xref_set_key(cat,"AcroForm","null")
    d.save(f"{out}/{n}.pdf", garbage=4, deflate=True, use_objstms=0, no_new_id=True)
    c=fitz.open(f"{out}/{n}.pdf")
    raw=open(f"{out}/{n}.pdf","rb").read()
    print(n, c.page_count, "widgets", sum(len(list(p.widgets())) for p in c), "xref-stream" if b"/XRef" in raw else "classic-xref", "objstm" if b"/ObjStm" in raw else "no-objstm", len(raw))


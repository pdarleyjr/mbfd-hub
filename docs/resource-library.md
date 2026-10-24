# Resource library

Employee > Resources is a searchable list of department manuals and guidelines. Members open a document in the browser or download the original file. Administrators curate it in Admin > Communications > Resource Library.

## What members see

- Left navigation, "My work" group, directly under Forms: **Resources** (`/employee/resources`). Any account that can open the Employee panel can use it.
- A search box and, when more than one category exists, a category list. Search matches the title, category, description and the text inside the document. Every word typed must appear somewhere in the same document. Capital letters do not matter.
- One card per document: title, page count, file type and size, the description, and two actions.
  - **Open** shows the PDF in a new browser tab. For a PDF original it is the original itself. For a Word, Excel or PowerPoint original it is the PDF preview that was stored with it. A document with no preview shows only Download.
  - **Download PDF / DOCX / ...** saves the original file in its original format with its original file name.
- Unpublished and deleted documents are not listed and their links return 404.

Files are never served from a public or static URL. `GET /employee/resources/{uuid}/preview` and `.../download` are authenticated routes (the same gate as the rest of the Employee panel) that stream from private storage with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`. Opening a document is not logged; imports, replacements and visibility changes are.

## Where files live

| What | Where |
|---|---|
| Original | private disk (`config('filesystems.private')`), `resource-library/{uuid}/original.{ext}` |
| PDF preview (only when the original is not a PDF) | `resource-library/{uuid}/preview.pdf` |
| Metadata, extracted search text, page count, checksums | table `resource_documents` |
| History (imported, replaced, published, unpublished; append-only) | table `resource_document_events` |
| Catalogue of launch documents (metadata and checksums, no file bytes) | `database/data/resources/resource-library.json` |

There is exactly one stored copy of each file. The document files themselves are not in git.

## Add a document

1. Put the original file (and, for a non-PDF original, its PDF export) in a folder on the server, for example `/srv/resource-drop`. A PDF export of a Word file must be made from the same version of the Word file.
2. Add an entry to `database/data/resources/resource-library.json`:

   ```json
   {
     "slug": "uniform-guidelines",
     "title": "Uniform Guidelines",
     "category": "Policies & Guidelines",
     "description": "One factual line taken from the document's own title page.",
     "sort": 10,
     "published": true,
     "page_count": 35,
     "original": { "file": "2026 Uniform Guideline FINAL.docx", "sha256": "<64 hex characters>" },
     "preview": { "file": "2026 Uniform Guideline FINAL.pdf", "sha256": "<64 hex characters>" }
   }
   ```

   `preview` is only for non-PDF originals and is `null` or omitted otherwise. `page_count` is optional; when present, the import fails if the PDF has a different number of pages. Compute checksums with `Get-FileHash -Algorithm SHA256 <file>` (PowerShell) or `sha256sum <file>`. Slugs are lowercase words joined by hyphens and never change once published.
3. Check without writing anything:

   ```
   php artisan resources:import /srv/resource-drop --dry-run
   ```

4. Import:

   ```
   php artisan resources:import /srv/resource-drop
   ```

   Use `--manifest=/path/to/other.json` for a different manifest.

The command verifies that every file exists and matches its checksum before it touches anything. One missing file or one mismatch stops the whole run with nothing imported. It copies each file into private storage once, reads the page count and the searchable text from the PDF (or from the preview PDF), and records an `imported` event. Running it again with the same files changes nothing. It never deletes a document. If the text of a PDF cannot be read (for example a scanned image), the document is still imported and the command says it will only be found by title, category and description.

Accepted originals: PDF, DOCX, XLSX, PPTX, DOC, XLS, PPT. A file whose first bytes do not match its extension is refused.

## Replace a document

- **Same link, new file (command):** change the file in the folder, update that entry's `sha256` (and `preview` when it applies), and run `resources:import` again. The slug is unchanged, so the document keeps its link, title and category; the new bytes are written next to the old ones, checked, and swapped in together with a `replaced` event that records the previous checksum. Old files are removed only after the swap succeeds.
- **Same link, new file (Admin):** Admin > Resource Library > open the document > "Replace file". Upload the new original, and a preview PDF when the original is not a PDF. This uses the same code as the command. Uploads in Admin are limited to 50 MB; use the command for bigger files.
- Replacing a Word file without supplying a new preview removes the old preview, because it would show the old version, and the document becomes download-only until a new preview is added.
- The same bytes cannot be stored as two documents.

## Admin

- Permissions: `admin.resources.view` (see the list) and `admin.resources.manage` (edit, replace). Both are direct permissions on top of `admin.access` and appear as "Resource library" in member access administration. The migration grants both to everyone who held `admin.access` when it ran; super administrators always have them.
- Editing title, category, description, sort order and "Visible to members" never touches the stored file. Hiding a document is the supported way to retire it; documents are not deleted from Admin.
- The edit page shows the current file name, type, size, SHA-256 and the last ten history entries.

## Operations notes

- Importing the five launch documents (about 40 MB) takes roughly a minute on a Windows workstation because every file is hashed, copied and read back. Re-running is instant.
- Back up `resource-library/` together with the database. The manifest can rebuild the table, not the files.

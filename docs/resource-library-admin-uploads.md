# Resource Library: adding and managing documents in Admin

Admin > Communications > Resource Library publishes documents to every member's Employee > Resources page. This is the same library the `resources:import` command fills; there is one library, one writer (`ResourceLibraryService::store()`), and one private storage area.

## Who can do what

| Action | Needs |
| --- | --- |
| See the list | `admin.resources.view` (or `admin.resources.manage`), plus `admin.access` |
| Add, edit, replace, hide or show, reorder, open the edit page, download an earlier version | `admin.resources.manage`, plus `admin.access` (super administrators can do everything) |

Every action is checked on the server. A hidden button is not the protection: a crafted request from someone with view-only access is refused.

## Add a document

1. Open Admin > Resource Library and choose **Add document**.
2. Pick the file. PDF, Word (.docx, .doc), Excel (.xlsx, .xls) or PowerPoint (.pptx, .ppt), up to 50 MB. The title is filled in from the file name; change it if you like.
3. Optional: for a Word, Excel or PowerPoint file, add a **PDF copy for in-browser preview**. Export it from the same file. Without it, members can only download the file. A PDF original never takes a PDF copy.
4. Enter the title and category (pick an existing category or type a new one) and an optional description.
5. **Show to members now** is on by default. Turn it off to add the document hidden and show it later.

Members see a shown document on their next page load. Documents first shown in the last 14 days carry a small "New" badge.

The file is refused, and nothing is saved, if it is the wrong type, is not really that kind of file (Word, Excel and PowerPoint files must be genuine Office packages), is over 50 MB, or has exactly the same bytes as a document already in the library (including the earlier versions of another document).

## Replace a file

Open the document (Edit) and use **Replace file**. The title, link and position stay. The old file is not deleted: it becomes an entry under **Earlier versions**, and its bytes stay in private storage at their old path. New bytes always go to a new path (`resource-library/{id}/v2/...`, `v3/...`), so nothing is overwritten. Going back to an earlier file of the same document is allowed.

Managers can download an earlier version from **Earlier versions** (route `admin.resources.versions.download`). The download is private and never cached.

If you replace a Word, Excel or PowerPoint file without a new PDF copy, the old PDF copy is taken off the member page (it showed the old document) and members can only download the new file until you add a new copy.

## Hide or show

Use the **Hide from members** / **Show to members** row action. Nothing is ever deleted from Admin. Hiding removes the document from Employee > Resources and its preview and download links stop working for members right away.

## Reorder

Choose the reorder button above the list and drag rows. Members see documents grouped by category, then in this order, then by title. Do the dragging with no search or filter active, because the order numbers are assigned across the rows you can see.

## History

Everything is recorded with who did it, when, and the source: added, file replaced (with the previous file's name, size and checksum), shown, hidden, details changed (the previous and new title, category and description) and moved (the previous and new position). The edit page shows the latest entries.

## Limits worth knowing

- 50 MB per file, enforced by the form and by the service (the import command too).
- There is no automatic Word-to-PDF conversion. Export the PDF copy yourself.
- A document is never hard-deleted from Admin. Soft-deleted rows are untouched, and their slugs stay reserved.

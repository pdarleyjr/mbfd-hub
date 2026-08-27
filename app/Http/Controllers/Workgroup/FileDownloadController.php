<?php

namespace App\Http\Controllers\Workgroup;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupSharedUpload;
use App\Support\Workgroups\WorkgroupAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class FileDownloadController extends Controller
{
    public function __construct(private readonly WorkgroupAccess $workgroupAccess) {}

    /**
     * Download a workgroup file.
     */
    public function downloadFile(WorkgroupFile $file)
    {
        $this->workgroupAccess->requireFile($this->currentUser(), $file);

        // Try multiple storage disks
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($file->filepath)) {
                return Storage::disk($disk)->download($file->filepath, $file->filename);
            }
        }

        abort(404, 'File not found.');
    }

    /**
     * Download a shared upload.
     */
    public function downloadSharedUpload(WorkgroupSharedUpload $upload)
    {
        $this->workgroupAccess->requireUpload($this->currentUser(), $upload);

        // Stream from the private disk; fall back to the legacy public disk for
        // uploads created before this hardening (and not yet migrated).
        $privateDisk = config('filesystems.private', 'local');

        foreach ([$privateDisk, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($upload->filepath)) {
                return Storage::disk($disk)->download($upload->filepath, $upload->filename);
            }
        }

        abort(404, 'File not found.');
    }

    /**
     * Preview a workgroup file inline (for PDFs).
     */
    public function previewFile(WorkgroupFile $file)
    {
        $this->workgroupAccess->requireFile($this->currentUser(), $file);

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($file->filepath)) {
                $mimeType = Storage::disk($disk)->mimeType($file->filepath);

                return Storage::disk($disk)->response($file->filepath, $file->filename, [
                    'Content-Type' => $mimeType,
                    'Content-Disposition' => 'inline; filename="'.$file->filename.'"',
                ]);
            }
        }

        abort(404, 'File not found.');
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 404);

        return $user;
    }
}

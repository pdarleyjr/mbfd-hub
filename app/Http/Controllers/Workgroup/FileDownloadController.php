<?php

namespace App\Http\Controllers\Workgroup;

use App\Http\Controllers\Controller;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSharedUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class FileDownloadController extends Controller
{
    /**
     * Download a workgroup file.
     */
    public function downloadFile(WorkgroupFile $file)
    {
        $user = Auth::user();
        
        // Allow admins or workgroup members
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
        $member = $this->getCurrentMember();
        
        if (!$isAdmin && (!$member || $member->workgroup_id !== $file->workgroup_id)) {
            abort(403, 'You do not have access to this file.');
        }

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
        // Check if user has access to this upload
        $member = $this->getCurrentMember();
        
        if (!$member) {
            abort(403, 'You do not have access to this file.');
        }

        // Check if member belongs to the workgroup
        if ($member->workgroup_id !== $upload->workgroup_id) {
            abort(403, 'You do not have access to this file.');
        }

        // Check if file exists
        if (!Storage::disk('public')->exists($upload->filepath)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download(
            $upload->filepath,
            $upload->filename
        );
    }

    /**
     * Preview a workgroup file inline (for PDFs).
     */
    public function previewFile(WorkgroupFile $file)
    {
        $user = Auth::user();
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
        $member = $this->getCurrentMember();
        
        if (!$isAdmin && (!$member || $member->workgroup_id !== $file->workgroup_id)) {
            abort(403, 'You do not have access to this file.');
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($file->filepath)) {
                $mimeType = Storage::disk($disk)->mimeType($file->filepath);
                return Storage::disk($disk)->response($file->filepath, $file->filename, [
                    'Content-Type' => $mimeType,
                    'Content-Disposition' => 'inline; filename="' . $file->filename . '"',
                ]);
            }
        }

        abort(404, 'File not found.');
    }

    /**
     * Get the current workgroup member.
     */
    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();
        
        if (!$user) {
            return null;
        }

        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }
}

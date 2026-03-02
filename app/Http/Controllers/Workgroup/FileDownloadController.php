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
        // Check if user has access to this file
        $member = $this->getCurrentMember();
        
        if (!$member) {
            abort(403, 'You do not have access to this file.');
        }

        // Check if member belongs to the workgroup
        if ($member->workgroup_id !== $file->workgroup_id) {
            abort(403, 'You do not have access to this file.');
        }

        // Check if file exists
        if (!Storage::disk('public')->exists($file->filepath)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download(
            $file->filepath,
            $file->filename
        );
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

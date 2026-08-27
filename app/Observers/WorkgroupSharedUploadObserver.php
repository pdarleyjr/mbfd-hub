<?php

namespace App\Observers;

use App\Models\WorkgroupSharedUpload;
use App\Services\Workgroup\WorkgroupAIService;
use Illuminate\Support\Facades\Log;

/**
 * Observer for WorkgroupSharedUpload model.
 * Auto-vectorizes uploaded files into the workgroup-specs Vectorize index
 * so the AI can reference product specs, brochures, and datasheets
 * when generating evaluation analyses.
 */
class WorkgroupSharedUploadObserver
{
    public function created(WorkgroupSharedUpload $upload): void
    {
        $this->vectorizeIfApplicable($upload);
    }

    public function updated(WorkgroupSharedUpload $upload): void
    {
        // Re-vectorize if the file path changed (file was replaced)
        if ($upload->wasChanged('filepath')) {
            $this->vectorizeIfApplicable($upload);
        }
    }

    protected function vectorizeIfApplicable(WorkgroupSharedUpload $upload): void
    {
        // Only vectorize document file types (not images, videos, etc.)
        $vectorizableExtensions = ['pdf', 'docx', 'doc', 'txt', 'ppt', 'pptx', 'csv', 'md'];
        $extension = strtolower(pathinfo($upload->filename, PATHINFO_EXTENSION));

        if (! in_array($extension, $vectorizableExtensions)) {
            return;
        }

        // Dispatch async to avoid slowing down the file upload response
        dispatch(function () use ($upload) {
            try {
                $service = app(WorkgroupAIService::class);
                $result = $service->vectorizeUpload($upload);

                if ($result['success'] ?? false) {
                    Log::info('[WorkgroupAI] File vectorized successfully', [
                        'operation' => 'vectorize_upload',
                        'upload_id' => $upload->id,
                        'chunks' => $result['chunks'] ?? 0,
                        'vectorized' => $result['vectorized'] ?? 0,
                    ]);
                } else {
                    Log::warning('[WorkgroupAI] File vectorization failed', [
                        'operation' => 'vectorize_upload',
                        'upload_id' => $upload->id,
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::error('[WorkgroupAI] Vectorization observer exception', [
                    'operation' => 'vectorize_upload',
                    'upload_id' => $upload->id,
                    'exception_type' => $exception::class,
                ]);
            }
        })->afterResponse();
    }
}

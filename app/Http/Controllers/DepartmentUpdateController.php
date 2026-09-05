<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DepartmentUpdate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DepartmentUpdateController extends Controller
{
    public function index(): View
    {
        return view('updates.index', [
            'departmentUpdates' => DepartmentUpdate::query()
                ->publishedArchive()
                ->with('author:id,name')
                ->orderByDesc('publish_at')
                ->paginate(12),
        ]);
    }

    public function show(DepartmentUpdate $departmentUpdate): View
    {
        $this->assertPublishedHistory($departmentUpdate);

        return view('updates.show', ['departmentUpdate' => $departmentUpdate->load('author:id,name')]);
    }

    public function image(DepartmentUpdate $departmentUpdate): StreamedResponse
    {
        $this->assertPublishedHistory($departmentUpdate);

        return $this->stream($departmentUpdate, 'image_path', 'image_name', true);
    }

    public function attachment(DepartmentUpdate $departmentUpdate): StreamedResponse
    {
        $this->assertPublishedHistory($departmentUpdate);

        return $this->stream($departmentUpdate, 'attachment_path', 'attachment_name', false);
    }

    private function assertPublishedHistory(DepartmentUpdate $departmentUpdate): void
    {
        abort_unless($departmentUpdate->isPublishedHistory(), 404);
    }

    private function stream(
        DepartmentUpdate $departmentUpdate,
        string $pathAttribute,
        string $nameAttribute,
        bool $inline,
    ): StreamedResponse {
        $path = $departmentUpdate->getAttribute($pathAttribute);
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        $name = $departmentUpdate->getAttribute($nameAttribute);
        $downloadName = is_string($name) && $name !== '' ? $name : basename($path);
        $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';

        $response = response()->streamDownload(
            function () use ($path): void {
                $stream = Storage::disk('local')->readStream($path);
                abort_unless(is_resource($stream), 404);
                fpassthru($stream);
                fclose($stream);
            },
            $downloadName,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );

        if ($inline) {
            $contentDisposition = $response->headers->get('Content-Disposition');
            if (is_string($contentDisposition)) {
                $response->headers->set('Content-Disposition', str_replace('attachment;', 'inline;', $contentDisposition));
            }
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\ResourceDocumentResource\Support;

use App\Models\ResourceDocument;
use App\Models\ResourceDocumentEvent;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/** Read-only panels on the Admin edit page: the current file, earlier versions, and the change history. */
final class DocumentPanels
{
    private const EVENT_LABELS = [
        'imported' => 'Added',
        'replaced' => 'File replaced',
        'published' => 'Shown to members',
        'unpublished' => 'Hidden from members',
        'details_changed' => 'Details changed',
        'reordered' => 'Moved in the list',
    ];

    public static function currentFile(?ResourceDocument $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }
        $preview = match (true) {
            $record->isPdf() => 'The original PDF is what members open.',
            $record->hasPreview() => 'Members open a separate PDF copy.',
            default => 'Download only: add a PDF copy so members can open it in the browser.',
        };

        return new HtmlString(
            '<p><strong>'.e($record->original_filename).'</strong> (version '.$record->currentVersionNumber().')</p>'
            .'<p>'.e($record->fileTypeLabel().' · '.$record->readableSize().($record->page_count ? ' · '.$record->page_count.' pages' : '')).'</p>'
            .'<p>'.e($preview).'</p>'
            .'<p>SHA-256 <code>'.e($record->original_sha256).'</code></p>',
        );
    }

    /** Earlier files, newest first. The download link is offered only to people who may use it. */
    public static function versions(?ResourceDocument $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }
        $canDownload = Gate::allows('update', ResourceDocument::class);
        $rows = $record->versions()->with('replacedBy:id,name')->orderByDesc('version')->get()->map(function ($version) use ($record, $canDownload): string {
            $line = 'Version '.$version->version.' · '.$version->original_filename.' · '.$version->fileTypeLabel().' · '.$version->readableSize()
                .' · replaced '.$version->replaced_at->timezone('America/New_York')->format('M j, Y g:i A')
                .($version->replacedBy ? ' by '.$version->replacedBy->name : '');
            $link = $canDownload
                ? ' <a class="fi-link text-primary-600 underline" href="'.e(route('admin.resources.versions.download', ['document' => $record->uuid, 'version' => $version->version])).'">Download</a>'
                : '';

            return '<li>'.e($line).$link.'</li>';
        })->implode('');

        return new HtmlString($rows === ''
            ? '<p>No earlier versions. When you replace the file, the old one is kept here.</p>'
            : '<ul>'.$rows.'</ul>');
    }

    public static function history(?ResourceDocument $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }
        $rows = $record->events()->with('actor:id,name')->latest('id')->limit(15)->get()->map(function (ResourceDocumentEvent $event): string {
            $sha = (string) ($event->metadata['sha256'] ?? '');
            $line = (self::EVENT_LABELS[$event->event] ?? ucfirst(str_replace('_', ' ', $event->event)))
                .' · '.$event->created_at->timezone('America/New_York')->format('M j, Y g:i A')
                .($event->actor ? ' · '.$event->actor->name : '')
                .($sha !== '' && in_array($event->event, ['imported', 'replaced'], true) ? ' · '.substr($sha, 0, 12) : '');

            return '<li>'.e($line).'</li>';
        })->implode('');

        return new HtmlString($rows === '' ? '<p>No history yet.</p>' : '<ul>'.$rows.'</ul>');
    }
}

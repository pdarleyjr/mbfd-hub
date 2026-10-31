<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailHtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The only place a received message's HTML is ever shown, and only inside a sandboxed iframe. The HTML is
 * sanitized again on the way out (it was sanitized at ingest; both layers are cheap), remote content is
 * never loaded, and the response carries a policy that forbids scripts, frames, forms and network requests
 * even if a sanitizer bug let something through.
 */
final class MessageBodyController extends Controller
{
    /**
     * Exactly this string; the page and its tests rely on it. A sandbox without allow-scripts and without
     * allow-same-origin: nothing can run, and the document has an opaque origin. Popups are allowed so a link
     * (target=_blank, rel=noopener noreferrer) opens in a new tab that is not sandboxed and cannot reach the Hub.
     * Only the Hub itself may frame it.
     */
    public const CONTENT_SECURITY_POLICY = "sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'";

    public function __invoke(Request $request, MailAccess $access, MailHtmlSanitizer $sanitizer, string $kind, int $id): Response
    {
        abort_unless($access->canView($request->user()), 403);
        $email = ($kind === 'inbound' ? InboundEmail::query() : OutboundEmail::query())->findOrFail($id);
        $html = trim((string) ($email instanceof InboundEmail ? $email->sanitized_html_body : $email->html_body));
        $body = $html !== ''
            ? $sanitizer->display($html)
            : '<pre>'.e((string) $email->text_body).'</pre>';

        return response($this->document($body), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function document(string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            // The page's height probe loads this document without the response header, so the document carries the rule too.
            .'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">'
            .'<style>body{margin:0;padding:12px;font:14px/1.5 system-ui,sans-serif;color:#1c1b1f;overflow-wrap:anywhere}'
            .'blockquote{margin:8px 0;padding-left:12px;border-left:3px solid #c4c7c5;color:#444746}'
            .'pre{white-space:pre-wrap;font:inherit;margin:0}table{border-collapse:collapse;max-width:100%}td,th{padding:2px 6px}a{color:#0b57d0}</style>'
            // No closing </html>: that is legal HTML, and Livewire only injects its scripts into a response that has
            // one. Nothing may add a script to a document that is shown without scripts.
            .'</head><body>'.$body.'</body>';
    }
}

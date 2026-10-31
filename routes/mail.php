<?php

declare(strict_types=1);

use App\Http\Controllers\Mail\MessageAttachmentController;
use App\Http\Controllers\Mail\MessageBodyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Center message routes (docs/email-center-architecture.md)
|--------------------------------------------------------------------------
| Required from routes/web.php, so the web middleware group (session, CSRF) applies. Both routes need a signed-in
| administrator with admin.email.view (or the legacy admin.communications.view); the controllers check MailAccess again, so a change to the
| middleware alone can never open them. Nothing here is ever cached.
|
|   GET /admin/email/messages/{kind}/{id}/body                 sanitized HTML for the sandboxed iframe
|   GET /admin/email/messages/{kind}/{id}/attachments/{index}  one attachment, as a download only
*/

Route::prefix('admin/email/messages/{kind}/{id}')
    ->middleware(['auth:web', 'admin.capability:admin.email.view,admin.communications.view', 'throttle:300,1'])
    ->whereIn('kind', ['inbound', 'outbound'])
    ->whereNumber('id')
    ->name('mail.messages.')
    ->group(function (): void {
        Route::get('/body', MessageBodyController::class)->name('body');
        Route::get('/attachments/{index}', MessageAttachmentController::class)->whereNumber('index')->name('attachment');
    });

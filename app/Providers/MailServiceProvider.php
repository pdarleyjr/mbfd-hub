<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\InboundEmail;
use App\Models\OutboundEmail;
use App\Services\Mail\MailThreadIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Keeps Email Center threads current. Indexing runs after the surrounding transaction commits and swallows
 * its own failures: the inbound request (and with it TeleStaff's roster path) must never fail because a
 * thread could not be written. `mail:index-sweep` repairs anything a failure here leaves behind.
 */
final class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        InboundEmail::created(function (InboundEmail $email): void {
            $id = $email->getKey();
            DB::afterCommit(fn () => $this->safely('inbound', $id, fn () => app(MailThreadIndexer::class)->indexInbound($id)));
        });

        OutboundEmail::created(function (OutboundEmail $email): void {
            $id = $email->getKey();
            DB::afterCommit(fn () => $this->safely('outbound', $id, fn () => app(MailThreadIndexer::class)->indexOutbound($id)));
        });

        // The provider assigns a Message-ID only after it accepts the message; a reply that arrives later
        // threads against it, and earlier children may now resolve.
        OutboundEmail::updated(function (OutboundEmail $email): void {
            if (! $email->wasChanged(['message_id', 'provider_message_id'])) {
                return;
            }
            $id = $email->getKey();
            DB::afterCommit(fn () => $this->safely('outbound', $id, fn () => app(MailThreadIndexer::class)->indexOutbound($id)));
        });
    }

    private function safely(string $kind, int|string $id, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            // Class and row id only. An exception message can embed bound values (addresses, subjects).
            Log::warning('mail_thread_index_failed', ['kind' => $kind, 'id' => $id, 'exception' => $exception::class]);
        }
    }
}

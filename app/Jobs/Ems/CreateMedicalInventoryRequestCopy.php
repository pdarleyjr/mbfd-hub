<?php

declare(strict_types=1);

namespace App\Jobs\Ems;

use App\Models\Ems\InventoryRequest;
use App\Services\Ems\MemberCopyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Creates (or repairs) the member's Files copy of one request. One run per request at a time. */
final class CreateMedicalInventoryRequestCopy implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('operational-forms');
    }

    public function uniqueId(): string
    {
        return 'ems-mir-copy-'.$this->requestId;
    }

    public function handle(MemberCopyService $copies): void
    {
        $request = InventoryRequest::query()->find($this->requestId);
        if ($request !== null) {
            $copies->ensure($request);
        }
    }
}

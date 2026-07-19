<?php

namespace App\Jobs;

use App\Models\OperationalFormGeneration;
use App\Services\OperationalForms\PdfGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateOperationalFormPdf implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 45;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $generationId)
    {
        $this->onQueue('operational-forms');
    }

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(PdfGenerationService $generator): void
    {
        $generation = OperationalFormGeneration::query()->with(['record', 'employee'])->findOrFail($this->generationId);
        if ($generation->status === 'completed') {
            return;
        }

        $generation->update([
            'status' => 'processing',
            'error_message' => null,
            'started_at' => now(),
        ]);

        $generated = $generator->generate($generation->record, $generation->employee);
        $generation->update([
            'status' => 'completed',
            'document_id' => $generated['document']->id,
            'completed_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        OperationalFormGeneration::query()->whereKey($this->generationId)->update([
            'status' => 'failed',
            'error_message' => 'The controlled PDF could not be generated. Please save and try again.',
            'completed_at' => now(),
        ]);
    }
}

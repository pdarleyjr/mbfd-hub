<?php

declare(strict_types=1);

$workerEnabled = filter_var(env('WORKGROUP_AI_ENABLED', false), FILTER_VALIDATE_BOOL);
$workerUrl = trim((string) env('WORKGROUP_AI_WORKER_URL', ''));
$workerSecret = trim((string) env('WORKGROUP_AI_WORKER_SECRET', ''));
$canCallWorker = $workerEnabled && $workerUrl !== '' && $workerSecret !== '';

return [
    // Document text may leave the Hub only when all three values are explicit.
    // An empty URL prevents WorkgroupAIService from falling back to a public
    // Worker URL before the data-classification/consent decision is complete.
    'ai_worker_enabled' => $canCallWorker,
    'ai_worker_url' => $canCallWorker ? $workerUrl : '',
    'ai_worker_secret' => $canCallWorker ? $workerSecret : null,
];

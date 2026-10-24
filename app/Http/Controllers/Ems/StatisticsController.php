<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ems;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Ems\Concerns\RespondsWithEnvelope;
use App\Services\Ems\EmsStatistics;
use App\Services\Ems\EmsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Medical Data / Statistics: requests, not consumption. */
final class StatisticsController extends Controller
{
    use RespondsWithEnvelope;

    public function __construct(private readonly EmsStatistics $statistics) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guarded(function () use ($request): JsonResponse {
            $filters = EmsValidator::validate($request->query(), [
                'apparatus_id' => ['nullable', 'integer'],
                'target_kind' => ['nullable', 'in:rescue_unit,other_unit,detail_event'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ], ['date_from.date_format' => 'Enter the start date as year-month-day.', 'date_to.date_format' => 'Enter the end date as year-month-day.', 'date_to.after_or_equal' => 'The end date cannot be before the start date.']);

            return $this->ok($this->statistics->report($filters));
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Workgroup;

use App\Models\User;
use App\Models\WorkgroupSurvey;
use App\Models\WorkgroupSurveyReport;
use App\Services\LocalAIService;
use App\Support\Workgroups\WorkgroupAccess;
use Illuminate\Support\Arr;

final class SurveyExecutiveNarrativeService
{
    /** @return array{report: WorkgroupSurveyReport, generated: bool} */
    public function generate(WorkgroupSurvey $survey, User $actor): array
    {
        app(WorkgroupAccess::class)->requireManageSurvey($actor, $survey);
        $analytics = app(SurveyAnalyticsService::class)->calculate($survey);
        $json = json_encode($analytics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $narrative = null;
        $generated = false;

        try {
            $result = app(LocalAIService::class)->runModel('mbfd-general', [
                ['role' => 'system', 'content' => 'Write a concise, professional executive survey narrative. Use only supplied aggregate facts. Do not calculate, infer, name people, or state suppressed demographic values.'],
                ['role' => 'user', 'content' => "Deterministic survey analytics:\n{$json}"],
            ], ['temperature' => 0.1, 'max_tokens' => 900, 'request_timeout' => 120]);
            $narrative = trim((string) Arr::get($result, 'result.response')) ?: null;
            $generated = $narrative !== null;
        } catch (\Throwable) {
            // The deterministic report remains fully available if the optional AI call fails.
        }

        return [
            'report' => WorkgroupSurveyReport::create([
                'survey_id' => $survey->id,
                'survey_revision' => $survey->revision,
                'included_response_count' => $analytics['summary']['included_in_analysis'],
                'analytics_hash' => $hash,
                'analytics' => $analytics,
                'executive_narrative' => $narrative,
                'generated_by' => $actor->id,
            ]),
            'generated' => $generated,
        ];
    }
}



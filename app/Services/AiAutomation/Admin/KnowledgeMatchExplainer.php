<?php

declare(strict_types=1);

namespace App\Services\AiAutomation\Admin;

use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plain-language answers to "which instructions would be used for this message, and why?" for the Knowledge &
 * Instructions screens. Built on KnowledgeLibrary::previewRetrieval and selfRetrievalCheck; nothing is stored.
 */
final class KnowledgeMatchExplainer
{
    public function __construct(private readonly KnowledgeLibrary $library) {}

    /** @return list<array{title: string, used: bool, why: string}> */
    public function forMessage(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return [];
        }

        try {
            $rows = $this->library->previewRetrieval($message, 5);
        } catch (Throwable $e) {
            Log::warning('Knowledge match preview failed.', ['error' => $e::class]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $words = array_values(array_unique([...$row['matched_words'], ...$row['synonym_words']]));
            $out[] = [
                'title' => $row['title'],
                'used' => $row['retrieved'],
                'why' => match (true) {
                    $row['retrieved'] && $words !== [] => 'Would be used. It matches: '.implode(', ', array_slice($words, 0, 8)).'.',
                    $row['retrieved'] => 'Would be used.',
                    $words !== [] => 'Close, but not a strong enough match to use. It only shares: '.implode(', ', array_slice($words, 0, 8)).'.',
                    default => 'Not a match.',
                },
            ];
        }

        return $out;
    }

    /**
     * Does this version answer the messages it says it should?
     *
     * @return list<array{phrase: string, ok: bool, note: string}>
     */
    public function checkVersion(Version $version): array
    {
        try {
            $report = $this->library->selfRetrievalCheck($version);
        } catch (Throwable $e) {
            Log::warning('Knowledge self check failed.', ['error' => $e::class]);

            return [];
        }

        $titles = [];
        $out = [];
        foreach ($report as $row) {
            if ($row['competitor'] !== null) {
                $titles[$row['competitor']] ??= (string) (AiKnowledgeArticle::query()->where('slug', $row['competitor'])->value('title') ?? 'another article');
            }
            $out[] = [
                'phrase' => $row['phrase'],
                'ok' => $row['top'],
                'note' => match (true) {
                    $row['top'] => 'This would be picked.',
                    $row['retrieved'] && $row['competitor'] !== null => 'Another article, "'.$titles[$row['competitor']].'", would be picked instead.',
                    default => 'This would not be matched. Add an example message or use the member\'s own words in the text.',
                },
            ];
        }

        return $out;
    }
}

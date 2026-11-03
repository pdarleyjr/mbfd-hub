<?php

declare(strict_types=1);

namespace App\Console\Commands\AiAutomation;

use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Services\AiAutomation\PlaybookRepository;
use Illuminate\Console\Command;

/**
 * Loads the first-install Playbooks and Knowledge Library content. Adds only what is missing and never
 * overwrites anything an administrator has edited, so it is safe to run after every deploy.
 */
final class SeedAiAutomationContent extends Command
{
    protected $signature = 'ai-automation:seed';

    protected $description = 'Add the default AI Response playbooks and starter Knowledge Library articles that are not there yet (never overwrites edits)';

    public function handle(PlaybookRepository $playbooks, KnowledgeLibrary $library): int
    {
        $this->info('Playbooks added: '.$playbooks->ensureSeeded());

        $path = resource_path('ai/knowledge/mbfd-support-services-guidance.md');
        if (! is_file($path)) {
            $this->warn('Starter guide not found; no Knowledge Library articles were added.');

            return self::SUCCESS;
        }

        $result = $library->importSeed($path);
        $this->info("Knowledge articles added: {$result['created']}, already present: {$result['skipped']}");

        return self::SUCCESS;
    }
}

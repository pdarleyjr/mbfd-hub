<?php

namespace App\Console\Commands;

use App\Services\EquipmentMatchingService;
use App\Models\EquipmentItem;
use Illuminate\Console\Command;

class TestEquipmentMatching extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mbfd:test-matching {query}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test equipment matching algorithm';

    /**
     * Execute the console command.
     */
    public function handle(EquipmentMatchingService $service): int
    {
        $query = $this->argument('query');
        
        $this->info("Testing match for: \"{$query}\"");
        $this->info("Normalized: \"" . EquipmentItem::normalizeName($query) . "\"");
        $this->newLine();
        
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('findBestMatch');
        $method->setAccessible(true);
        
        $result = $method->invoke($service, $query);
        
        if (!$result) {
            $this->error('No match found');
            return Command::FAILURE;
        }
        
        $this->table(
            ['Field', 'Value'],
            [
                ['Item Name', $result['item']->name],
                ['Normalized Name', $result['item']->normalized_name],
                ['Match Method', $result['method']],
                ['Confidence', ($result['confidence'] * 100) . '%'],
                ['Stock', $result['item']->stock],
                ['Location', $result['item']->location?->full_location ?? 'N/A'],
                ['Reasoning', $result['reasoning']],
            ]
        );
        
        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\AiAutomation;

use App\Services\AiAutomation\AiAutomationSwitch;
use Illuminate\Console\Command;

/** Server-side emergency control for the same master switch the admin page uses. */
final class SwitchAiAutomation extends Command
{
    protected $signature = 'ai-automation:switch {state : on, off or status}';

    protected $description = 'Turn AI automations ON or OFF (or show the current state).';

    public function handle(AiAutomationSwitch $switch): int
    {
        $state = strtolower((string) $this->argument('state'));

        if ($state === 'status') {
            $this->line(sprintf(
                'AI AUTOMATIONS: %s (epoch %d)%s',
                $switch->isOn() ? 'ON' : 'OFF',
                $switch->epoch(),
                $switch->hardDisabled() ? ' - hard-disabled by AI_AUTOMATION_HARD_DISABLED' : '',
            ));

            return self::SUCCESS;
        }

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('State must be on, off or status.');

            return self::INVALID;
        }

        $result = $state === 'off'
            ? $switch->turnOff(null, 'console')
            : $switch->turnOn(null, 'console');

        $this->info(sprintf(
            'AI AUTOMATIONS %s%s. Stopped %d queued responses.',
            strtoupper($state),
            $result['changed'] ? '' : ' (already '.strtoupper($state).')',
            $result['suppressed'],
        ));

        if ($state === 'on' && $switch->hardDisabled()) {
            $this->warn('AI_AUTOMATION_HARD_DISABLED is set; nothing will run until it is removed.');
        }

        return self::SUCCESS;
    }
}

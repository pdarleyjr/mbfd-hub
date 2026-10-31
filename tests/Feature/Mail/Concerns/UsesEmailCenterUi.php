<?php

declare(strict_types=1);

namespace Tests\Feature\Mail\Concerns;

use App\Models\Mail\MailThread;
use App\Models\User;
use Filament\Facades\Filament;

/** Common set-up for the Email Center page tests: the admin panel is current and Vite is not needed. */
trait UsesEmailCenterUi
{
    use BuildsMailFixtures;

    protected function bootEmailCenterUi(): void
    {
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A conversation with one unread inbound message, indexed. */
    protected function conversation(string $subject = 'Hydrant inspection schedule', string $body = 'Can we move the inspection to Friday?'): MailThread
    {
        $email = $this->inbound([
            'subject' => $subject, 'text_body' => $body,
            'from_address' => 'chief@example.test', 'from_display_name' => 'Chief Alvarez',
            'received_at' => now()->subMinutes(30),
        ]);

        return $this->threadOf($email) ?? throw new \LogicException('The inbound message was not indexed.');
    }

    protected function signIn(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}

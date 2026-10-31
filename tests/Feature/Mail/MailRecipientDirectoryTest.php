<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Services\Mail\MailRecipientDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mail\Concerns\BuildsMailFixtures;
use Tests\TestCase;

final class MailRecipientDirectoryTest extends TestCase
{
    use BuildsMailFixtures;
    use RefreshDatabase;

    private function directory(): MailRecipientDirectory
    {
        return app(MailRecipientDirectory::class);
    }

    public function test_it_suggests_active_members_and_past_correspondents_but_never_the_hub_itself(): void
    {
        $asker = $this->mailAdmin();
        User::factory()->create(['name' => 'Rivera Chief', 'email' => 'rivera@miamibeachfl.gov', 'account_status' => 'active']);
        User::factory()->create(['name' => 'Rivera Retired', 'email' => 'rivera.old@miamibeachfl.gov', 'account_status' => 'disabled']);
        $this->inbound(['from_address' => 'rivera.vendor@example.test', 'from_display_name' => 'Rivera Vendor']);
        $this->inbound(['from_address' => 'info@mbfdhub.com', 'from_display_name' => 'Rivera Hub']);

        $found = $this->directory()->suggest($asker, 'rivera');

        $addresses = array_column($found, 'address');
        self::assertContains('rivera@miamibeachfl.gov', $addresses);
        self::assertContains('rivera.vendor@example.test', $addresses);
        self::assertNotContains('rivera.old@miamibeachfl.gov', $addresses, 'disabled accounts are not suggested');
        self::assertNotContains('info@mbfdhub.com', $addresses);
        self::assertSame('member', collect($found)->firstWhere('address', 'rivera@miamibeachfl.gov')['source']);
        self::assertSame('correspondent', collect($found)->firstWhere('address', 'rivera.vendor@example.test')['source']);
    }

    public function test_short_queries_wildcards_and_limits(): void
    {
        $asker = $this->mailAdmin();
        foreach (range(1, 12) as $i) {
            User::factory()->create(['name' => "Smith {$i}", 'email' => "smith{$i}@miamibeachfl.gov", 'account_status' => 'active']);
        }

        self::assertSame([], $this->directory()->suggest($asker, 's'));
        self::assertSame([], $this->directory()->suggest($asker, '%%'));
        self::assertCount(8, $this->directory()->suggest($asker, 'smith'));
        self::assertCount(3, $this->directory()->suggest($asker, 'smith', 3));
    }
}

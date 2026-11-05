<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeMessaging;

use App\Filament\Admin\Pages\EmployeeMessages;
use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Models\EmployeeMessaging\EmployeeLogisticsThread;
use App\Services\EmployeeMessaging\EmployeeMessageReason;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

final class BunkerGearEmphasisTest extends TestCase
{
    use BuildsEmployeeMessagingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        RateLimiter::clear('employee-logistics-message:1');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function thread(string $reason, ?string $subject, string $body, string $name = 'Ann Jones'): EmployeeLogisticsThread
    {
        $message = $this->memberSends($this->messagingMember($name), $body, $subject);
        EmployeeLogisticsThread::query()->whereKey($message->thread_id)->update(['reason' => $reason]);

        return EmployeeLogisticsThread::query()->findOrFail($message->thread_id);
    }

    public function test_reason_threads_are_emphasised_in_the_card_and_the_inbox(): void
    {
        $this->thread(EmployeeMessageReason::BUNKER_GEAR_CLEANING, null, 'Please clean my coat.', 'Bo Smith');
        $this->thread(EmployeeMessageReason::EQUIPMENT, 'Radio strap', 'Strap broke.', 'Cy Doe');
        $this->actingAs($this->logisticsResponder());

        $html = Livewire::test(EmployeeMessagesWidget::class)->html();
        $this->assertSame(1, substr_count($html, 'data-emsg-bunker '));
        $this->assertStringContainsString('emsg-row--bunker', $html);
        $this->assertStringContainsString('Bunker gear cleaning', $html);
        $this->assertStringContainsString('1 bunker gear cleaning request waiting', $html);
        $this->assertStringNotContainsString('(detected)', $html);

        $inbox = Livewire::test(EmployeeMessages::class)->html();
        $this->assertStringContainsString('emsg-row--bunker', $inbox);
    }

    public function test_keyword_detection_labels_general_threads_clearly_and_is_narrow(): void
    {
        $this->assertTrue($this->thread(EmployeeMessageReason::GENERAL, 'Turnout gear cleaning', 'Need it washed.', 'A One')->isBunkerGearByKeyword());
        $this->assertTrue($this->thread(EmployeeMessageReason::GENERAL, null, 'My bunker gear needs to be cleaned after the fire.', 'B Two')->isBunkerGearByKeyword());
        $this->assertFalse($this->thread(EmployeeMessageReason::GENERAL, 'Bunker gear sizing', 'Need a bigger coat.', 'C Three')->isBunkerGearByKeyword());
        $this->assertFalse($this->thread(EmployeeMessageReason::UNIFORMS, 'Turnout gear cleaning', 'x', 'D Four')->isBunkerGear());

        $this->actingAs($this->logisticsResponder());
        $html = Livewire::test(EmployeeMessagesWidget::class)->html();
        $this->assertStringContainsString('(detected)', $html);
    }

    public function test_long_message_bodies_get_a_show_full_message_control(): void
    {
        $message = $this->memberSends($this->messagingMember('Long Writer'), str_repeat("A long line of text.\n", 20));
        $this->actingAs($this->logisticsResponder());

        Livewire::test(EmployeeMessagesWidget::class)
            ->call('toggleThread', $message->thread_id)
            ->assertSee('Show full message');
    }
}

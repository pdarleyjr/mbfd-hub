<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Filament\Training\Resources\TrainingCourseResource\Pages\CreateTrainingCourse;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\CreateTrainingSession;
use App\Filament\Training\Resources\TrainingSessionResource\Pages\ListTrainingSessions;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingRosterService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TrainingClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TrainingRosterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $this->admin->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->service = app(TrainingRosterService::class);
        Filament::setCurrentPanel(Filament::getPanel('training'));
    }

    public function test_custom_classifications_are_normalized_reusable_and_do_not_rewrite_history(): void
    {
        $course = $this->service->createCourse(['name' => 'Primary Screener', 'classifications' => [' STC ', " Airway\n\t Practice "]], $this->admin);
        self::assertSame(['STC', 'Airway Practice'], $course->classifications);
        $session = $this->service->createSession($course, ['held_on' => '2026-10-15'], $this->admin);
        $version = $session->versions()->sole();
        $this->service->updateCourse($course, ['name' => $course->name, 'classifications' => ['New course category']], $this->admin);
        self::assertSame(['STC', 'Airway Practice'], $session->fresh()->classifications);
        self::assertContains('Airway Practice', TrainingCourse::classificationSuggestions());
        self::assertContains('New course category', TrainingCourse::classificationSuggestions());
        $this->service->updateSession($session, ['held_on' => '2026-10-15', 'classifications' => [" Session\t Only "]], $this->admin);
        self::assertSame(['Session Only'], $session->fresh()->classifications);
        self::assertSame(['STC', 'Airway Practice'], $version->fresh()->snapshot['session']['classifications']);
        self::assertContains('Session Only', TrainingCourse::classificationSuggestions());
        self::assertSame(['New course category'], $course->fresh()->classifications);
    }

    public function test_suggestions_include_original_categories_and_stc_without_duplicate_case_variants(): void
    {
        $course = $this->service->createCourse(['name' => 'Synthetic case variant', 'classifications' => ['stc']], $this->admin);
        self::assertSame(['STC'], $course->classifications);
        $suggestions = TrainingCourse::classificationSuggestions();
        self::assertSame(16, count(TrainingCourse::CLASSIFICATIONS));
        self::assertSame([...TrainingCourse::CLASSIFICATIONS, 'STC'], $suggestions);
        $this->service->updateCourse($course, ['name' => $course->name, 'classifications' => ['Custom Category']], $this->admin);
        $session = $this->service->createSession($course, ['held_on' => '2026-10-15', 'classifications' => ["custom\tCATEGORY", 'stc']], $this->admin);
        self::assertSame(['Custom Category', 'STC'], $session->classifications);
    }

    public function test_sixteen_labels_and_eighty_characters_are_allowed_after_whitespace_normalization(): void
    {
        $labels = array_map(fn (int $number): string => str_repeat('a', 78).str_pad((string) $number, 2, '0', STR_PAD_LEFT), range(1, 16));
        $course = $this->service->createCourse(['name' => 'Synthetic boundary', 'classifications' => array_map(fn (string $label): string => '  '.$label.'  ', $labels)], $this->admin);
        self::assertSame($labels, $course->classifications);
        $session = $this->service->createSession($course, ['held_on' => '2026-10-15', 'classifications' => $labels], $this->admin);
        self::assertSame($labels, $session->classifications);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidClassifications(): array
    {
        return [
            'empty' => [[]], 'not an array' => ['STC'], 'blank' => [[" \t\n "]],
            'too long' => [[str_repeat('a', 81)]], 'too many' => [array_map(fn (int $number): string => 'Category '.$number, range(1, 17))],
            'case and whitespace duplicate' => [[' Test Category ', "test\tcategory"]],
            'number' => [[123]], 'nested array' => [[['STC']]],
            'mixed nested array' => [['STC', ['X']]], 'mixed number' => [['STC', 123]],
        ];
    }

    #[DataProvider('invalidClassifications')]
    public function test_invalid_labels_are_rejected_for_course_and_session_mutations(mixed $labels): void
    {
        $course = $this->service->createCourse(['name' => 'Synthetic validation', 'classifications' => ['STC']], $this->admin);
        $session = $this->service->createSession($course, ['held_on' => '2026-10-15'], $this->admin);
        foreach ([
            fn () => $this->service->createCourse(['name' => 'Invalid course', 'classifications' => $labels], $this->admin),
            fn () => $this->service->updateCourse($course, ['name' => 'Invalid update', 'classifications' => $labels], $this->admin),
            fn () => $this->service->createSession($course, ['held_on' => '2026-10-15', 'classifications' => $labels], $this->admin),
            fn () => $this->service->updateSession($session, ['held_on' => '2026-10-15', 'classifications' => $labels], $this->admin),
        ] as $mutate) {
            try {
                $mutate();
                self::fail('Invalid classifications must not be saved.');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('classifications', implode(',', array_keys($exception->errors())));
            }
        }
        self::assertSame(['STC'], $course->fresh()->classifications);
        self::assertSame(['STC'], $session->fresh()->classifications);
        $this->assertDatabaseCount('training_courses', 1);
        $this->assertDatabaseCount('training_sessions', 1);
    }

    public function test_training_admin_can_create_primary_screener_and_reuse_custom_labels_in_real_forms(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateTrainingCourse::class)->fillForm([
            'name' => 'Primary Screener', 'classifications' => [' stc ', " Custom\t Practice "],
        ])->call('create')->assertHasNoFormErrors();
        $course = TrainingCourse::query()->sole();
        self::assertSame(['STC', 'Custom Practice'], $course->classifications);
        Livewire::test(CreateTrainingSession::class)->fillForm([
            'training_course_id' => $course->id, 'course_name' => $course->name,
            'classifications' => ['STC', 'Custom Practice', 'Session Category'], 'held_on' => '2026-10-15',
        ])->call('create')->assertHasNoFormErrors();
        $session = TrainingSession::query()->sole();
        self::assertSame(['STC', 'Custom Practice', 'Session Category'], $session->classifications);
        Livewire::test(ListTrainingSessions::class)->filterTable('classification', 'STC')->assertCanSeeTableRecords([$session]);
        Livewire::test(ListTrainingSessions::class)->filterTable('classification', 'Session Category')->assertCanSeeTableRecords([$session]);
        self::assertContains('Session Category', TrainingCourse::classificationSuggestions());
        $this->assertDatabaseHas('training_roster_audits', ['action' => 'course.created', 'actor_user_id' => $this->admin->id]);
        $this->assertDatabaseHas('training_roster_audits', ['action' => 'session.created', 'actor_user_id' => $this->admin->id]);
    }

    public function test_viewer_cannot_create_or_change_classifications_through_forms_or_service(): void
    {
        $course = $this->service->createCourse(['name' => 'Protected course', 'classifications' => ['STC']], $this->admin);
        $session = $this->service->createSession($course, ['held_on' => '2026-10-15'], $this->admin);
        $viewer = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $viewer->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $this->actingAs($viewer);
        Livewire::test(CreateTrainingCourse::class)->assertForbidden();
        Livewire::test(CreateTrainingSession::class)->assertForbidden();
        foreach ([
            fn () => $this->service->createCourse(['name' => 'Forbidden course', 'classifications' => ['Forbidden']], $viewer),
            fn () => $this->service->updateCourse($course, ['name' => $course->name, 'classifications' => ['Forbidden']], $viewer),
            fn () => $this->service->updateSession($session, ['held_on' => '2026-10-15', 'classifications' => ['Forbidden']], $viewer),
        ] as $mutate) {
            try {
                $mutate();
                self::fail('Viewer must not change classifications.');
            } catch (AuthorizationException) {
                self::assertNotContains('Forbidden', TrainingCourse::classificationSuggestions());
            }
        }
        self::assertSame(['STC'], $course->fresh()->classifications);
        self::assertSame(['STC'], $session->fresh()->classifications);
    }
}

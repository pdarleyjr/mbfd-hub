<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Workgroup\CandidateProductResource;
use App\Filament\Resources\Workgroup\EvaluationCategoryResource;
use App\Filament\Resources\Workgroup\EvaluationSubmissionResource;
use App\Filament\Resources\Workgroup\Pages\CreateCandidateProduct;
use App\Filament\Resources\Workgroup\Pages\ListEvaluationSubmissions;
use App\Filament\Resources\Workgroup\WorkgroupFileResource;
use App\Filament\Resources\Workgroup\WorkgroupMemberResource;
use App\Filament\Resources\Workgroup\WorkgroupResource;
use App\Filament\Resources\Workgroup\WorkgroupSessionResource;
use App\Filament\Workgroup\Pages\Dashboard;
use App\Filament\Workgroup\Pages\EvaluationFormPage;
use App\Filament\Workgroup\Pages\Evaluations;
use App\Filament\Workgroup\Pages\SessionResultsPage;
use App\Models\CandidateProduct;
use App\Models\EvaluationCategory;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupFile;
use App\Models\WorkgroupMember;
use App\Models\WorkgroupSession;
use App\Models\WorkgroupSharedUpload;
use App\Models\WorkgroupSurvey;
use App\Services\Workgroup\EvaluationService;
use App\Services\Workgroup\SurveyResponseService;
use App\Support\Workgroups\WorkgroupAccess;
use App\Support\Workgroups\WorkgroupContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkgroupTenancyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        Http::fake();
    }

    public function test_membership_is_required_and_global_access_is_explicit(): void
    {
        $member = User::factory()->create();
        $memberWorkgroup = $this->makeWorkgroupContext($member, 'A');
        $otherWorkgroup = $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $roleOnlyAdmin = User::factory()->create();
        $roleOnlyAdmin->assignRole(Role::findOrCreate('admin', 'web'));
        $roleOnlyLogisticsAdmin = User::factory()->create();
        $roleOnlyLogisticsAdmin->assignRole(Role::findOrCreate('logistics_admin', 'web'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $explicitGlobal = User::factory()->create();
        $explicitGlobal->givePermissionTo(Permission::findOrCreate('workgroup.global_access', 'web'));

        $access = app(WorkgroupAccess::class);

        $this->assertTrue($access->canEnterPanel($member));
        $this->assertTrue($access->canViewWorkgroup($member, $memberWorkgroup['workgroup']));
        $this->assertFalse($access->canViewWorkgroup($member, $otherWorkgroup['workgroup']));
        $this->assertFalse($access->canEnterPanel($roleOnlyAdmin));
        $this->assertFalse($access->canEnterPanel($roleOnlyLogisticsAdmin));
        $this->assertTrue($access->canViewWorkgroup($superAdmin, $otherWorkgroup['workgroup']));
        $this->assertTrue($access->canViewWorkgroup($explicitGlobal, $otherWorkgroup['workgroup']));
    }

    public function test_multiple_memberships_require_an_explicit_active_workgroup_context(): void
    {
        $user = User::factory()->create();
        $contextA = $this->makeWorkgroupContext($user, 'A');
        $contextB = $this->makeWorkgroupContext($user, 'B');
        $singleMember = User::factory()->create();
        $contextC = $this->makeWorkgroupContext($singleMember, 'C');

        session()->forget(WorkgroupContext::SESSION_KEY);
        $context = app(WorkgroupContext::class);

        $this->assertNull($context->current($user));
        $this->assertSame($contextA['workgroup']->id, $context->select($user, $contextA['workgroup']->id)->id);
        $this->assertSame($contextA['workgroup']->id, $context->current($user)?->id);
        $this->assertSame($contextB['workgroup']->id, $context->select($user, $contextB['workgroup']->id)->id);

        session()->forget(WorkgroupContext::SESSION_KEY);
        $this->assertSame($contextC['workgroup']->id, $context->current($singleMember)?->id);

        $roleOnlyAdmin = User::factory()->create();
        $roleOnlyAdmin->assignRole(Role::findOrCreate('admin', 'web'));

        try {
            $context->select($roleOnlyAdmin, $contextA['workgroup']->id);
            $this->fail('A broad non-workgroup role must not select a workgroup context.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_member_a_cannot_download_workgroup_b_upload_and_role_only_admin_has_no_bypass(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $contextA = $this->makeWorkgroupContext($memberA, 'A');

        $memberB = User::factory()->create();
        $memberB->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $contextB = $this->makeWorkgroupContext($memberB, 'B');

        $uploadA = $this->makeUpload($contextA);
        $uploadB = $this->makeUpload($contextB);

        $this->actingAs($memberA)
            ->get(route('workgroup.shared-upload.download', $uploadA))
            ->assertOk();

        $this->actingAs($memberA)
            ->get(route('workgroup.shared-upload.download', $uploadB))
            ->assertNotFound();

        $roleOnlyAdmin = User::factory()->create();
        $roleOnlyAdmin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($roleOnlyAdmin)
            ->get(route('workgroup.shared-upload.download', $uploadA))
            ->assertNotFound();
    }

    public function test_resource_queries_and_ai_requests_are_scoped_before_an_external_effect(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $contextA = $this->makeWorkgroupContext($memberA, 'A');

        $memberB = User::factory()->create();
        $memberB->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $contextB = $this->makeWorkgroupContext($memberB, 'B');

        $this->actingAs($memberA);

        $this->assertSame(
            [$contextA['product']->id],
            CandidateProductResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->get(route('filament.workgroups.resources.workgroup.candidate-products.view', $contextB['product']))
            ->assertNotFound();

        $this->postJson('/api/workgroup/ai/analyze-product/'.$contextB['product']->id)
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_member_a_cannot_switch_a_livewire_results_page_to_workgroup_b(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $this->makeWorkgroupContext($memberA, 'A');
        $contextB = $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $this->actingAs($memberA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(SessionResultsPage::class)
            ->call('switchSession', $contextB['session']->id)
            ->assertStatus(404);
    }

    public function test_results_page_requires_an_explicit_context_for_a_multi_workgroup_member(): void
    {
        $member = User::factory()->create();
        $this->makeWorkgroupContext($member, 'A');
        $this->makeWorkgroupContext($member, 'B');

        session()->forget(WorkgroupContext::SESSION_KEY);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(SessionResultsPage::class)
            ->assertStatus(404);
    }

    public function test_session_progress_fallback_is_limited_to_the_selected_sessions_workgroup(): void
    {
        $contextA = $this->makeWorkgroupContext(User::factory()->create(), 'A');
        $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $progress = app(EvaluationService::class)->getSessionProgress($contextA['session']->id);

        $this->assertSame(1, $progress['total_members']);
    }

    public function test_admin_discovered_workgroup_resources_scope_records_and_do_not_grant_role_only_access(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $contextA = $this->makeWorkgroupContext($memberA, 'A');

        $memberB = User::factory()->create();
        $memberB->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $contextB = $this->makeWorkgroupContext($memberB, 'B');

        $fileA = WorkgroupFile::create([
            'workgroup_id' => $contextA['workgroup']->id,
            'workgroup_session_id' => $contextA['session']->id,
            'filename' => 'a.pdf',
            'filepath' => 'workgroup/a.pdf',
            'uploaded_by' => $memberA->id,
        ]);
        $fileB = WorkgroupFile::create([
            'workgroup_id' => $contextB['workgroup']->id,
            'workgroup_session_id' => $contextB['session']->id,
            'filename' => 'b.pdf',
            'filepath' => 'workgroup/b.pdf',
            'uploaded_by' => $memberB->id,
        ]);
        $submissionA = EvaluationSubmission::create([
            'workgroup_member_id' => $contextA['member']->id,
            'candidate_product_id' => $contextA['product']->id,
            'status' => 'draft',
        ]);
        $submissionB = EvaluationSubmission::create([
            'workgroup_member_id' => $contextB['member']->id,
            'candidate_product_id' => $contextB['product']->id,
            'status' => 'draft',
        ]);

        $this->actingAs($memberA);

        $this->assertSame([$contextA['workgroup']->id], WorkgroupResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$contextA['session']->id], WorkgroupSessionResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$contextA['member']->id], WorkgroupMemberResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$fileA->id], WorkgroupFileResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame([$submissionA->id], EvaluationSubmissionResource::getEloquentQuery()->pluck('id')->all());

        $this->assertNotSame($fileA->id, $fileB->id);
        $this->assertNotSame($submissionA->id, $submissionB->id);

        $roleOnlyAdmin = User::factory()->create();
        $roleOnlyAdmin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($roleOnlyAdmin);

        $this->assertFalse(WorkgroupResource::canViewAny());
        $this->assertFalse(CandidateProductResource::canViewAny());
        $this->assertFalse(EvaluationCategoryResource::canViewAny());
    }

    public function test_facilitator_cannot_create_a_candidate_in_an_assigned_group_where_they_are_only_a_member(): void
    {
        $facilitator = User::factory()->create();
        $contextA = $this->makeWorkgroupContext($facilitator, 'A', 'facilitator');
        $contextB = $this->makeWorkgroupContext(User::factory()->create(), 'B');
        WorkgroupMember::create([
            'workgroup_id' => $contextB['workgroup']->id,
            'user_id' => $facilitator->id,
            'role' => 'member',
            'is_active' => true,
        ]);

        $this->actingAs($facilitator);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(CreateCandidateProduct::class)
            ->fillForm([
                'workgroup_session_id' => $contextB['session']->id,
                'category_id' => $contextB['product']->category_id,
                'name' => 'Cross-workgroup candidate',
            ])
            ->call('create')
            ->assertStatus(404);

        $this->assertDatabaseMissing('candidate_products', [
            'workgroup_session_id' => $contextB['session']->id,
            'name' => 'Cross-workgroup candidate',
        ]);

        $this->assertTrue(CandidateProductResource::canCreate());
        $this->assertFalse(
            app(WorkgroupAccess::class)
                ->scopeManageSessions(WorkgroupSession::query(), $facilitator)
                ->whereKey($contextB['session']->id)
                ->exists(),
        );
        $this->assertTrue(
            app(WorkgroupAccess::class)
                ->scopeManageSessions(WorkgroupSession::query(), $facilitator)
                ->whereKey($contextA['session']->id)
                ->exists(),
        );
    }

    public function test_member_a_cannot_load_workgroup_b_evaluation_product_through_livewire_query_state(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $this->makeWorkgroupContext($memberA, 'A');
        $contextB = $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $this->actingAs($memberA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::withQueryParams(['productId' => $contextB['product']->id])
            ->test(EvaluationFormPage::class)
            ->assertStatus(404);
    }

    public function test_evaluation_form_requires_the_active_workgroup_context_for_a_multi_workgroup_member(): void
    {
        $member = User::factory()->create();
        $contextA = $this->makeWorkgroupContext($member, 'A', 'facilitator');
        $contextB = $this->makeWorkgroupContext($member, 'B', 'facilitator');
        app(WorkgroupContext::class)->select($member, $contextA['workgroup']->id);

        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::withQueryParams(['productId' => $contextB['product']->id])
            ->test(EvaluationFormPage::class)
            ->assertStatus(404);
    }

    public function test_dashboard_rejects_a_forged_cross_workgroup_livewire_session_property(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $this->makeWorkgroupContext($memberA, 'A');
        $contextB = $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $this->actingAs($memberA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Dashboard::class)
            ->set('selectedSessionId', $contextB['session']->id)
            ->assertStatus(404);
    }

    public function test_evaluations_rejects_forged_cross_workgroup_livewire_session_state(): void
    {
        $memberA = User::factory()->create();
        $memberA->assignRole(Role::findOrCreate('workgroup_member', 'web'));
        $this->makeWorkgroupContext($memberA, 'A');
        $contextB = $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $this->actingAs($memberA);
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Evaluations::class)
            ->set('selectedSession', (string) $contextB['session']->id)
            ->assertStatus(404);
    }

    public function test_authorized_executive_report_uses_the_session_workgroup_without_an_external_call(): void
    {
        $facilitator = User::factory()->create();
        $context = $this->makeWorkgroupContext($facilitator, 'A', 'facilitator');

        $this->actingAs($facilitator)
            ->postJson('/api/workgroup/ai/executive-report', [
                'session_id' => $context['session']->id,
            ])
            ->assertOk()
            ->assertJsonPath('error', 'AI service not configured');

        Http::assertNothingSent();
    }

    public function test_facilitator_cannot_create_or_mutate_a_draft_for_another_workgroup_session(): void
    {
        $facilitator = User::factory()->create();
        $contextA = $this->makeWorkgroupContext($facilitator, 'A', 'facilitator');
        $contextB = $this->makeWorkgroupContext(User::factory()->create(), 'B');

        $service = app(EvaluationService::class);

        $this->assertFalse($service->canMemberAccessSession($contextA['member'], $contextB['session']->id));

        try {
            $service->getOrCreateDraft($contextA['member'], $contextB['product']->id);
            $this->fail('A cross-workgroup draft must not be created.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('evaluation_submissions', [
            'workgroup_member_id' => $contextA['member']->id,
            'candidate_product_id' => $contextB['product']->id,
        ]);
    }

    public function test_parent_workgroup_and_session_deletion_cannot_cascade_submitted_evaluation_or_survey_evidence(): void
    {
        foreach (['evaluation', 'survey'] as $domain) {
            $manager = User::factory()->create();
            $context = $this->makeWorkgroupContext($manager, 'QA-'.$domain, 'facilitator');
            $this->actingAs($manager);
            app(WorkgroupContext::class)->select($manager, $context['workgroup']->id);
            if ($domain === 'evaluation') {
                $evidence = EvaluationSubmission::create([
                    'workgroup_member_id' => $context['member']->id, 'candidate_product_id' => $context['product']->id,
                    'status' => 'submitted', 'submitted_at' => now(), 'criterion_payload' => ['ratings' => ['qa' => 5]],
                ]);
            } else {
                $survey = WorkgroupSurvey::create([
                    'workgroup_id' => $context['workgroup']->id, 'workgroup_session_id' => $context['session']->id,
                    'title' => '[QA TEST] Retained survey', 'status' => 'active', 'is_anonymous' => true,
                ]);
                $question = $survey->questions()->create([
                    'position' => 1, 'type' => 'single', 'prompt' => 'QA answer', 'is_required' => true,
                    'configuration' => ['options' => [['key' => 'confirmed', 'label' => 'Confirmed']]],
                ]);
                $evidence = app(SurveyResponseService::class)->submit($survey, $manager, [(string) $question->id => 'confirmed']);
            }
            self::assertFalse(WorkgroupResource::canDelete($context['workgroup']));
            self::assertFalse(WorkgroupSessionResource::canDelete($context['session']));
            $parents = [$context['workgroup'], $context['session'], $context['member']];
            if ($domain === 'evaluation') {
                self::assertFalse(CandidateProductResource::canDelete($context['product']));
                $parents[] = $context['product'];
                $parents[] = $context['product']->category;
            }
            foreach ($parents as $parent) {
                try {
                    $parent->delete();
                    self::fail('Submitted evidence must prevent parent cascade deletion.');
                } catch (LogicException $exception) {
                    self::assertStringContainsString('cannot be deleted', $exception->getMessage());
                    $this->assertDatabaseHas($parent->getTable(), ['id' => $parent->id]);
                    $this->assertDatabaseHas($evidence->getTable(), ['id' => $evidence->id]);
                }
            }
            $context['workgroup']->update(['is_active' => false]);
            $context['session']->update(['status' => 'completed']);
            $this->assertDatabaseHas($evidence->getTable(), ['id' => $evidence->id]);
        }
        Http::assertNothingSent();
    }

    public function test_parent_deletion_cannot_purge_a_soft_trashed_note_or_upload_without_other_submitted_evidence(): void
    {
        $manager = User::factory()->create();
        $context = $this->makeWorkgroupContext($manager, 'QA-retained-only', 'facilitator');
        $this->actingAs($manager);
        $note = $context['member']->notes()->create(['workgroup_session_id' => $context['session']->id, 'title' => '[QA TEST] Retained note', 'content' => 'Retained content']);
        $upload = $this->makeUpload($context);
        $note->delete();
        $upload->delete();
        foreach ([$context['workgroup'], $context['session'], $context['member']] as $parent) {
            try {
                $parent->delete();
                self::fail('Trash must remain restorable after a denied parent deletion.');
            } catch (LogicException) {
                $this->assertSoftDeleted($note);
                $this->assertSoftDeleted($upload);
                Storage::disk('local')->assertExists($upload->filepath);
            }
        }
        $note->restore();
        $upload->restore();
        self::assertSame('Retained content', $note->fresh()->content);
        self::assertSame($context['member']->id, $upload->fresh()->workgroup_member_id);
        Http::assertNothingSent();
    }

    public function test_member_evaluation_draft_submit_and_admin_discovery_preserve_submitted_content(): void
    {
        $member = User::factory()->create();
        $context = $this->makeWorkgroupContext($member, 'QA-evaluation', 'facilitator');
        $this->actingAs($member);
        app(WorkgroupContext::class)->select($member, $context['workgroup']->id);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));
        $page = Livewire::withQueryParams(['productId' => $context['product']->id])->test(EvaluationFormPage::class)
            ->call('setAllHighest')->set('advance_recommendation', 'yes')->set('confidence_level', 'high')
            ->set('additional_comments', '[QA TEST] Submitted evaluation evidence')->call('saveDraft')->assertHasNoErrors();
        $submission = EvaluationSubmission::query()->sole();
        self::assertSame('draft', $submission->status);
        self::assertNotEmpty($submission->criterion_payload['ratings']);
        $page->call('submitEvaluation')->assertHasNoErrors()->assertSet('isReadOnly', true);
        $submitted = $submission->fresh();
        self::assertSame('submitted', $submitted->status);
        self::assertNotNull($submitted->submitted_at);
        self::assertSame('[QA TEST] Submitted evaluation evidence', $submitted->narrative_payload['additional_comments']);
        $page->set('additional_comments', 'Forged replacement')->call('saveDraft')->assertStatus(404);
        self::assertSame($submitted->narrative_payload, $submission->fresh()->narrative_payload);

        $member->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListEvaluationSubmissions::class)->filterTable('status', 'submitted')->assertCanSeeTableRecords([$submission])
            ->assertTableActionExists('view')->assertTableActionDoesNotExist('delete');
        Http::assertNothingSent();
    }

    /** @return array{workgroup: Workgroup, member: WorkgroupMember, session: WorkgroupSession, product: CandidateProduct} */
    private function makeWorkgroupContext(User $user, string $suffix, string $memberRole = 'member'): array
    {
        $workgroup = Workgroup::create([
            'name' => 'Workgroup '.$suffix,
            'created_by' => $user->id,
        ]);
        $member = WorkgroupMember::create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => $memberRole,
            'is_active' => true,
        ]);
        $session = WorkgroupSession::create([
            'workgroup_id' => $workgroup->id,
            'name' => 'Session '.$suffix,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);
        $category = EvaluationCategory::firstOrCreate([
            'name' => 'Category '.$suffix,
        ], [
            'is_active' => true,
            'is_rankable' => true,
        ]);
        $product = CandidateProduct::create([
            'workgroup_session_id' => $session->id,
            'category_id' => $category->id,
            'name' => 'Product '.$suffix,
        ]);

        return compact('workgroup', 'member', 'session', 'product');
    }

    /** @param array{workgroup: Workgroup, member: WorkgroupMember, session: WorkgroupSession, product: CandidateProduct} $context */
    private function makeUpload(array $context): WorkgroupSharedUpload
    {
        $path = 'workgroup-shared-uploads/'.$context['workgroup']->id.'/test.txt';
        Storage::disk('local')->put($path, 'disposable test upload');

        return WorkgroupSharedUpload::create([
            'workgroup_id' => $context['workgroup']->id,
            'workgroup_session_id' => $context['session']->id,
            'user_id' => $context['member']->user_id,
            'workgroup_member_id' => $context['member']->id,
            'filename' => 'test.txt',
            'filepath' => $path,
            'file_type' => 'text/plain',
            'file_size' => 22,
        ]);
    }
}

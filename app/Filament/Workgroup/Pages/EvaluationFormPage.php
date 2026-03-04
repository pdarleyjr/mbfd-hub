<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\CandidateProduct;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Support\Workgroups\UniversalEvaluationRubric;
use App\Services\Workgroup\EvaluationService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Group;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class EvaluationFormPage extends Page
{
    use InteractsWithForms;

    protected static string $view = 'filament-workgroup.pages.evaluation-form';
    protected static ?string $title = 'Evaluate Product';
    protected static ?string $navigationLabel = 'Evaluate';
    protected static bool $shouldRegisterNavigation = false;

    public ?int $productId = null;
    public ?int $submissionId = null;
    public array $ratings = [];
    public array $notes = [];
    public ?string $advance_recommendation = null;
    public ?string $confidence_level = null;
    public bool $has_deal_breaker = false;
    public ?string $deal_breaker_note = null;
    public ?string $strongest_advantages = null;
    public ?string $biggest_weaknesses = null;
    public ?string $best_use_case = null;
    public ?string $compatibility_notes = null;
    public ?string $training_notes = null;
    public ?string $safety_concerns = null;
    public ?string $additional_comments = null;
    public ?CandidateProduct $product = null;
    public ?EvaluationSubmission $submission = null;
    public ?WorkgroupMember $member = null;
    public array $criteria = [];
    public array $criteriaByBucket = [];
    public bool $isReadOnly = false;
    public bool $canSubmit = false;
    public string $assessmentProfile = 'generic_apparatus';

    protected EvaluationService $evaluationService;

    public function mount(): void
    {
        $this->productId = (int) request()->get('productId', 0);
        if (!$this->productId) {
            $this->redirect(Evaluations::getUrl());
            return;
        }
        $this->evaluationService = new EvaluationService();
        $this->member = $this->getCurrentMember();
        if (!$this->member) {
            Notification::make()->title('Access Denied')->body('You must be a workgroup member.')->danger()->send();
            $this->redirect(Evaluations::getUrl());
            return;
        }
        $this->loadProduct();
    }

    protected function loadProduct(): void
    {
        $this->product = CandidateProduct::with(['category', 'session'])->findOrFail($this->productId);
        $this->assessmentProfile = $this->product->category->getRawOriginal('assessment_profile') ?? 'generic_apparatus';
        $this->criteriaByBucket = UniversalEvaluationRubric::getCriteriaByBucket($this->assessmentProfile);
        foreach ($this->criteriaByBucket as $bucket => $bucketCriteria) {
            foreach ($bucketCriteria as $id => $criterion) {
                $this->criteria[$id] = $criterion;
            }
        }
        $this->submission = $this->evaluationService->getOrCreateDraft($this->member, $this->productId);
        $this->submissionId = $this->submission->id;

        // Only read-only if LOCKED (not just submitted - allow editing submitted evals)
        if ($this->submission->is_locked) {
            $this->isReadOnly = true;
        }

        $this->loadExistingData();
        $this->checkCanSubmit();
    }

    protected function loadExistingData(): void
    {
        if (!$this->submission) return;
        if ($this->submission->criterion_payload) {
            $this->ratings = $this->submission->criterion_payload['ratings'] ?? [];
            $this->notes = $this->submission->criterion_payload['notes'] ?? [];
        }
        $this->advance_recommendation = $this->submission->advance_recommendation;
        $this->confidence_level = $this->submission->confidence_level;
        $this->has_deal_breaker = $this->submission->has_deal_breaker ?? false;
        $this->deal_breaker_note = $this->submission->deal_breaker_note;
        if ($this->submission->narrative_payload) {
            $n = $this->submission->narrative_payload;
            $this->strongest_advantages = $n['strongest_advantages'] ?? null;
            $this->biggest_weaknesses = $n['biggest_weaknesses'] ?? null;
            $this->best_use_case = $n['best_use_case'] ?? null;
            $this->compatibility_notes = $n['compatibility_notes'] ?? null;
            $this->training_notes = $n['training_notes'] ?? null;
            $this->safety_concerns = $n['safety_concerns'] ?? null;
            $this->additional_comments = $n['additional_comments'] ?? null;
        }
    }

    protected function checkCanSubmit(): void
    {
        foreach ($this->criteria as $id => $criterion) {
            if (!isset($this->ratings[$id]) || $this->ratings[$id] === '') {
                $this->canSubmit = false;
                return;
            }
        }
        if (empty($this->advance_recommendation) || empty($this->confidence_level)) {
            $this->canSubmit = false;
            return;
        }
        if ($this->has_deal_breaker && empty($this->deal_breaker_note)) {
            $this->canSubmit = false;
            return;
        }
        $this->canSubmit = true;
    }

    public function updatedRatings(): void { $this->checkCanSubmit(); }
    public function updatedAdvanceRecommendation(): void { $this->checkCanSubmit(); }
    public function updatedConfidenceLevel(): void { $this->checkCanSubmit(); }
    public function updatedHasDealBreaker(): void { $this->checkCanSubmit(); }
    public function updatedDealBreakerNote(): void { $this->checkCanSubmit(); }

    public function setAllHighest(): void
    {
        foreach ($this->criteria as $id => $criterion) {
            $this->ratings[$id] = 5;
        }
        $this->checkCanSubmit();
        Notification::make()->title('All ratings set to 5 (Outstanding)')->body('Adjust individual scores as needed.')->success()->send();
    }

    public function form(Form $form): Form
    {
        return $form->schema($this->getFormSchema());
    }

    protected function getFormSchema(): array
    {
        $schema = [];
        $profileLabel = UniversalEvaluationRubric::getAssessmentProfiles()[$this->assessmentProfile] ?? 'Generic';
        $schema[] = Section::make('Product Information')
            ->schema([
                Placeholder::make('product_name')->label('Product')->content($this->product?->name ?? '-'),
                Placeholder::make('product_mfr')->label('Manufacturer')->content($this->product?->manufacturer ?? '-'),
                Placeholder::make('product_model')->label('Model')->content($this->product?->model ?? '-'),
                Placeholder::make('profile')->label('Evaluation Profile')->content($profileLabel),
            ])->columns(4);

        $schema[] = Section::make('Rating Scale')
            ->schema([
                Placeholder::make('legend')->label('')->content(new HtmlString(
                    '<span class="font-bold text-green-600">5=Outstanding</span> · '
                    . '<span class="font-bold text-blue-600">4=Strong</span> · '
                    . '<span class="font-bold text-yellow-600">3=Acceptable</span> · '
                    . '<span class="font-bold text-red-600">2=Below</span> · '
                    . '<span class="font-bold text-red-800">1=Unacceptable</span> · '
                    . '<span class="text-gray-500">N/A</span>'
                )),
            ])->collapsed();

        $bucketConfig = [
            'capability' => ['title' => 'Capability (30%)', 'desc' => 'Performance, safety, and effectiveness'],
            'usability' => ['title' => 'Usability (30%)', 'desc' => 'Ergonomics, ease of use, and portability'],
            'affordability' => ['title' => 'Affordability (20%)', 'desc' => 'Cost effectiveness and value'],
            'maintainability' => ['title' => 'Maintainability (15%)', 'desc' => 'Service, support, and upkeep'],
            'deployability' => ['title' => 'Deployability (5%)', 'desc' => 'Readiness and logistics'],
        ];

        foreach ($this->criteriaByBucket as $bucket => $bucketCriteria) {
            if (empty($bucketCriteria)) continue;
            $config = $bucketConfig[$bucket] ?? ['title' => ucfirst($bucket), 'desc' => ''];
            $fields = [];
            foreach ($bucketCriteria as $id => $criterion) {
                $srcLabel = UniversalEvaluationRubric::getSourceLabel($criterion['source']);
                $fields[] = Group::make([
                    Placeholder::make('desc_' . $id)
                        ->label($criterion['name'])
                        ->content($criterion['description'] . " ({$srcLabel} · Wt: {$criterion['weight']})")
                        ->columnSpanFull(),
                    Select::make('ratings.' . $id)
                        ->label('Score')
                        ->options(UniversalEvaluationRubric::getRatingOptions())
                        ->disabled($this->isReadOnly)
                        ->columnSpan(1),
                    TextInput::make('notes.' . $id)
                        ->label('Notes')
                        ->disabled($this->isReadOnly)
                        ->columnSpan(1),
                ])->columns(2);
            }
            $schema[] = Section::make($config['title'])->description($config['desc'])->schema($fields)->collapsible();
        }

        $schema[] = Section::make('Evaluation Decision')
            ->schema([
                Select::make('advance_recommendation')->label('Advance to finalist?')
                    ->options(UniversalEvaluationRubric::getRecommendationOptions())->required()->disabled($this->isReadOnly),
                Select::make('confidence_level')->label('Confidence')
                    ->options(UniversalEvaluationRubric::getConfidenceOptions())->required()->disabled($this->isReadOnly),
                Toggle::make('has_deal_breaker')->label('Deal-breaker?')->disabled($this->isReadOnly)->reactive(),
                Textarea::make('deal_breaker_note')->label('Deal-breaker details')
                    ->visible(fn () => $this->has_deal_breaker)->rows(2)->disabled($this->isReadOnly),
            ])->columns(2);

        $schema[] = Section::make('Narrative Feedback')->collapsible()->schema([
            Textarea::make('strongest_advantages')->label('Strongest Advantages')->rows(2)->disabled($this->isReadOnly),
            Textarea::make('biggest_weaknesses')->label('Biggest Weaknesses')->rows(2)->disabled($this->isReadOnly),
            Textarea::make('best_use_case')->label('Best Use Case')->rows(2)->disabled($this->isReadOnly),
            Textarea::make('safety_concerns')->label('Safety Concerns')->rows(2)->disabled($this->isReadOnly),
            Textarea::make('additional_comments')->label('Additional Comments')->rows(2)->disabled($this->isReadOnly),
        ]);

        return $schema;
    }

    public function saveDraft(): void
    {
        if ($this->isReadOnly) {
            Notification::make()->title('Locked')->body('This evaluation is locked and cannot be edited.')->danger()->send();
            return;
        }
        $this->saveRubricData();
        Notification::make()->title('Draft Saved')->success()->send();
    }

    public function submitEvaluation(): void
    {
        if ($this->isReadOnly) {
            Notification::make()->title('Locked')->body('This evaluation is locked.')->danger()->send();
            return;
        }
        if (!$this->canSubmit) {
            Notification::make()->title('Incomplete')->body('Complete all fields first.')->danger()->send();
            return;
        }
        $this->saveRubricData();
        try {
            $this->submission->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->submission->refresh();
            Notification::make()->title('Evaluation Submitted')->body('You can still edit this until an admin locks it.')->success()->send();

            // Fire event for AI worker
            event(new \App\Events\EvaluationSubmitted($this->submission));
        } catch (\Exception $e) {
            Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
        }
    }

    protected function saveRubricData(): void
    {
        if (!$this->submission) return;
        $ratings = array_filter($this->ratings, fn($v) => $v !== '' && $v !== null);
        $scores = UniversalEvaluationRubric::calculateAllScores($ratings);
        $this->submission->update([
            'user_id' => $this->member->user_id,
            'rubric_version' => UniversalEvaluationRubric::getVersion(),
            'assessment_profile' => $this->assessmentProfile,
            'overall_score' => $scores['overall_score'],
            'capability_score' => $scores['capability_score'],
            'usability_score' => $scores['usability_score'],
            'affordability_score' => $scores['affordability_score'],
            'maintainability_score' => $scores['maintainability_score'],
            'deployability_score' => $scores['deployability_score'],
            'advance_recommendation' => $this->advance_recommendation,
            'confidence_level' => $this->confidence_level,
            'has_deal_breaker' => $this->has_deal_breaker,
            'deal_breaker_note' => $this->has_deal_breaker ? $this->deal_breaker_note : null,
            'criterion_payload' => ['ratings' => $this->ratings, 'notes' => $this->notes ?? []],
            'narrative_payload' => [
                'strongest_advantages' => $this->strongest_advantages,
                'biggest_weaknesses' => $this->biggest_weaknesses,
                'best_use_case' => $this->best_use_case,
                'safety_concerns' => $this->safety_concerns,
                'additional_comments' => $this->additional_comments,
            ],
        ]);
        $this->submission->refresh();
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        return WorkgroupMember::where('user_id', Auth::id())
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return WorkgroupMember::where('user_id', $user->id)->where('is_active', true)->exists();
    }
}

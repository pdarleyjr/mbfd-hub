<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\CandidateProduct;
use App\Models\EvaluationComment;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\WorkgroupMember;
use App\Support\Workgroups\UniversalEvaluationRubric;
use App\Services\Workgroup\EvaluationService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Auth;

class EvaluationFormPage extends Page
{
    use InteractsWithForms;

    protected static string $view = 'filament-workgroup.pages.simple-page';

    protected static ?string $title = 'Evaluate Product';
    
    protected static ?string $navigationLabel = 'Evaluate';

    public ?int $productId = null;
    
    public ?int $submissionId = null;
    
    // Form state
    public array $ratings = [];
    
    public array $notes = [];
    
    // Decision fields
    public ?string $advance_recommendation = null;
    
    public ?string $confidence_level = null;
    
    public bool $has_deal_breaker = false;
    
    public ?string $deal_breaker_note = null;
    
    // Narrative fields
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
    
    public string $instructions = '';
    
    public string $assessmentProfile = UniversalEvaluationRubric::PROFILE_GENERIC;

    protected EvaluationService $evaluationService;

    public function mount(): void
    {
        // Get productId from route parameter using Laravel request
        $this->productId = (int) request()->get('productId', 0);
        
        if (!$this->productId) {
            Notification::make()
                ->title('Invalid Product')
                ->body('No product specified for evaluation.')
                ->danger()
                ->send();
            
            $this->redirect(Evaluations::getUrl());
            return;
        }
        
        $this->evaluationService = new EvaluationService();
        $this->member = $this->getCurrentMember();
        
        $this->member = $this->getCurrentMember();
        
        if (!$this->member) {
            Notification::make()
                ->title('Access Denied')
                ->body('You must be a member of a workgroup to evaluate products.')
                ->danger()
                ->send();
            
            $this->redirect(Evaluations::getUrl());
            return;
        }

        $this->loadProduct();
    }

    protected function loadProduct(): void
    {
        $this->product = CandidateProduct::with(['category', 'session'])->findOrFail($this->productId);

        // Get assessment profile from category
        $this->assessmentProfile = $this->product->category->assessment_profile ?? UniversalEvaluationRubric::PROFILE_GENERIC;
        
        // Load criteria from rubric
        $this->criteriaByBucket = UniversalEvaluationRubric::getCriteriaByBucket($this->assessmentProfile);
        
        // Flatten criteria for easier access
        foreach ($this->criteriaByBucket as $bucket => $criteria) {
            foreach ($criteria as $id => $criterion) {
                $this->criteria[$id] = $criterion;
            }
        }

        // Get or create draft submission
        $this->submission = $this->evaluationService->getOrCreateDraft($this->member, $this->productId);
        $this->submissionId = $this->submission->id;

        // Check if already submitted (read-only mode)
        if ($this->submission->isSubmitted()) {
            $this->isReadOnly = true;
        }

        // Load existing rubric data from JSON payload
        $this->loadExistingData();

        // Get evaluator instructions
        $this->instructions = $this->product->category->evaluator_instructions ?? 
                             UniversalEvaluationRubric::getEvaluatorInstructions();

        // Check if can submit
        $this->checkCanSubmit();
    }

    protected function loadExistingData(): void
    {
        if (!$this->submission) {
            return;
        }

        // Load criterion ratings and notes from JSON payload
        if ($this->submission->criterion_payload) {
            $this->ratings = $this->submission->criterion_payload['ratings'] ?? [];
            $this->notes = $this->submission->criterion_payload['notes'] ?? [];
        }

        // Load decision fields
        $this->advance_recommendation = $this->submission->advance_recommendation;
        $this->confidence_level = $this->submission->confidence_level;
        $this->has_deal_breaker = $this->submission->has_deal_breaker ?? false;
        $this->deal_breaker_note = $this->submission->deal_breaker_note;

        // Load narrative fields from JSON payload
        if ($this->submission->narrative_payload) {
            $narrative = $this->submission->narrative_payload;
            $this->strongest_advantages = $narrative['strongest_advantages'] ?? null;
            $this->biggest_weaknesses = $narrative['biggest_weaknesses'] ?? null;
            $this->best_use_case = $narrative['best_use_case'] ?? null;
            $this->compatibility_notes = $narrative['compatibility_notes'] ?? null;
            $this->training_notes = $narrative['training_notes'] ?? null;
            $this->safety_concerns = $narrative['safety_concerns'] ?? null;
            $this->additional_comments = $narrative['additional_comments'] ?? null;
        }
    }

    protected function checkCanSubmit(): void
    {
        // Must have ratings for all criteria
        foreach ($this->criteria as $id => $criterion) {
            if (!isset($this->ratings[$id]) || $this->ratings[$id] === '') {
                $this->canSubmit = false;
                return;
            }
        }

        // Must have recommendation
        if (empty($this->advance_recommendation)) {
            $this->canSubmit = false;
            return;
        }

        // Must have confidence level
        if (empty($this->confidence_level)) {
            $this->canSubmit = false;
            return;
        }

        // If deal-breaker, must have note
        if ($this->has_deal_breaker && empty($this->deal_breaker_note)) {
            $this->canSubmit = false;
            return;
        }

        $this->canSubmit = true;
    }

    public function updatedRatings(): void
    {
        $this->checkCanSubmit();
    }

    public function updatedNotes(): void
    {
        $this->checkCanSubmit();
    }

    public function updatedAdvanceRecommendation(): void
    {
        $this->checkCanSubmit();
    }

    public function updatedConfidenceLevel(): void
    {
        $this->checkCanSubmit();
    }

    public function updatedHasDealBreaker(): void
    {
        $this->checkCanSubmit();
    }

    public function updatedDealBreakerNote(): void
    {
        $this->checkCanSubmit();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getFormSchema())
            ->statePath('*');
    }

    protected function getFormSchema(): array
    {
        $schema = [];

        // Instructions Section
        $schema[] = \Filament\Forms\Components\Section::make('Evaluator Briefing')
            ->description($this->instructions)
            ->collapsed(false)
            ->columns(1);

        // Product Info Header
        $schema[] = \Filament\Forms\Components\Section::make('Product Information')
            ->schema([
                \Filament\Forms\Components\TextInput::make('product_name')
                    ->label('Product Name')
                    ->default($this->product?->name)
                    ->disabled(),
                \Filament\Forms\Components\TextInput::make('product_manufacturer')
                    ->label('Manufacturer')
                    ->default($this->product?->manufacturer)
                    ->disabled(),
                \Filament\Forms\Components\TextInput::make('product_model')
                    ->label('Model')
                    ->default($this->product?->model)
                    ->disabled(),
                \Filament\Forms\Components\TextInput::make('assessment_profile_display')
                    ->label('Evaluation Profile')
                    ->default(UniversalEvaluationRubric::getAssessmentProfiles()[$this->assessmentProfile] ?? 'Generic')
                    ->disabled(),
            ])
            ->columns(2);

        // Rating Scale Legend
        $schema[] = \Filament\Forms\Components\Section::make('Rating Scale')
            ->schema([
                \Filament\Forms\Components\Placeholder::make('rating_legend')
                    ->label('')
                    ->content('5 = Outstanding | 4 = Strong | 3 = Acceptable | 2 = Below Expectations | 1 = Unacceptable | N/A = Not Applicable'),
            ]);

        // SAVER Category Sections
        $schema = array_merge($schema, $this->getSaverSectionSchemas());

        // Decision Section
        $schema[] = \Filament\Forms\Components\Section::make('Evaluation Decision')
            ->schema([
                \Filament\Forms\Components\Select::make('advance_recommendation')
                    ->label('Advance this item to finalist consideration?')
                    ->options(UniversalEvaluationRubric::getRecommendationOptions())
                    ->required()
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Select::make('confidence_level')
                    ->label('Confidence in my evaluation')
                    ->options(UniversalEvaluationRubric::getConfidenceOptions())
                    ->required()
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Toggle::make('has_deal_breaker')
                    ->label('Is there any deal-breaker?')
                    ->disabled($this->isReadOnly)
                    ->reactive(),
                    
                \Filament\Forms\Components\Textarea::make('deal_breaker_note')
                    ->label('Deal-breaker details')
                    ->visible(fn () => $this->has_deal_breaker)
                    ->required(fn () => $this->has_deal_breaker)
                    ->rows(2)
                    ->disabled($this->isReadOnly),
            ])
            ->columns(2);

        // Narrative Section
        $schema[] = \Filament\Forms\Components\Section::make('Evaluation Narrative')
            ->schema([
                \Filament\Forms\Components\Textarea::make('strongest_advantages')
                    ->label('Strongest Advantages')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Textarea::make('biggest_weaknesses')
                    ->label('Biggest Weaknesses')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Textarea::make('best_use_case')
                    ->label('Best Use Case on the Ladder Truck')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Textarea::make('compatibility_notes')
                    ->label('Compatibility / Mounting Notes')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Textarea::make('training_notes')
                    ->label('Training Notes')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Textarea::make('safety_concerns')
                    ->label('Safety Concerns')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
                    
                \Filament\Forms\Components\Textarea::make('additional_comments')
                    ->label('Additional Comments')
                    ->rows(2)
                    ->disabled($this->isReadOnly),
            ]);

        // Actions Section (only if not read-only)
        if (!$this->isReadOnly) {
            $schema[] = \Filament\Forms\Components\Section::make('Actions')
                ->schema([
                    \Filament\Forms\Components\Actions\Action::make('saveDraft')
                        ->label('Save as Draft')
                        ->action('saveDraft')
                        ->color('gray'),
                    \Filament\Forms\Components\Actions\Action::make('submit')
                        ->label('Submit Evaluation')
                        ->action('submitEvaluation')
                        ->requiresConfirmation()
                        ->disabled(!$this->canSubmit)
                        ->color('success'),
                ]);
        }

        return $schema;
    }

    protected function getSaverSectionSchemas(): array
    {
        $schemas = [];
        
        $bucketConfig = [
            'capability' => ['title' => 'Capability (30%)', 'icon' => 'heroicon-o-cpu-chip', 'description' => 'Core performance, safety, and effectiveness'],
            'usability' => ['title' => 'Usability (30%)', 'icon' => 'heroicon-o-hand-thumb-up', 'description' => 'Ergonomics, ease of use, and portability'],
            'affordability' => ['title' => 'Affordability (20%)', 'icon' => 'heroicon-o-currency-dollar', 'description' => 'Cost effectiveness and value'],
            'maintainability' => ['title' => 'Maintainability (15%)', 'icon' => 'heroicon-o-wrench-screwdriver', 'description' => 'Service, support, and upkeep'],
            'deployability' => ['title' => 'Deployability (5%)', 'icon' => 'heroicon-o-truck', 'description' => 'Ready-to-use and logistics'],
        ];

        foreach ($this->criteriaByBucket as $bucket => $criteria) {
            if (empty($criteria)) {
                continue;
            }

            $config = $bucketConfig[$bucket] ?? ['title' => ucfirst($bucket), 'icon' => 'heroicon-o-star', 'description' => ''];
            
            $criterionFields = [];
            
            foreach ($criteria as $id => $criterion) {
                $criterionFields[] = \Filament\Forms\Components\Group::make([
                    \Filament\Forms\Components\TextInput::make('criterion_label_' . $id)
                        ->label($criterion['name'])
                        ->default($criterion['description'])
                        ->disabled()
                        ->columnSpanFull(),
                    \Filament\Forms\Components\Select::make('ratings.' . $id)
                        ->label('Rating')
                        ->options(UniversalEvaluationRubric::getRatingOptions())
                        ->required()
                        ->disabled($this->isReadOnly)
                        ->columnSpan(1),
                    \Filament\Forms\Components\TextInput::make('notes.' . $id)
                        ->label('Notes (optional)')
                        ->disabled($this->isReadOnly)
                        ->columnSpan(1),
                ])->columns(2);
            }

            $schemas[] = \Filament\Forms\Components\Section::make($config['title'])
                ->description($config['description'])
                ->icon($config['icon'])
                ->schema($criterionFields)
                ->collapsible();
        }

        return $schemas;
    }

    public function saveDraft(): void
    {
        $this->saveRubricData();

        Notification::make()
            ->title('Draft Saved')
            ->body('Your evaluation has been saved as a draft.')
            ->success()
            ->send();
    }

    public function submitEvaluation(): void
    {
        if (!$this->canSubmit) {
            Notification::make()
                ->title('Incomplete Evaluation')
                ->body('Please complete all required fields before submitting.')
                ->danger()
                ->send();
            return;
        }

        $this->saveRubricData();

        try {
            $this->submission->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
            
            $this->submission->refresh();
            $this->isReadOnly = true;

            Notification::make()
                ->title('Evaluation Submitted')
                ->body('Your evaluation has been submitted successfully.')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Submission Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function saveRubricData(): void
    {
        if (!$this->submission) {
            return;
        }

        // Calculate scores using the rubric
        $ratings = array_filter($this->ratings, fn($v) => $v !== '' && $v !== null);
        $scores = UniversalEvaluationRubric::calculateAllScores($ratings);

        // Prepare criterion payload
        $criterionPayload = [
            'ratings' => $this->ratings,
            'notes' => $this->notes ?? [],
        ];

        // Prepare narrative payload
        $narrativePayload = [
            'strongest_advantages' => $this->strongest_advantages,
            'biggest_weaknesses' => $this->biggest_weaknesses,
            'best_use_case' => $this->best_use_case,
            'compatibility_notes' => $this->compatibility_notes,
            'training_notes' => $this->training_notes,
            'safety_concerns' => $this->safety_concerns,
            'additional_comments' => $this->additional_comments,
        ];

        // Update submission
        $this->submission->update([
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
            'criterion_payload' => $criterionPayload,
            'narrative_payload' => $narrativePayload,
        ]);

        $this->submission->refresh();
    }

    protected function getCurrentMember(): ?WorkgroupMember
    {
        $user = Auth::user();
        
        return WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['workgroup.sessions'])
            ->first();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        $member = WorkgroupMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return $member !== null;
    }
}

<?php

namespace App\Filament\Workgroup\Pages;

use App\Models\CandidateProduct;
use App\Models\EvaluationComment;
use App\Models\EvaluationScore;
use App\Models\EvaluationSubmission;
use App\Models\EvaluationTemplate;
use App\Models\WorkgroupMember;
use App\Services\Workgroup\EvaluationService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class EvaluationFormPage extends Page
{
    use InteractsWithForms;

    protected static string $view = 'filament-workgroup::pages.evaluation-form';

    protected static ?string $title = 'Evaluate Product';
    
    protected static ?string $navigationLabel = 'Evaluate';

    public ?int $productId = null;
    
    public ?int $submissionId = null;
    
    public ?string $comment = null;
    
    public array $scores = [];
    
    public array $criteriaData = [];
    
    public ?CandidateProduct $product = null;
    
    public ?EvaluationSubmission $submission = null;
    
    public ?WorkgroupMember $member = null;
    
    public array $criteria = [];
    
    public bool $isReadOnly = false;
    
    public bool $canSubmit = false;

    protected EvaluationService $evaluationService;

    public function mount(int $productId): void
    {
        $this->evaluationService = new EvaluationService();
        $this->productId = $productId;
        
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
        $this->product = CandidateProduct::with(['category', 'session', 'category.templates' => function ($q) {
            $q->active();
        }])->findOrFail($this->productId);

        // Get or create draft submission
        $this->submission = $this->evaluationService->getOrCreateDraft($this->member, $this->productId);
        $this->submissionId = $this->submission->id;

        // Check if already submitted (read-only mode)
        if ($this->submission->isSubmitted()) {
            $this->isReadOnly = true;
        }

        // Load criteria from template
        $template = $this->product->category->templates->first();
        
        if ($template) {
            $this->criteria = $template->criteria()->orderBy('display_order')->get()->toArray();
            
            // Initialize scores from existing submission
            foreach ($this->criteria as $criterion) {
                $existingScore = $this->submission->scores
                    ->where('criterion_id', $criterion['id'])
                    ->first();
                
                $this->scores[$criterion['id']] = $existingScore?->score ?? '';
            }
        }

        // Load existing comment
        $existingComment = EvaluationComment::where('submission_id', $this->submission->id)->first();
        $this->comment = $existingComment?->comment ?? '';

        // Check if can submit (all criteria scored)
        $this->checkCanSubmit();
    }

    protected function checkCanSubmit(): void
    {
        $this->canSubmit = true;
        
        foreach ($this->criteria as $criterion) {
            if (!isset($this->scores[$criterion['id']]) || $this->scores[$criterion['id']] === '') {
                $this->canSubmit = false;
                break;
            }
        }
    }

    public function updatedScores(): void
    {
        $this->checkCanSubmit();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getFormSchema())
            ->statePath('scores');
    }

    protected function getFormSchema(): array
    {
        $schema = [];

        // Product Info Header
        $schema[] = \Filament\Forms\Components\Section::make('Product Information')
            ->schema([
                \Filament\Forms\Components\TextEntry::make('product.name')
                    ->label('Product Name'),
                \Filament\Forms\Components\TextEntry::make('product.manufacturer')
                    ->label('Manufacturer'),
                \Filament\Forms\Components\TextEntry::make('product.model')
                    ->label('Model'),
                \Filament\Forms\Components\TextEntry::make('product.category.name')
                    ->label('Category'),
            ])
            ->columns(2);

        // Criteria Section
        if (!empty($this->criteria)) {
            $criteriaFields = [];

            foreach ($this->criteria as $criterion) {
                $criteriaFields[] = \Filament\Forms\Components\Group::make([
                    \Filament\Forms\Components\TextEntry::make('criterion_name_' . $criterion['id'])
                        ->label($criterion['name'])
                        ->default($criterion['description'] ?? '')
                        ->columnSpanFull(),
                    \Filament\Forms\Components\TextInput::make('scores.' . $criterion['id'])
                        ->label('Score (0-' . $criterion['max_score'] . ')')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue($criterion['max_score'])
                        ->helperText('Weight: ' . $criterion['weight'])
                        ->disabled($this->isReadOnly),
                ])->columns(2);
            }

            $schema[] = \Filament\Forms\Components\Section::make('Evaluation Criteria')
                ->schema($criteriaFields);
        }

        // Comments Section
        $schema[] = \Filament\Forms\Components\Section::make('Additional Comments')
            ->schema([
                Textarea::make('comment')
                    ->label('Comments (Optional)')
                    ->rows(4)
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

    public function saveDraft(): void
    {
        $this->saveScores();
        $this->saveComment();

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
                ->body('Please complete all criteria before submitting.')
                ->danger()
                ->send();
            return;
        }

        $this->saveScores();
        $this->saveComment();

        try {
            $this->submission = $this->evaluationService->submitEvaluation($this->submission);
            
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

    protected function saveScores(): void
    {
        $filteredScores = array_filter($this->scores, fn($value) => $value !== '' && $value !== null);
        
        $this->submission = $this->evaluationService->saveScores($this->submission, $filteredScores);
    }

    protected function saveComment(): void
    {
        EvaluationComment::updateOrCreate(
            ['submission_id' => $this->submission->id],
            ['comment' => $this->comment ?? '']
        );
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
}"

<?php

declare(strict_types=1);

namespace App\Services\AiAutomation;

/** The engine's decision for one submission: the exact text plus why it was chosen. */
final readonly class ComposedReply
{
    public const RECEIPT_ONLY = 'receipt_only';

    public const RECEIPT_PLUS_GUIDANCE = 'receipt_plus_template_guidance';

    public const APPROVED_RAG_GUIDANCE = 'approved_rag_guidance';

    public const RECEIPT_AND_HUMAN_FLAG = 'receipt_and_human_flag';

    /**
     * @param  'routine'|'elevated'|'critical'  $riskTier
     * @param  list<string>  $reasonCodes
     * @param  list<array{article_id: int, version_id: int, version: int}>  $knowledgeRefs
     */
    public function __construct(
        public string $decision,
        public string $text,
        public string $riskTier,
        public bool $needsHuman,
        public ?string $templateKey,
        public ?int $templateVersion,
        public array $reasonCodes = [],
        public array $knowledgeRefs = [],
        public ?int $modelCallId = null,
    ) {}
}

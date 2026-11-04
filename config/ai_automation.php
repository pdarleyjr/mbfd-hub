<?php

declare(strict_types=1);

/*
 * AI Response automation (docs/ai-response-automation.md).
 *
 * The admin master switch (AI Response Automation page) is the everyday ON/OFF. Everything here is a
 * server-side ceiling: AI_AUTOMATION_HARD_DISABLED=true turns the whole feature off whatever the switch says.
 * Templates and word lists below are only first-install defaults; the database copies, edited on the
 * Response Playbooks screen, are what the engine uses.
 *
 * What is LOW, MEDIUM or HIGH is NOT decided here. The equipment catalog (support_equipment_rules, read through
 * App\Services\SupportTriage\Contracts\EquipmentClassifier) is the one opinion; the templates below only say
 * what a member is told for each result.
 */
return [
    'hard_disabled' => (bool) env('AI_AUTOMATION_HARD_DISABLED', false),

    // What members see as the sender (owner decision 2026-11-03: never an 'AI' title). Staff screens add a marker.
    'sender_label' => 'Support Services',
    'staff_label' => 'Support Services (automated)',

    'timezone' => 'America/New_York',

    // Only these three areas. Anything else is out of scope by design.
    'domains' => [
        'vehicle' => true,
        'station' => true,
        'support' => true,
    ],

    'adapters' => [
        App\Services\AiAutomation\Domains\VehicleAdapter::class,
        App\Services\AiAutomation\Domains\StationAdapter::class,
        App\Services\AiAutomation\Domains\SupportServicesAdapter::class,
    ],

    'queue' => [
        'connection' => env('AI_AUTOMATION_QUEUE_CONNECTION'),
        'live' => 'ai-automation',
        'backlog' => 'ai-backlog',
    ],

    'processing' => [
        'max_attempts' => 3,
        'lease_seconds' => 120,
        'retry_delay_seconds' => 60,
        // At most one routine automated reply per conversation in this window; a new high-priority or hazard report is still answered.
        'thread_cooldown_minutes' => 120,
    ],

    'backlog' => [
        'batch_size' => (int) env('AI_AUTOMATION_BACKLOG_BATCH', 10),
        'batch_delay_seconds' => (int) env('AI_AUTOMATION_BACKLOG_DELAY', 20),
        // Optional: skip submissions older than this many days. Empty (the default) includes every open item,
        // because the owner asked for all unattended submissions, not only recent ones.
        'max_age_days' => env('AI_AUTOMATION_BACKLOG_MAX_AGE_DAYS') !== null ? (int) env('AI_AUTOMATION_BACKLOG_MAX_AGE_DAYS') : null,
        // Preview scans at most this many candidates per source type.
        'preview_cap_per_type' => 2000,
    ],

    // Optional Workers AI composition. It is used only to answer a member's question (or free text the catalog
    // cannot classify) when a published Knowledge Library article strongly matches. Statements about equipment are
    // answered by the templates below with no model call. Off until a scoped token exists.
    'model' => [
        'enabled' => (bool) env('AI_AUTOMATION_MODEL_ENABLED', false),
        'account_id' => env('AI_AUTOMATION_CF_ACCOUNT_ID'),
        'api_token' => env('AI_AUTOMATION_CF_API_TOKEN'),
        // Benchmark 2026-11-03 (27 anonymized samples): Llama 3.3 70B fast was the most accurate (85%), fastest (p50 1.0 s)
        // and needs no reasoning tokens; gpt-oss-120b scored 59%. Re-run ai-automation:benchmark before changing.
        'model' => env('AI_AUTOMATION_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
        'timeout_seconds' => (int) env('AI_AUTOMATION_MODEL_TIMEOUT', 30),
        // gpt-oss reasons before answering and bills reasoning as output; 700 leaves room for a short reply.
        'max_output_tokens' => (int) env('AI_AUTOMATION_MODEL_MAX_TOKENS', 700),
        // null sends nothing. none|low|medium|high|max where the model supports it (DeepSeek accepts none).
        'reasoning_effort' => env('AI_AUTOMATION_MODEL_REASONING'),
        'daily_call_limit' => (int) env('AI_AUTOMATION_MODEL_DAILY_CALLS', 100),
        'monthly_budget_usd' => (float) env('AI_AUTOMATION_MODEL_MONTHLY_USD', 10),

        // USD per million tokens by model id, from https://developers.cloudflare.com/workers-ai/platform/pricing/
        // (page dated 2026-10-20, read 2026-11-03). Switching `model` above always switches the meter with it.
        // A model that is not listed here is refused (no request leaves the server) until it is priced.
        'prices' => [
            '@cf/openai/gpt-oss-120b' => ['in' => 0.35, 'out' => 0.75],
            '@cf/openai/gpt-oss-20b' => ['in' => 0.20, 'out' => 0.30],
            '@cf/google/gemma-4-26b-a4b-it' => ['in' => 0.10, 'out' => 0.30],
            '@cf/google/gemma-3-12b-it' => ['in' => 0.345, 'out' => 0.556],
            '@cf/qwen/qwen3-30b-a3b-fp8' => ['in' => 0.051, 'out' => 0.335],
            '@cf/qwen/qwen3.8-27b' => ['in' => 0.45, 'out' => 3.20],
            '@cf/meta/llama-3.3-70b-instruct-fp8-fast' => ['in' => 0.293, 'out' => 2.253],
            '@cf/meta/llama-3.1-8b-instruct-fp8-fast' => ['in' => 0.045, 'out' => 0.384],
            '@cf/zai-org/glm-4.7-flash' => ['in' => 0.06, 'out' => 0.40],
            '@cf/ibm-granite/granite-4.0-h-micro' => ['in' => 0.017, 'out' => 0.112],
        ],
    ],

    'reply' => [
        'max_characters' => 320,
        'max_sentences' => 3,
        // A weekend line is added only when the submission itself is this recent, never on old backlog items.
        'weekend_line_max_age_hours' => 48,
    ],

    // Knowledge Library retrieval. Synonym groups: when a message uses any phrase of a group, the other phrases
    // of that group count (at half weight) when matching articles. The equipment catalog's names and aliases are
    // added automatically, so "multigas" finds an article that says "gas meter".
    'retrieval' => [
        'synonyms' => [
            ['scba', 'air pack', 'airpack', 'breathing apparatus', 'self contained breathing apparatus'],
            ['cylinder', 'bottle', 'air bottle', 'tank'],
            ['bunker gear', 'turnouts', 'turnout gear', 'structural gear', 'pbi'],
            ['gas meter', 'multigas', 'multi gas', 'four gas', 'gas monitor', 'detector', 'co monitor'],
            ['lifepak', 'life pak', 'cardiac monitor', 'monitor defibrillator', 'defibrillator', 'aed'],
            ['dewalt', 'battery', 'batteries', 'flexvolt', 'tool battery'],
            ['kitty litter', 'absorbent', 'speedy dry', 'spill absorbent'],
            ['replacement', 'spare', 'swap', 'swapped out', 'replace'],
            ['station 2', 'supply room', 'logistics', 'support services'],
            ['a c', 'ac', 'air conditioner', 'air conditioning', 'hvac'],
            ['checkout', 'check out', 'daily inspection', 'vehicle checkout', 'inspection'],
            ['300', 'division chief', 'chief on duty'],
        ],
        // Plain-language words for the catalog categories (EquipmentClassification::CATEGORIES).
        'category_labels' => [
            'consumable' => 'consumable supplies',
            'hand_tool' => 'hand tool',
            'battery' => 'battery',
            'power_tool' => 'power tool',
            'specialized_tool' => 'specialized tool',
            'detection' => 'gas meter detector',
            'scba_unit' => 'scba air pack',
            'scba_facepiece' => 'scba facepiece mask',
            'scba_component' => 'scba component',
            'scba_cylinder' => 'scba cylinder',
            'ppe' => 'protective gear ppe',
            'rescue_medical' => 'medical rescue equipment',
            'vehicle_component' => 'vehicle component',
            'station_facility' => 'station facility',
            'electronics' => 'electronics',
        ],
    ],

    // Owner decisions 2026-11-03. Which template a report gets is decided by the equipment catalog result
    // (level, handler, category, hazard) in ResponseComposer; the words are edited on the Playbooks screen.
    'templates' => [
        'receipt' => [
            'label' => 'Acknowledgment',
            'help' => 'Default reply when nothing more is useful.',
            'body' => 'Acknowledged.',
        ],
        'receipt_forwarded' => [
            'label' => 'Acknowledgment, forwarded',
            'help' => 'Station requests that reached the staff work list.',
            'body' => 'Acknowledged. Forwarded to the appropriate personnel.',
        ],
        'missing_low' => [
            'label' => 'LOW: one missing item',
            'help' => 'Low-cost items. {item} is replaced with the item name from the checkout.',
            'body' => 'Please check around the station and other apparatus for the {item} first. If nothing turns up, call or text before coming by Station 2 so we can check on a replacement.',
        ],
        'missing_low_multi' => [
            'label' => 'LOW: several missing items',
            'help' => '{item} is replaced with the item names, e.g. "spanner wrench, kitty litter and K-tool".',
            'body' => "Please check around the station and other apparatus to make sure the {item} weren't misplaced. If anything is still missing, call or text before coming by Station 2 so we can check on replacements.",
        ],
        'missing_battery_dewalt' => [
            'label' => 'LOW: missing DeWalt battery',
            'help' => 'DeWalt batteries are often on a charger or in another tool.',
            'body' => "Check the chargers and other DeWalt tools, including the station blower. If it's still missing, call or text before coming by Station 2 so we can check on a replacement.",
        ],
        'missing_battery' => [
            'label' => 'LOW: missing battery (other brands)',
            'help' => '{item} is replaced with the battery name from the checkout.',
            'body' => "Check the chargers and other tools, including the station blower, to make sure the {item} wasn't left behind. If it's still missing, call or text before coming by Station 2 so we can check on a replacement.",
        ],
        'battery_line' => [
            'label' => 'LOW: battery reminder (added to several missing items)',
            'help' => 'Added when one of several missing items is a battery.',
            'body' => 'Check chargers and other tools, such as the station blower, for any batteries.',
        ],
        'damaged_low' => [
            'label' => 'LOW: damaged item',
            'help' => 'Damaged low-priority equipment.',
            'body' => "Acknowledged. We'll follow up if needed.",
        ],
        'medium_tool' => [
            'label' => 'MEDIUM: expensive tool or item',
            'help' => 'Handled by Support Services. Also used for items the catalog does not know yet.',
            'body' => 'Acknowledged. Call or text before coming by Station 2 so we can check whether a replacement is available.',
        ],
        'medium_urgent_line' => [
            'label' => 'MEDIUM: needed right away (added when the member says so)',
            'help' => 'Added to the expensive-tool reply when the report uses urgent words.',
            'body' => "If it's needed right away, notify your officer and coordinate through 300.",
        ],
        'medium_detection' => [
            'label' => 'MEDIUM: gas meter or detection device',
            'help' => 'Gas meters and detectors.',
            'body' => 'Acknowledged. Coordinate a replacement gas meter or detector with Support Services or the appropriate equipment technician. Call or text first so availability can be confirmed.',
        ],
        'medium_air_tech' => [
            'label' => 'MEDIUM: SCBA component or cylinder (Air Tech)',
            'help' => 'Breathing apparatus parts handled by the on-duty Air Tech.',
            'body' => 'Acknowledged. Coordinate with the on-duty Air Tech to have the item checked or swapped out. Notify your officer and contact 300 if immediate assistance or after-hours coordination is needed.',
        ],
        'medium_cylinder_leak' => [
            'label' => 'MEDIUM: leaking SCBA cylinder',
            'help' => 'A cylinder that is leaking.',
            'body' => 'Acknowledged. Remove the leaking cylinder from use and contact the on-duty Air Tech to have it assessed or swapped out. Notify your officer, and coordinate through 300 if immediate or after-hours assistance is needed.',
        ],
        'captain5_medium' => [
            'label' => 'MEDIUM: Captain 5 equipment (Lifepak and similar)',
            'help' => 'EMS equipment handled by Captain 5.',
            'body' => 'Acknowledged. Coordinate with Captain 5 to arrange a replacement or pickup. Confirm availability before coming by.',
        ],
        'captain5_low' => [
            'label' => 'LOW: Captain 5 supplies (splints, backboards)',
            'help' => 'EMS supplies handled by Captain 5.',
            'body' => 'Acknowledged. Contact Captain 5 to coordinate a replacement and pickup.',
        ],
        'high_ppe' => [
            'label' => 'HIGH: protective equipment',
            'help' => 'SCBA units, facepieces, hoods, helmets, bunker coat and pants.',
            'body' => 'Acknowledged. Notify your officer immediately and coordinate with Support Services or 300 for further direction.',
        ],
        'readiness' => [
            'label' => 'HIGH or hazard: readiness override',
            'help' => 'A hazard, or an item that affects readiness.',
            'body' => 'Acknowledged. Notify your officer and 300 right away.',
        ],
        'hazard_station' => [
            'label' => 'Station hazard',
            'help' => 'Station requests that describe a hazard.',
            'body' => 'Acknowledged. If there is an immediate hazard, notify your officer and 300 right away.',
        ],
        'weekend_line' => [
            'label' => 'Weekend reminder',
            'help' => 'Added on Saturday and Sunday (Eastern time) to LOW and MEDIUM replies handled by Support Services, only for submissions made in the last 48 hours.',
            'body' => "Support Services staff are off this weekend. If this can't wait, contact 300. Otherwise we'll follow up on the next weekday.",
        ],
    ],

    // Defaults retired by the 2026-11-03 catalog rewrite. Unedited copies (never changed since the repository
    // seeded them) are removed on the next seed; an admin-edited copy is left alone.
    'retired_playbooks' => [
        'missing_item', 'missing_items', 'damaged_item', 'readiness_line',
        'safety_terms', 'expensive_terms', 'battery_terms',
    ],

    // One term per line on the Playbooks screen. Matching is case-insensitive on whole words or phrases;
    // a trailing * matches any word starting with the term.
    'lists' => [
        'urgency_terms' => [
            'label' => 'Needed right away',
            'help' => 'Reports with these words get the "coordinate through 300" line, and urgent Support Services messages get the weekend reminder on Saturday and Sunday.',
            'terms' => ['asap', 'urgent', 'urgently', 'immediately', 'right away', 'right now', 'need it now', 'need one now', 'need them now', 'needed now', 'cannot wait', "can't wait", 'today', 'tonight', 'tomorrow', 'this shift', 'this weekend'],
        ],
        'bunker_dropoff_terms' => [
            'label' => 'Bunker gear drop-off',
            'help' => 'Support Services messages that report gear left for pickup.',
            'terms' => [
                'bunker gear', 'bunker coat', 'bunker pants', 'turnout gear', 'turnouts', 'my gear',
                'gear dropped off', 'dropped off my gear', 'dropped off gear', 'left my gear', 'left gear',
                'gear for cleaning', 'gear for repair', 'gear to be cleaned', 'gear for pickup', 'gear for pick up',
                'gray bin', 'grey bin', 'gear bin',
            ],
        ],
        // A drop-off message that also mentions one of these keeps its human flag.
        'exposure_terms' => [
            'label' => 'Exposure or contamination',
            'help' => 'Bunker gear messages with these words are flagged for staff.',
            'terms' => ['contaminat*', 'exposure', 'exposed', 'blood', 'bodily', 'body fluid', 'fluid', 'carcinogen*', 'asbestos', 'hazmat', 'smoke', 'fire', 'torn', 'rip', 'ripped', 'burn*', 'melt*', 'damage*'],
        ],
    ],
];

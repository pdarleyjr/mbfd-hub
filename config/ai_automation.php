<?php

declare(strict_types=1);

/*
 * AI Response automation (docs/ai-response-automation.md).
 *
 * The admin master switch (AI Response Automation page) is the everyday ON/OFF. Everything here is a
 * server-side ceiling: AI_AUTOMATION_HARD_DISABLED=true turns the whole feature off whatever the switch says.
 * Templates and word lists below are only first-install defaults; the database copies, edited on the
 * Response Playbooks screen, are what the engine uses.
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
        // At most one AI Response per conversation in this window, however many messages the member sends.
        'thread_cooldown_hours' => 24,
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

    // Optional Workers AI composition for approved low-risk questions. Off until a scoped token exists.
    'model' => [
        'enabled' => (bool) env('AI_AUTOMATION_MODEL_ENABLED', false),
        'account_id' => env('AI_AUTOMATION_CF_ACCOUNT_ID'),
        'api_token' => env('AI_AUTOMATION_CF_API_TOKEN'),
        'model' => env('AI_AUTOMATION_MODEL', '@cf/openai/gpt-oss-120b'),
        'timeout_seconds' => (int) env('AI_AUTOMATION_MODEL_TIMEOUT', 30),
        // gpt-oss reasons before answering and bills reasoning as output; 700 leaves room for a short reply.
        'max_output_tokens' => (int) env('AI_AUTOMATION_MODEL_MAX_TOKENS', 700),
        // null sends nothing. none|low|medium|high|max where the model supports it (DeepSeek accepts none).
        'reasoning_effort' => env('AI_AUTOMATION_MODEL_REASONING'),
        'daily_call_limit' => (int) env('AI_AUTOMATION_MODEL_DAILY_CALLS', 100),
        'monthly_budget_usd' => (float) env('AI_AUTOMATION_MODEL_MONTHLY_USD', 10),
        // USD per million tokens, used to meter spend before Cloudflare's own bill arrives.
        'price_input_per_million' => (float) env('AI_AUTOMATION_MODEL_PRICE_IN', 0.35),
        'price_output_per_million' => (float) env('AI_AUTOMATION_MODEL_PRICE_OUT', 0.75),
    ],

    'reply' => [
        'max_characters' => 320,
        'max_sentences' => 3,
    ],

    // Owner decisions 2026-11-03: every missing vehicle item gets the "look for it first" reply naming the item;
    // members call or text before heading to Station 2; weekends have no admin staff, so 300 is the contact.
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
        'missing_item' => [
            'label' => 'One missing vehicle item',
            'help' => '{item} is replaced with the item name from the checkout.',
            'body' => "Acknowledged. Please make sure the {item} hasn't been misplaced and isn't somewhere around the station, on another apparatus, or left on a spare truck during a recent truck swap. If it's still missing, call or text us before heading to Station 2 so we can confirm we have a spare ready.",
        ],
        'missing_items' => [
            'label' => 'Several missing vehicle items',
            'help' => '{item} is replaced with the item names, e.g. "spanner wrench, kitty litter and K-tool".',
            'body' => "Acknowledged. Please make sure the {item} haven't been misplaced and aren't somewhere around the station, on another apparatus, or left on a spare truck during a recent truck swap. If anything is still missing, call or text us before heading to Station 2 so we can confirm we have spares ready.",
        ],
        'missing_battery' => [
            'label' => 'One missing battery',
            'help' => 'Batteries are often on a charger or in another tool.',
            'body' => "Acknowledged. Please make sure the {item} wasn't left on a charger or in another tool, such as the station blower, and isn't somewhere around the station, on another apparatus, or on a spare truck from a recent truck swap. If it's still missing, call or text us before heading to Station 2 so we can confirm we have a spare ready.",
        ],
        'battery_line' => [
            'label' => 'Battery reminder (added to several missing items)',
            'help' => 'Added when one of several missing items is a battery.',
            'body' => 'Check chargers and other tools, such as the station blower, for any batteries.',
        ],
        'damaged_item' => [
            'label' => 'Damaged vehicle item',
            'help' => 'Damaged equipment that is not on the readiness list.',
            'body' => "Acknowledged. We'll follow up if needed.",
        ],
        'readiness_line' => [
            'label' => 'Readiness reminder',
            'help' => 'Added on weekdays when the item is on the readiness list or marked significant.',
            'body' => "If this affects your unit's readiness, notify your officer and 300 right away.",
        ],
        'weekend_line' => [
            'label' => 'Weekend reminder',
            'help' => 'Added on Saturday and Sunday (Eastern time), when no Support Services staff are on duty.',
            'body' => "Support Services staff are off this weekend. If this can't wait, contact 300. Otherwise we'll follow up on the next weekday.",
        ],
    ],

    // One term per line on the Playbooks screen. Matching is case-insensitive on whole words or phrases;
    // a trailing * matches any word starting with the term.
    'lists' => [
        'safety_terms' => [
            'label' => 'Readiness and safety items',
            'help' => 'Missing or damaged items here add the readiness reminder, and messages about them never get an AI-written answer.',
            'terms' => [
                // Breathing air, PPE and accountability
                'scba', 'air pack', 'airpack', 'cylinder', 'bottle', 'facepiece', 'face piece', 'mask', 'regulator', 'pass device', 'rit pack',
                'respirator', 'scott pack', 'n95', 'n-95', 'tyvek', 'tyvex', 'safety glasses', 'goggles', 'vest*', 'ballistic', 'gear',
                'bunker', 'turnout', 'helmet*', 'hood', 'glove*', 'boot*', 'ppe', 'sleeves', 'earplug*', 'ladder belt*', 'passport*', 'par tag*', 'accountability', 'contaminat*', 'harness', 'rope', 'carabiner', 'webbing', 'life jacket', 'pfd*',
                // Monitors and detection
                'gas monitor', 'multigas', 'multi-gas', 'four gas', '4 gas', 'co monitor', 'detector', 'meter', 'monitor',
                'sensit', 'drager', 'dreager', 'multirae*', 'h2s', 'rad alert', 'radalert', 'radialert', 'radiation', 'calibrat*', 'hot stick',
                // Medical and EMS (out of scope here; acknowledgment only)
                'oxygen', 'o2', 'lifepak', 'life-pak', 'life pak', 'lucas', 'aed', 'defib', 'suction', 'bvm', 'airway', 'narcotic', 'drug box', 'medication',
                'med box', 'med supply', 'medical', 'trauma', 'handtevy', 'autopulse', 'auto pulse', 'duodote', 'stretcher', 'stair chair', 'stairchair', 'ked',
                'backboard*', 'scoop', 'c-collar*', 'collar*', 'splint*', 'traction', 'head bed*', 'headbed*', 'sharps', 'boo-boo', 'boo boo', 'ob kit*', 'mci', 'sterile', 'patient*',
                // Radios and electronics
                'radio', 'radios', 'portable', 'portables', 'mobile radio', 'headset', 'intercom', 'mdt', 'mdc', 'vhf', 'motorola', 'voice amp*', 'raymarine', 'flir', 'radar', 'sonar', 'thermal', 'tic', 'camera',
                // Warning devices and lighting
                'siren', 'horn', 'light bar', 'lightbar', 'emergency light', 'scene light', 'warning light', 'strobe',
                'light', 'lights', 'lighting', 'flashlight*', 'flash light*', 'headlamp', 'floodlight', 'light tower*', 'portable light*', 'dive light',
                // Vehicle readiness
                'brake*', 'steering', 'tire', 'tyre', 'wheel', 'pump', 'primer', 'aerial', 'ladder', 'outrigger', 'stabilizer', 'jack',
                'engine', 'transmission', 'leak*', 'fluid', 'coolant', 'check engine', 'warning', 'alarm',
                'psi', 'air pressure', 'tire pressure', 'fuel', 'diesel', 'def', 'engine oil', 'oil level', 'oil pressure', 'overheat*', 'plymovent', 'exhaust',
                // Hose, appliances, foam, extinguishers, scene safety
                'hose*', 'nozzle*', 'wye', 'storz', 'siamese', 'reducer', 'increaser', 'double male', 'double female', 'eductor', 'manifold',
                'coupling*', 'gasket*', 'strainer', 'hose clamp*', 'foam', 'extinguisher*', 'flare*', 'triangle*',
                // Rescue, cutting and forcible-entry tools
                'saw', 'extrication', 'jaws', 'spreader', 'cutter*', 'ram', 'combi', 'airbag', 'air bag',
                'sawzall', 'reciprocating', 'k-saw', 'k saw', 'chainsaw', 'chain saw', 'bandsaw', 'band saw', 'grinder*', 'chisel',
                'impact*', 'hydraulic*', 'rabbit', 'k-tool', 'k tool', 'tnt', 'paratech', 'res-q', 'resq', 'strut*',
                'glassmaster', 'glass master', 'center punch', 'halligan', 'irons', 'axe*', 'sledge*', 'crowbar', 'pry bar*', 'come-a-long', 'come a long', 'winch', 'cribbing',
                // Water rescue and marine
                'buoy*', 'torpedo', 'throw bag*', 'life ring', 'dive', 'scuba', 'snorkel', 'fins', 'bilge', 'anchor', 'seafire', 'sea fire',
                // Station and hazard conditions
                'shock', 'electrical', 'spark*', 'smok*', 'fire', 'gas leak', 'gas smell', 'carbon monoxide', 'structural', 'ceiling', 'collapse', 'mold', 'injury', 'injured', 'hurt',
                'sprinkler*', 'gas shut*', 'squeeze off', 'squeeze-off', 'leak bag', 'dike', 'decon', 'hazmat', 'hazardous',
                'sewage', 'sewer', 'flood*', 'pole hole', 'pole-hole', 'gas can*', 'fuel can*', 'asbestos',
            ],
        ],
        'expensive_terms' => [
            'label' => 'Expensive or unique items',
            'help' => 'Plain acknowledgment only.',
            'terms' => [
                'ipad', 'tablet', 'laptop', 'computer', 'tv', 'television', 'generator', 'honda generator', 'ppv', 'fan', 'electric fan', 'gas fan', 'blower motor',
                'battery charger', 'charger', 'drone', 'binoculars', 'gps', 'knox', 'key', 'lock box', 'lockbox', 'gas card', 'fuel card', 'toughbook', 'cad',
                'clicker', 'garage door', 'supervac', 'super vac',
            ],
        ],
        'battery_terms' => [
            'label' => 'Batteries',
            'help' => 'Missing items matching these get the charger / other-tool reminder.',
            'terms' => ['battery', 'batteries', 'dewalt', 'flexvolt', 'm18', 'milwaukee battery', 'makita battery', 'battery pack'],
        ],
        'urgency_terms' => [
            'label' => 'Needed right away',
            'help' => 'Support Services messages with these words get the weekend reminder on Saturday and Sunday.',
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

<?php

declare(strict_types=1);

return [
    /*
    | Checklist templates have no controlled flag, so controlled equipment is recognised by name. A line whose name
    | contains one of these (case-insensitive) is never marked moved by default: it stays pending verification until
    | someone with authority records the separate accountability step.
    */
    'controlled_name_patterns' => ['narcotic', 'med box'],
];

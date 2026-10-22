<?php

declare(strict_types=1);

return [
    'body_max_length' => 10000,
    'subject_max_length' => 160,
    'max_attachments' => 5,
    'max_attachment_bytes' => 15 * 1024 * 1024,
    'page_size' => 20,
    'max_page_size' => 50,
    'search_limit' => 50,
    'search_min_length' => 2,
    'storage_prefix' => 'command-communications',
];

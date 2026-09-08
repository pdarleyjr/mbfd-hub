<?php

return [
    'temporary_file_upload' => [
        // Keep temporary uploads private and independent of the public asset disk.
        'disk' => 'local',
        // Match the existing 50 MB workgroup file limit; individual forms may be stricter.
        'rules' => ['required', 'file', 'max:51200'],
    ],
];

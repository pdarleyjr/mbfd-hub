<?php

declare(strict_types=1);

/*
| Training Tools hosted by the Hub.
|
| command_board: the Command Board training sandbox, a static single-page bundle built in pdarleyjr/mbfd-command and
| vendored here by scripts/training/vendor-command-board-sandbox.mjs (see docs/training/command-board-sandbox.md).
| The page lives under resources/ so only the authenticated route can serve it; its content-hashed assets live
| under public/. Tests point both at a fixture bundle.
*/
return [
    'command_board' => [
        'html_directory' => resource_path('training-tools/command-board'),
        'assets_directory' => public_path('training-tools/command-board/assets'),
    ],
];

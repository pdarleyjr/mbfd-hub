<?php
require "vendor/autoload.php";

\\Sentry\\init(["dsn" => "https://5c59915d36fe82b8f8db7d37c5bb4c0f@o4510757508481024.ingest.us.sentry.io/4510757793628160"]);

try {
    throw new Exception("Manual Sentry Test Exception - " . date("Y-m-d H:i:s"));
} catch (Exception \$e) {
    \\Sentry\\captureException(\$e);
    echo "Exception sent to Sentry: " . \$e->getMessage() . "\\n";
}

\\Sentry\\flush();
echo "Sentry test completed\\n";

<?php

namespace App\Services;

use Chatify\ChatifyMessenger;
use Pusher\Pusher;

/**
 * Override ChatifyMessenger to use internal Reverb endpoint for the PHP Pusher SDK
 * while keeping config('chatify.pusher') frontend-facing (public wss:// values).
 *
 * The Chatify package creates a Pusher PHP SDK instance using config('chatify.pusher.options'),
 * which contains the PUBLIC host (www.mbfdhub.com:443 https) for the browser.
 * But the PHP SDK needs to talk to Reverb INTERNALLY (127.0.0.1:8080 http).
 *
 * This override replaces the Pusher instance with one using backend-specific options.
 */
class ChatifyMessengerOverride extends ChatifyMessenger
{
    public function __construct()
    {
        // Build backend-specific options for the Pusher PHP SDK
        $frontendOptions = config('chatify.pusher.options', []);

        $backendOptions = array_merge($frontendOptions, [
            'host' => env('CHATIFY_BACKEND_PUSHER_HOST', '127.0.0.1'),
            'port' => env('CHATIFY_BACKEND_PUSHER_PORT', env('REVERB_SERVER_PORT', 8080)),
            'scheme' => env('CHATIFY_BACKEND_PUSHER_SCHEME', 'http'),
            'useTLS' => false,
            'encrypted' => false,
        ]);

        $this->pusher = new Pusher(
            config('chatify.pusher.key'),
            config('chatify.pusher.secret'),
            config('chatify.pusher.app_id'),
            $backendOptions,
        );
    }
}

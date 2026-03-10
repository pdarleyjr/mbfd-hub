<script src="https://js.pusher.com/7.2.0/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@3.0.3/dist/index.min.js"></script>
<script >
    // Global Chatify variables from PHP to JS
    // FRONTEND config uses public-facing Reverb host (browser connects via wss://)
    window.chatify = {
        name: "{{ config('chatify.name') }}",
        sounds: {!! json_encode(config('chatify.sounds')) !!},
        allowedImages: {!! json_encode(config('chatify.attachments.allowed_images')) !!},
        allowedFiles: {!! json_encode(config('chatify.attachments.allowed_files')) !!},
        maxUploadSize: {{ Chatify::getMaxUploadSize() }},
        pusher: {
            debug: {{ config('app.debug') ? 'true' : 'false' }},
            key: "{{ config('chatify.pusher.key') }}",
            secret: "{{ config('chatify.pusher.secret') }}",
            app_id: "{{ config('chatify.pusher.app_id') }}",
            options: {
                cluster: "mt1",
                host: "www.mbfdhub.com",
                wsHost: "www.mbfdhub.com",
                port: 443,
                wsPort: 443,
                wssPort: 443,
                scheme: "https",
                encrypted: true,
                useTLS: true,
                forceTLS: true,
                enabledTransports: ['ws', 'wss']
            }
        },
        pusherAuthEndpoint: '{{route("pusher.auth")}}'
    };
    window.chatify.allAllowedExtensions = chatify.allowedImages.concat(chatify.allowedFiles);
</script>
<script src="{{ asset('js/chatify/utils.js') }}"></script>
<script src="{{ asset('js/chatify/code.js') }}"></script>

import type { ConferenceBootstrap } from './types';

export async function subscribeToLineupChanges(
    bootstrap: ConferenceBootstrap,
    onChange: () => void,
): Promise<() => void> {
    if (!bootstrap.realtime.key || !bootstrap.realtime.host) return () => undefined;

    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]);

    const echo = new Echo({
        broadcaster: 'reverb',
        client: Pusher,
        key: bootstrap.realtime.key,
        wsHost: bootstrap.realtime.host,
        wsPort: bootstrap.realtime.port,
        wssPort: bootstrap.realtime.port,
        forceTLS: bootstrap.realtime.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
    echo.channel(bootstrap.realtime.channel).listen('.lineup.changed', onChange);

    return () => {
        echo.leave(bootstrap.realtime.channel);
        echo.disconnect();
    };
}

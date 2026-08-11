export type JoinRole = 'self' | '300' | 'sta1' | 'sta2' | 'sta3' | 'sta4' | 'sta6';
export type RoomMode = 'lineup' | 'direct';
export type ConferencePhase =
    | 'lobby'
    | 'requesting_media'
    | 'ready'
    | 'joining'
    | 'connected'
    | 'reconnecting'
    | 'leaving'
    | 'failed';

export interface RoleOption {
    value: JoinRole;
    label: string;
    station: boolean;
}

export interface ConferenceBootstrap {
    roles: RoleOption[];
    lineup_time: string | null;
    endpoints: {
        sessions: string;
        api_base: string;
    };
    csrf_token: string;
}

export interface SessionResponse {
    session: {
        id: string;
        type: RoomMode;
        target_station: JoinRole | null;
        scheduled_for: string | null;
        lineup_time_configured: boolean;
    };
}

export interface TokenResponse {
    token: string;
    server_url: string;
    expires_at: string;
    participation_id: string;
    participant: {
        identity: string;
        name: string;
        join_as: JoinRole;
    };
}

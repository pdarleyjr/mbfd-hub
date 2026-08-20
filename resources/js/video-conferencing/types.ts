export type JoinRole = 'self' | '300' | 'sta1' | 'sta2' | 'sta3' | 'sta4' | 'sta6';
export type StationRole = Exclude<JoinRole, 'self' | '300'>;
export type RoomMode = 'lineup' | 'direct';
export type EntryMode = 'self' | 'station' | 'command';
export type ConferencePhase =
    | 'requesting_media'
    | 'ready'
    | 'standing_by'
    | 'authorizing'
    | 'joining'
    | 'connected'
    | 'reconnecting'
    | 'leaving'
    | 'failed';

export interface ConferenceBootstrap {
    entry_mode: EntryMode;
    join_as: JoinRole;
    display_name: string;
    launch_context: string | null;
    lineup_time: string | null;
    lineup_max_minutes: number;
    status_poll_ms: number;
    heartbeat_ms: number;
    realtime: {
        key: string;
        host: string;
        port: number;
        scheme: string;
        channel: string;
    };
    endpoints: {
        station_ready: string;
        station_heartbeat: string;
        station_stand_down: string;
        station_status: string;
        station_token: string;
        station_participation_base: string;
        command_authorize: string;
        command_status: string;
        command_start: string;
        command_end: string;
        command_direct: string;
        sessions: string;
        api_base: string;
        connectivity_failures: string;
    };
    csrf_token: string;
}

export interface SessionResponse {
    session: {
        id: string;
        type: RoomMode;
        target_station: StationRole | null;
        scheduled_for: string | null;
        lineup_time_configured: boolean;
    };
}

export interface TokenResponse {
    session?: SessionResponse['session'];
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

export interface StationReadiness {
    join_as: StationRole;
    label: string;
    ready: boolean;
    camera_ready: boolean;
    microphone_ready: boolean;
    ready_at: string | null;
    last_heartbeat_at: string | null;
}

export interface LineupState {
    active: boolean;
    session_id: string | null;
    started_at: string | null;
    ends_at: string | null;
}

export interface StationStatusResponse {
    station: StationReadiness;
    lineup: LineupState;
    direct: LineupState & { type: RoomMode | null; target_station: StationRole | null };
}

export interface CommandStatusResponse {
    provider_api_healthy: boolean;
    lineup: LineupState;
    stations: StationReadiness[];
    participants: Array<{ identity: string; name: string }>;
}

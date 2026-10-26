/**
 * Shapes shared by every "who is filling this role today" surface: the Captain 5 workspace
 * (/api/captain5/*) and the 300 page (/api/command-300/*). Display only; an administrator approves.
 */

export type ShiftLetter = 'A' | 'B' | 'C';

export interface OnDuty {
  duty_date: string;
  shift: ShiftLetter | null;
  /** 'acting' is an approved acting member; 'bid_holder' is the shift's bid holder. */
  source: 'acting' | 'bid_holder' | 'unknown';
  resolved: boolean;
  name: string | null;
  rank: string | null;
  /** ISO time an approved acting member stops being shown. */
  acting_until: string | null;
  acting_request_id: number | null;
}

export type ActingDuration = '12h' | '24h' | 'custom';

/** A request to be shown as (or to stop showing) the acting member of a role. An administrator approves. */
export interface ActingRequest {
  id: number;
  /** 'captain_5' or 'command_300'; older Captain 5 answers omit it. */
  role?: string;
  /** "Captain 5" or "300", as the server labels the role. */
  role_label?: string;
  kind: 'act' | 'end';
  requested_by: string;
  name: string | null;
  rank: string | null;
  duration: ActingDuration | null;
  shift: ShiftLetter | null;
  ends_at: string | null;
  requested_at: string | null;
  own: boolean;
}

export interface ActingState {
  /** Server clock used for custom-end bounds; absent in older cached responses. */
  server_time?: string;
  /** 'captain_5' or 'command_300'; older Captain 5 answers omit it. */
  role?: string;
  /** False hides the "Click here" control. Absent (older Captain 5 answers) means the viewer may ask. */
  can_request?: boolean;
  can_approve: boolean;
  /** The viewer's own request that is waiting for approval. */
  mine: ActingRequest | null;
  /** Everything waiting for approval; filled only for people who can approve. */
  pending: ActingRequest[];
  /** When a 12 or 24 hour stint would end, as the server computes it. */
  choices: { '12h': string | null; '24h': string | null };
}

/** Answer of every acting-request call: what is shown now, and the acting state. */
export interface ActingResult {
  on_duty: OnDuty;
  acting: ActingState;
}

export interface ActingRequestInput {
  kind: 'act' | 'end';
  duration?: ActingDuration;
  /** Custom end, exactly as the datetime-local field gives it; the server reads it as Miami Beach time. */
  ends_at?: string;
  client_request_id: string;
}

/** The four calls an acting control makes; each role binds them to its own endpoint. */
export interface ActingApi {
  requestActing: (input: ActingRequestInput) => Promise<ActingResult>;
  approveActing: (id: number, note?: string) => Promise<ActingResult>;
  rejectActing: (id: number, note: string) => Promise<ActingResult>;
  withdrawActing: (id: number) => Promise<ActingResult>;
}

/** Everything role-specific about the acting UI: wording, test ids, element ids and the endpoint. */
export interface ActingRole {
  /** How the role reads in a sentence: "Captain 5" or "300". */
  label: string;
  /** Show the member's rank before the name on the shift line. */
  withRank: boolean;
  /** Prefix of every data-testid: "captain5" gives captain5-acting-open. */
  testPrefix: string;
  /** Prefix of element ids and the radio group name. */
  idPrefix: string;
  api: ActingApi;
}

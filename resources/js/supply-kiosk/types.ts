// Shapes from docs/ems/supply-room-kiosk.md (sections 3 to 5).
import type { SearchableItem } from './search.ts';

export interface Member { employee_id: string; name: string }
export interface Apparatus { id: number; label: string }
export interface Location { code: string; label: string; sort: number }
export interface ItemUnit { key: string; label: string; plural: string; per: number }

export interface SupplyItem extends SearchableItem {
    location_code: string | null;
    location_label: string | null;
    section_label: string | null;
    category: string | null;
    aliases: string[];
    learned_aliases: string[];
    item_numbers: string[];
    units: ItemUnit[];
    default_unit: string;
    confirm_above_base: number | null;
    popularity: number;
}

export interface Bootstrap {
    device: { name: string };
    csrf_token: string;
    generated_at: string;
    members: Member[];
    units: Apparatus[];
    locations: Location[];
    items: SupplyItem[];
    most_taken: number[];
    shorthand: Record<string, string[]>;
}

/** The apparatus picked on the unit screen: a bootstrap unit, free text, or nothing. */
export type UnitChoice =
    | { apparatus_id: number; label: null; display: string }
    | { apparatus_id: null; label: string; display: string }
    | null;

export interface CartLine {
    key: string;
    item: SupplyItem;
    quantity: number;
    unit: string;
}

export interface SearchRecord { term: string; supply_item_id: number }

export interface WithdrawalBody {
    client_uuid: string;
    employee_id: string;
    unit: { apparatus_id: number | null; label: string | null } | null;
    lines: { supply_item_id: number; quantity: number; unit: string }[];
    note: string | null;
    searches: SearchRecord[];
}

export interface WithdrawalResult {
    uuid: string;
    number: string;
    employee_name: string;
    unit_label: string | null;
    taken_at: string;
    undo_until: string;
    lines: { name: string; quantity: number; unit_label: string }[];
    note: string | null;
    copy: 'sent' | 'no_account';
}

/** What the Done screen shows: the server's receipt, or the local copy of a queued withdrawal. */
export interface Receipt {
    uuid: string;
    queued: boolean;
    number: string | null;
    employeeName: string;
    unitLabel: string | null;
    lines: { name: string; quantity: number; unit_label: string }[];
    note: string | null;
    copy: 'sent' | 'no_account' | null;
    /** Local clock milliseconds when Undo stops being offered. */
    undoUntil: number;
}

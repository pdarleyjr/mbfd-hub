/** Shapes of the Master Supply Room Inventory API (docs/ems/captain5-medical-inventory-workflow.md, section 6). */
import type { StockLevel } from './stockLevel.ts';

/** Every way an item can be counted, in the order the server lists them (`units`). Anything but `each` needs a size. */
export type CountUnit =
  | 'each' | 'box' | 'case' | 'pack' | 'bag' | 'bottle' | 'vial' | 'kit'
  | 'roll' | 'pair' | 'tube' | 'carton' | 'can' | 'set' | 'sleeve' | 'tray';

export interface UnitOption { value: CountUnit; label: string; plural: string }

/** Where an item stands for ordering. An open order wins over the computed state. */
export type OrderState = 'ok' | 'needs_order' | 'pending' | 'ordered' | 'backordered' | 'no_level' | 'not_counted';

export type OpenOrderStatus = 'pending' | 'ordered' | 'backordered';

export interface OpenOrder {
  id: number;
  status: OpenOrderStatus;
  /** Whole count units. */
  quantity: number;
  unit_label: string;
  /** YYYY-MM-DD */
  expected_on: string | null;
  note: string | null;
  placed_at: string | null;
  placed_by: string | null;
  updated_at: string | null;
  updated_by: string | null;
}

export interface OrderSummary { needs_order: number; pending: number; ordered: number; backordered: number }

export interface LevelSummary { red: number; yellow: number; green: number }

/** One shelf. `code` is null for the "No shelf assigned" group. */
export interface MasterSection {
  code: string | null;
  label: string;
  item_count: number;
}

export interface MasterItem {
  id: number;
  name: string;
  aliases: string[];
  location_code: string | null;
  location_label: string | null;
  section_key: string | null;
  section_label: string | null;
  base_unit: string;
  count_unit: CountUnit;
  count_unit_label: string;
  count_unit_label_plural: string;
  units_per_count_unit: number;
  package_unit: string | null;
  units_per_package: number | null;
  on_hand_base: number | null;
  /** In count units; null until the item has been counted. May carry one decimal. */
  on_hand: number | null;
  deducted_base: number;
  /** In count units: what was taken out since the last physical count. */
  deducted: number;
  counted_at: string | null;
  counted_by: string | null;
  /** The number is a best guess, not a physical count. The next real count clears this. */
  estimated?: boolean;
  /** The "order when fewer than" level in base units; null when none is set. */
  required_base: number | null;
  order_rule_text: string | null;
  refrigerated: boolean;
  needs_review: boolean;
  review_reasons: string[];
  config_problem: string | null;
  /** Expected on hand in the count unit (may carry one decimal); null when no level is set. */
  expected: number | null;
  /** Whole count units to order: ceil(max(0, expected - on hand)). Null when either side is unknown. */
  to_order: number | null;
  order_state: OrderState;
  open_order: OpenOrder | null;
  /** Green, yellow or red from the live on hand. Null when never counted or no expected amount. */
  level: StockLevel | null;
  /** Whole count units: at or below this the item is yellow. Null when the item has no expected amount. */
  reorder_at: number | null;
  /** Whole count units: at or below this (or at 0) the item is red. */
  critical_at: number | null;
  /** True when `reorder_at` is the preset (half the expected amount) rather than a person's number. */
  reorder_at_auto: boolean;
  /** True when `critical_at` is the preset (a quarter of the expected amount). */
  critical_at_auto: boolean;
  /** More was taken than the ledger held, so on hand shows 0 until someone counts the shelf. */
  count_due?: boolean;
}

// ------------------------------------------------------------ supply room screen (GET /supply/screen)

export interface ScreenWithdrawalLine { name: string; quantity: number; unit_label: string; count_due: boolean }

export interface ScreenWithdrawal {
  uuid: string;
  number: string;
  employee_name: string;
  unit_label: string | null;
  /** Ready to show, for example "Nov 3, 9:15 AM". */
  taken_at: string;
  taken_at_iso: string;
  status: 'taken' | 'undone';
  note: string | null;
  count_due: boolean;
  lines: ScreenWithdrawalLine[];
}

export interface KioskScreen {
  id: number;
  name: string;
  state: 'online' | 'offline' | 'waiting' | 'revoked';
  state_label: string;
  last_seen: string | null;
  paired_at: string | null;
  paired_by: string | null;
}

export interface SupplyScreenData {
  withdrawals: ScreenWithdrawal[];
  screens: KioskScreen[];
  search_words_waiting: number;
}

export interface InactiveItem { id: number; name: string; location_code: string | null }

export interface DraftSummary { id: string; started_at: string | null; started_by: string | null; counted: number }

export interface LastCount { id: string; finalized_at: string | null; finalized_name: string | null }

export interface MasterData {
  sections: MasterSection[];
  items: MasterItem[];
  inactive: InactiveItem[];
  draft: DraftSummary | null;
  last_count: LastCount | null;
  can_edit: boolean;
  /** Newer servers also count the items at each stock level. */
  summary: OrderSummary & Partial<LevelSummary>;
  units: UnitOption[];
}

/** What `orderFor` needs from an item. `expected` (count units) wins over `required_base` when the server sends it. */
export type OrderBasis = Pick<MasterItem, 'units_per_count_unit' | 'required_base'> & { expected?: number | null };

export type OrderResult =
  | { kind: 'none-set' }
  | { kind: 'no-order' }
  | { kind: 'order'; quantity: number };

// ------------------------------------------------------------------ writes

export interface NewItemInput {
  name: string;
  location_code?: string | null;
  new_location_label?: string;
  count_unit: CountUnit;
  units_per_package?: number;
  threshold?: number;
  on_hand?: number;
}

export interface ItemChanges {
  name?: string;
  location_code?: string | null;
  new_location_label?: string;
  count_unit?: CountUnit;
  units_per_package?: number | null;
  threshold?: number | null;
  active?: boolean;
}

/** One row of the Edit mode batch save. Only the fields that changed are present. */
export interface BatchChange {
  id: number;
  name?: string;
  location_code?: string | null;
  new_location_label?: string;
  count_unit?: CountUnit;
  units_per_package?: number;
  /** Whole count units; null clears the expected level. */
  expected?: number | null;
  /** Whole count units. Counts as a physical count of the item. */
  on_hand?: number;
  /** Whole count units for the yellow line; null puts it back to the preset (half the expected amount). */
  reorder_at?: number | null;
  /** Whole count units for the red line; null puts it back to the preset (a quarter of the expected amount). */
  critical_at?: number | null;
}

export interface OrderInput { status: OpenOrderStatus; quantity: number; expected_on?: string; note?: string }

export interface OrderUpdate { status?: OpenOrderStatus | 'cancelled'; quantity?: number; expected_on?: string | null; note?: string | null }

export interface ArrivedInput { quantity: number; note?: string }

// ---------------------------------------------------------------- count form

/** Typed numbers by item id. An id that is missing is blank (not entered); 0 is a real count. */
export type EntryMap = Record<string, number>;

export interface CountFormCount {
  id: string;
  started_at: string | null;
  started_by: string | null;
  entries: EntryMap;
}

export interface StartResult {
  count: CountFormCount;
  resumed: boolean;
  sections: MasterSection[];
  items: MasterItem[];
}

export interface SaveEntriesResult { saved: number; entries: EntryMap }

export interface EntryWrite { supply_item_id: number; on_hand: number | null }

export interface ReviewMissing { id: number; name: string; location_code: string | null }

export interface ReviewOrder { supply_item_id: number; name: string; location_code: string | null; on_hand: number; order: number; unit_label: string }

export interface ReviewProblem { id: number; name: string; problem: string }

export interface CountReviewData {
  counted: number;
  uncounted: ReviewMissing[];
  orders: ReviewOrder[];
  config_problems: ReviewProblem[];
}

export interface SubmitResult {
  id: string;
  finalized_at: string | null;
  counted_items: number;
  uncounted_items: number;
  order_items: number;
  /** Not in the contract yet; shown when the server sends it. */
  finalized_name?: string | null;
}

export type SaveStatus = 'saved' | 'saving' | 'offline' | 'error';

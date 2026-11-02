/** Shapes of the Master Supply Room Inventory API (docs/ems/captain5-medical-inventory-workflow.md, section 6). */

export type CountUnit = 'each' | 'box' | 'case' | 'pack' | 'bag';

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
  /** The "order when fewer than" level in base units; null when none is set. */
  required_base: number | null;
  order_rule_text: string | null;
  refrigerated: boolean;
  needs_review: boolean;
  review_reasons: string[];
  config_problem: string | null;
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
}

/** What `orderFor` needs from an item. */
export type OrderBasis = Pick<MasterItem, 'units_per_count_unit' | 'required_base'>;

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

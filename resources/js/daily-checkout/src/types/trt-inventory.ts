export interface TrtCatalogItem {
  id: number;
  name: string;
  category: string;
  expected_quantity: number;
  sort_order: number;
}

export interface TrtCategoryGroup {
  category: string;
  items: TrtCatalogItem[];
}

export type ItemCondition = 'excellent' | 'good' | 'poor';
export type ItemAction = 'keep' | 'replace';

export interface TrtEntryDraft {
  catalog_item_id: number;
  present: boolean | null;
  actual_quantity: number | null;
  condition: ItemCondition | null;
  action: ItemAction | null;
  image: string | null;
}

export interface TrtSubmissionPayload {
  entries: TrtEntryDraft[];
  submitted_at: string;
}

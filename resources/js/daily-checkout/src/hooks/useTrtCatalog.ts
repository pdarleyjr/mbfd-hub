import { useState, useEffect } from 'react';
import { db } from '../lib/db';
import type { TrtCatalogItem, TrtCategoryGroup } from '../types/trt-inventory';

interface UseTrtCatalogResult {
  categories: TrtCategoryGroup[];
  loading: boolean;
  error: string | null;
}

export function useTrtCatalog(): UseTrtCatalogResult {
  const [categories, setCategories] = useState<TrtCategoryGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadCatalog() {
      try {
        // Try fetching from API first
        const response = await fetch('/api/public/trt-inventory/catalog');

        if (response.ok) {
          const json = await response.json();
          const groups: TrtCategoryGroup[] = json.data;

          // Cache all items in Dexie for offline use
          const allItems: TrtCatalogItem[] = groups.flatMap((g) => g.items);
          await db.trtCatalog.clear();
          await db.trtCatalog.bulkPut(allItems);

          if (!cancelled) {
            setCategories(groups);
            setLoading(false);
          }
          return;
        }

        throw new Error(`HTTP ${response.status}`);
      } catch {
        // Offline or error — fall back to Dexie
        try {
          const cached = await db.trtCatalog.orderBy('sort_order').toArray();

          if (cached.length > 0) {
            const grouped = groupByCategory(cached);
            if (!cancelled) {
              setCategories(grouped);
              setError(null);
              setLoading(false);
            }
          } else {
            if (!cancelled) {
              setError('No catalog data available. Connect to the network and try again.');
              setLoading(false);
            }
          }
        } catch (dbError) {
          if (!cancelled) {
            setError('Failed to load catalog data.');
            setLoading(false);
          }
        }
      }
    }

    loadCatalog();

    return () => {
      cancelled = true;
    };
  }, []);

  return { categories, loading, error };
}

function groupByCategory(items: TrtCatalogItem[]): TrtCategoryGroup[] {
  const map = new Map<string, TrtCatalogItem[]>();

  for (const item of items) {
    const existing = map.get(item.category) ?? [];
    map.set(item.category, [...existing, item]);
  }

  return Array.from(map.entries()).map(([category, categoryItems]) => ({
    category,
    items: categoryItems.sort((a, b) => a.sort_order - b.sort_order),
  }));
}

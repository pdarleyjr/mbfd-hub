import { useState, useRef, useCallback, useEffect, useMemo } from 'react';
import { Link } from 'react-router';
import { useTrtCatalog } from '../hooks/useTrtCatalog';
import { enqueueSubmission, processPendingSubmissions } from '../lib/sync';
import type { TrtEntryDraft, ItemCondition, ItemAction, TrtCatalogItem } from '../types/trt-inventory';

interface SearchResult {
  item: TrtCatalogItem;
  category: string;
  pageIndex: number;
}

async function compressImage(file: File): Promise<string> {
  const imageCompression = (await import('browser-image-compression')).default;
  const compressed = await imageCompression(file, {
    maxWidthOrHeight: 800,
    maxSizeMB: 0.1,
    useWebWorker: true,
  });

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => resolve(reader.result as string);
    reader.onerror = reject;
    reader.readAsDataURL(compressed);
  });
}

export default function TrtInventoryWizard() {
  const { categories, loading, error } = useTrtCatalog();
  const [step, setStep] = useState(0);
  const [entries, setEntries] = useState<Map<number, TrtEntryDraft>>(new Map());
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [partialSubmitted, setPartialSubmitted] = useState(false);
  const fileInputRefs = useRef<Map<number, HTMLInputElement>>(new Map());
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const searchInputRef = useRef<HTMLInputElement>(null);

  const totalSteps = categories.length + 2; // welcome + N categories + review

  // Scroll to top whenever step changes
  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, [step]);

  // Focus search input when opened
  useEffect(() => {
    if (searchOpen && searchInputRef.current) {
      searchInputRef.current.focus();
    }
  }, [searchOpen]);

  // Client-side search across all categories
  const searchResults: SearchResult[] = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    if (q.length < 2) return [];

    const results: SearchResult[] = [];
    for (let i = 0; i < categories.length; i++) {
      const cat = categories[i];
      for (const item of cat.items) {
        if (item.name.toLowerCase().includes(q)) {
          results.push({ item, category: cat.category, pageIndex: i + 1 });
        }
      }
    }
    return results;
  }, [searchQuery, categories]);

  const getEntry = useCallback(
    (itemId: number): TrtEntryDraft =>
      entries.get(itemId) ?? {
        catalog_item_id: itemId,
        present: null,
        actual_quantity: null,
        condition: null,
        action: null,
        image: null,
      },
    [entries]
  );

  const updateEntry = useCallback((itemId: number, patch: Partial<TrtEntryDraft>) => {
    setEntries((prev) => {
      const next = new Map(prev);
      const current = next.get(itemId) ?? {
        catalog_item_id: itemId,
        present: null,
        actual_quantity: null,
        condition: null,
        action: null,
        image: null,
      };
      next.set(itemId, { ...current, ...patch });
      return next;
    });
  }, []);

  const handleImageCapture = async (itemId: number, file: File | null) => {
    if (!file) return;
    try {
      const base64 = await compressImage(file);
      updateEntry(itemId, { image: base64 });
    } catch {
      alert('Failed to process the photo. Please try a smaller image or retake the photo.');
    }
  };

  const getFilledEntries = () =>
    Array.from(entries.values()).filter(
      (e) =>
        e.present !== null ||
        e.actual_quantity !== null ||
        e.condition !== null ||
        e.action !== null ||
        e.image !== null
    );

  const submitEntries = async (andFinish: boolean) => {
    setSubmitting(true);
    try {
      const filledEntries = getFilledEntries();

      if (filledEntries.length === 0) {
        alert('No items have been checked. Please fill in at least one item.');
        setSubmitting(false);
        return;
      }

      await enqueueSubmission('trt-inventory/submit', {
        entries: filledEntries,
        submitted_at: new Date().toISOString(),
      });
      processPendingSubmissions('/api/public').catch(() => {});

      if (andFinish) {
        setSubmitted(true);
      } else {
        setPartialSubmitted(true);
        setTimeout(() => setPartialSubmitted(false), 3000);
      }
    } catch {
      alert('Failed to save. Your inventory will be retried automatically when online.');
    } finally {
      setSubmitting(false);
    }
  };

  // --- Success State ---
  if (submitted) {
    return (
      <div className="text-center py-16 space-y-6">
        <div className="w-20 h-20 mx-auto bg-emerald-50 rounded-full flex items-center justify-center">
          <svg className="w-10 h-10 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-2xl font-bold text-neutral-800 font-heading">Inventory Submitted</h2>
        <p className="text-neutral-500 max-w-md mx-auto">
          Your TRT trailer inventory has been queued and will sync when online. Other team members can submit their sections too.
        </p>
        <Link
          to="/forms-hub"
          className="inline-flex items-center min-h-[44px] px-6 py-3 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 transition-colors"
        >
          Back to Forms Hub
        </Link>
      </div>
    );
  }

  // --- Loading / Error States ---
  if (loading) {
    return (
      <div className="text-center py-16">
        <div className="w-12 h-12 mx-auto border-4 border-neutral-200 border-t-red-600 rounded-full animate-spin" />
        <p className="mt-4 text-neutral-500">Loading inventory catalog...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center py-16 space-y-4">
        <p className="text-red-600 font-medium">{error}</p>
        <Link to="/forms-hub" className="text-neutral-500 hover:text-neutral-700">
          Back to Forms Hub
        </Link>
      </div>
    );
  }

  const stepLabels = ['Welcome', ...categories.map((c) => c.category), 'Review'];
  const filledCount = Array.from(entries.values()).filter(
    (e) => e.present !== null || e.actual_quantity !== null
  ).length;
  const totalItems = categories.reduce((sum, c) => sum + c.items.length, 0);

  return (
    <div className="max-w-2xl mx-auto">
      {/* Header */}
      <div className="mb-6">
        <Link to="/forms-hub" className="inline-flex items-center text-neutral-500 hover:text-neutral-700 mb-4 min-h-[44px]">
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back
        </Link>
        <h1 className="text-2xl font-bold text-neutral-800 font-heading">TRT Trailer Inventory</h1>
        <p className="text-sm text-neutral-500 mt-1">
          Collaborative equipment checkout — {filledCount}/{totalItems} items checked
        </p>
      </div>

      {/* Stepper */}
      <nav className="flex items-center gap-1 mb-6 overflow-x-auto pb-2" aria-label="Progress">
        {stepLabels.map((label, i) => (
          <div key={label} className="flex items-center gap-1 flex-shrink-0">
            <button
              type="button"
              onClick={() => setStep(i)}
              className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-medium transition-colors ${
                i === step
                  ? 'bg-amber-600 text-white'
                  : i < step
                    ? 'bg-amber-200 text-amber-800'
                    : 'bg-neutral-200 text-neutral-500'
              }`}
              aria-label={label}
            >
              {i + 1}
            </button>
            {i < stepLabels.length - 1 && <div className="w-3 h-px bg-neutral-300" />}
          </div>
        ))}
      </nav>

      {/* Cross-Section Search */}
      {!searchOpen && step > 0 && step < totalSteps - 1 && (
        <button
          type="button"
          onClick={() => setSearchOpen(true)}
          className="fixed bottom-24 right-4 z-30 w-12 h-12 bg-amber-600 text-white rounded-full shadow-lg flex items-center justify-center hover:bg-amber-700 transition-colors"
          aria-label="Search items across all sections"
        >
          <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
        </button>
      )}

      {searchOpen && (
        <div className="fixed inset-0 z-40 bg-black/50 flex flex-col">
          <div className="bg-white rounded-b-2xl shadow-xl max-h-[80vh] flex flex-col">
            {/* Search header */}
            <div className="flex items-center gap-3 p-4 border-b border-neutral-200">
              <svg className="w-5 h-5 text-neutral-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <input
                ref={searchInputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search items across all sections..."
                className="flex-1 text-sm outline-none placeholder-neutral-400"
                autoComplete="off"
              />
              <button
                type="button"
                onClick={() => { setSearchOpen(false); setSearchQuery(''); }}
                className="min-h-[44px] min-w-[44px] flex items-center justify-center text-neutral-500 hover:text-neutral-700"
                aria-label="Close search"
              >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Search results */}
            <div className="overflow-y-auto flex-1 p-2">
              {searchQuery.trim().length < 2 && (
                <p className="text-sm text-neutral-400 text-center py-6">
                  Type at least 2 characters to search
                </p>
              )}

              {searchQuery.trim().length >= 2 && searchResults.length === 0 && (
                <p className="text-sm text-neutral-400 text-center py-6">
                  No items found matching &ldquo;{searchQuery.trim()}&rdquo;
                </p>
              )}

              {searchResults.map((result) => (
                <button
                  key={result.item.id}
                  type="button"
                  onClick={() => {
                    setStep(result.pageIndex);
                    setSearchOpen(false);
                    setSearchQuery('');
                  }}
                  className="w-full text-left px-3 py-3 rounded-lg hover:bg-amber-50 transition-colors flex items-center justify-between gap-2"
                >
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-neutral-800 truncate">{result.item.name}</p>
                    <p className="text-xs text-neutral-500 truncate">{result.category}</p>
                  </div>
                  <span className="flex-shrink-0 text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded-full">
                    Page {result.pageIndex}
                  </span>
                </button>
              ))}
            </div>
          </div>
          {/* Tap backdrop to close */}
          <div className="flex-1" onClick={() => { setSearchOpen(false); setSearchQuery(''); }} />
        </div>
      )}

      {/* Step 0: Welcome + Section Picker */}
      {step === 0 && (
        <div className="space-y-6">
          <div className="bg-amber-50 rounded-xl p-6 ring-1 ring-amber-200/60">
            <h2 className="text-lg font-semibold text-neutral-800 font-heading mb-3">How it works</h2>
            <ul className="space-y-2 text-sm text-neutral-600">
              <li className="flex items-start gap-2">
                <span className="text-amber-600 font-bold mt-0.5">1.</span>
                Walk through each trailer compartment section
              </li>
              <li className="flex items-start gap-2">
                <span className="text-amber-600 font-bold mt-0.5">2.</span>
                Mark items as present, note quantities and conditions
              </li>
              <li className="flex items-start gap-2">
                <span className="text-amber-600 font-bold mt-0.5">3.</span>
                Take photos of as much equipment as possible (especially power tools)
              </li>
              <li className="flex items-start gap-2">
                <span className="text-amber-600 font-bold mt-0.5">4.</span>
                Submit when done — works offline too
              </li>
            </ul>
            <p className="mt-4 text-xs text-neutral-500">
              Multiple team members can submit their sections. All entries merge into today&apos;s session automatically.
            </p>
          </div>

          {/* Section Picker — jump directly to an assigned area */}
          <div>
            <h3 className="text-sm font-semibold text-neutral-700 mb-3">Jump to a section:</h3>
            <div className="grid grid-cols-1 gap-2">
              {categories.map((cat, catIndex) => (
                <button
                  key={cat.category}
                  type="button"
                  onClick={() => setStep(catIndex + 1)}
                  className="flex items-center justify-between min-h-[48px] px-4 py-3 bg-white rounded-lg ring-1 ring-neutral-200/60 text-left hover:bg-neutral-50 hover:ring-amber-300 transition-all"
                >
                  <div>
                    <span className="text-sm font-medium text-neutral-800">{cat.category}</span>
                    <span className="text-xs text-neutral-400 ml-2">{cat.items.length} items</span>
                  </div>
                  <svg className="w-4 h-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                  </svg>
                </button>
              ))}
            </div>
          </div>

          <p className="text-sm text-neutral-500">
            {categories.length} compartment sections &middot; {totalItems} items total
          </p>
        </div>
      )}

      {/* Category Steps */}
      {categories.map((cat, catIndex) => {
        const stepIndex = catIndex + 1;
        if (step !== stepIndex) return null;

        return (
          <div key={cat.category} className="space-y-4">
            <div className="bg-neutral-100 rounded-lg px-4 py-3 ring-1 ring-neutral-200/60">
              <h2 className="text-lg font-semibold text-neutral-800 font-heading">{cat.category}</h2>
              <p className="text-xs text-neutral-500">{cat.items.length} items in this section</p>
            </div>

            {cat.items.map((item) => {
              const entry = getEntry(item.id);
              return (
                <div
                  key={item.id}
                  className="bg-white rounded-xl p-4 ring-1 ring-neutral-200/60 space-y-3"
                >
                  {/* Item header */}
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <h3 className="font-medium text-neutral-800 text-sm">{item.name}</h3>
                      <p className="text-xs text-neutral-400">Expected: {item.expected_quantity}</p>
                    </div>
                    {entry.present !== null && (
                      <span
                        className={`text-xs font-medium px-2 py-0.5 rounded-full ${
                          entry.present
                            ? 'bg-emerald-50 text-emerald-700'
                            : 'bg-red-50 text-red-700'
                        }`}
                      >
                        {entry.present ? 'Present' : 'Missing'}
                      </span>
                    )}
                  </div>

                  {/* Present toggle */}
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => updateEntry(item.id, { present: entry.present === true ? null : true })}
                      className={`flex-1 min-h-[44px] rounded-lg font-medium text-sm transition-colors ${
                        entry.present === true
                          ? 'bg-emerald-600 text-white'
                          : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'
                      }`}
                    >
                      Yes
                    </button>
                    <button
                      type="button"
                      onClick={() => updateEntry(item.id, { present: entry.present === false ? null : false })}
                      className={`flex-1 min-h-[44px] rounded-lg font-medium text-sm transition-colors ${
                        entry.present === false
                          ? 'bg-red-600 text-white'
                          : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'
                      }`}
                    >
                      No
                    </button>
                  </div>

                  {/* Actual quantity */}
                  <div className="flex items-center gap-3">
                    <span className="text-xs text-neutral-500 w-12">Qty:</span>
                    <button
                      type="button"
                      onClick={() =>
                        updateEntry(item.id, {
                          actual_quantity: Math.max(0, (entry.actual_quantity ?? 0) - 1),
                        })
                      }
                      className="stepper-btn w-10 h-10 rounded-lg bg-neutral-100 text-neutral-700 font-bold text-lg hover:bg-neutral-200 transition-colors flex items-center justify-center"
                    >
                      -
                    </button>
                    <input
                      type="number"
                      inputMode="numeric"
                      min={0}
                      value={entry.actual_quantity ?? ''}
                      onChange={(e) => {
                        const parsed = parseInt(e.target.value, 10);
                        updateEntry(item.id, {
                          actual_quantity: e.target.value === '' || isNaN(parsed) ? null : parsed,
                        });
                      }}
                      placeholder="—"
                      className="stepper-input w-16 h-10 text-center rounded-lg border border-neutral-200 text-sm font-medium"
                    />
                    <button
                      type="button"
                      onClick={() =>
                        updateEntry(item.id, {
                          actual_quantity: (entry.actual_quantity ?? 0) + 1,
                        })
                      }
                      className="stepper-btn w-10 h-10 rounded-lg bg-neutral-100 text-neutral-700 font-bold text-lg hover:bg-neutral-200 transition-colors flex items-center justify-center"
                    >
                      +
                    </button>
                  </div>

                  {/* Condition */}
                  <div>
                    <span className="text-xs text-neutral-500 block mb-1">Condition:</span>
                    <div className="flex gap-2">
                      {(['excellent', 'good', 'poor'] as ItemCondition[]).map((c) => (
                        <button
                          key={c}
                          type="button"
                          onClick={() => updateEntry(item.id, { condition: entry.condition === c ? null : c })}
                          className={`flex-1 min-h-[40px] rounded-lg text-xs font-medium capitalize transition-colors ${
                            entry.condition === c
                              ? c === 'excellent'
                                ? 'bg-emerald-600 text-white'
                                : c === 'good'
                                  ? 'bg-amber-500 text-white'
                                  : 'bg-red-600 text-white'
                              : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'
                          }`}
                        >
                          {c}
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Action */}
                  <div>
                    <span className="text-xs text-neutral-500 block mb-1">Action:</span>
                    <div className="flex gap-2">
                      {(['keep', 'replace'] as ItemAction[]).map((a) => (
                        <button
                          key={a}
                          type="button"
                          onClick={() => updateEntry(item.id, { action: entry.action === a ? null : a })}
                          className={`flex-1 min-h-[40px] rounded-lg text-xs font-medium capitalize transition-colors ${
                            entry.action === a
                              ? a === 'keep'
                                ? 'bg-emerald-600 text-white'
                                : 'bg-red-600 text-white'
                              : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'
                          }`}
                        >
                          {a}
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Camera / Image */}
                  <div>
                    {entry.image ? (
                      <div className="flex items-center gap-3">
                        <img
                          src={entry.image}
                          alt={`Photo of ${item.name}`}
                          className="w-16 h-16 object-cover rounded-lg ring-1 ring-neutral-200"
                        />
                        <button
                          type="button"
                          onClick={() => updateEntry(item.id, { image: null })}
                          className="text-xs text-red-600 hover:text-red-700 min-h-[44px]"
                        >
                          Remove photo
                        </button>
                      </div>
                    ) : (
                      <label className="flex items-center gap-2 min-h-[44px] px-3 py-2 bg-neutral-100 rounded-lg cursor-pointer hover:bg-neutral-200 transition-colors">
                        <svg className="w-5 h-5 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
                          />
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span className="text-xs text-neutral-600">Take Photo</span>
                        <input
                          ref={(el) => {
                            if (el) fileInputRefs.current.set(item.id, el);
                          }}
                          type="file"
                          accept="image/*"
                          capture="environment"
                          className="hidden"
                          onChange={(e) => handleImageCapture(item.id, e.target.files?.[0] ?? null)}
                        />
                      </label>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        );
      })}

      {/* Review Step */}
      {step === totalSteps - 1 && (
        <div className="space-y-4">
          <div className="bg-neutral-100 rounded-lg px-4 py-3 ring-1 ring-neutral-200/60">
            <h2 className="text-lg font-semibold text-neutral-800 font-heading">Review &amp; Submit</h2>
            <p className="text-xs text-neutral-500">
              {filledCount} of {totalItems} items checked
            </p>
          </div>

          {categories.map((cat) => {
            const catEntries = cat.items
              .map((item) => ({ item, entry: entries.get(item.id) }))
              .filter(({ entry }) => entry != null);

            if (catEntries.length === 0) return null;

            return (
              <div key={cat.category} className="bg-white rounded-xl p-4 ring-1 ring-neutral-200/60">
                <h3 className="font-medium text-neutral-700 text-sm mb-2">{cat.category}</h3>
                <div className="space-y-1">
                  {catEntries.map(({ item, entry }) => (
                    <div key={item.id} className="flex items-center justify-between text-xs text-neutral-600 py-1">
                      <span className="truncate mr-2">{item.name}</span>
                      <div className="flex items-center gap-2 flex-shrink-0">
                        {entry?.present !== null && entry?.present !== undefined && (
                          <span
                            className={`px-1.5 py-0.5 rounded text-[10px] font-medium ${
                              entry.present ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'
                            }`}
                          >
                            {entry.present ? 'Yes' : 'No'}
                          </span>
                        )}
                        {entry?.actual_quantity != null && (
                          <span className="text-neutral-400">x{entry.actual_quantity}</span>
                        )}
                        {entry?.condition && (
                          <span className="capitalize text-neutral-400">{entry.condition}</span>
                        )}
                        {entry?.image && (
                          <svg className="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                          </svg>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            );
          })}

          {filledCount === 0 && (
            <p className="text-sm text-neutral-400 text-center py-4">
              No items have been checked yet. Go back and fill in at least one section.
            </p>
          )}
        </div>
      )}

      {/* Partial Submit Banner */}
      {partialSubmitted && (
        <div className="mt-4 bg-emerald-50 rounded-lg px-4 py-3 ring-1 ring-emerald-200/60 flex items-center gap-2">
          <svg className="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
          <p className="text-sm text-emerald-700">Progress submitted! You can keep working or go back to Forms Hub.</p>
        </div>
      )}

      {/* Navigation */}
      <div className="flex flex-wrap justify-between mt-8 gap-3">
        <button
          type="button"
          onClick={() => setStep((s) => Math.max(0, s - 1))}
          disabled={step === 0}
          className={`min-h-[48px] px-5 py-3 rounded-lg font-medium text-sm transition-colors ${
            step === 0
              ? 'bg-neutral-100 text-neutral-300 cursor-not-allowed'
              : 'bg-neutral-200 text-neutral-700 hover:bg-neutral-300'
          }`}
        >
          Previous
        </button>

        <div className="flex gap-2">
          {/* Submit What I Have — available on any category step when there's data */}
          {step > 0 && step < totalSteps - 1 && filledCount > 0 && (
            <button
              type="button"
              onClick={() => submitEntries(false)}
              disabled={submitting}
              className={`min-h-[48px] px-4 py-3 rounded-lg font-medium text-sm transition-colors ${
                submitting
                  ? 'bg-neutral-200 text-neutral-400 cursor-not-allowed'
                  : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200 ring-1 ring-emerald-300/60'
              }`}
            >
              {submitting ? 'Saving...' : 'Submit Progress'}
            </button>
          )}

          {step < totalSteps - 1 ? (
            <button
              type="button"
              onClick={() => setStep((s) => Math.min(totalSteps - 1, s + 1))}
              className="min-h-[48px] px-5 py-3 bg-amber-600 text-white rounded-lg font-medium text-sm hover:bg-amber-700 transition-colors"
            >
              Next
            </button>
          ) : (
            <button
              type="button"
              onClick={() => submitEntries(true)}
              disabled={submitting || filledCount === 0}
              className={`min-h-[48px] px-6 py-3 rounded-lg font-medium text-sm transition-colors ${
                submitting || filledCount === 0
                  ? 'bg-neutral-300 text-neutral-500 cursor-not-allowed'
                  : 'bg-red-600 text-white hover:bg-red-700'
              }`}
            >
              {submitting ? 'Submitting...' : 'Submit & Finish'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

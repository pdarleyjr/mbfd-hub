import { useState, useEffect, useCallback } from 'react';
import { Shift, InventoryV2Item, InventoryV2Category, SupplyRequest } from '../types';
import { ApiClient } from '../utils/api';

interface InventoryCountPageProps {
  stationId: number;
  stationName: string;
  actorName: string;
  actorShift: Shift;
  token: string;
  onLogout: () => void;
}

type TimerID = ReturnType<typeof setTimeout>;

export default function InventoryCountPage({
  stationId,
  stationName,
  actorName,
  actorShift,
  token,
  onLogout,
}: InventoryCountPageProps) {
  const [categories, setCategories] = useState<InventoryV2Category[]>([]);
  const [supplyRequests, setSupplyRequests] = useState<SupplyRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState<Record<number, boolean>>({});
  const [showRequestForm, setShowRequestForm] = useState(false);
  const [newRequestText, setNewRequestText] = useState('');
  const [submittingRequest, setSubmittingRequest] = useState(false);
  const [showRequests, setShowRequests] = useState(false);
  const [pendingChanges, setPendingChanges] = useState<Set<number>>(new Set());
  const [saveTimers, setSaveTimers] = useState<Record<number, TimerID>>({});

  const fetchInventory = useCallback(async () => {
    try {
      const data = await ApiClient.getInventoryV2(stationId, token);
      setCategories(data.categories);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load inventory');
    } finally {
      setLoading(false);
    }
  }, [stationId, token]);

  const fetchSupplyRequests = useCallback(async () => {
    try {
      const requests = await ApiClient.getSupplyRequests(stationId, token);
      setSupplyRequests(requests);
    } catch (err) {
      console.error('Failed to load supply requests:', err);
    }
  }, [stationId, token]);

  useEffect(() => {
    fetchInventory();
    fetchSupplyRequests();
  }, [fetchInventory, fetchSupplyRequests]);

  const handleCountChange = (categoryIndex: number, itemIndex: number, newCount: number) => {
    setCategories(prev => {
      const updated = [...prev];
      const item = updated[categoryIndex].items[itemIndex];
      item.on_hand = Math.max(0, newCount);
      return updated;
    });

    const item = categories[categoryIndex].items[itemIndex];
    const itemId = item.id;

    setPendingChanges(prev => new Set(prev).add(itemId));

    if (saveTimers[itemId]) {
      clearTimeout(saveTimers[itemId]);
    }

    const timer = setTimeout(() => {
      saveItemCount(itemId, newCount);
    }, 1000);

    setSaveTimers(prev => ({ ...prev, [itemId]: timer }));
  };

  const saveItemCount = async (itemId: number, count: number) => {
    setSaving(prev => ({ ...prev, [itemId]: true }));
    
    try {
      await ApiClient.updateInventoryItem(
        stationId,
        itemId,
        {
          on_hand: count,
          actor_name: actorName,
          actor_shift: actorShift,
        },
        token
      );
      
      setPendingChanges(prev => {
        const updated = new Set(prev);
        updated.delete(itemId);
        return updated;
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save count');
    } finally {
      setSaving(prev => ({ ...prev, [itemId]: false }));
    }
  };

  const handleSubmitRequest = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!newRequestText.trim() || newRequestText.length > 1000) {
      return;
    }

    setSubmittingRequest(true);
    
    try {
      await ApiClient.createSupplyRequest(
        stationId,
        {
          request_text: newRequestText.trim(),
          actor_name: actorName,
          actor_shift: actorShift,
        },
        token
      );
      
      setNewRequestText('');
      setShowRequestForm(false);
      await fetchSupplyRequests();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to submit request');
    } finally {
      setSubmittingRequest(false);
    }
  };

  const handleLogout = () => {
    if (pendingChanges.size > 0) {
      if (confirm('You have unsaved changes. Are you sure you want to logout?')) {
        onLogout();
      }
    } else {
      onLogout();
    }
  };

  const getStatusBadge = (item: InventoryV2Item) => {
    switch (item.status) {
      case 'ok':
        return <span className="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">OK</span>;
      case 'low':
        return <span className="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded">LOW</span>;
      case 'ordered':
        return <span className="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded">ORDERED</span>;
      default:
        return null;
    }
  };

  const getRequestStatusBadge = (status: string) => {
    switch (status) {
      case 'open':
        return <span className="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded">OPEN</span>;
      case 'ordered':
        return <span className="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded">ORDERED</span>;
      case 'denied':
        return <span className="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded">DENIED</span>;
      default:
        return null;
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-screen">
        <div className="text-lg text-gray-600">Loading inventory...</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50 pb-24">
      <div className="sticky top-0 z-10 bg-green-600 text-white shadow-md">
        <div className="px-4 py-4">
          <div className="flex items-center justify-between mb-2">
            <h1 className="text-xl font-bold">{stationName}</h1>
            <button
              onClick={handleLogout}
              className="px-3 py-1 text-sm bg-green-700 hover:bg-green-800 rounded-lg transition"
            >
              Logout
            </button>
          </div>
          <div className="flex items-center justify-between text-sm text-green-100">
            <span>{actorName} • Shift {actorShift}</span>
            {pendingChanges.size > 0 && (
              <span className="flex items-center">
                <svg className="animate-spin h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
                Saving...
              </span>
            )}
          </div>
        </div>
      </div>

      {error && (
        <div className="mx-4 mt-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
          {error}
          <button onClick={() => setError(null)} className="ml-2 font-medium underline">
            Dismiss
          </button>
        </div>
      )}

      <div className="px-4 py-4 space-y-6">
        {categories.map((category, catIndex) => (
          <div key={catIndex}>
            <h2 className="text-lg font-bold text-gray-900 mb-3 pb-2 border-b-2 border-gray-300">
              {category.name}
            </h2>
            
            <div className="space-y-3">
              {category.items.map((item, itemIndex) => (
                <div
                  key={item.id}
                  className="bg-white rounded-lg border border-gray-200 shadow-sm p-4"
                >
                  <div className="flex items-start justify-between mb-3">
                    <div className="flex-1 pr-2">
                      <div className="font-semibold text-gray-900">{item.name}</div>
                      <div className="text-xs text-gray-500">SKU: {item.sku}</div>
                    </div>
                    {getStatusBadge(item)}
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <div className="text-xs text-gray-500 mb-1">Expected:</div>
                      <div className="text-2xl font-bold text-gray-700">{item.par}</div>
                    </div>

                    <div>
                      <div className="text-xs text-gray-500 mb-1">On Hand:</div>
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => handleCountChange(catIndex, itemIndex, item.on_hand - 1)}
                          className="w-12 h-12 bg-red-500 hover:bg-red-600 text-white rounded-lg font-bold text-xl shadow-md active:scale-95 transition"
                          aria-label="Decrease count"
                        >
                          −
                        </button>
                        
                        <input
                          type="number"
                          inputMode="numeric"
                          value={item.on_hand}
                          onChange={(e) => handleCountChange(catIndex, itemIndex, parseInt(e.target.value) || 0)}
                          className="w-16 text-center text-2xl font-bold border-2 border-gray-300 rounded-lg py-2"
                          min="0"
                        />
                        
                        <button
                          onClick={() => handleCountChange(catIndex, itemIndex, item.on_hand + 1)}
                          className="w-12 h-12 bg-green-500 hover:bg-green-600 text-white rounded-lg font-bold text-xl shadow-md active:scale-95 transition"
                          aria-label="Increase count"
                        >
                          +
                        </button>
                      </div>
                      {saving[item.id] && (
                        <div className="text-xs text-gray-500 mt-1">Saving...</div>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>

      <div className="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-lg">
        <button
          onClick={() => setShowRequests(!showRequests)}
          className="w-full px-4 py-4 flex items-center justify-between text-left hover:bg-gray-50"
        >
          <span className="font-semibold text-gray-900">Special Supply Requests</span>
          <svg
            className={`w-5 h-5 text-gray-600 transition-transform ${showRequests ? 'rotate-180' : ''}`}
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
          </svg>
        </button>

        {showRequests && (
          <div className="px-4 pb-4 max-h-96 overflow-y-auto">
            {!showRequestForm && (
              <button
                onClick={() => setShowRequestForm(true)}
                className="w-full py-3 mb-4 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700"
              >
                + Add New Request
              </button>
            )}

            {showRequestForm && (
              <form onSubmit={handleSubmitRequest} className="mb-4 p-4 bg-gray-50 rounded-lg">
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Request Details (max 1000 characters)
                </label>
                <textarea
                  value={newRequestText}
                  onChange={(e) => setNewRequestText(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  rows={4}
                  maxLength={1000}
                  placeholder="Describe what supplies you need..."
                  required
                />
                <div className="text-xs text-gray-500 mb-3">{newRequestText.length}/1000</div>
                
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => {
                     setShowRequestForm(false);
                      setNewRequestText('');
                    }}
                    className="flex-1 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={submittingRequest || !newRequestText.trim()}
                    className="flex-1 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                  >
                    {submittingRequest ? 'Submitting...' : 'Submit'}
                  </button>
                </div>
              </form>
            )}

            <div className="space-y-3">
              {supplyRequests.length === 0 ? (
                <p className="text-sm text-gray-500 text-center py-4">No supply requests yet</p>
              ) : (
                supplyRequests.map((request) => (
                  <div key={request.id} className="p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div className="flex items-start justify-between mb-2">
                      <div className="text-sm font-medium text-gray-900">{request.created_by_name} (Shift {request.created_by_shift})</div>
                      {getRequestStatusBadge(request.status)}
                    </div>
                    <p className="text-sm text-gray-700 mb-2">{request.request_text}</p>
                    <div className="text-xs text-gray-500">
                      {new Date(request.created_at).toLocaleDateString()} {new Date(request.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
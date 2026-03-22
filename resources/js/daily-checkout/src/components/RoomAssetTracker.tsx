import { useState, useEffect } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Room, RoomAsset, RoomAudit, AssetCondition } from '../types';
import { ApiClient } from '../utils/api';

export default function RoomAssetTracker() {
  const { stationId, roomId } = useParams<{ stationId: string; roomId: string }>();
  const [room, setRoom] = useState<Room | null>(null);
  const [assets, setAssets] = useState<RoomAsset[]>([]);
  const [audits, setAudits] = useState<RoomAudit[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'assets' | 'audits'>('assets');
  const [showAddAsset, setShowAddAsset] = useState(false);
  const [showAuditForm, setShowAuditForm] = useState(false);
  
  // New asset form state
  const [newAsset, setNewAsset] = useState<Partial<RoomAsset>>({
    name: '',
    description: '',
    asset_tag: '',
    quantity: 1,
    unit: 'each',
    condition: 'good',
    location: '',
  });

  // Audit form state
  const [newAudit, setNewAudit] = useState<Partial<RoomAudit>>({
    audit_type: 'physical_count',
    notes: '',
  });

  const fetchRoomData = async () => {
    if (!stationId || !roomId) return;
    try {
      const [roomData, assetsData, auditsData] = await Promise.all([
        ApiClient.getRoom(parseInt(stationId), parseInt(roomId)),
        ApiClient.getRoomAssets(parseInt(stationId), parseInt(roomId)),
        ApiClient.getRoomAudits(parseInt(stationId), parseInt(roomId)),
      ]);
      setRoom(roomData);
      setAssets(assetsData);
      setAudits(auditsData);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load room data');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchRoomData();
  }, [stationId, roomId]);

  const handleAddAsset = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!stationId || !roomId) return;
    try {
      await ApiClient.createRoomAsset(parseInt(stationId), parseInt(roomId), newAsset);
      setShowAddAsset(false);
      setNewAsset({
        name: '',
        description: '',
        asset_tag: '',
        quantity: 1,
        unit: 'each',
        condition: 'good',
        location: '',
      });
      fetchRoomData();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add asset');
    }
  };

  const handleRecordAudit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!stationId || !roomId) return;
    try {
      await ApiClient.createRoomAudit(parseInt(stationId), parseInt(roomId), newAudit);
      setShowAuditForm(false);
      setNewAudit({
        audit_type: 'physical_count',
        notes: '',
      });
      fetchRoomData();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to record audit');
    }
  };

  const getConditionBadge = (condition: string | undefined) => {
    const colors: Record<string, string> = {
      excellent: 'bg-green-100 text-green-800',
      good: 'bg-blue-100 text-blue-800',
      fair: 'bg-yellow-100 text-yellow-800',
      poor: 'bg-orange-100 text-orange-800',
      critical: 'bg-red-100 text-red-800',
      unknown: 'bg-gray-100 text-gray-800',
    };
    const cond = condition || 'unknown';
    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[cond] || colors.unknown}`}>
        {cond}
      </span>
    );
  };

  const getAuditTypeBadge = (type: string | undefined) => {
    const colors: Record<string, string> = {
      physical_count: 'bg-blue-100 text-blue-800',
      random_spot: 'bg-purple-100 text-purple-800',
      annual: 'bg-amber-100 text-amber-800',
      incident: 'bg-red-100 text-red-800',
      transfer: 'bg-green-100 text-green-800',
    };
    const t = type || 'unknown';
    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${colors[t] || 'bg-gray-100 text-gray-800'}`}>
        {t.replace('_', ' ')}
      </span>
    );
  };

  const formatDate = (dateString: string | undefined) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-64">
        <div className="text-lg">Loading room data...</div>
      </div>
    );
  }

  if (error || !room) {
    return (
      <div className="text-center text-red-600 p-4">
        <p>Error: {error || 'Room not found'}</p>
        <Link
          to={`/stations/${stationId}`}
          className="mt-4 inline-block px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
        >
          Back to Station
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Back button and header */}
      <div className="flex items-center justify-between">
        <Link
          to={`/stations/${stationId}`}
          className="inline-flex items-center text-gray-600 hover:text-gray-900"
        >
          <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to Station
        </Link>
        <span className="px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm font-medium">
          {room.type?.replace('_', ' ') || 'Room'}
        </span>
      </div>

      {/* Room Header */}
      <div className="bg-white rounded-lg shadow-md p-6">
        <div className="flex flex-col md:flex-row md:items-start md:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900 mb-2">{room.name}</h1>
            {room.room_number && (
              <p className="text-gray-600">Room {room.room_number}</p>
            )}
            {room.floor && (
              <p className="text-gray-500 text-sm">{room.floor} floor</p>
            )}
          </div>
          <div className="mt-4 md:mt-0 text-right">
            <p className="text-sm text-gray-500">Total Assets</p>
            <p className="text-3xl font-bold text-gray-900">{assets.length}</p>
          </div>
        </div>

        {/* Quick Stats */}
        <div className="mt-6 grid grid-cols-3 gap-4">
          <div className="text-center p-3 bg-blue-50 rounded-lg">
            <p className="text-2xl font-bold text-blue-600">{assets.length}</p>
            <p className="text-sm text-blue-700">Assets</p>
          </div>
          <div className="text-center p-3 bg-purple-50 rounded-lg">
            <p className="text-2xl font-bold text-purple-600">{audits.length}</p>
            <p className="text-sm text-purple-700">Audits</p>
          </div>
          <div className="text-center p-3 bg-amber-50 rounded-lg">
            <p className="text-2xl font-bold text-amber-600">
              {assets.filter((a) => a.condition === 'poor' || a.condition === 'critical').length}
            </p>
            <p className="text-sm text-amber-700">Needs Attention</p>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white rounded-lg shadow-md overflow-hidden">
        <div className="flex overflow-x-auto border-b border-gray-200">
          <button
            onClick={() => setActiveTab('assets')}
            className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${
              activeTab === 'assets'
                ? 'border-b-2 border-blue-500 text-blue-600 bg-blue-50'
                : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
            }`}
          >
            Assets ({assets.length})
          </button>
          <button
            onClick={() => setActiveTab('audits')}
            className={`px-6 py-3 text-sm font-medium whitespace-nowrap transition-colors ${
              activeTab === 'audits'
                ? 'border-b-2 border-blue-500 text-blue-600 bg-blue-50'
                : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
            }`}
          >
            Audits ({audits.length})
          </button>
        </div>

        <div className="p-6">
          {/* Actions */}
          <div className="mb-4 flex justify-end space-x-2">
            {activeTab === 'assets' && (
              <button
                onClick={() => setShowAddAsset(true)}
                className="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600 touch-manipulation"
              >
                Add Asset
              </button>
            )}
            {activeTab === 'audits' && (
              <button
                onClick={() => setShowAuditForm(true)}
                className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 touch-manipulation"
              >
                Record Audit
              </button>
            )}
          </div>

          {/* Add Asset Form */}
          {showAddAsset && activeTab === 'assets' && (
            <form onSubmit={handleAddAsset} className="mb-6 p-4 bg-gray-50 rounded-lg">
              <h3 className="text-lg font-semibold mb-4">Add New Asset</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                  <input
                    type="text"
                    required
                    value={newAsset.name || ''}
                    onChange={(e) => setNewAsset({ ...newAsset, name: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Asset Tag</label>
                  <input
                    type="text"
                    value={newAsset.asset_tag || ''}
                    onChange={(e) => setNewAsset({ ...newAsset, asset_tag: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                  <input
                    type="number"
                    min="1"
                    value={newAsset.quantity || 1}
                    onChange={(e) => setNewAsset({ ...newAsset, quantity: parseInt(e.target.value || '1', 10) || 1 })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                  <select
                    value={newAsset.unit || 'each'}
                    onChange={(e) => setNewAsset({ ...newAsset, unit: e.target.value as 'each' | 'box' | 'case' | 'set' | 'gallon' | 'pound' | 'dozen' })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="each">Each</option>
                    <option value="box">Box</option>
                    <option value="case">Case</option>
                    <option value="set">Set</option>
                    <option value="gallon">Gallon</option>
                    <option value="pound">Pound</option>
                    <option value="dozen">Dozen</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Condition</label>
                  <select
                    value={newAsset.condition || 'good'}
                    onChange={(e) => setNewAsset({ ...newAsset, condition: e.target.value as AssetCondition })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="excellent">Excellent</option>
                    <option value="good">Good</option>
                    <option value="fair">Fair</option>
                    <option value="poor">Poor</option>
                    <option value="critical">Critical</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Location</label>
                  <input
                    type="text"
                    value={newAsset.location || ''}
                    onChange={(e) => setNewAsset({ ...newAsset, location: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                  <textarea
                    value={newAsset.description || ''}
                    onChange={(e) => setNewAsset({ ...newAsset, description: e.target.value })}
                    rows={2}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="mt-4 flex justify-end space-x-2">
                <button
                  type="button"
                  onClick={() => setShowAddAsset(false)}
                  className="px-4 py-2 text-gray-600 hover:text-gray-900"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600"
                >
                  Add Asset
                </button>
              </div>
            </form>
          )}

          {/* Record Audit Form */}
          {showAuditForm && activeTab === 'audits' && (
            <form onSubmit={handleRecordAudit} className="mb-6 p-4 bg-gray-50 rounded-lg">
              <h3 className="text-lg font-semibold mb-4">Record New Audit</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Audit Type</label>
                  <select
                    value={newAudit.audit_type || 'physical_count'}
                    onChange={(e) => setNewAudit({ ...newAudit, audit_type: e.target.value as 'physical_count' | 'random_spot' | 'annual' | 'incident' | 'transfer' })}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="physical_count">Physical Count</option>
                    <option value="random_spot">Random Spot Check</option>
                    <option value="annual">Annual Audit</option>
                    <option value="incident">Incident Report</option>
                    <option value="transfer">Transfer Audit</option>
                  </select>
                </div>
                <div className="md:col-span-2">
                  <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                  <textarea
                    value={newAudit.notes || ''}
                    onChange={(e) => setNewAudit({ ...newAudit, notes: e.target.value })}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="mt-4 flex justify-end space-x-2">
                <button
                  type="button"
                  onClick={() => setShowAuditForm(false)}
                  className="px-4 py-2 text-gray-600 hover:text-gray-900"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
                >
                  Record Audit
                </button>
              </div>
            </form>
          )}

          {/* Assets Tab */}
          {activeTab === 'assets' && (
            <div>
              {assets.length > 0 ? (
                <div className="space-y-4">
                  {assets.map((asset) => (
                    <div
                      key={asset.id}
                      className="p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
                    >
                      <div className="flex justify-between items-start">
                        <div>
                          <h4 className="font-semibold text-gray-900">{asset.name}</h4>
                          {asset.asset_tag && (
                            <p className="text-sm text-gray-600">Tag: {asset.asset_tag}</p>
                          )}
                          <p className="text-sm text-gray-500">
                            {asset.quantity} {asset.unit}
                            {asset.location && ` • ${asset.location}`}
                          </p>
                        </div>
                        <div className="text-right">
                          {getConditionBadge(asset.condition || 'unknown')}
                          {asset.last_audit_date && (
                            <p className="text-xs text-gray-500 mt-1">
                              Last audit: {formatDate(asset.last_audit_date || '')}
                            </p>
                          )}
                        </div>
                      </div>
                      {asset.description && (
                        <p className="mt-2 text-sm text-gray-600">{asset.description}</p>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-gray-500 text-center py-8">No assets recorded for this room.</p>
              )}
            </div>
          )}

          {/* Audits Tab */}
          {activeTab === 'audits' && (
            <div>
              {audits.length > 0 ? (
                <div className="space-y-4">
                  {audits.map((audit) => (
                    <div
                      key={audit.id}
                      className="p-4 border border-gray-200 rounded-lg"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div>
                          <h4 className="font-semibold text-gray-900">
                            {getAuditTypeBadge(audit.audit_type || 'unknown')}
                          </h4>
                          <p className="text-sm text-gray-500">
                            {audit.audited_by ? `By: ${audit.audited_by}` : 'Pending'}
                          </p>
                        </div>
                        <span className="text-sm text-gray-500">
                          {formatDate(audit.created_at || '')}
                        </span>
                      </div>
                      {audit.notes && (
                        <p className="text-sm text-gray-600 mt-2">{audit.notes}</p>
                      )}
                      {audit.completed_at && (
                        <div className="mt-3 pt-3 border-t border-gray-100">
                          <p className="text-sm text-green-600">
                            ✓ Completed: {formatDate(audit.completed_at || '')}
                          </p>
                          {audit.items_checked !== undefined && (
                            <p className="text-sm text-gray-600">
                              Items checked: {audit.items_checked}
                            </p>
                          )}
                          {audit.discrepancies !== undefined && audit.discrepancies > 0 && (
                            <p className="text-sm text-red-600">
                              Discrepancies: {audit.discrepancies}
                            </p>
                          )}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-gray-500 text-center py-8">No audits recorded for this room.</p>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
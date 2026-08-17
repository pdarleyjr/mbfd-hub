import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router';
import SignatureCanvas from 'react-signature-canvas';
import type { EmployeeOption, Room, RoomAsset, Station, StationRequestSummary, StationRequestType } from '../../types';
import { createClientSubmissionId, submitOrQueueWithResponse, type SubmissionOutcome } from '../../lib/sync';
import { ApiClient } from '../../utils/api';
import { safeReturnTo } from '../../utils/stationRequestNavigation';
import { availableRoomAreas, roomDetailPrompt, roomsForArea, type RoomArea } from '../../utils/stationRoomBlueprint';
import PreviousPageButton from '../PreviousPageButton';

type Priority = 'low' | 'normal' | 'high' | 'critical';
type Reason = 'Damaged/Broken' | 'Lost' | 'Stolen' | 'Needed' | 'Replacement' | 'End of Service Life' | 'Other';
type RepairTarget = 'facility_room' | 'existing_asset' | 'untracked_item';

interface DraftItem {
  id: string;
  roomAssetId: string;
  itemName: string;
  category: string;
  quantity: number;
  reason: Reason;
  requestedAction: 'inspect' | 'repair' | 'replace' | 'service' | 'remove';
  condition: string;
  pdCaseNumber: string;
  photo: string;
}

const REPAIR_CATEGORIES = [
  ['hvac', 'HVAC'], ['electrical', 'Electrical'], ['plumbing', 'Plumbing'],
  ['doors_locks', 'Doors / Locks'], ['appliance', 'Appliance'], ['kitchen', 'Kitchen'],
  ['dorm_furniture', 'Dorm / Furniture'], ['restroom', 'Restroom'],
  ['apparatus_bay', 'Apparatus Bay / Overhead Door'], ['it_communications', 'IT / Communications'],
  ['building_structural', 'Building / Structural'], ['grounds', 'Grounds'],
  ['pest_control', 'Pest Control'], ['cleaning_sanitation', 'Cleaning / Sanitation'], ['other', 'Other'],
] as const;

const makeItem = (type: StationRequestType): DraftItem => ({
  id: createClientSubmissionId(),
  roomAssetId: '',
  itemName: '',
  category: type === 'repair_service' ? 'appliance' : '',
  quantity: 1,
  reason: type === 'repair_service' ? 'Damaged/Broken' : 'Needed',
  requestedAction: type === 'repair_service' ? 'inspect' : 'replace',
  condition: type === 'repair_service' ? 'needs_repair' : '',
  pdCaseNumber: '',
  photo: '',
});

const fileToDataUrl = async (file: File): Promise<string> => {
  const imageCompression = (await import('browser-image-compression')).default;
  const compressed = await imageCompression(file, {
    maxSizeMB: 1.5,
    maxWidthOrHeight: 1920,
    useWebWorker: true,
  });

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('The image could not be read.'));
    reader.readAsDataURL(compressed);
  });
};

export default function StationRequestWizard() {
  const [searchParams] = useSearchParams();
  const initialType = searchParams.get('type') === 'equipment' ? 'equipment' : 'repair_service';
  const initialStationId = /^\d+$/.test(searchParams.get('station_id') ?? '') ? searchParams.get('station_id')! : '';
  const returnTo = safeReturnTo(searchParams.get('return_to'), initialStationId ? `/stations/${initialStationId}` : '/stations');
  const clientSubmissionId = useRef(createClientSubmissionId());
  const initialOptionsLoadStarted = useRef(false);
  const memberSignature = useRef<SignatureCanvas>(null);
  const officerSignature = useRef<SignatureCanvas>(null);

  const [step, setStep] = useState(1);
  const [stations, setStations] = useState<Station[]>([]);
  const [employees, setEmployees] = useState<EmployeeOption[]>([]);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [assets, setAssets] = useState<RoomAsset[]>([]);
  const [requestType, setRequestType] = useState<StationRequestType>(initialType);
  const [stationId, setStationId] = useState(initialStationId);
  const [employeeId, setEmployeeId] = useState('');
  const [employeeSearch, setEmployeeSearch] = useState('');
  const [roomId, setRoomId] = useState('');
  const [roomArea, setRoomArea] = useState<RoomArea | ''>('');
  const [roomNotListed, setRoomNotListed] = useState(false);
  const [roomNameSnapshot, setRoomNameSnapshot] = useState('');
  const [subjectType, setSubjectType] = useState(initialType === 'repair_service' ? 'appliance' : 'equipment');
  const [repairTarget, setRepairTarget] = useState<RepairTarget>('facility_room');
  const [outOfService, setOutOfService] = useState(false);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [priority, setPriority] = useState<Priority>('normal');
  const [items, setItems] = useState<DraftItem[]>([makeItem(initialType)]);
  const [loadingOptions, setLoadingOptions] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [outcome, setOutcome] = useState<SubmissionOutcome | null>(null);
  const [requestNumber, setRequestNumber] = useState('');
  const [memberSignatureData, setMemberSignatureData] = useState('');
  const [officerSignatureData, setOfficerSignatureData] = useState('');
  const [processingPhotoIds, setProcessingPhotoIds] = useState<string[]>([]);

  const totalSteps = requestType === 'equipment' ? 4 : 3;
  const selectedStation = stations.find((station) => String(station.id) === stationId);
  const selectedRoom = rooms.find((room) => String(room.id) === roomId);
  const selectedEmployee = employees.find((employee) => String(employee.id) === employeeId);
  const roomAreas = availableRoomAreas(rooms);
  const selectedAreaRooms = roomArea ? roomsForArea(rooms, roomArea) : [];
  const normalizedEmployeeSearch = employeeSearch.trim().toLocaleLowerCase();
  const filteredEmployees = employees.filter((employee) => (
    String(employee.id) === employeeId
    || normalizedEmployeeSearch === ''
    || `${employee.name} ${employee.rank ?? ''}`.toLocaleLowerCase().includes(normalizedEmployeeSearch)
  ));
  const stepLabels = requestType === 'equipment'
    ? ['Context', 'Request', 'Signatures', 'Review']
    : ['Context', 'Request', 'Review'];

  const loadOptions = useCallback(() => {
    setLoadingOptions(true);
    setError('');
    return Promise.all([ApiClient.getStations(), ApiClient.getEmployees()])
      .then(([stationRows, employeeRows]) => {
        setStations(stationRows);
        setEmployees(employeeRows);
        setError('');
      })
      .catch(() => setError('Station and employee options could not be loaded. Check the connection and try again.'))
      .finally(() => setLoadingOptions(false));
  }, []);

  useEffect(() => {
    if (initialOptionsLoadStarted.current) return;
    initialOptionsLoadStarted.current = true;
    void loadOptions();
  }, [loadOptions]);

  useEffect(() => {
    setRooms([]);
    setRoomId('');
    setRoomArea('');
    setRoomNotListed(false);
    setRoomNameSnapshot('');
    setAssets([]);
    if (!stationId) return;
    const controller = new AbortController();
    fetch(`/api/public/stations/${stationId}/rooms`, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal })
      .then((response) => {
        if (!response.ok) throw new Error();
        return response.json();
      })
      .then((payload) => {
        setRooms(payload.rooms || []);
        setError('');
      })
      .catch((fetchError) => {
        if (fetchError instanceof DOMException && fetchError.name === 'AbortError') return;
        setError('Rooms for this station could not be loaded.');
      });
    return () => controller.abort();
  }, [stationId]);

  useEffect(() => {
    setAssets([]);
    if (!stationId || !roomId) return;
    let cancelled = false;
    ApiClient.getRoomAssets(Number(stationId), Number(roomId))
      .then((assetRows) => {
        if (cancelled) return;
        setAssets(assetRows);
        setError('');
      })
      .catch(() => {
        if (!cancelled) setError('Assets for this room could not be loaded.');
      });
    return () => { cancelled = true; };
  }, [stationId, roomId]);

  useEffect(() => {
    if (step !== 3 || requestType !== 'equipment') return;
    const frame = window.requestAnimationFrame(() => {
      if (memberSignatureData && memberSignature.current?.isEmpty()) {
        memberSignature.current.fromDataURL(memberSignatureData);
      }
      if (officerSignatureData && officerSignature.current?.isEmpty()) {
        officerSignature.current.fromDataURL(officerSignatureData);
      }
    });
    return () => window.cancelAnimationFrame(frame);
  }, [step, requestType, memberSignatureData, officerSignatureData]);

  useEffect(() => {
    setItems([makeItem(requestType)]);
    setSubjectType(requestType === 'repair_service' ? 'appliance' : 'equipment');
    setRepairTarget('facility_room');
    setOutOfService(false);
    setMemberSignatureData('');
    setOfficerSignatureData('');
    setStep(1);
  }, [requestType]);

  const updateItem = (id: string, changes: Partial<DraftItem>) => {
    setItems((current) => current.map((item) => item.id === id ? { ...item, ...changes } : item));
  };

  const selectedAssetLabel = (assetId: string) => assets.find((asset) => String(asset.id) === assetId)?.name;

  const preparePhoto = async (itemId: string, file: File) => {
    setProcessingPhotoIds((current) => current.includes(itemId) ? current : [...current, itemId]);
    setError('');
    try {
      updateItem(itemId, { photo: await fileToDataUrl(file) });
    } catch {
      setError('The selected photo could not be prepared. Choose a JPEG, PNG, or WebP image and try again.');
    } finally {
      setProcessingPhotoIds((current) => current.filter((id) => id !== itemId));
    }
  };

  const getValidationMessage = () => {
    if (step === 1) {
      if (!stationId) return 'Select the station responsible for this request.';
      if (!loadingOptions && !selectedStation) return 'The selected station is unavailable.';
      if (!employeeId) return 'Select the requesting employee.';
      if (roomNotListed && !roomNameSnapshot.trim()) return 'Enter the room or location that is not listed.';
      if (roomArea && !roomNotListed && !roomId) return 'Select the specific room or area.';
    }
    if (step === 2) {
      if (requestType === 'repair_service' && repairTarget === 'existing_asset') {
        if (!roomId) return 'Select a listed room before choosing an existing asset.';
        if (!items[0]?.roomAssetId) return 'Select the existing room asset that needs attention.';
      }
      if (!title.trim()) return 'Enter a short request title.';
      if (!description.trim()) return 'Describe what is needed and why.';
      if (items.length > 25) return 'A station request can include no more than 25 items.';
      if (processingPhotoIds.length > 0) return 'Wait for each selected photo to finish preparing.';
      for (const item of items) {
        if (!item.itemName.trim()) return 'Enter a name for every requested item.';
        if (item.quantity < 1) return 'Every quantity must be at least one.';
        if (item.quantity > 100) return 'Every quantity must be 100 or less.';
        if (item.reason === 'Stolen' && !item.pdCaseNumber.trim()) return 'A police case number is required for stolen equipment.';
      }
    }
    if (step === 3 && requestType === 'equipment') {
      if (memberSignature.current?.isEmpty() !== false) return 'The requesting member signature is required.';
      if (officerSignature.current?.isEmpty() !== false) return 'The company officer signature is required.';
    }
    return '';
  };

  const advance = () => {
    setError('');
    const validationMessage = getValidationMessage();
    if (validationMessage) {
      setError(validationMessage);
      return;
    }
    if (step === 3 && requestType === 'equipment') {
      setMemberSignatureData(memberSignature.current!.toDataURL('image/png'));
      setOfficerSignatureData(officerSignature.current!.toDataURL('image/png'));
    }
    setStep((current) => Math.min(totalSteps, current + 1));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleSubmit = async () => {
    setError('');
    setSubmitting(true);
    try {
      const payload: Record<string, unknown> = {
        client_submission_id: clientSubmissionId.current,
        station_id: Number(stationId),
        room_id: roomId ? Number(roomId) : null,
        room_name_snapshot: roomNotListed ? roomNameSnapshot.trim() : null,
        requested_by_employee_id: Number(employeeId),
        request_type: requestType,
        subject_type: requestType === 'repair_service'
          ? repairTarget === 'existing_asset'
            ? 'existing_asset'
            : repairTarget === 'untracked_item'
              ? 'other'
              : (roomId || roomNameSnapshot.trim() ? 'room' : 'facility')
          : 'equipment_item',
        title: title.trim(),
        description: description.trim(),
        priority,
        submitted_at: new Date().toISOString(),
        items: items.map((item) => ({
          room_asset_id: item.roomAssetId ? Number(item.roomAssetId) : null,
          item_name: item.itemName.trim(),
          category: item.category || subjectType,
          quantity: item.quantity,
          reason: item.reason,
          requested_action: item.requestedAction,
          condition: requestType === 'repair_service' && outOfService ? 'out_of_service' : item.condition || null,
          pd_case_number: item.pdCaseNumber.trim() || null,
          photo: item.photo || null,
        })),
      };
      if (requestType === 'equipment') {
        payload.member_signature = memberSignatureData;
        payload.officer_signature = officerSignatureData;
      }
      const result = await submitOrQueueWithResponse('station_request', payload, '/api/public');
      setOutcome(result.outcome);
      const response = result.response as { data?: StationRequestSummary } | null;
      setRequestNumber(response?.data?.request_number ?? '');
    } catch (submissionError) {
      setError(submissionError instanceof Error ? submissionError.message : 'The request could not be submitted.');
    } finally {
      setSubmitting(false);
    }
  };

  if (outcome) {
    return (
      <div className="mx-auto max-w-2xl rounded-2xl bg-white p-6 text-center ring-1 ring-stone-200 sm:p-10">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-700" aria-hidden="true">
          <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>
        </div>
        <h1 className="mt-5 font-heading text-2xl font-bold text-slate-900">{outcome === 'submitted' ? 'Request submitted' : 'Request saved offline'}</h1>
        <p className="mt-2 text-stone-600">
          {outcome === 'submitted'
            ? `${requestNumber || 'Your station request'} is now in the Support Services queue.`
            : 'This request is safely stored on this device and will sync automatically when connectivity returns.'}
        </p>
        {outcome === 'queued' && <p className="mt-3 font-mono text-xs text-stone-500">Offline reference {clientSubmissionId.current.slice(0, 8).toUpperCase()}</p>}
        <PreviousPageButton fallback={returnTo} className="mt-7 inline-flex min-h-12 items-center justify-center rounded-xl bg-blue-700 px-6 py-3 font-semibold text-white hover:bg-blue-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl space-y-5 pb-[max(2rem,env(safe-area-inset-bottom))]">
      <div className="flex items-center justify-between gap-4">
        <PreviousPageButton fallback={returnTo} className="inline-flex min-h-12 items-center gap-2 rounded-lg px-2 text-sm font-semibold text-stone-600 hover:text-slate-900">
          <span aria-hidden="true">←</span> Back to previous page
        </PreviousPageButton>
        <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-blue-800">Station request</span>
      </div>

      <header className="rounded-2xl bg-slate-900 p-5 text-white sm:p-7">
        <p className="text-sm font-semibold text-blue-200">Repair • Service • Equipment</p>
        <h1 className="mt-1 font-heading text-2xl font-bold sm:text-3xl">Tell Support Services what the station needs.</h1>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">One request number follows the work from submission through completion and room-asset history.</p>
      </header>

      <ol className={`grid gap-2 ${requestType === 'equipment' ? 'grid-cols-4' : 'grid-cols-3'}`} aria-label="Request progress">
        {stepLabels.map((label, index) => {
          const number = index + 1;
          return <li key={label} className={`rounded-lg px-2 py-2 text-center text-xs font-semibold ${number === step ? 'bg-blue-700 text-white' : number < step ? 'bg-blue-50 text-blue-800' : 'bg-stone-100 text-stone-500'}`} aria-current={number === step ? 'step' : undefined}>{number}. {label}</li>;
        })}
      </ol>

      {error && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{error}{!stations.length && !loadingOptions && <button type="button" onClick={() => void loadOptions()} className="ml-3 min-h-11 rounded-lg border border-red-300 bg-white px-3 font-bold hover:bg-red-100">Retry</button>}</div>}

      <section className="rounded-2xl bg-white p-5 ring-1 ring-stone-200 sm:p-7">
        {step === 1 && (
          <div className="space-y-6">
            <div>
              <h2 className="font-heading text-xl font-bold text-slate-900">Request context</h2>
              <p className="mt-1 text-sm text-stone-600">The selected station and room stay attached to the request and its history.</p>
            </div>
            <fieldset>
              <legend className="mb-2 text-sm font-semibold text-slate-800">What do you need?</legend>
              <div className="grid gap-3 sm:grid-cols-2">
                {[['repair_service', 'Repair / service', 'Report a facility, room, or asset issue.'], ['equipment', 'Equipment', 'Request one or more station equipment items.']].map(([value, label, detail]) => (
                  <button key={value} type="button" onClick={() => setRequestType(value as StationRequestType)} className={`min-h-20 rounded-xl border p-4 text-left ${requestType === value ? 'border-blue-700 bg-blue-50 ring-2 ring-blue-700' : 'border-stone-300 hover:border-blue-400'}`} aria-pressed={requestType === value}>
                    <span className="block font-semibold text-slate-900">{label}</span><span className="mt-1 block text-sm text-stone-600">{detail}</span>
                  </button>
                ))}
              </div>
            </fieldset>
            <div className="grid gap-5 sm:grid-cols-2">
              {initialStationId ? (
                <div className="text-sm font-semibold text-slate-800">
                  Station
                  <div className="mt-2 flex min-h-12 items-center rounded-xl border border-stone-200 bg-stone-100 px-3 text-base font-bold text-slate-900" aria-label="Selected station">
                    {selectedStation ? `Station ${selectedStation.station_number}` : loadingOptions ? 'Loading station…' : 'Station unavailable'}
                  </div>
                </div>
              ) : (
                <label className="text-sm font-semibold text-slate-800">Station <span className="text-red-700">*</span>
                  <select value={stationId} onChange={(event) => setStationId(event.target.value)} disabled={loadingOptions} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base focus:border-blue-700 focus:ring-blue-700">
                    <option value="">Select station</option>{stations.map((station) => <option key={station.id} value={station.id}>Station {station.station_number}</option>)}
                  </select>
                </label>
              )}
              <div className="text-sm font-semibold text-slate-800">
                <label htmlFor="employee-search">Search employees</label>
                <input id="employee-search" type="search" value={employeeSearch} onChange={(event) => setEmployeeSearch(event.target.value)} disabled={loadingOptions} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base font-normal focus:border-blue-700 focus:ring-blue-700" placeholder="Name or rank" />
              </div>
              <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Requesting employee <span className="text-red-700">*</span>
                <select value={employeeId} onChange={(event) => setEmployeeId(event.target.value)} disabled={loadingOptions} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base focus:border-blue-700 focus:ring-blue-700">
                  <option value="">{normalizedEmployeeSearch && filteredEmployees.length === 0 ? 'No matching employees' : 'Select employee'}</option>{filteredEmployees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}{employee.rank ? ` — ${employee.rank}` : ''}</option>)}
                </select>
              </label>
              <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Room area <span className="font-normal text-stone-500">(optional for station-wide work)</span>
                <select value={roomNotListed ? 'other' : roomArea} onChange={(event) => {
                  const value = event.target.value;
                  setRoomNotListed(value === 'other');
                  setRoomArea(value === 'other' ? '' : value as RoomArea | '');
                  setRoomId('');
                  if (value !== 'other') setRoomNameSnapshot('');
                  setAssets([]);
                  setItems((current) => current.map((item) => ({ ...item, roomAssetId: '' })));
                }} disabled={!stationId} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base focus:border-blue-700 focus:ring-blue-700">
                  <option value="">Station-wide / no single room</option>{roomAreas.map((area) => <option key={area.value} value={area.value}>{area.label}</option>)}<option value="other">Room not listed / Other</option>
                </select>
              </label>
              {roomArea && !roomNotListed && <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Specific room / area <span className="text-red-700">*</span>
                <select value={roomId} onChange={(event) => {
                  setRoomId(event.target.value);
                  setAssets([]);
                  setItems((current) => current.map((item) => ({ ...item, roomAssetId: '' })));
                }} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base focus:border-blue-700 focus:ring-blue-700">
                  <option value="">{roomDetailPrompt(roomArea)}</option>{selectedAreaRooms.map((room) => <option key={room.id} value={room.id}>{room.name}</option>)}
                </select>
              </label>}
              {roomNotListed && <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Room or location <span className="text-red-700">*</span><input value={roomNameSnapshot} onChange={(event) => setRoomNameSnapshot(event.target.value)} maxLength={255} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 px-3 text-base" placeholder="Example: Rear storage alcove" /><span className="mt-2 block font-normal text-stone-500">This preserves the location on the request for later admin reconciliation; it does not create a room record.</span></label>}
            </div>
          </div>
        )}

        {step === 2 && (
          <div className="space-y-6">
            <div><h2 className="font-heading text-xl font-bold text-slate-900">Request details</h2><p className="mt-1 text-sm text-stone-600">Be specific enough that the next person can act without calling the station back.</p></div>
            {requestType === 'repair_service' && <fieldset><legend className="mb-2 text-sm font-semibold text-slate-800">What needs attention?</legend><div className="grid gap-3 sm:grid-cols-3">{[
              ['facility_room', 'Facility / room', 'A building, room, grounds, or utility issue.'],
              ['existing_asset', 'Existing room asset', 'Repair or service an item already tracked in this room.'],
              ['untracked_item', 'Item not in inventory', 'Report an item without creating an inventory record.'],
            ].map(([value, label, detail]) => <button key={value} type="button" onClick={() => {
              setRepairTarget(value as RepairTarget);
              if (value !== 'existing_asset') setItems((current) => current.map((item) => ({ ...item, roomAssetId: '' })));
            }} className={`min-h-24 rounded-xl border p-4 text-left ${repairTarget === value ? 'border-blue-700 bg-blue-50 ring-2 ring-blue-700' : 'border-stone-300 hover:border-blue-400'}`} aria-pressed={repairTarget === value}><span className="block font-semibold text-slate-900">{label}</span><span className="mt-1 block text-sm text-stone-600">{detail}</span></button>)}</div></fieldset>}
            <div className="grid gap-5 sm:grid-cols-2">
              <label className="text-sm font-semibold text-slate-800">Category
                {requestType === 'repair_service' ? (
                  <select value={subjectType} onChange={(event) => setSubjectType(event.target.value)} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base">{REPAIR_CATEGORIES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                ) : <input value={subjectType} onChange={(event) => setSubjectType(event.target.value)} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 px-3 text-base" placeholder="PPE, communications, hose…" />}
              </label>
              <label className="text-sm font-semibold text-slate-800">Priority
                <select value={priority} onChange={(event) => setPriority(event.target.value as Priority)} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3 text-base"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="critical">Critical — immediate operational impact</option></select>
              </label>
              <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Short title <span className="text-red-700">*</span>
                <input value={title} onChange={(event) => setTitle(event.target.value)} maxLength={255} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 px-3 text-base" placeholder="Example: Kitchen refrigerator is not cooling" />
              </label>
              <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Description and operational impact <span className="text-red-700">*</span>
                <textarea value={description} onChange={(event) => setDescription(event.target.value)} rows={4} maxLength={5000} className="mt-2 w-full rounded-xl border border-stone-300 p-3 text-base" placeholder="What happened, when it started, and what work or replacement is needed?" />
              </label>
            </div>

            <div className="space-y-4">
              <div className="flex items-center justify-between gap-3"><h3 className="font-heading text-lg font-bold text-slate-900">{requestType === 'equipment' ? 'Equipment items' : 'Affected item or asset'}</h3>{requestType === 'equipment' && <button type="button" onClick={() => setItems((current) => current.length >= 25 ? current : [...current, makeItem('equipment')])} disabled={items.length >= 25} className="min-h-12 rounded-xl bg-blue-50 px-4 font-semibold text-blue-800 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50" title={items.length >= 25 ? 'Maximum 25 items per request' : undefined}>+ Add item</button>}</div>
              {items.map((item, index) => (
                <fieldset key={item.id} className="rounded-xl border border-stone-200 bg-stone-50/60 p-4">
                  <legend className="px-1 text-sm font-bold text-slate-800">Item {index + 1}</legend>
                  <div className="grid gap-4 sm:grid-cols-2">
                    {roomId && (requestType === 'equipment' || repairTarget === 'existing_asset') && assets.length > 0 && <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Existing room asset {requestType === 'repair_service' ? <span className="text-red-700">*</span> : <span className="font-normal text-stone-500">(optional replacement link)</span>}<select value={item.roomAssetId} onChange={(event) => { const asset = assets.find((row) => String(row.id) === event.target.value); updateItem(item.id, { roomAssetId: event.target.value, itemName: asset?.name || item.itemName, condition: asset?.condition || item.condition }); }} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3"><option value="">{requestType === 'repair_service' ? 'Select an existing asset' : 'Not linked to an existing asset'}</option>{assets.map((asset) => <option key={asset.id} value={asset.id}>{asset.name} — {asset.condition}</option>)}</select></label>}
                    {requestType === 'repair_service' && repairTarget === 'existing_asset' && roomId && assets.length === 0 && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:col-span-2">No active assets are recorded in this room. Choose “Item not in inventory” to report it without creating an inventory record.</div>}
                    <label className="text-sm font-semibold text-slate-800">Item name <span className="text-red-700">*</span><input value={item.itemName} onChange={(event) => updateItem(item.id, { itemName: event.target.value })} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 px-3" placeholder={requestType === 'equipment' ? 'Portable radio' : 'Refrigerator'} /></label>
                    <label className="text-sm font-semibold text-slate-800">Quantity<input type="number" min={1} max={100} value={item.quantity} onChange={(event) => updateItem(item.id, { quantity: Math.max(1, Number(event.target.value)) })} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 px-3" /></label>
                    {requestType === 'repair_service' ? <label className="text-sm font-semibold text-slate-800">Requested action<select value={item.requestedAction} onChange={(event) => updateItem(item.id, { requestedAction: event.target.value as DraftItem['requestedAction'] })} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3"><option value="inspect">Inspect</option><option value="repair">Repair</option><option value="replace">Replace</option><option value="service">Service</option><option value="remove">Remove</option></select></label> : <label className="text-sm font-semibold text-slate-800">Reason<select value={item.reason} onChange={(event) => updateItem(item.id, { reason: event.target.value as Reason, pdCaseNumber: '' })} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 bg-white px-3"><option value="Needed">New / Needed</option><option value="Replacement">Replacement</option><option value="Damaged/Broken">Damaged / Broken</option><option value="Lost">Lost</option><option value="Stolen">Stolen</option><option value="End of Service Life">End of Service Life</option><option value="Other">Other</option></select></label>}
                    {item.reason === 'Stolen' && <label className="text-sm font-semibold text-slate-800">Police case number <span className="text-red-700">*</span><input value={item.pdCaseNumber} onChange={(event) => updateItem(item.id, { pdCaseNumber: event.target.value })} className="mt-2 min-h-12 w-full rounded-xl border border-stone-300 px-3" /></label>}
                    {(requestType === 'repair_service' || item.reason === 'Damaged/Broken') && <label className="text-sm font-semibold text-slate-800 sm:col-span-2">Damage photo <span className="font-normal text-stone-500">(optional)</span><input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => { const file = event.target.files?.[0]; if (file) void preparePhoto(item.id, file); }} disabled={processingPhotoIds.includes(item.id)} className="mt-2 block min-h-12 w-full rounded-xl border border-stone-300 bg-white p-2 text-sm disabled:cursor-wait disabled:opacity-60" />{processingPhotoIds.includes(item.id) ? <p className="mt-2 text-sm font-medium text-blue-700">Preparing photo…</p> : item.photo && <p className="mt-2 text-sm font-medium text-emerald-700">Photo attached</p>}</label>}
                    {requestType === 'repair_service' && <label className="flex min-h-12 items-center gap-3 rounded-xl border border-stone-300 bg-white px-3 text-sm font-semibold text-slate-800 sm:col-span-2"><input type="checkbox" checked={outOfService} onChange={(event) => setOutOfService(event.target.checked)} className="h-5 w-5 rounded border-stone-400 text-blue-700 focus:ring-blue-700" />The item or area is unusable / out of service</label>}
                  </div>
                  {requestType === 'equipment' && items.length > 1 && <button type="button" onClick={() => setItems((current) => current.filter((row) => row.id !== item.id))} className="mt-4 min-h-12 rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50">Remove item</button>}
                </fieldset>
              ))}
            </div>
          </div>
        )}

        {step === 3 && requestType === 'equipment' && (
          <div className="space-y-6"><div><h2 className="font-heading text-xl font-bold text-slate-900">Required signatures</h2><p className="mt-1 text-sm text-stone-600">Both signatures are retained with the request record.</p></div>{[['Requesting member', memberSignature], ['Company officer', officerSignature]].map(([label, ref], index) => <div key={label as string}><div className="mb-2 flex items-center justify-between"><p className="text-sm font-semibold text-slate-800">{label as string} <span className="text-red-700">*</span></p><button type="button" onClick={() => { (ref as React.RefObject<SignatureCanvas>).current?.clear(); if (index === 0) setMemberSignatureData(''); else setOfficerSignatureData(''); }} className="min-h-12 px-3 text-sm font-semibold text-red-700">Clear</button></div><div className="overflow-hidden rounded-xl border-2 border-stone-300 bg-white"><SignatureCanvas ref={ref as React.RefObject<SignatureCanvas>} penColor="#0f172a" canvasProps={{ className: 'h-40 w-full touch-none', 'aria-label': `${label} signature pad` }} /></div></div>)}</div>
        )}

        {step === totalSteps && (
          <div className="space-y-6"><div><h2 className="font-heading text-xl font-bold text-slate-900">Review and submit</h2><p className="mt-1 text-sm text-stone-600">Confirm the station context before sending.</p></div><dl className="divide-y divide-stone-200 rounded-xl border border-stone-200"><ReviewRow label="Station" value={selectedStation ? `Station ${selectedStation.station_number}` : '—'} /><ReviewRow label="Requester" value={selectedEmployee?.name ?? '—'} /><ReviewRow label="Room" value={selectedRoom?.name ?? (roomNotListed ? roomNameSnapshot : 'Station-wide')} /><ReviewRow label="Type" value={requestType === 'repair_service' ? 'Repair / service' : 'Equipment'} /><ReviewRow label="Priority" value={priority} /><ReviewRow label="Title" value={title} /><ReviewRow label="Items" value={items.map((item) => `${item.quantity}× ${selectedAssetLabel(item.roomAssetId) || item.itemName}`).join(', ')} /><ReviewRow label="Description" value={description} /></dl><div className="rounded-xl bg-blue-50 p-4 text-sm text-blue-900">Status updates and public responses will appear in the station’s Requests tab. Internal administrative notes are never shown there.</div></div>
        )}
      </section>

      <div className="sticky bottom-3 flex gap-3 rounded-2xl bg-white/95 p-3 shadow-lg ring-1 ring-stone-200 backdrop-blur">
        {step > 1 && <button type="button" onClick={() => { setError(''); setStep((current) => current - 1); }} className="min-h-12 flex-1 rounded-xl border border-stone-300 px-4 font-semibold text-slate-800 hover:bg-stone-50">Back</button>}
        {step < totalSteps ? <button type="button" onClick={advance} disabled={processingPhotoIds.length > 0} className="min-h-12 flex-[2] rounded-xl bg-blue-700 px-5 font-bold text-white hover:bg-blue-800 disabled:cursor-wait disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700">{processingPhotoIds.length > 0 ? 'Preparing photo…' : 'Continue'}</button> : <button type="button" onClick={handleSubmit} disabled={submitting} className="min-h-12 flex-[2] rounded-xl bg-orange-600 px-5 font-bold text-white hover:bg-orange-700 disabled:cursor-wait disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-700">{submitting ? 'Submitting…' : 'Submit station request'}</button>}
      </div>
    </div>
  );
}

function ReviewRow({ label, value }: { label: string; value: string }) {
  return <div className="grid gap-1 px-4 py-3 sm:grid-cols-[9rem_1fr]"><dt className="text-sm font-semibold text-stone-500">{label}</dt><dd className="text-sm font-medium text-slate-900 first-letter:uppercase">{value}</dd></div>;
}

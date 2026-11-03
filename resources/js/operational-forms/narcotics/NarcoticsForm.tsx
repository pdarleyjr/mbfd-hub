import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react';
import { Button, Field, Input, MessageBar, MessageBarBody, ProgressBar, Select, Spinner, Textarea } from '@fluentui/react-components';
import { ArrowLeft, Camera, CheckCircle2, ImagePlus, ImageUp, RefreshCw, Send, Trash2, ZoomIn } from 'lucide-react';
import { NarcoticsApiError, type NarcoticsApi } from './narcoticsApi';
import { SENT_MESSAGE, describeFailure, formPhase, formatBytes, newSubmissionId } from './narcoticsImage.js';
import type { NarcoticsCheckSummary, NarcoticsCheckDetail, NarcoticsFormDefinition, PreparedPhoto } from './narcoticsTypes';
import {
    blankUnit, pickUnit, serverUnitErrors, trimUnitText, unitErrors, unitFromCheck, unitPayload, unitSettings, unitStarted,
} from './narcoticsUnit.js';
import { sealError, sealFromCheck, sealPayload, sealSettings, serverSealError } from './narcoticsSeal.js';
import { CameraCapture, canUseCamera } from './CameraCapture';
import { PhotoViewer } from './PhotoViewer';
import { PhotoProblem, preparePhoto } from './processPhoto';
import { useNarcoticsBusy } from './useNarcoticsBusy';

/** The rejected check's stored unit and seal number, which the answer starts with. */
export type UnitSource = Pick<NarcoticsCheckSummary, 'rescue_unit_choice' | 'rescue_unit_other' | 'seal_number'>;

interface Props {
    api: NarcoticsApi;
    definition: NarcoticsFormDefinition;
    /** The rejected check this one answers (the old record is never edited). */
    resubmitOf: string | null;
    /** Its rescue unit and seal number: prefilled, still editable. */
    resubmitUnit?: UnitSource | null;
    goForms: () => void;
    goMine: () => void;
    goDetail: (id: string) => void;
    onSent: () => void;
}

interface Failure { message: string; retry: boolean; newPhoto: boolean }

/** A prepared photo waiting to be sent, with the object URL of its in-memory blob for the preview. */
interface HeldPhoto { key: number; photo: PreparedPhoto; url: string }

const photoInfo = (photo: PreparedPhoto) => (
    `${photo.processed ? `${photo.width} × ${photo.height} · ${photo.type === 'image/webp' ? 'WebP' : 'JPEG'} · ` : 'Original photo · '}${formatBytes(photo.blob.size)}`
);

/** Take or upload one or more photos, add an optional note, send them once. Confirmation appears only after the server has stored them. */
export function NarcoticsForm({ api, definition, resubmitOf, resubmitUnit = null, goForms, goMine, goDetail, onSent }: Props) {
    const unitConfig = useMemo(() => unitSettings(definition), [definition]);
    // An older server answer has no max_photos: it takes exactly one photo.
    const maxPhotos = Math.max(1, Math.floor(definition.max_photos ?? 1));
    const [photos, setPhotos] = useState<HeldPhoto[]>([]);
    const [preparing, setPreparing] = useState(false);
    const [unit, setUnit] = useState(() => (resubmitUnit ? unitFromCheck(resubmitUnit, unitConfig) : blankUnit()));
    // True once the member picks or types a unit themselves: only then is it unsaved work (a prefill is not).
    const [unitEdited, setUnitEdited] = useState(false);
    const sealConfig = useMemo(() => sealSettings(definition), [definition]);
    const [seal, setSeal] = useState(() => sealFromCheck(resubmitUnit));
    // As with the unit: only a seal number the member typed is unsaved work, not a prefill.
    const [sealEdited, setSealEdited] = useState(false);
    const [sealServerError, setSealServerError] = useState<string | undefined>(undefined);
    // Set when a send is attempted with something required missing: from then on the required messages show.
    const [attempted, setAttempted] = useState(false);
    const [unitServerErrors, setUnitServerErrors] = useState<{ choice?: string; other?: string }>({});
    const [note, setNote] = useState('');
    const [notice, setNotice] = useState<{ intent: 'info' | 'warning' | 'error'; text: string } | null>(null);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [viewing, setViewing] = useState<number | null>(null);
    const [sending, setSending] = useState(false);
    const [progress, setProgress] = useState(0);
    const [failure, setFailure] = useState<Failure | null>(null);
    const [sent, setSent] = useState<NarcoticsCheckDetail | null>(null);

    const takeInput = useRef<HTMLInputElement>(null);
    const pickInput = useRef<HTMLInputElement>(null);
    const unitSelect = useRef<HTMLSelectElement>(null);
    const unitOtherInput = useRef<HTMLInputElement>(null);
    const sealInput = useRef<HTMLInputElement>(null);
    const submissionId = useRef(newSubmissionId());
    const sendingNow = useRef(false);
    const preparingNow = useRef(0);
    const nextKey = useRef(1);
    // Mirrors `photos` for the async picker and for releasing every preview URL when the form closes.
    const held = useRef<HeldPhoto[]>([]);
    held.current = photos;

    const phase = formPhase({ hasPhoto: photos.length > 0, note, hasUnit: unitEdited && unitStarted(unit), hasSeal: sealEdited && seal.trim() !== '', sending, failed: failure !== null, saved: sent !== null }) as
        'empty' | 'dirty' | 'sending' | 'failed' | 'saved';
    useNarcoticsBusy(phase);

    // Each preview is an object URL of an in-memory blob; release them all when the form closes. A photo still
    // being prepared then is dropped too (`alive`), so no preview outlives the form.
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => { alive.current = false; held.current.forEach((entry) => URL.revokeObjectURL(entry.url)); };
    }, []);

    /** Any change to what will be sent starts a new submission id, so an edited retry is never swallowed as a duplicate. */
    const touch = useCallback(() => { submissionId.current = newSubmissionId(); setFailure(null); }, []);

    const clearPhotos = useCallback(() => {
        held.current.forEach((entry) => URL.revokeObjectURL(entry.url));
        held.current = [];
        setPhotos([]);
        setViewing(null);
    }, []);

    const handleFiles = useCallback(async (files: File[]) => {
        const room = maxPhotos - held.current.length;
        if (files.length === 0) return;
        if (room <= 0) {
            setNotice({ intent: 'warning', text: `A check can carry ${maxPhotos} photos at most. Remove one to add another.` });
            return;
        }
        const ticket = ++preparingNow.current;
        const chosen = files.slice(0, room);
        setPreparing(true);
        setNotice(null);
        const added: HeldPhoto[] = [];
        let problem: string | null = null;
        try {
            for (const file of chosen) {
                try {
                    const prepared = await preparePhoto(file, {
                        acceptedMimeTypes: definition.accepted_mime_types,
                        maxEdge: definition.max_edge,
                        minEdge: definition.min_edge,
                        maxUploadBytes: definition.max_upload_bytes,
                    });
                    added.push({ key: nextKey.current++, photo: prepared, url: URL.createObjectURL(prepared.blob) });
                } catch (error) {
                    const text = error instanceof PhotoProblem ? error.message : 'That photo could not be read. Take it again or choose another.';
                    problem ??= chosen.length > 1 ? `${file.name || 'One photo'}: ${text}` : text;
                }
                if (!alive.current || ticket !== preparingNow.current) break;
            }
        } finally {
            if (ticket === preparingNow.current) setPreparing(false);
        }
        if (!alive.current || ticket !== preparingNow.current) { added.forEach((entry) => URL.revokeObjectURL(entry.url)); return; }
        if (added.length > 0) {
            const next = [...held.current, ...added];
            held.current = next;
            setPhotos(next);
            touch();
        }
        if (problem) setNotice({ intent: 'error', text: problem });
        else if (files.length > room) setNotice({ intent: 'warning', text: `Only ${room} more ${room === 1 ? 'photo fits' : 'photos fit'} (${maxPhotos} at most), so the rest were left out.` });
    }, [definition, maxPhotos, touch]);

    const removePhoto = (key: number) => {
        const gone = held.current.find((entry) => entry.key === key);
        if (gone) URL.revokeObjectURL(gone.url);
        const next = held.current.filter((entry) => entry.key !== key);
        held.current = next;
        setPhotos(next);
        setViewing(null);
        setNotice(null);
        touch();
    };

    const onPicked = (event: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        event.target.value = ''; // the same file can be chosen again
        void handleFiles(files);
    };

    const takePhoto = () => {
        setNotice(null);
        // Phones and tablets: the system camera (it handles permission, focus and rotation). Computers: the in-page camera.
        const touchDevice = typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
        if (touchDevice || !canUseCamera()) takeInput.current?.click();
        else setCameraOpen(true);
    };

    /** Choosing an option; anything but "Other" hides and clears the typed text. */
    const chooseUnit = (choice: string) => {
        setUnit((current) => pickUnit(current, choice, unitConfig));
        setUnitEdited(true);
        setUnitServerErrors({});
        touch();
    };

    const typeUnit = (other: string) => {
        setUnit((current) => ({ ...current, other }));
        setUnitEdited(true);
        setUnitServerErrors({});
        touch();
    };

    const typeSeal = (value: string) => {
        setSeal(value);
        setSealEdited(true);
        setSealServerError(undefined);
        touch();
    };

    const send = async () => {
        if (photos.length === 0 || sendingNow.current) return;
        // The unit and the seal number are required: show what is missing next to the field and move to the first one instead of sending.
        const problems = unitErrors(unit, unitConfig);
        const sealProblem = sealError(seal, sealConfig);
        if (problems.choice || problems.other || sealProblem) {
            setAttempted(true);
            (problems.choice ? unitSelect.current : problems.other ? unitOtherInput.current : sealInput.current)?.focus();
            return;
        }
        sendingNow.current = true;
        setSending(true);
        setFailure(null);
        setProgress(0);
        try {
            const chosen = unitPayload(unit, unitConfig);
            const result = await api.send({
                photos: photos.map((entry) => entry.photo), note, rescueUnit: chosen.rescue_unit, rescueUnitOther: chosen.rescue_unit_other, sealNumber: sealPayload(seal), clientSubmissionId: submissionId.current, resubmissionOf: resubmitOf,
            }, setProgress);
            setSent(result.check);
            clearPhotos();
            onSent();
        } catch (error) {
            const known = error instanceof NarcoticsApiError ? error : null;
            // The server is authoritative: its words for the unit fields appear beside them.
            setUnitServerErrors(serverUnitErrors(known?.fields));
            setSealServerError(serverSealError(known?.fields));
            setFailure(describeFailure({
                status: known?.status ?? 0, code: known?.code ?? null, message: known?.message ?? '', network: known?.network ?? true,
            }) as Failure);
        } finally {
            sendingNow.current = false;
            setSending(false);
        }
    };

    const startAnother = () => {
        submissionId.current = newSubmissionId();
        clearPhotos();
        setSent(null); setNote(''); setFailure(null); setNotice(null); setProgress(0);
        setUnit(blankUnit()); setUnitEdited(false); setAttempted(false); setUnitServerErrors({});
        setSeal(''); setSealEdited(false); setSealServerError(undefined);
    };

    if (sent) {
        return (
            <div className="nc nc-app" data-testid="narcotics-sent">
                <div className="nc-sent" role="status" aria-live="polite">
                    <CheckCircle2 size={44} aria-hidden="true" />
                    <h1>{SENT_MESSAGE}</h1>
                    <p>You will get a notification when Captain 5 decides. You can also see its status under My narcotics checks.</p>
                </div>
                <div className="nc-actions">
                    <Button appearance="primary" size="large" onClick={() => goDetail(sent.id)}>See this check</Button>
                    <Button size="large" onClick={goMine}>My narcotics checks</Button>
                    <Button size="large" onClick={startAnother}>Send another</Button>
                    <Button appearance="subtle" size="large" onClick={goForms}>Back to Forms</Button>
                </div>
            </div>
        );
    }

    const unitProblems = unitErrors(unit, unitConfig);
    const otherTyped = trimUnitText(unit.other);
    const choiceError = unitServerErrors.choice ?? (attempted ? unitProblems.choice : undefined);
    // Too long or unusable text is flagged as it is typed; "required" waits until a send is attempted.
    const otherError = unitServerErrors.other ?? (unitProblems.other && (attempted || otherTyped !== '') ? unitProblems.other : undefined);
    const sealTyped = trimUnitText(seal);
    const sealProblem = sealError(seal, sealConfig);
    // Too long or unusable text is flagged as it is typed; "required" waits until a send is attempted.
    const sealMessage = sealServerError ?? (sealProblem && (attempted || sealTyped !== '') ? sealProblem : undefined);
    const tooLong = note.length > definition.note_max_length;
    const hasPhoto = photos.length > 0;
    const full = photos.length >= maxPhotos;
    const canSend = hasPhoto && !sending && !preparing && !tooLong && !(failure?.newPhoto ?? false);
    const viewed = viewing === null ? null : photos[viewing] ?? null;

    return (
        <div className="nc nc-app" data-testid="narcotics-form">
            <div className="nc-topline">
                <Button appearance="subtle" icon={<ArrowLeft size={18} />} onClick={goForms} disabled={sending}>Back to Forms</Button>
                <Button appearance="subtle" onClick={goMine} disabled={sending}>My narcotics checks</Button>
            </div>

            <header className="nc-head">
                <p className="of-eyebrow">Forms</p>
                <h1>Narcotics check</h1>
                <p>Choose your rescue unit, enter the seal number, then take or upload {maxPhotos > 1 ? 'photos' : 'a photo'} of your narcotics check and send {maxPhotos > 1 ? 'them' : 'it'} to Captain 5. Make sure the labels and counts are sharp.</p>
            </header>

            {resubmitOf && <MessageBar intent="info"><MessageBarBody>Captain 5 did not accept your last photo. Send a new one. The earlier record stays as it was.</MessageBarBody></MessageBar>}
            {notice && <MessageBar intent={notice.intent} role="alert"><MessageBarBody>{notice.text}</MessageBarBody></MessageBar>}

            <input ref={takeInput} type="file" accept="image/*" capture="environment" className="nc-file" onChange={onPicked} tabIndex={-1} aria-hidden="true" data-testid="narcotics-take-input" />
            <input ref={pickInput} type="file" accept="image/*" multiple={maxPhotos > 1} className="nc-file" onChange={onPicked} tabIndex={-1} aria-hidden="true" data-testid="narcotics-upload-input" />

            {cameraOpen && (
                <CameraCapture
                    onCapture={(file) => { setCameraOpen(false); void handleFiles([file]); }}
                    onCancel={() => setCameraOpen(false)}
                    onUnavailable={(reason) => { setCameraOpen(false); setNotice({ intent: 'warning', text: reason }); }}
                />
            )}

            {!cameraOpen && (
                <section className="nc-card nc-unit" data-testid="narcotics-unit-card">
                    <Field
                        label="Rescue unit"
                        required
                        hint="Not on the list? Choose Other and type it."
                        validationState={choiceError ? 'error' : 'none'}
                        validationMessage={choiceError}
                    >
                        <Select
                            ref={unitSelect}
                            value={unit.choice}
                            onChange={(_event, data) => chooseUnit(data.value)}
                            disabled={sending}
                            data-testid="narcotics-unit"
                        >
                            <option value="">Choose a unit</option>
                            {unitConfig.options.map((option) => <option key={option} value={option}>{option}</option>)}
                        </Select>
                    </Field>
                    {unit.choice === unitConfig.other && (
                        <Field
                            label="Which unit?"
                            required
                            hint={`${Array.from(otherTyped).length} / ${unitConfig.otherMax}`}
                            validationState={otherError ? 'error' : 'none'}
                            validationMessage={otherError}
                        >
                            <Input
                                ref={unitOtherInput}
                                value={unit.other}
                                onChange={(_event, data) => typeUnit(data.value)}
                                disabled={sending}
                                autoComplete="off"
                                enterKeyHint="done"
                                data-testid="narcotics-unit-other"
                            />
                        </Field>
                    )}
                </section>
            )}

            {!cameraOpen && (
                <section className="nc-card nc-unit" data-testid="narcotics-seal-card">
                    <Field
                        label="Seal number"
                        required
                        hint={`${Array.from(sealTyped).length} / ${sealConfig.max}`}
                        validationState={sealMessage ? 'error' : 'none'}
                        validationMessage={sealMessage}
                    >
                        <Input
                            ref={sealInput}
                            value={seal}
                            onChange={(_event, data) => typeSeal(data.value)}
                            disabled={sending}
                            autoComplete="off"
                            inputMode="text"
                            enterKeyHint="done"
                            data-testid="narcotics-seal"
                        />
                    </Field>
                </section>
            )}

            {!cameraOpen && (
                <section className="nc-card" aria-labelledby="nc-photo-heading">
                    <h2 id="nc-photo-heading">
                        {maxPhotos > 1 ? 'Photos' : 'Photo'}
                        {maxPhotos > 1 && hasPhoto && <span className="nc-optional"> ({photos.length} of {maxPhotos})</span>}
                    </h2>

                    {hasPhoto && (
                        <ul className={`nc-photos${photos.length === 1 ? ' nc-photos--one' : ''}`} aria-label="Photos you are about to send" data-testid="narcotics-photos">
                            {photos.map((entry, index) => (
                                <li key={entry.key} className="nc-photo">
                                    <button type="button" className="nc-preview-frame" onClick={() => setViewing(index)} aria-label={photos.length > 1 ? `Zoom in on photo ${index + 1}` : 'Zoom in on the photo'} data-testid="narcotics-preview">
                                        <img src={entry.url} alt={photos.length > 1 ? `Photo ${index + 1} you are about to send` : 'Preview of the photo you are about to send'} />
                                        <span className="nc-zoom-hint"><ZoomIn size={16} aria-hidden="true" /> {photos.length > 1 ? index + 1 : 'Tap to zoom'}</span>
                                    </button>
                                    <div className="nc-photo-bar">
                                        <p className="nc-hint" data-testid="narcotics-photo-info">{photos.length > 1 ? `Photo ${index + 1} · ` : ''}{photoInfo(entry.photo)}</p>
                                        <Button
                                            appearance="subtle"
                                            icon={<Trash2 size={18} />}
                                            onClick={() => removePhoto(entry.key)}
                                            disabled={sending || preparing}
                                            aria-label={`Remove photo ${index + 1}`}
                                            data-testid="narcotics-remove-photo"
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {preparing && <div className="nc-preparing" role="status"><Spinner size="small" label="Preparing the photo…" /></div>}

                    {!full && (
                        <div className="nc-actions">
                            <Button appearance={hasPhoto ? 'secondary' : 'primary'} size="large" icon={<Camera size={20} />} onClick={takePhoto} disabled={preparing || sending} data-testid="narcotics-take">
                                {hasPhoto ? 'Take another photo' : 'Take photo'}
                            </Button>
                            <Button size="large" icon={hasPhoto ? <ImagePlus size={20} /> : <ImageUp size={20} />} onClick={() => pickInput.current?.click()} disabled={preparing || sending} data-testid="narcotics-upload">
                                {hasPhoto ? 'Upload more photos' : 'Upload photo'}
                            </Button>
                        </div>
                    )}
                    {!hasPhoto && !preparing && (
                        <p className="nc-hint">
                            {maxPhotos > 1 ? `You can add up to ${maxPhotos} photos. ` : ''}No camera? Choose "Upload photo" to pick {maxPhotos > 1 ? 'photos' : 'one'} from this device.
                        </p>
                    )}
                    {full && maxPhotos > 1 && <p className="nc-hint" data-testid="narcotics-photos-full">That is the most one check can carry ({maxPhotos}). Remove a photo to add a different one.</p>}
                </section>
            )}

            <section className="nc-card" aria-labelledby="nc-note-heading">
                <h2 id="nc-note-heading">Note <span className="nc-optional">(optional)</span></h2>
                <Textarea
                    value={note}
                    onChange={(_event, data) => { setNote(data.value); touch(); }}
                    placeholder="Anything Captain 5 should know about this check"
                    rows={3}
                    resize="vertical"
                    disabled={sending}
                    aria-label="Optional note for Captain 5"
                    aria-invalid={tooLong || undefined}
                    data-testid="narcotics-note"
                />
                <p className={`nc-hint${tooLong ? ' is-error' : ''}`}>{note.length} / {definition.note_max_length}{tooLong ? '. That is too long.' : ''}</p>
            </section>

            {failure && (
                <MessageBar intent="error" role="alert" data-testid="narcotics-failure">
                    <MessageBarBody>{failure.message}</MessageBarBody>
                </MessageBar>
            )}

            {sending && (
                <div className="nc-progress" role="status" aria-live="polite" data-testid="narcotics-progress">
                    <ProgressBar value={progress / 100} aria-label="Upload progress" />
                    <p className="nc-hint">{progress >= 99 ? 'Waiting for the server to confirm…' : `Sending… ${progress}%`}</p>
                </div>
            )}

            <div className="nc-send">
                <Button appearance="primary" size="large" icon={failure?.retry ? <RefreshCw size={20} /> : <Send size={20} />} onClick={() => void send()} disabled={!canSend} data-testid="narcotics-send">
                    {sending ? 'Sending…' : failure?.retry ? 'Send again' : 'Send to Captain 5'}
                </Button>
                {!hasPhoto && !preparing && <p className="nc-hint">Add a photo to send.</p>}
                {hasPhoto && (unitProblems.choice || sealProblem) && !attempted && <p className="nc-hint">{unitProblems.choice ? 'Choose your rescue unit' : 'Enter the seal number'} to send.</p>}
                {hasPhoto && failure?.newPhoto && <p className="nc-hint">Remove or replace the photo the message names first.</p>}
            </div>

            {viewed && <PhotoViewer src={viewed.url} alt={photos.length > 1 ? `Photo ${(viewing ?? 0) + 1} you are about to send` : 'The photo you are about to send'} onClose={() => setViewing(null)} />}
        </div>
    );
}

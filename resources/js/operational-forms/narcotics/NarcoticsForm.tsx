import { useCallback, useEffect, useRef, useState, type ChangeEvent } from 'react';
import { Button, MessageBar, MessageBarBody, ProgressBar, Spinner, Textarea } from '@fluentui/react-components';
import { ArrowLeft, Camera, CheckCircle2, ImageUp, RefreshCw, RotateCcw, Send, ZoomIn } from 'lucide-react';
import { NarcoticsApiError, type NarcoticsApi } from './narcoticsApi';
import { SENT_MESSAGE, describeFailure, formPhase, formatBytes, newSubmissionId } from './narcoticsImage.js';
import type { NarcoticsCheckDetail, NarcoticsFormDefinition, PreparedPhoto } from './narcoticsTypes';
import { CameraCapture, canUseCamera } from './CameraCapture';
import { PhotoViewer } from './PhotoViewer';
import { PhotoProblem, preparePhoto } from './processPhoto';
import { useNarcoticsBusy } from './useNarcoticsBusy';

interface Props {
    api: NarcoticsApi;
    definition: NarcoticsFormDefinition;
    /** The rejected check this one answers (the old record is never edited). */
    resubmitOf: string | null;
    goForms: () => void;
    goMine: () => void;
    goDetail: (id: string) => void;
    onSent: () => void;
}

interface Failure { message: string; retry: boolean; newPhoto: boolean }

/** Take or upload one photo, add an optional note, send it once. Confirmation appears only after the server has stored it. */
export function NarcoticsForm({ api, definition, resubmitOf, goForms, goMine, goDetail, onSent }: Props) {
    const [photo, setPhoto] = useState<PreparedPhoto | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [preparing, setPreparing] = useState(false);
    const [note, setNote] = useState('');
    const [notice, setNotice] = useState<{ intent: 'info' | 'warning' | 'error'; text: string } | null>(null);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [viewing, setViewing] = useState(false);
    const [sending, setSending] = useState(false);
    const [progress, setProgress] = useState(0);
    const [failure, setFailure] = useState<Failure | null>(null);
    const [sent, setSent] = useState<NarcoticsCheckDetail | null>(null);

    const takeInput = useRef<HTMLInputElement>(null);
    const pickInput = useRef<HTMLInputElement>(null);
    const submissionId = useRef(newSubmissionId());
    const sendingNow = useRef(false);
    const preparingNow = useRef(0);

    const phase = formPhase({ hasPhoto: photo !== null, note, sending, failed: failure !== null, saved: sent !== null }) as
        'empty' | 'dirty' | 'sending' | 'failed' | 'saved';
    useNarcoticsBusy(phase);

    // The preview is an object URL of the in-memory blob; release it when it changes or the form closes.
    useEffect(() => {
        if (!photo) { setPreviewUrl(null); return undefined; }
        const url = URL.createObjectURL(photo.blob);
        setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [photo]);

    /** Any change to what will be sent starts a new submission id, so an edited retry is never swallowed as a duplicate. */
    const touch = useCallback(() => { submissionId.current = newSubmissionId(); setFailure(null); }, []);

    const handleFile = useCallback(async (file: File) => {
        const ticket = ++preparingNow.current;
        setPreparing(true);
        setNotice(null);
        try {
            const prepared = await preparePhoto(file, {
                acceptedMimeTypes: definition.accepted_mime_types,
                maxEdge: definition.max_edge,
                minEdge: definition.min_edge,
                maxUploadBytes: definition.max_upload_bytes,
            });
            if (ticket !== preparingNow.current) return;
            setPhoto(prepared);
            touch();
        } catch (error) {
            if (ticket !== preparingNow.current) return;
            setNotice({ intent: 'error', text: error instanceof PhotoProblem ? error.message : 'That photo could not be read. Take it again or choose another.' });
        } finally {
            if (ticket === preparingNow.current) setPreparing(false);
        }
    }, [definition, touch]);

    const onPicked = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        event.target.value = ''; // the same file can be chosen again
        if (file) void handleFile(file);
    };

    const takePhoto = () => {
        setNotice(null);
        // Phones and tablets: the system camera (it handles permission, focus and rotation). Computers: the in-page camera.
        const touchDevice = typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
        if (touchDevice || !canUseCamera()) takeInput.current?.click();
        else setCameraOpen(true);
    };

    const send = async () => {
        if (!photo || sendingNow.current) return;
        sendingNow.current = true;
        setSending(true);
        setFailure(null);
        setProgress(0);
        try {
            const result = await api.send({ photo, note, clientSubmissionId: submissionId.current, resubmissionOf: resubmitOf }, setProgress);
            setSent(result.check);
            setPhoto(null);
            onSent();
        } catch (error) {
            const known = error instanceof NarcoticsApiError ? error : null;
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
        setSent(null); setPhoto(null); setNote(''); setFailure(null); setNotice(null); setProgress(0);
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

    const tooLong = note.length > definition.note_max_length;
    const canSend = photo !== null && !sending && !preparing && !tooLong && !(failure?.newPhoto ?? false);

    return (
        <div className="nc nc-app" data-testid="narcotics-form">
            <div className="nc-topline">
                <Button appearance="subtle" icon={<ArrowLeft size={18} />} onClick={goForms} disabled={sending}>Back to Forms</Button>
                <Button appearance="subtle" onClick={goMine} disabled={sending}>My narcotics checks</Button>
            </div>

            <header className="nc-head">
                <p className="of-eyebrow">Forms</p>
                <h1>Narcotics check</h1>
                <p>Take or upload a photo of your narcotics check and send it to Captain 5. Make sure the labels and counts are sharp.</p>
            </header>

            {resubmitOf && <MessageBar intent="info"><MessageBarBody>Captain 5 did not accept your last photo. Send a new one. The earlier record stays as it was.</MessageBarBody></MessageBar>}
            {notice && <MessageBar intent={notice.intent} role="alert"><MessageBarBody>{notice.text}</MessageBarBody></MessageBar>}

            <input ref={takeInput} type="file" accept="image/*" capture="environment" className="nc-file" onChange={onPicked} tabIndex={-1} aria-hidden="true" data-testid="narcotics-take-input" />
            <input ref={pickInput} type="file" accept="image/*" className="nc-file" onChange={onPicked} tabIndex={-1} aria-hidden="true" data-testid="narcotics-upload-input" />

            {cameraOpen && (
                <CameraCapture
                    onCapture={(file) => { setCameraOpen(false); void handleFile(file); }}
                    onCancel={() => setCameraOpen(false)}
                    onUnavailable={(reason) => { setCameraOpen(false); setNotice({ intent: 'warning', text: reason }); }}
                />
            )}

            {!cameraOpen && (
                <section className="nc-card" aria-labelledby="nc-photo-heading">
                    <h2 id="nc-photo-heading">Photo</h2>

                    {preparing && <div className="nc-preparing" role="status"><Spinner size="small" label="Preparing the photo…" /></div>}

                    {!preparing && photo && previewUrl && (
                        <div className="nc-preview">
                            <button type="button" className="nc-preview-frame" onClick={() => setViewing(true)} aria-label="Zoom in on the photo" data-testid="narcotics-preview">
                                <img src={previewUrl} alt="Preview of the photo you are about to send" />
                                <span className="nc-zoom-hint"><ZoomIn size={16} aria-hidden="true" /> Tap to zoom</span>
                            </button>
                            <p className="nc-hint" data-testid="narcotics-photo-info">
                                {photo.processed ? `${photo.width} × ${photo.height} · ${photo.type === 'image/webp' ? 'WebP' : 'JPEG'} · ` : 'Original photo · '}{formatBytes(photo.blob.size)}
                            </p>
                        </div>
                    )}

                    <div className="nc-actions">
                        <Button appearance={photo ? 'secondary' : 'primary'} size="large" icon={photo ? <RotateCcw size={20} /> : <Camera size={20} />} onClick={takePhoto} disabled={preparing || sending} data-testid="narcotics-take">
                            {photo ? 'Retake photo' : 'Take photo'}
                        </Button>
                        <Button size="large" icon={<ImageUp size={20} />} onClick={() => pickInput.current?.click()} disabled={preparing || sending} data-testid="narcotics-upload">
                            {photo ? 'Choose a different photo' : 'Upload photo'}
                        </Button>
                    </div>
                    {!photo && !preparing && <p className="nc-hint">No camera? Choose "Upload photo" to pick one from this device.</p>}
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
                {!photo && !preparing && <p className="nc-hint">Add a photo to send.</p>}
                {photo && failure?.newPhoto && <p className="nc-hint">Retake or choose a different photo first.</p>}
            </div>

            {viewing && previewUrl && <PhotoViewer src={previewUrl} alt="The photo you are about to send" onClose={() => setViewing(false)} />}
        </div>
    );
}

<script>
    /*
     * Media Control uploader: a minimal tus 1.0 client (creation + core
     * protocol, HEAD resume) so media bytes go from this browser straight to
     * Media Control. The Hub only issues the one-time grant via Livewire.
     */
    window.mcMediaUploader = window.mcMediaUploader || function (config) {
        const TUS_VERSION = '1.0.0';
        const POLL_INTERVAL_MS = 2000;
        const POLL_LIMIT = 150;
        const SPAN_TOKENS = {
            1: ['single', '1tv', '1_tv', 'one'],
            2: ['double', '2wide', '2screen', 'two_screens', '2display', 'vw2'],
            3: ['triple', 'tripple', '3wide', '3screen', '3_screens', '3wall', '3display', 'vw1', 'wide'],
        };
        const EXTENSION_MIMES = {
            jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif',
            mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime',
        };
        let sequence = 0;

        function escapeRegExp(value) {
            return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function base64Utf8(value) {
            const bytes = new TextEncoder().encode(value);
            let binary = '';
            bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
            return btoa(binary);
        }

        function sleep(ms) {
            return new Promise((resolve) => setTimeout(resolve, ms));
        }

        // Contract section 3: filename tokens first, then aspect ratio.
        function guessSpan(filename, width, height) {
            const stem = filename.replace(/\.[^.]+$/, '').toLowerCase();
            for (const span of [1, 2, 3]) {
                for (const token of SPAN_TOKENS[span]) {
                    const pattern = new RegExp('(^|[_\\-\\s])' + escapeRegExp(token) + '($|[_\\-\\s])');
                    if (pattern.test(stem)) {
                        return { span: span, hint: 'Suggested from the file name.' };
                    }
                }
            }
            if (width > 0 && height > 0) {
                const ratio = width / height;
                let best = null;
                for (let n = 1; n <= 5; n++) {
                    const target = n * 16 / 9;
                    const error = Math.abs(ratio - target) / target;
                    if (error <= 0.08 && (best === null || error < best.error)) {
                        best = { span: n, error: error };
                    }
                }
                if (best) {
                    return { span: best.span, hint: 'Suggested from the image shape.' };
                }
            }
            return { span: '', hint: '' };
        }

        return {
            config: config,
            files: [],
            dragging: false,
            running: false,
            notice: '',

            addFiles(list) {
                this.notice = '';
                const rejected = [];
                Array.from(list || []).forEach((file) => {
                    const extension = (file.name.split('.').pop() || '').toLowerCase();
                    const mime = (file.type || EXTENSION_MIMES[extension] || '').toLowerCase();
                    const isImage = this.config.imageMimes.includes(mime);
                    const isVideo = this.config.videoMimes.includes(mime);
                    if (!isImage && !isVideo) {
                        rejected.push(file.name + ' (not a supported image or video)');
                        return;
                    }
                    if (isImage && file.size > this.config.imageMaxBytes) {
                        rejected.push(file.name + ' (images can be at most 200 MB)');
                        return;
                    }
                    if (file.size <= 0) {
                        rejected.push(file.name + ' (empty file)');
                        return;
                    }
                    const entry = {
                        key: 'mc-' + (++sequence),
                        file: file,
                        name: file.name,
                        size: file.size,
                        mime: mime,
                        kind: isImage ? 'image' : 'video',
                        title: file.name.replace(/\.[^.]+$/, '').slice(0, 120),
                        span: '',
                        spanHint: '',
                        category: '',
                        tags: '',
                        width: null,
                        height: null,
                        previewUrl: null,
                        status: 'ready',
                        progress: 0,
                        error: null,
                        grant: null,
                        location: null,
                        xhr: null,
                        canceled: false,
                    };
                    const nameGuess = guessSpan(file.name, 0, 0);
                    entry.span = nameGuess.span;
                    entry.spanHint = nameGuess.hint;
                    this.files.push(entry);
                    if (isImage) {
                        this.readImageSize(this.files[this.files.length - 1]);
                    }
                });
                if (rejected.length > 0) {
                    this.notice = 'Skipped: ' + rejected.join('; ') + '.';
                }
            },

            readImageSize(entry) {
                const url = URL.createObjectURL(entry.file);
                const image = new Image();
                image.onload = () => {
                    entry.width = image.naturalWidth;
                    entry.height = image.naturalHeight;
                    entry.previewUrl = url;
                    if (entry.span === '') {
                        const guess = guessSpan(entry.name, entry.width, entry.height);
                        entry.span = guess.span;
                        entry.spanHint = guess.hint;
                    }
                };
                image.onerror = () => URL.revokeObjectURL(url);
                image.src = url;
            },

            hasPending() {
                return this.files.some((entry) => entry.status === 'ready');
            },

            busy(entry) {
                return ['requesting', 'uploading', 'processing'].includes(entry.status);
            },

            async uploadAll() {
                if (this.running) {
                    return;
                }
                this.running = true;
                try {
                    for (const entry of this.files) {
                        if (entry.status === 'ready') {
                            await this.upload(entry);
                        }
                    }
                } finally {
                    this.running = false;
                }
            },

            async retry(entry) {
                entry.error = null;
                entry.canceled = false;
                if (entry.grant && entry.location) {
                    entry.status = 'uploading';
                    try {
                        await this.resume(entry);
                        return;
                    } catch (error) {
                        if (error.resumable !== false) {
                            if (!entry.canceled) {
                                this.fail(entry, error.message || 'The upload stopped. Retry to continue where it left off.');
                            }
                            return;
                        }
                        // Media Control no longer knows this upload: start over with a new grant.
                    }
                }
                entry.grant = null;
                entry.location = null;
                entry.progress = 0;
                await this.upload(entry);
            },

            cancel(entry) {
                entry.canceled = true;
                if (entry.xhr) {
                    entry.xhr.abort();
                }
                entry.status = 'canceled';
            },

            remove(entry) {
                if (entry.previewUrl) {
                    URL.revokeObjectURL(entry.previewUrl);
                }
                this.files = this.files.filter((candidate) => candidate.key !== entry.key);
            },

            async upload(entry) {
                entry.error = null;
                entry.canceled = false;
                entry.status = 'requesting';
                let grant;
                try {
                    grant = await this.$wire.requestGrant({
                        filename: entry.name,
                        size: entry.size,
                        mime: entry.mime,
                        title: entry.title.trim() || null,
                        display_span: entry.span === '' ? null : Number(entry.span),
                        category: entry.category.trim() || null,
                        tags: entry.tags.split(',').map((tag) => tag.trim()).filter((tag) => tag !== '').slice(0, 10),
                    });
                } catch (error) {
                    return this.fail(entry, 'The Hub could not request an upload. Check your connection and retry.');
                }
                if (entry.canceled) {
                    return;
                }
                if (!grant || !grant.ok) {
                    return this.fail(entry, (grant && grant.error) || 'Media Control did not accept this file.');
                }
                if (grant.max_bytes && entry.size > grant.max_bytes) {
                    return this.fail(entry, 'This file is larger than Media Control allows.');
                }
                entry.grant = grant;
                entry.status = 'uploading';
                try {
                    await this.create(entry);
                    await this.resume(entry);
                } catch (error) {
                    if (!entry.canceled) {
                        this.fail(entry, error.message || 'The upload stopped. Retry to continue where it left off.');
                    }
                }
            },

            async create(entry) {
                const response = await fetch(entry.grant.upload_url, {
                    method: 'POST',
                    credentials: 'omit',
                    headers: {
                        'Tus-Resumable': TUS_VERSION,
                        'Upload-Length': String(entry.size),
                        'Upload-Metadata': 'filename ' + base64Utf8(entry.name) + ',filetype ' + base64Utf8(entry.mime),
                        'X-MC-Upload-Grant': entry.grant.upload_token,
                    },
                });
                if (response.status !== 201 || !response.headers.get('Location')) {
                    throw new Error(await this.errorText(response, 'Media Control refused the upload.'));
                }
                entry.location = new URL(response.headers.get('Location'), entry.grant.upload_url).toString();
            },

            async resume(entry) {
                const head = await fetch(entry.location, {
                    method: 'HEAD',
                    credentials: 'omit',
                    cache: 'no-store',
                    headers: { 'Tus-Resumable': TUS_VERSION, 'X-MC-Upload-Grant': entry.grant.upload_token },
                });
                if (!head.ok) {
                    const error = new Error('The upload can no longer be resumed.');
                    error.resumable = false;
                    throw error;
                }
                let offset = Number(head.headers.get('Upload-Offset') || 0);
                const chunkSize = entry.grant.chunk_size;
                while (offset < entry.size) {
                    if (entry.canceled) {
                        return;
                    }
                    offset = await this.patch(entry, offset, Math.min(offset + chunkSize, entry.size));
                }
                entry.progress = 100;
                entry.status = 'processing';
                await this.poll(entry);
            },

            patch(entry, start, end) {
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    entry.xhr = xhr;
                    xhr.open('PATCH', entry.location, true);
                    xhr.withCredentials = false;
                    xhr.setRequestHeader('Tus-Resumable', TUS_VERSION);
                    xhr.setRequestHeader('Upload-Offset', String(start));
                    xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');
                    xhr.setRequestHeader('X-MC-Upload-Grant', entry.grant.upload_token);
                    xhr.upload.onprogress = (event) => {
                        entry.progress = Math.min(99, Math.floor(((start + event.loaded) / entry.size) * 100));
                    };
                    xhr.onload = () => {
                        entry.xhr = null;
                        const offset = Number(xhr.getResponseHeader('Upload-Offset'));
                        if (xhr.status === 204 && Number.isFinite(offset) && offset > start) {
                            resolve(offset);
                            return;
                        }
                        let message = 'Media Control stopped the upload (status ' + xhr.status + ').';
                        try {
                            const body = JSON.parse(xhr.responseText || '{}');
                            if (body && typeof body.error === 'string' && body.error !== '') {
                                message = body.error;
                            }
                        } catch (error) {
                            // Non-JSON error body: keep the generic message.
                        }
                        reject(new Error(message));
                    };
                    xhr.onerror = () => {
                        entry.xhr = null;
                        reject(new Error('The connection to Media Control dropped. Retry to continue where it left off.'));
                    };
                    xhr.onabort = () => {
                        entry.xhr = null;
                        reject(new Error('Upload canceled.'));
                    };
                    xhr.send(entry.file.slice(start, end));
                });
            },

            async poll(entry) {
                for (let attempt = 0; attempt < POLL_LIMIT; attempt++) {
                    let result;
                    try {
                        result = await this.$wire.grantStatus(entry.grant.grant_id);
                    } catch (error) {
                        result = { ok: false, retry: true };
                    }
                    if (result && result.ok && result.status === 'complete') {
                        entry.status = 'done';
                        this.$wire.refreshLibrary();
                        return;
                    }
                    if (result && result.ok && (result.status === 'failed' || result.status === 'expired')) {
                        return this.fail(entry, result.error || 'Media Control could not process this file.', true);
                    }
                    if (result && !result.ok && !result.retry) {
                        return this.fail(entry, result.error || 'Could not check the upload status.', true);
                    }
                    await sleep(POLL_INTERVAL_MS);
                }
                entry.status = 'slow';
            },

            async errorText(response, fallback) {
                try {
                    const body = await response.json();
                    return (body && typeof body.error === 'string' && body.error) || fallback;
                } catch (error) {
                    return fallback + ' (status ' + response.status + ')';
                }
            },

            fail(entry, message, final) {
                entry.status = 'error';
                entry.error = message;
                if (final) {
                    entry.grant = null;
                    entry.location = null;
                }
            },

            statusText(entry) {
                return {
                    ready: 'Ready to upload',
                    requesting: 'Requesting upload…',
                    uploading: 'Uploading ' + entry.progress + '%',
                    processing: 'Processing in Media Control…',
                    done: 'Available in Media Control',
                    slow: 'Still processing. It will appear in the library when ready.',
                    error: 'Not uploaded',
                    canceled: 'Canceled',
                }[entry.status] || entry.status;
            },

            statusClass(entry) {
                if (entry.status === 'done') {
                    return 'text-green-700';
                }
                if (entry.status === 'error') {
                    return 'text-red-700';
                }
                return 'text-gray-600';
            },

            formatBytes(bytes) {
                if (bytes >= 1073741824) {
                    return (bytes / 1073741824).toFixed(1) + ' GB';
                }
                if (bytes >= 1048576) {
                    return (bytes / 1048576).toFixed(1) + ' MB';
                }
                return Math.max(1, Math.round(bytes / 1024)) + ' KB';
            },
        };
    };
</script>

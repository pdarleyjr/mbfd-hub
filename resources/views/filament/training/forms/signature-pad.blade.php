<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        drawing: false, ink: false, canvas: null, context: null, logicalWidth: 1, observer: null,
        init() {
            this.canvas = this.$refs.canvas;
            this.observer = new ResizeObserver(() => { if (!this.context) this.prepare(); });
            this.observer.observe(this.canvas);
            this.$nextTick(() => this.prepare());
        },
        destroy() { this.observer?.disconnect(); },
        prepare() {
                const width = this.canvas.clientWidth;
                if (width < 2 || this.context) return;
                this.logicalWidth = width;
                const scale = Math.min(window.devicePixelRatio || 1, 1600 / width, 800 / 190);
                this.canvas.width = Math.round(width * scale);
                this.canvas.height = Math.round(190 * scale);
                this.context = this.canvas.getContext('2d');
                this.context.scale(scale, scale);
                this.context.lineWidth = 2.5;
                this.context.lineCap = 'round';
                this.context.lineJoin = 'round';
                this.context.strokeStyle = '#0f172a';
        },
        point(event) {
            const rect = this.canvas.getBoundingClientRect();
            return { x: (event.clientX - rect.left) * this.logicalWidth / rect.width, y: (event.clientY - rect.top) * 190 / rect.height };
        },
        start(event) {
            if (!this.context) return;
            this.drawing = true;
            const p = this.point(event);
            this.context.beginPath(); this.context.moveTo(p.x, p.y);
            this.canvas.setPointerCapture(event.pointerId);
        },
        move(event) {
            if (!this.drawing) return;
            const p = this.point(event);
            this.context.lineTo(p.x, p.y); this.context.stroke(); this.ink = true;
        },
        end() {
            if (!this.drawing) return;
            this.drawing = false;
            if (this.ink) $wire.set('{{ $getStatePath() }}', this.canvas.toDataURL('image/png'));
        },
        resetCanvas() {
            if (!this.context) return;
            this.context.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.ink = false; this.drawing = false;
        },
        clear() {
            this.resetCanvas();
            $wire.set('{{ $getStatePath() }}', null);
        }
    }" x-on:close-modal.window="resetCanvas()" wire:ignore>
        <p class="mb-2 text-sm text-gray-600">Sign with your finger, stylus, or mouse. Only the person named above may supply this signature.</p>
        <canvas x-ref="canvas" aria-label="Handwritten signature" role="img" style="width:100%;height:190px;touch-action:none;display:block;background:white;border:2px solid #64748b;border-radius:8px"
            x-on:pointerdown.prevent="start($event)" x-on:pointermove.prevent="move($event)" x-on:pointerup.prevent="end()" x-on:pointercancel="end()"></canvas>
        <button type="button" x-on:click="clear()" class="mt-2 rounded-lg border border-gray-300 px-4 py-3 text-sm font-semibold">Clear / redo signature</button>
    </div>
</x-dynamic-component>

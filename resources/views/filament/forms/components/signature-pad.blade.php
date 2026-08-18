<div
    x-data="{
        drawing: false,
        empty: true,
        canvas: null,
        context: null,
        initPad() {
            this.canvas = this.$refs.canvas;
            const ratio = window.devicePixelRatio || 1;
            this.canvas.width = this.canvas.clientWidth * ratio;
            this.canvas.height = 190 * ratio;
            this.context = this.canvas.getContext('2d');
            this.context.scale(ratio, ratio);
            this.context.lineWidth = 2.5;
            this.context.lineCap = 'round';
            this.context.lineJoin = 'round';
            this.context.strokeStyle = '#0f172a';
        },
        point(event) {
            const rect = this.canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        },
        start(event) {
            this.drawing = true;
            const p = this.point(event);
            this.context.beginPath();
            this.context.moveTo(p.x, p.y);
            this.canvas.setPointerCapture?.(event.pointerId);
        },
        move(event) {
            if (! this.drawing) return;
            const p = this.point(event);
            this.context.lineTo(p.x, p.y);
            this.context.stroke();
            this.empty = false;
        },
        end() {
            if (! this.drawing) return;
            this.drawing = false;
            if (! this.empty) $wire.set('{{ $getStatePath() }}', this.canvas.toDataURL('image/png'));
        },
        clearPad() {
            this.context.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.empty = true;
            $wire.set('{{ $getStatePath() }}', null);
        }
    }"
    x-init="initPad()"
    class="signature-shell"
>
    <div class="signature-label"><span>Sign inside the box with mouse, finger, or stylus</span><button type="button" x-on:click="clearPad">Clear / Redo</button></div>
    <canvas x-ref="canvas" class="signature-canvas" aria-label="Officer signature pad" role="img" x-on:pointerdown.prevent="start($event)" x-on:pointermove.prevent="move($event)" x-on:pointerup.prevent="end()" x-on:pointercancel="end()" x-on:pointerleave="end()"></canvas>
    <p x-show="empty" class="signature-hint" aria-hidden="true">Officer signature</p>
    <style>.signature-shell{position:relative}.signature-label{display:flex;min-height:3rem;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.5rem;color:#57534e;font-size:.8rem}.signature-label button{min-height:3rem;padding:.5rem .8rem;border:1px solid #d6d3d1;border-radius:.65rem;background:#fff;color:#1d4ed8;font-weight:800}.signature-canvas{display:block;width:100%;height:190px;touch-action:none;border:2px solid #a8a29e;border-radius:.8rem;background:linear-gradient(to bottom,#fff 0,#fff 78%,#d6d3d1 78%,#d6d3d1 79%,#fff 79%);cursor:crosshair}.signature-canvas:focus{outline:3px solid #2563eb}.signature-hint{position:absolute;bottom:1rem;left:1rem;margin:0;color:#a8a29e;font-size:.75rem;pointer-events:none}@media(max-width:520px){.signature-label{align-items:flex-start;flex-direction:column}.signature-label button{width:100%}}</style>
</div>

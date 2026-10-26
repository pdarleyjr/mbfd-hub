/** Calendar time comes from the Hub; the browser supplies elapsed time only. */
export class ServerClock {
    private serverMs: number | null = null;
    private observedAt = 0;

    constructor(serverTime?: string | null, observedAt = performance.now()) {
        this.sync(serverTime, observedAt);
    }

    sync(serverTime?: string | null, observedAt = performance.now()): void {
        const parsed = serverTime ? Date.parse(serverTime) : NaN;
        if (!Number.isFinite(parsed)) return;
        this.serverMs = parsed;
        this.observedAt = observedAt;
    }

    now(observedAt = performance.now()): number | null {
        return this.serverMs === null ? null : this.serverMs + Math.max(0, observedAt - this.observedAt);
    }
}

/**
 * Debounces "the session looks finished" signals. A single status poll that
 * reports an inactive session can be a transient server blip, so navigation
 * away from a live conference requires repeated, time-separated confirmations.
 * ROOM_DELETED from the media server does not go through this and stays immediate.
 */
export class InactiveConfirmation {
    private firstObservedAt: number | null = null;

    private observations = 0;

    private readonly requiredObservations: number;

    private readonly minimumSpanMs: number;

    constructor(requiredObservations = 2, minimumSpanMs = 1500) {
        this.requiredObservations = requiredObservations;
        this.minimumSpanMs = minimumSpanMs;
    }

    /** Returns true once the inactive state has been confirmed. */
    observe(inactive: boolean, now: number): boolean {
        if (!inactive) {
            this.reset();

            return false;
        }
        this.firstObservedAt ??= now;
        this.observations += 1;

        return this.observations >= this.requiredObservations
            && now - this.firstObservedAt >= this.minimumSpanMs;
    }

    reset(): void {
        this.firstObservedAt = null;
        this.observations = 0;
    }
}

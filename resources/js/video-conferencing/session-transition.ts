// Keep duplicate polling and Reverb notifications from replaying a session alert.
export class LineupStartAlert {
    private inactiveSeen = false;
    private alerted = new Set<string>();

    observe(active: boolean, sessionId: string | null, standingBy: boolean): boolean {
        if (!active) {
            this.inactiveSeen = true;
            return false;
        }
        if (!sessionId || this.alerted.has(sessionId)) return false;
        this.alerted.add(sessionId);
        return this.inactiveSeen && standingBy;
    }
}

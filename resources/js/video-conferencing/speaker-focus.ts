export const SPEAKER_HYSTERESIS_MS = 650;
export const SPEAKER_MINIMUM_DWELL_MS = 1400;

export function speakerSwitchDelay(now: number, lastSwitchAt: number): number {
    return Math.max(SPEAKER_HYSTERESIS_MS, SPEAKER_MINIMUM_DWELL_MS - (now - lastSwitchAt));
}

export class SpeakerFocusTracker {
    private candidateIdentity: string | null = null;

    private candidateSince = 0;

    private focusedIdentity: string | null = null;

    private lastSwitchAt = 0;

    recordFocus(identity: string | null, now: number): void {
        this.focusedIdentity = identity;
        this.lastSwitchAt = now;
        this.candidateIdentity = null;
    }

    updateCandidate(identity: string | null, now: number): void {
        if (identity === null || identity === this.focusedIdentity) {
            this.candidateIdentity = null;

            return;
        }
        if (identity !== this.candidateIdentity) {
            this.candidateIdentity = identity;
            this.candidateSince = now;
        }
    }

    remainingDelay(now: number): number | null {
        if (this.candidateIdentity === null) return null;

        return Math.max(
            0,
            SPEAKER_HYSTERESIS_MS - (now - this.candidateSince),
            SPEAKER_MINIMUM_DWELL_MS - (now - this.lastSwitchAt),
        );
    }

    commit(now: number): string | undefined {
        if (this.candidateIdentity === null || (this.remainingDelay(now) ?? 1) > 0) return undefined;
        this.focusedIdentity = this.candidateIdentity;
        this.candidateIdentity = null;
        this.lastSwitchAt = now;

        return this.focusedIdentity;
    }

    participantLeft(identity: string): boolean {
        if (this.candidateIdentity === identity) this.candidateIdentity = null;
        if (this.focusedIdentity !== identity) return false;
        this.focusedIdentity = null;

        return true;
    }
}

export function resolveFocusedIdentity(
    screenShareIdentity: string | null,
    manuallyPinnedIdentity: string | null,
    automaticIdentity: string | null,
    fallbackIdentity: string | null,
): string | null {
    return screenShareIdentity ?? manuallyPinnedIdentity ?? automaticIdentity ?? fallbackIdentity;
}

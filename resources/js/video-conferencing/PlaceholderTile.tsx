interface PlaceholderTileProps {
    label: string;
    identity: string;
    /** Shown under the label, e.g. "Waiting to join". */
    status?: string;
}

/**
 * Reserved position for an expected endpoint that has not connected. It has no
 * `vc-tile` class so live-tile counts and selectors keep describing real video.
 */
export function PlaceholderTile({ label, identity, status = 'Waiting to join' }: PlaceholderTileProps) {
    return (
        <article className="vc-placeholder" data-identity={identity} aria-label={`${label} — ${status.toLowerCase()}`}>
            <div className="vc-placeholder__media">
                <span className="vc-placeholder__status">{status}</span>
                <span className="vc-tile__name">{label}</span>
                <span className="vc-mic vc-mic--muted">Offline</span>
            </div>
        </article>
    );
}

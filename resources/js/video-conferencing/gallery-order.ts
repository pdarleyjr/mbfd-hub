/**
 * Stable ordering of conference tiles.
 *
 * The six core endpoints always occupy the same positions (300 Command, then
 * Stations 1, 2, 3, 4 and 6). An expected core endpoint that has not connected
 * is represented by a placeholder so the layout never shifts when it joins.
 * Individual members follow, ordered by when they joined.
 */

export interface CoreEndpoint {
    identity: string;
    label: string;
}

export const CORE_ENDPOINTS: readonly CoreEndpoint[] = [
    { identity: 'mbfd:300', label: '300 Command' },
    { identity: 'mbfd:sta1', label: 'Station 1' },
    { identity: 'mbfd:sta2', label: 'Station 2' },
    { identity: 'mbfd:sta3', label: 'Station 3' },
    { identity: 'mbfd:sta4', label: 'Station 4' },
    { identity: 'mbfd:sta6', label: 'Station 6' },
];

export interface OrderableParticipant {
    identity: string;
    name?: string;
}

export type GallerySlot<T extends OrderableParticipant> =
    | { kind: 'participant'; key: string; identity: string; label: string; core: boolean; participant: T }
    | { kind: 'placeholder'; key: string; identity: string; label: string; core: true };

export function coreEndpointIdentities(): string[] {
    return CORE_ENDPOINTS.map((endpoint) => endpoint.identity);
}

/**
 * Endpoints expected in a room. A Morning Lineup expects all six; a direct call
 * expects only 300 and the called station, so no stray placeholders appear.
 */
export function expectedEndpointIdentities(
    roomMode: 'lineup' | 'direct',
    directStation: string | null,
): string[] {
    if (roomMode === 'lineup') return coreEndpointIdentities();
    const target = directStation ? `mbfd:${directStation}` : null;

    return CORE_ENDPOINTS
        .filter((endpoint) => endpoint.identity === 'mbfd:300' || endpoint.identity === target)
        .map((endpoint) => endpoint.identity);
}

/** Epoch milliseconds when a participant joined (or was first seen); null sorts last. */
export type JoinedAtResolver<T> = (participant: T) => number | null | undefined;

function memberOrder<T extends OrderableParticipant>(left: T, right: T, joinedAt: JoinedAtResolver<T>): number {
    const leftJoined = joinedAt(left) ?? Number.MAX_SAFE_INTEGER;
    const rightJoined = joinedAt(right) ?? Number.MAX_SAFE_INTEGER;
    if (leftJoined !== rightJoined) return leftJoined - rightJoined;
    const byName = (left.name ?? '').localeCompare(right.name ?? '');

    return byName !== 0 ? byName : left.identity.localeCompare(right.identity);
}

export function buildGallerySlots<T extends OrderableParticipant>(
    participants: readonly T[],
    expectedIdentities: readonly string[],
    joinedAt: JoinedAtResolver<T> = () => null,
): Array<GallerySlot<T>> {
    const byIdentity = new Map(participants.map((participant) => [participant.identity, participant]));
    const expected = new Set(expectedIdentities);
    const slots: Array<GallerySlot<T>> = [];

    for (const endpoint of CORE_ENDPOINTS) {
        const participant = byIdentity.get(endpoint.identity);
        if (participant) {
            slots.push({ kind: 'participant', key: endpoint.identity, identity: endpoint.identity, label: endpoint.label, core: true, participant });
        } else if (expected.has(endpoint.identity)) {
            slots.push({ kind: 'placeholder', key: `placeholder:${endpoint.identity}`, identity: endpoint.identity, label: endpoint.label, core: true });
        }
    }

    const coreIdentities = new Set(coreEndpointIdentities());
    const others = participants
        .filter((participant) => !coreIdentities.has(participant.identity))
        .sort((left, right) => memberOrder(left, right, joinedAt));
    for (const participant of others) {
        slots.push({
            kind: 'participant',
            key: participant.identity,
            identity: participant.identity,
            label: participant.name || 'MBFD participant',
            core: false,
            participant,
        });
    }

    return slots;
}

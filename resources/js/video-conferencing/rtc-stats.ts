export interface InboundRtcSample {
    id: string;
    bytesReceived: number;
    packetsReceived: number;
    packetsLost: number;
    jitterMs: number;
}

export interface InboundRtcCounters {
    downstreamBytes: number;
    packetsReceived: number;
    packetsLost: number;
    jitterMs: number;
}

export interface InboundRtcAccumulator {
    previous: Map<string, Omit<InboundRtcSample, 'id' | 'jitterMs'>>;
    totals: InboundRtcCounters;
}

const zeroCounters = (): InboundRtcCounters => ({
    downstreamBytes: 0,
    packetsReceived: 0,
    packetsLost: 0,
    jitterMs: 0,
});

export function emptyInboundRtcAccumulator(): InboundRtcAccumulator {
    return { previous: new Map(), totals: zeroCounters() };
}

function delta(current: number, previous: number | undefined): number {
    if (previous === undefined || current < previous) return Math.max(0, current);

    return Math.max(0, current - previous);
}

export function accumulateInboundRtcStats(
    accumulator: InboundRtcAccumulator,
    samples: InboundRtcSample[],
): InboundRtcAccumulator {
    const previous = new Map(accumulator.previous);
    const totals = { ...accumulator.totals, jitterMs: 0 };
    for (const sample of samples) {
        const last = previous.get(sample.id);
        totals.downstreamBytes += delta(sample.bytesReceived, last?.bytesReceived);
        totals.packetsReceived += delta(sample.packetsReceived, last?.packetsReceived);
        totals.packetsLost += delta(sample.packetsLost, last?.packetsLost);
        totals.jitterMs = Math.max(totals.jitterMs, Math.max(0, sample.jitterMs));
        previous.set(sample.id, {
            bytesReceived: sample.bytesReceived,
            packetsReceived: sample.packetsReceived,
            packetsLost: sample.packetsLost,
        });
    }

    return { previous, totals };
}

import assert from 'node:assert/strict';
import test from 'node:test';
import {
    accumulateInboundRtcStats,
    emptyInboundRtcAccumulator,
} from '../../resources/js/video-conferencing/rtc-stats.ts';

const sample = (id, bytesReceived, packetsReceived, packetsLost, jitterMs = 0) => ({
  id, bytesReceived, packetsReceived, packetsLost, jitterMs,
});

test('RTC totals aggregate per-stream deltas without double counting cumulative reports', () => {
  let state = emptyInboundRtcAccumulator();
  state = accumulateInboundRtcStats(state, [
    sample('video-a', 1_000, 10, 1, 8),
    sample('audio-a', 400, 20, 0, 3),
  ]);
  state = accumulateInboundRtcStats(state, [
    sample('video-a', 1_600, 15, 2, 12),
    sample('audio-a', 650, 28, 0, 4),
  ]);

  assert.deepEqual(state.totals, {
    downstreamBytes: 2_250,
    packetsReceived: 43,
    packetsLost: 2,
    jitterMs: 12,
  });
});

test('new and restarted RTC streams add their current counters to the running total', () => {
  let state = accumulateInboundRtcStats(emptyInboundRtcAccumulator(), [sample('video-a', 900, 9, 1)]);
  state = accumulateInboundRtcStats(state, [sample('video-a', 100, 2, 0), sample('video-b', 500, 5, 1)]);

  assert.equal(state.totals.downstreamBytes, 1_500);
  assert.equal(state.totals.packetsReceived, 16);
  assert.equal(state.totals.packetsLost, 2);
});

import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildGallerySlots,
    coreEndpointIdentities,
    expectedEndpointIdentities,
} from '../../resources/js/video-conferencing/gallery-order.ts';

const person = (identity, name = identity, joinedAtMs = null) => ({ identity, name, joinedAtMs });
const joinedAt = (participant) => participant.joinedAtMs;
const summarize = (slots) => slots.map((slot) => `${slot.kind === 'placeholder' ? '~' : ''}${slot.identity}`);

test('a Morning Lineup reserves all six core positions in canonical order', () => {
    const slots = buildGallerySlots([], expectedEndpointIdentities('lineup', null));

    assert.deepEqual(summarize(slots), coreEndpointIdentities().map((identity) => `~${identity}`));
    assert.deepEqual(slots.map((slot) => slot.label), [
        '300 Command', 'Station 1', 'Station 2', 'Station 3', 'Station 4', 'Station 6',
    ]);
});

test('connected endpoints keep their canonical position regardless of join order', () => {
    const participants = [person('mbfd:sta6'), person('mbfd:sta2'), person('mbfd:300')];
    const slots = buildGallerySlots(participants, expectedEndpointIdentities('lineup', null));

    assert.deepEqual(summarize(slots), [
        'mbfd:300', '~mbfd:sta1', 'mbfd:sta2', '~mbfd:sta3', '~mbfd:sta4', 'mbfd:sta6',
    ]);
});

test('members follow the core six, ordered by join time then name', () => {
    const participants = [
        person('mbfd:member:9', 'Zed', 3_000),
        person('mbfd:300', '300'),
        person('mbfd:member:4', 'Bea', 1_000),
        person('mbfd:member:7', 'Al', 1_000),
        person('mbfd:sta1'),
    ];
    const slots = buildGallerySlots(participants, expectedEndpointIdentities('lineup', null), joinedAt);

    assert.deepEqual(summarize(slots).slice(6), ['mbfd:member:7', 'mbfd:member:4', 'mbfd:member:9']);
    assert.equal(slots.slice(6).every((slot) => slot.core === false), true);
});

test('a member who has not reported a join time sorts after those who have', () => {
    const participants = [person('mbfd:member:2', 'Early', null), person('mbfd:member:1', 'Late', 9_000)];
    const slots = buildGallerySlots(participants, [], joinedAt);

    assert.deepEqual(summarize(slots), ['mbfd:member:1', 'mbfd:member:2']);
});

test('a direct call expects only 300 and the called station', () => {
    assert.deepEqual(expectedEndpointIdentities('direct', 'sta4'), ['mbfd:300', 'mbfd:sta4']);
    const slots = buildGallerySlots([person('mbfd:300')], expectedEndpointIdentities('direct', 'sta4'));

    assert.deepEqual(summarize(slots), ['mbfd:300', '~mbfd:sta4']);
});

test('a direct call never shows placeholders for unrelated stations, even before anyone connects', () => {
    assert.deepEqual(summarize(buildGallerySlots([], expectedEndpointIdentities('direct', 'sta1'))), ['~mbfd:300', '~mbfd:sta1']);
    assert.deepEqual(summarize(buildGallerySlots([], expectedEndpointIdentities('direct', null))), ['~mbfd:300']);
});

test('an unexpected core endpoint that connects is still shown in its canonical slot', () => {
    const slots = buildGallerySlots([person('mbfd:sta3'), person('mbfd:300')], expectedEndpointIdentities('direct', 'sta1'));

    assert.deepEqual(summarize(slots), ['mbfd:300', '~mbfd:sta1', 'mbfd:sta3']);
});

test('slot keys are unique and placeholders never share a key with a live tile', () => {
    const slots = buildGallerySlots([person('mbfd:sta1'), person('mbfd:member:3')], expectedEndpointIdentities('lineup', null));
    const keys = slots.map((slot) => slot.key);

    assert.equal(new Set(keys).size, keys.length);
});

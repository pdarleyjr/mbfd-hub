import assert from 'node:assert/strict';
import test from 'node:test';
import {
  amountLabel, blankDraft, buildPayload, cadenceText, defaultTargetChoice, draftFromPayload, draftFromRequest, draftStoreKey, familyFromFormKey,
  familyFromLibraryLink, familyOfDefinition, familyOfPayload, familyOfView, filterEntered, filterForms, formKeyForFamily, itemChips, itemDomId, lineKey,
  normalizeSearch, refillLabel, requestFamilyShort, requestFamilyTitle, resolveFamily, restoreDraft, retiredCount, reviewGroups, searchForView,
  searchResults, sectionNotes, serializeDraft, setAnswer, setQuantity, shortFormTitle, viewFromDeepLink, viewFromSearch, withDefaultTarget,
  hasUnsavedWork, isEntered, countRequested,
} from '../../../resources/js/operational-forms/ems/emsLogic.js';

const item = (item_key, label, extra = {}) => ({
  item_key, label, min_text: null, min_qty: null, min_unit: null, variant_group: null, variant_label: null,
  response_type: 'quantity', flags: {}, aliases: [], ...extra,
});

const units = [
  { id: 7, kind: 'rescue_unit', label: 'RESCUE R2', designation: 'R2', status: 'In Service', group: 'Rescue units' },
  { id: 31, kind: 'other_unit', label: 'FIRE BOAT 6', designation: 'FB6', status: 'In Service', group: 'Other units' },
  { id: null, kind: 'detail_event', label: 'Detail / Special Event', designation: null, status: null, group: 'Details' },
];
const limits = { max_quantity: 999, note_max: 2000, event_assignment_max: 160 };
const rules = { blank_is_not_zero: true, needs_item_or_note: true, controlled_substance_note: 'x' };

const AIRWAY = {
  key: 'airway-bag', title: 'AIRWAY BAG INVENTORY', version_label: 'undated', printed_note: null,
  sections: [
    { key: 'airway-top', label: 'Top Compartment', parent_key: null, items: [
      item('airway.et-tube-75', 'ET tube 7.5 (cuffed)', { min_text: '2 EACH', min_qty: 2 }),
      item('airway.igel-3', 'i-gel Size 3', { aliases: ['Supraglottic airway'] }),
      item('airway.epi', 'Épinéphrine 1 mg', { flags: { search_terms: ['adrenaline'] } }),
    ] },
    { key: 'airway-main', label: 'Main Compartment', parent_key: null, note: 'Check the seal on every bag.', items: [
      item('airway.mask', 'Mask', { flags: { location_text: 'Placed in BVM bag', color_band: 'Yellow (2 years)' } }),
    ] },
  ],
};
const TRANSPORT = {
  key: 'als-transport-vehicle', title: 'ALS TRANSPORT VEHICLE', version_label: 'April 2021', printed_note: null,
  sections: [{ key: 'tv-iv', label: 'I.V. / Needles', parent_key: null, items: [
    item('tv.iv.cath-18', 'IV catheter 18 ga', { min_text: '4', min_qty: 4, flags: { fill_if_text: '2', fill_if_qty: 2 } }),
    item('tv.iv.cath-20', 'IV catheter 20g', { min_text: '4', min_qty: 4 }),
    item('tv.iv.cath-22', 'IV catheter 22', { search_terms: ['pink'] }),
  ] }],
};
const HANDTEVY = {
  key: 'handtevy', title: 'HANDTEVY INVENTORY', version_label: '2024', printed_note: null,
  sections: [{ key: 'ht-monthly', label: 'Monthly check', parent_key: null, cadence: 'monthly_first_tuesday', items: [
    item('handtevy.tape', 'Handtevy tape', { flags: { cadence: 'monthly_first_tuesday', qualifier_text: 'Detail Airway Bags Only', detail_airway_bag_only: true } }),
  ] }],
};
const NON_TRANSPORT = {
  key: 'als-non-transport', title: 'ALS NON TRANSPORT', version_label: 'Revised 1-2024', printed_note: null,
  sections: [{ key: 'nt-main', label: 'Main', parent_key: null, items: [item('nt.gauze', 'Gauze 4x4')] }],
};
const BOAT = {
  key: 'fire-boat-6-als', title: 'FIRE BOAT 6 ALS', version_label: '7/2025', printed_note: null,
  sections: [{ key: 'fb-main', label: 'Main', parent_key: null, items: [item('fb.gauze', 'Gauze 4x4')] }],
};

const family = (key, title, short, form_key, bag_keys, default_target = null) => ({ key, title, short_title: short, form_key, description: '', bag_keys, default_target });
const FAMILIES = [
  { key: 'als-transport', title: 'ALS Transport Inventory Form', short_title: 'ALS Transport', form_key: 'als-transport-inventory', description: '' },
  { key: 'als-non-transport', title: 'ALS Non Transport Inventory Form', short_title: 'ALS Non Transport', form_key: 'als-non-transport-inventory', description: '' },
  { key: 'fire-boat-6', title: 'Fire Boat 6 Medical Inventory Form', short_title: 'Fire Boat 6', form_key: 'fire-boat-6-medical-inventory', description: '' },
  { key: 'bls-detail', title: 'BLS Detail Medical Inventory Form', short_title: 'BLS Detail', form_key: 'bls-detail-medical-inventory', description: '' },
];

const alsTransport = {
  family: family('als-transport', 'ALS Transport Inventory Form', 'ALS Transport', 'als-transport-inventory', ['airway-bag', 'als-transport-vehicle', 'handtevy']),
  families: FAMILIES, forms: [AIRWAY, TRANSPORT, HANDTEVY], units, limits, rules,
};
const alsNonTransport = {
  family: family('als-non-transport', 'ALS Non Transport Inventory Form', 'ALS Non Transport', 'als-non-transport-inventory', ['als-non-transport', 'handtevy']),
  families: FAMILIES, forms: [NON_TRANSPORT, HANDTEVY], units, limits, rules,
};
const fireBoat = {
  family: family('fire-boat-6', 'Fire Boat 6 Medical Inventory Form', 'Fire Boat 6', 'fire-boat-6-medical-inventory', ['fire-boat-6-als'], 'unit:31'),
  families: FAMILIES, forms: [BOAT], units, limits, rules,
};
const oldServer = { forms: [AIRWAY], units, limits, rules };

const ET = lineKey('airway-bag', 'airway.et-tube-75');
const MASK = lineKey('airway-bag', 'airway.mask');
const TAPE = lineKey('handtevy', 'handtevy.tape');
const BOAT_GAUZE = lineKey('fire-boat-6-als', 'fb.gauze');
const NT_GAUZE = lineKey('als-non-transport', 'nt.gauze');
const matches = (definition, query) => filterForms(definition.forms, query).matches;
const labels = (definition, query) => searchResults(definition.forms, query).rows.map((row) => row.item.label);

// ---------------------------------------------------------------- search

test('search ignores case, accents, spacing and punctuation', () => {
  assert.equal(normalizeSearch('  ET   Tube 7.5 (cuffed) '), 'et tube 7.5 cuffed');
  assert.equal(normalizeSearch('Épinéphrine'), 'epinephrine');
  assert.equal(normalizeSearch('i-gel'), 'i gel');
  assert.equal(normalizeSearch('4x4/gauze'), '4x4 gauze');
  assert.equal(normalizeSearch(null), '');

  for (const query of ['et tube 7.5', 'ET TUBE 7.5', 'et  tube   7.5', 'tube 7.5 cuffed', 'ettube', 'et-tube 7.5']) {
    assert.deepEqual(labels(alsTransport, query), ['ET tube 7.5 (cuffed)'], `"${query}" finds the ET tube`);
  }
  assert.deepEqual(labels(alsTransport, 'epinephrine'), ['Épinéphrine 1 mg'], 'accents do not matter either way');
  assert.deepEqual(labels(alsTransport, 'Épinéphrine'), ['Épinéphrine 1 mg']);
  for (const query of ['i gel', 'i-gel', 'igel', 'I-GEL size 3']) assert.deepEqual(labels(alsTransport, query), ['i-gel Size 3'], query);
  assert.equal(matches(alsTransport, 'zzz'), 0);
  assert.equal(matches(alsTransport, ''), 8, 'an empty search is everything');
});

test('sizes read the same however they are written: 18g, 18ga, 18 ga, 18 gauge', () => {
  for (const query of ['18g', '18ga', '18 ga', '18 g', '18 gauge', '18-gauge', '18GA']) {
    assert.deepEqual(labels(alsTransport, query), ['IV catheter 18 ga'], `"${query}"`);
  }
  for (const query of ['20g', '20 ga', '20 gauge']) assert.deepEqual(labels(alsTransport, query), ['IV catheter 20g'], `"${query}"`);
  assert.deepEqual(labels(alsTransport, 'catheter 18g'), ['IV catheter 18 ga']);
  assert.equal(normalizeSearch('18 ga'), normalizeSearch('18g'));
  assert.equal(normalizeSearch('14 french'), normalizeSearch('14fr'));
  assert.equal(normalizeSearch('12 mg'), normalizeSearch('12mg'));
  assert.equal(matches(alsTransport, '2 gauze'), 0, 'a word that only starts like a unit is not glued to the number');
});

test('the printed line of a variant does not bring its siblings, and a lone letter is a whole word', () => {
  const sizes = {
    key: 'als-med-bag', title: 'ALS MED BAG', version_label: null, printed_note: null,
    sections: [{ key: 'iv', label: 'IV Supplies', parent_key: null, items: ['18G', '20G', '22G'].map((size) => item(`iv.${size}`, `IV ${size}`, {
      source_label: 'IV 18G/20G/22G', variant_group: 'IV 18G/20G/22G', variant_label: size,
    })) }, { key: 'misc', label: 'Miscellaneous', parent_key: null, items: [item('misc.forceps', 'Magill Forceps'), item('misc.igel', 'i-gel Size 4')] }],
  };
  assert.deepEqual(searchResults([sizes], '20g').rows.map((row) => row.item.label), ['IV 20G']);
  assert.deepEqual(searchResults([sizes], '18 ga').rows.map((row) => row.item.label), ['IV 18G']);
  assert.deepEqual(searchResults([sizes], 'i gel').rows.map((row) => row.item.label), ['i-gel Size 4'], 'the "i" is a word, not a letter found inside "Magill"');
  assert.equal(searchResults([sizes], 'iv').matches, 3);
});

test('aliases, search terms, flags text and the AMOUNT text are searchable synonyms', () => {
  assert.deepEqual(labels(alsTransport, 'supraglottic'), ['i-gel Size 3'], 'aliases');
  assert.deepEqual(labels(alsTransport, 'adrenaline'), ['Épinéphrine 1 mg'], 'flags.search_terms');
  assert.deepEqual(labels(alsTransport, 'pink'), ['IV catheter 22'], 'item.search_terms');
  assert.deepEqual(labels(alsTransport, 'bvm bag'), ['Mask'], 'location_text');
  assert.deepEqual(labels(alsTransport, 'yellow 2 years'), ['Mask'], 'color_band');
  assert.deepEqual(labels(alsTransport, 'detail airway'), ['Handtevy tape'], 'qualifier_text');
  assert.deepEqual(labels(alsTransport, '2 each'), ['ET tube 7.5 (cuffed)'], 'AMOUNT text');
});

test('results span every list of the current family only, ranked, each with its list and section', () => {
  const rows = searchResults(alsTransport.forms, 'gloves').rows;
  assert.equal(rows.length, 0);

  const hits = searchResults(alsTransport.forms, 'catheter').rows;
  assert.deepEqual(hits.map((row) => row.context), ['ALS Transport Vehicle › I.V. / Needles', 'ALS Transport Vehicle › I.V. / Needles', 'ALS Transport Vehicle › I.V. / Needles']);

  const sectionHit = searchResults(alsTransport.forms, 'needles');
  assert.equal(sectionHit.matches, 3, 'a section name keeps its items');
  const mixed = searchResults(alsTransport.forms, 'main');
  assert.deepEqual(mixed.rows.map((row) => row.item.label), ['Mask'], 'matches through the section name');

  // Fire Boat 6 sees only its own list: another family's "Gauze 4x4" never appears.
  assert.deepEqual(searchResults(fireBoat.forms, 'gauze').rows.map((row) => row.context), ['Fire Boat 6 ALS › Main']);
  assert.equal(searchResults(fireBoat.forms, 'handtevy').matches, 0);
  assert.equal(searchResults(alsTransport.forms, 'gauze').matches, 0);
  assert.equal(searchResults(alsNonTransport.forms, 'handtevy').matches, 1, 'Handtevy is a list of both ALS forms');
  assert.equal(searchResults(alsTransport.forms, 'handtevy').matches, 1);

  // "airway" is in two items' own words (an alias, a printed condition) and in the name of the whole Airway Bag list.
  const ranked = searchResults(alsTransport.forms, 'airway');
  assert.deepEqual(ranked.rows.map((row) => row.item.label), ['i-gel Size 3', 'Handtevy tape', 'ET tube 7.5 (cuffed)', 'Épinéphrine 1 mg', 'Mask'],
    'items whose own words match come before items that match only through their list, each group in list order');
});

test('what was entered survives any filter, and "Entered only" keeps a row that was just cleared', () => {
  let draft = setQuantity(blankDraft(undefined, 'als-transport'), ET, 3);
  draft = setAnswer(draft, TAPE, false);
  const before = JSON.stringify(draft);
  filterForms(alsTransport.forms, 'catheter');
  searchResults(alsTransport.forms, 'zzz');
  assert.equal(JSON.stringify(draft), before, 'filtering never touches the draft');
  assert.equal(draft.quantities[ET], 3);

  assert.equal(isEntered(draft, 'airway-bag', 'airway.et-tube-75'), true);
  assert.equal(isEntered(draft, 'handtevy', 'handtevy.tape'), true, 'a No answer is entered');
  assert.equal(isEntered(draft, 'airway-bag', 'airway.mask'), false);
  const only = filterEntered(alsTransport.forms, draft);
  assert.equal(only.matches, 2);
  assert.deepEqual(only.forms.map((form) => form.key), ['airway-bag', 'handtevy']);
  const cleared = setQuantity(draft, ET, '');
  assert.equal(filterEntered(alsTransport.forms, cleared).matches, 1);
  assert.equal(filterEntered(alsTransport.forms, cleared, new Set([ET])).matches, 2, 'pinned rows stay while being retyped');
});

// ---------------------------------------------------------------- rows

test('a row shows AMOUNT and Fill if < apart, with chips for place, condition, color and flags', () => {
  const cath = TRANSPORT.sections[0].items[0];
  assert.equal(amountLabel(cath), 'Carry 4');
  assert.equal(refillLabel(cath), 'Refill under 2');
  assert.equal(amountLabel(TRANSPORT.sections[0].items[2]), '');
  assert.equal(refillLabel(TRANSPORT.sections[0].items[1]), '');
  assert.equal(amountLabel(item('x', 'X', { response_type: 'yes_no', min_text: 'Y / N' })), '', 'Yes/No rows have no amount');

  const mask = AIRWAY.sections[1].items[0];
  assert.deepEqual(itemChips(mask), [{ kind: 'color', text: 'Yellow (2 years)' }, { kind: 'place', text: 'Placed in BVM bag' }]);
  const tape = HANDTEVY.sections[0].items[0];
  assert.deepEqual(itemChips(tape), [{ kind: 'condition', text: 'Detail Airway Bags Only' }, { kind: 'cadence', text: 'Checked monthly, on the first Tuesday' }],
    'a flag whose words are already a chip is not repeated');
  assert.deepEqual(itemChips(item('y', 'Y')), []);
  assert.equal(cadenceText('weekly'), '', 'a cadence this build does not know is not guessed');
  assert.deepEqual(sectionNotes(HANDTEVY.sections[0]), ['Checked monthly, on the first Tuesday']);
  assert.deepEqual(sectionNotes(AIRWAY.sections[1]), ['Check the seal on every bag.']);
  assert.deepEqual(sectionNotes(AIRWAY.sections[0]), []);
  assert.equal(itemDomId('airway-bag', 'airway.mask'), 'ems-airway-bag-airway-mask');
});

test('the review groups only entered lines by list and section and says where each lives', () => {
  let draft = { ...blankDraft(undefined, 'als-transport'), targetChoice: 'unit:7' };
  draft = setQuantity(draft, ET, 2);
  draft = setQuantity(draft, MASK, 1);
  draft = setQuantity(draft, TAPE, 4);
  const groups = reviewGroups(draft, alsTransport);
  assert.deepEqual(groups.map((group) => [group.name, group.sections.map((section) => [section.label, section.rows.map((row) => `${row.label}:${row.detail}`)])]), [
    ['Airway Bag', [['Top Compartment', ['ET tube 7.5 (cuffed):2']], ['Main Compartment', ['Mask:1']]]],
    ['Handtevy', [['Monthly check', ['Handtevy tape:4']]]],
  ]);
  const row = groups[0].sections[1].rows[0];
  assert.deepEqual([row.formKey, row.itemId, row.sectionKey, row.panelKey], ['airway-bag', 'airway.mask', 'airway-main', 'airway-main']);
  assert.equal(groups[0].rows.length, 2, 'the flat rows are still there');
  assert.equal(reviewGroups(blankDraft(), alsTransport).length, 0, 'untouched rows are not listed');
});

// ---------------------------------------------------------------- families and the device

test('ALS Transport keeps the legacy draft key; every other form has its own', () => {
  assert.equal(draftStoreKey('42', 'als-transport'), '42');
  assert.equal(draftStoreKey('42', undefined), '42');
  assert.equal(draftStoreKey('42', null), '42');
  assert.equal(draftStoreKey('42', 'als-non-transport'), '42|als-non-transport');
  assert.equal(draftStoreKey('42', 'fire-boat-6'), '42|fire-boat-6');
  assert.equal(draftStoreKey('42', 'bls-detail'), '42|bls-detail');
  const keys = FAMILIES.map((entry) => draftStoreKey('42', entry.key));
  assert.equal(new Set(keys).size, 4, 'four forms, four rows');
  assert.notEqual(draftStoreKey('42', 'bls-detail'), draftStoreKey('420', 'bls-detail'));
});

test('a draft typed before this release opens in ALS Transport and nowhere else', () => {
  // The row an older bundle left: stored version 1, no family.
  const legacy = {
    v: 1, clientSubmissionId: '6f1e6b8a-1d2f-4c3a-9b1e-0d8c5a7e2f10', targetChoice: 'unit:7', savedAt: '2026-10-30T12:00:00.000Z',
    quantities: { [ET]: 3 }, answers: {},
  };
  const inTransport = restoreDraft(legacy, alsTransport);
  assert.equal(inTransport.quantities[ET], 3);
  assert.equal(inTransport.family, 'als-transport');
  assert.equal(inTransport.targetChoice, 'unit:7');
  assert.equal(restoreDraft(legacy, oldServer).quantities[ET], 3, 'a server without families is ALS Transport too');
  assert.equal(restoreDraft(legacy, alsNonTransport), null, 'never opens in another form');
  assert.equal(restoreDraft(legacy, fireBoat), null);

  const stored = serializeDraft(inTransport, '2026-11-02T12:00:00.000Z', alsTransport);
  assert.equal(stored.v, 1, 'the stored version stays 1');
  assert.equal(stored.family, 'als-transport');
  assert.deepEqual(restoreDraft(stored, alsTransport).quantities, { [ET]: 3 });
});

test('switching forms never mixes entries: each form reads and writes only its own row', () => {
  const device = new Map(); // the drafts table: key -> row
  const type = (definition, key, value) => {
    const fid = familyOfDefinition(definition);
    const row = device.get(draftStoreKey('42', fid));
    const draft = restoreDraft(row, definition) ?? blankDraft(undefined, fid);
    const next = setQuantity({ ...draft, targetChoice: 'unit:7' }, key, value);
    device.set(draftStoreKey('42', fid), serializeDraft(next, undefined, definition));
  };
  type(alsTransport, ET, 2);
  type(alsTransport, TAPE, 5); // Handtevy is a list of both ALS forms
  type(alsNonTransport, NT_GAUZE, 7);
  type(alsNonTransport, TAPE, 9);
  type(fireBoat, BOAT_GAUZE, 1);

  const open = (definition) => restoreDraft(device.get(draftStoreKey('42', familyOfDefinition(definition))), definition);
  assert.deepEqual(open(alsTransport).quantities, { [ET]: 2, [TAPE]: 5 });
  assert.deepEqual(open(alsNonTransport).quantities, { [NT_GAUZE]: 7, [TAPE]: 9 }, 'the same Handtevy item is typed separately per form');
  assert.deepEqual(open(fireBoat).quantities, { [BOAT_GAUZE]: 1 });
  assert.deepEqual([...device.keys()].sort(), ['42', '42|als-non-transport', '42|fire-boat-6']);

  // A row that somehow sits under the wrong key is refused, not merged.
  const misfiled = device.get('42|fire-boat-6');
  assert.equal(restoreDraft(misfiled, alsTransport), null);
  assert.equal(restoreDraft(misfiled, alsNonTransport), null);
});

test('retired entries are kept per form and never sent', () => {
  const row = {
    v: 1, family: 'als-non-transport', clientSubmissionId: '6f1e6b8a-1d2f-4c3a-9b1e-0d8c5a7e2f11', targetChoice: '',
    quantities: { [NT_GAUZE]: 2, 'retired-list|old.item': 4 }, answers: {}, labels: { 'retired-list|old.item': 'Old Item' },
  };
  const draft = restoreDraft(row, alsNonTransport);
  assert.equal(retiredCount(draft), 1);
  assert.deepEqual(draft.retired.quantities, { 'retired-list|old.item': 4 });
  assert.equal(draft.quantities[NT_GAUZE], 2);

  const payload = buildPayload({ ...draft, targetChoice: 'unit:7' }, alsNonTransport);
  assert.deepEqual(payload.lines, [{ form: 'als-non-transport', item_key: 'nt.gauze', quantity: 2 }], 'a retired entry is never sent');

  const again = serializeDraft(draft, undefined, alsNonTransport);
  assert.equal(again.family, 'als-non-transport');
  assert.deepEqual(again.retired.quantities, { 'retired-list|old.item': 4 });
  assert.equal(again.labels['retired-list|old.item'], 'Old Item');

  // A list that moves to another form: the item is live in the form that lists it and retired in the one that does not.
  const handtevyRow = { v: 1, family: 'fire-boat-6', clientSubmissionId: draft.clientSubmissionId, targetChoice: '', quantities: { [TAPE]: 3, [BOAT_GAUZE]: 1 }, answers: {} };
  const boat = restoreDraft(handtevyRow, fireBoat);
  assert.deepEqual(boat.quantities, { [BOAT_GAUZE]: 1 });
  assert.deepEqual(boat.retired.quantities, { [TAPE]: 3 });
  assert.equal(restoreDraft(handtevyRow, alsTransport), null, 'and never shown to a form it was not typed in');
});

test('the send carries its family, and a queued send without one is ALS Transport', () => {
  for (const [definition, key, lineForm] of [[alsTransport, ET, 'airway-bag'], [alsNonTransport, NT_GAUZE, 'als-non-transport'], [fireBoat, BOAT_GAUZE, 'fire-boat-6-als']]) {
    const fid = familyOfDefinition(definition);
    const draft = setQuantity({ ...blankDraft('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', fid), targetChoice: 'unit:7' }, key, 2);
    const payload = buildPayload(draft, definition);
    assert.equal(payload.family, fid);
    assert.equal(payload.lines[0].form, lineForm);
    assert.equal(familyOfPayload(payload), fid);
  }
  const shared = setQuantity({ ...blankDraft(undefined, 'als-non-transport'), targetChoice: 'detail' }, TAPE, 6);
  assert.deepEqual(buildPayload(shared, alsNonTransport).lines, [{ form: 'handtevy', item_key: 'handtevy.tape', quantity: 6 }]);

  // An entry queued by an older page has no family.
  const queued = { client_submission_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', target: { kind: 'detail_event' }, lines: [{ form: 'airway-bag', item_key: 'airway.et-tube-75', quantity: 2 }] };
  assert.equal(familyOfPayload(queued), 'als-transport');
  assert.equal(familyOfPayload(undefined), 'als-transport');
  assert.equal(familyOfPayload({ ...queued, family: 'not a key!' }), 'als-transport');
  const editable = draftFromPayload(queued, alsTransport);
  assert.equal(editable.family, 'als-transport');
  assert.equal(editable.quantities[ET], 2);
  assert.equal(editable.targetChoice, 'detail');
  const boatPayload = { ...queued, family: 'fire-boat-6', lines: [{ form: 'fire-boat-6-als', item_key: 'fb.gauze', quantity: 1 }] };
  assert.equal(familyOfPayload(boatPayload), 'fire-boat-6');
  assert.equal(draftFromPayload(boatPayload, fireBoat).family, 'fire-boat-6');
  assert.equal(draftFromPayload(boatPayload, fireBoat).quantities[BOAT_GAUZE], 1);
});

test('a returned form opens in its own family', () => {
  const request = {
    id: '0f8fad5b-d9cb-469f-a165-70867728950e', number: 'MIR-000005', status: 'returned', target_kind: 'other_unit', target_label: 'FIRE BOAT 6', apparatus_id: 31,
    form_family: 'fire-boat-6', form_family_short: 'Fire Boat 6', member_note: null, event_assignment: null,
    lines: [{ bag_key: 'fire-boat-6-als', item_key: 'fb.gauze', label: 'Gauze 4x4', response_type: 'quantity', requested_qty: 3, requested_flag: null }],
    history: [],
  };
  const draft = draftFromRequest(request, fireBoat);
  assert.equal(draft.family, 'fire-boat-6');
  assert.equal(draft.quantities[BOAT_GAUZE], 3);
  assert.equal(draft.targetChoice, 'unit:31');
  assert.equal(draft.revises, request.id);
  assert.equal(buildPayload(draft, fireBoat).family, 'fire-boat-6');
  assert.equal(requestFamilyShort(request), 'Fire Boat 6');
});

test('requests show their form; an old request is ALS Transport with its historical title', () => {
  assert.equal(requestFamilyShort({ form_family: 'bls-detail', form_family_short: 'BLS Detail' }), 'BLS Detail');
  assert.equal(requestFamilyShort({}), 'ALS Transport');
  assert.equal(requestFamilyShort({ form_family: null, form_family_short: null }), 'ALS Transport');
  assert.equal(requestFamilyShort({ form_family: 'als-transport' }), 'ALS Transport');
  assert.equal(requestFamilyTitle({}), 'Medical Inventory Request');
  assert.equal(requestFamilyTitle({ form_family_title: 'Fire Boat 6 Medical Inventory Form' }), 'Fire Boat 6 Medical Inventory Form');
  assert.equal(shortFormTitle('ALS Transport Inventory Form'), 'ALS Transport');
  assert.equal(shortFormTitle('Fire Boat 6 Medical Inventory Form'), 'Fire Boat 6');
  assert.equal(shortFormTitle('Something else'), 'Something else');
});

test('the form preselects its default unit, and a preselected unit is not the member\'s work', () => {
  assert.equal(defaultTargetChoice(fireBoat), 'unit:31');
  assert.equal(defaultTargetChoice(alsTransport), '');
  assert.equal(defaultTargetChoice(oldServer), '');
  assert.equal(defaultTargetChoice({ ...fireBoat, family: { ...fireBoat.family, default_target: 'unit:999' } }), '', 'a unit that is not on the picker is ignored');
  assert.equal(defaultTargetChoice({ ...fireBoat, family: { ...fireBoat.family, default_target: 'detail' } }), 'detail');

  const fresh = withDefaultTarget(blankDraft(undefined, 'fire-boat-6'), fireBoat);
  assert.equal(fresh.targetChoice, 'unit:31');
  assert.equal(withDefaultTarget({ ...fresh, targetChoice: 'unit:7' }, fireBoat).targetChoice, 'unit:7', 'the member can change it');
  assert.equal(withDefaultTarget(blankDraft(), alsTransport).targetChoice, '');

  assert.equal(serializeDraft(fresh, undefined, fireBoat), null, 'nothing but the default unit: nothing to keep');
  assert.equal(hasUnsavedWork(fresh, fireBoat), false);
  const changed = { ...fresh, targetChoice: 'unit:7' };
  assert.equal(serializeDraft(changed, undefined, fireBoat).targetChoice, 'unit:7');
  assert.equal(hasUnsavedWork(changed, fireBoat), true);
  const typed = setQuantity(fresh, BOAT_GAUZE, 1);
  assert.equal(serializeDraft(typed, undefined, fireBoat).quantities[BOAT_GAUZE], 1);
  assert.equal(countRequested(typed), 1);
});

// ---------------------------------------------------------------- addresses

test('every form opens by its own deep link, and the legacy link is ALS Transport', () => {
  assert.equal(formKeyForFamily('als-transport'), 'als-transport-inventory');
  assert.equal(formKeyForFamily('als-non-transport'), 'als-non-transport-inventory');
  assert.equal(formKeyForFamily('fire-boat-6'), 'fire-boat-6-medical-inventory');
  assert.equal(formKeyForFamily('bls-detail'), 'bls-detail-medical-inventory');
  assert.equal(formKeyForFamily(undefined), 'medical-inventory-request');

  for (const entry of FAMILIES) {
    assert.deepEqual(viewFromSearch(`?form=${entry.form_key}`), { name: 'form', family: entry.key }, entry.form_key);
    assert.equal(searchForView({ name: 'form', family: entry.key }), `?form=${entry.form_key}`);
    assert.deepEqual(viewFromSearch(searchForView({ name: 'form', family: entry.key })), { name: 'form', family: entry.key });
    assert.equal(familyFromFormKey(entry.form_key, FAMILIES), entry.key);
    assert.deepEqual(viewFromDeepLink({ view: 'form', request: null, family: entry.key }), { name: 'form', family: entry.key });
    assert.deepEqual(viewFromDeepLink({ view: 'form', request: null, family: entry.form_key }), { name: 'form', family: entry.key }, 'the bootstrap may send the form key');
  }
  assert.deepEqual(viewFromSearch('?form=medical-inventory-request'), { name: 'form' });
  assert.equal(familyOfView({ name: 'form' }), 'als-transport');
  assert.equal(familyFromFormKey('medical-inventory-request'), 'als-transport');
  assert.deepEqual(viewFromDeepLink({ view: 'form', request: null }), { name: 'form', family: 'als-transport' });
  assert.deepEqual(viewFromDeepLink({ view: 'form', request: null, family: 'medical-inventory-request' }), { name: 'form', family: 'als-transport' });
  assert.deepEqual(viewFromSearch('?form=fire-boat-6-medical-inventory&view=mine'), { name: 'mine' });
  assert.equal(viewFromSearch('?form=not-a-form'), null);
  assert.equal(familyFromFormKey('not-a-form', FAMILIES), null);
  assert.equal(resolveFamily('nonsense form key!'), 'als-transport');
  assert.equal(resolveFamily(''), 'als-transport');

  const id = '0f8fad5b-d9cb-469f-a165-70867728950e';
  const fix = { name: 'form', family: 'fire-boat-6', revises: id };
  assert.equal(searchForView(fix), `?form=fire-boat-6-medical-inventory&revises=${id}`);
  assert.deepEqual(viewFromSearch(searchForView(fix)), fix);
});

test('a library card opens the form its link names', () => {
  assert.equal(familyFromLibraryLink({ key: 'x', href: '/employee/forms?form=fire-boat-6-medical-inventory' }), 'fire-boat-6');
  assert.equal(familyFromLibraryLink({ key: 'x', href: 'https://www.mbfdhub.com/employee/forms?form=bls-detail-medical-inventory' }), 'bls-detail');
  assert.equal(familyFromLibraryLink({ key: 'x', href: '/employee/forms?form=medical-inventory-request' }), 'als-transport');
  assert.equal(familyFromLibraryLink({ key: 'als-non-transport-inventory', href: '/employee/forms' }), 'als-non-transport');
  assert.equal(familyFromLibraryLink({ key: 'als-non-transport', href: '' }), 'als-non-transport');
  assert.equal(familyFromLibraryLink({ key: 'x', href: '/employee/forms?form=fire-boat-6-medical-inventory', family: 'bls-detail' }), 'bls-detail', 'an explicit family wins');
  assert.equal(familyFromLibraryLink({ key: 'medical-inventory-request', href: '/x' }), 'als-transport');
});

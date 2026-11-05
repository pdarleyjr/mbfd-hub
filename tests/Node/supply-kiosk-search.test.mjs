import assert from 'node:assert/strict';
import test from 'node:test';
import { performance } from 'node:perf_hooks';

// Plain TypeScript with type-only imports, so Node loads the module directly (same approach as ems-stock-level.test.mjs).
const search = await import('../../resources/js/supply-kiosk/search.ts');

let nextId = 1;
const item = (name, extra = {}) => ({
  id: nextId++, name, location_code: 'A1', location_label: 'Shelf A1', section_label: 'General', category: 'General',
  aliases: [], learned_aliases: [], item_numbers: [], popularity: 0, ...extra,
});

const airway = { section_label: 'Airway', category: 'Airway', location_code: 'A1', location_label: 'Shelf A1' };
const oxygen = { section_label: 'Oxygen', category: 'Respiratory', location_code: 'A2', location_label: 'Shelf A2' };
const iv = { section_label: 'IV Supplies', category: 'IV', location_code: 'B3', location_label: 'Shelf B3' };
const trauma = { section_label: 'Trauma', category: 'Bleeding control', location_code: 'C1', location_label: 'Shelf C1' };
const wound = { section_label: 'Wound care', category: 'Dressings', location_code: 'C2', location_label: 'Shelf C2' };
const ppe = { section_label: 'PPE', category: 'PPE', location_code: 'D1', location_label: 'Bin D1' };
const cardiac = { section_label: 'Cardiac', category: 'Monitoring', location_code: 'E1', location_label: 'Shelf E1' };
const misc = { section_label: 'General', category: 'General', location_code: 'F1', location_label: 'Shelf F1' };

const items = [
  item('Non-Rebreather Mask Adult', { ...oxygen, popularity: 30 }),
  item('Non-Rebreather Mask Pediatric', { ...oxygen, popularity: 4 }),
  item('Nasal Cannula Adult', { ...oxygen, popularity: 40 }),
  item('Nasal Cannula Pediatric', { ...oxygen, popularity: 3 }),
  item('Simple Face Mask', { ...oxygen, popularity: 2 }),
  item('Nebulizer Kit', { ...oxygen, popularity: 12 }),
  item('Oxygen Regulator', { ...oxygen, popularity: 1 }),
  item('Bag Valve Mask Adult', { ...airway, popularity: 9 }),
  item('Bag Valve Mask Pediatric', { ...airway, popularity: 3 }),
  item('Bag Valve Mask Infant', { ...airway, popularity: 1 }),
  item('Oropharyngeal Airway Kit', { ...airway, popularity: 5 }),
  item('Nasopharyngeal Airway 28 Fr', { ...airway, popularity: 4 }),
  item('Endotracheal Tube 7.5', { ...airway, popularity: 3 }),
  item('Endotracheal Tube 8.0', { ...airway, popularity: 2 }),
  item('i-gel Size 4', { ...airway, popularity: 6 }),
  item('Yankauer Suction Tip', { ...airway, popularity: 7 }),
  item('Bougie Adult', { ...airway, popularity: 1 }),
  item('IV Catheter 14g', { ...iv, popularity: 2 }),
  item('IV Catheter 16g', { ...iv, popularity: 5 }),
  item('IV Catheter 18g', { ...iv, popularity: 14, aliases: ['angiocath 18'], item_numbers: ['381744'] }),
  item('IV Catheter 20g', { ...iv, popularity: 20 }),
  item('IV Catheter 22g', { ...iv, popularity: 8 }),
  item('IV Catheter 24g', { ...iv, popularity: 1 }),
  item('IV Start Kit', { ...iv, popularity: 25 }),
  item('IV Tubing 10 drop', { ...iv, popularity: 15 }),
  item('IV Tubing 60 drop', { ...iv, popularity: 4 }),
  item('Saline Flush 10 mL', { ...iv, popularity: 35 }),
  item('Normal Saline 1000 mL', { ...iv, popularity: 18 }),
  item('Tegaderm IV Dressing', { ...iv, popularity: 11 }),
  item('Alcohol Prep Pads', { ...iv, popularity: 22 }),
  item('EZ-IO Needle 25mm', { ...iv, popularity: 3 }),
  item('EZ-IO Needle 45mm', { ...iv, popularity: 1 }),
  item('Pressure Infusion Bag', { ...iv, popularity: 1, learned_aliases: ['squeeze bag'] }),
  item('Syringe 10 mL', { ...iv, popularity: 9 }),
  item('Hypodermic Needle 18g', { ...iv, popularity: 2 }),
  item('CAT Tourniquet', { ...trauma, popularity: 10 }),
  item('Israeli Trauma Dressing 6 inch', { ...trauma, popularity: 6 }),
  item('QuikClot Combat Gauze', { ...trauma, popularity: 3 }),
  item('ABD Pad 5x9', { ...trauma, popularity: 7 }),
  item('Gauze Pads 4x4 Sterile', { ...wound, popularity: 28 }),
  item('Kerlix Gauze Roll', { ...wound, popularity: 9 }),
  item('Silk Tape 1 inch', { ...wound, popularity: 12 }),
  item('Coban Wrap 3 inch', { ...wound, popularity: 2 }),
  item('Burn Sheet', { ...wound, popularity: 1 }),
  item('Band-Aids Assorted', { ...wound, popularity: 5 }),
  item('Nitrile Gloves Small', { ...ppe, popularity: 15 }),
  item('Nitrile Gloves Medium', { ...ppe, popularity: 30 }),
  item('Nitrile Gloves Large', { ...ppe, popularity: 32 }),
  item('Nitrile Gloves XL', { ...ppe, popularity: 12 }),
  item('N95 Respirator', { ...ppe, popularity: 8 }),
  item('Isolation Gown', { ...ppe, popularity: 2 }),
  item('ECG Electrodes', { ...cardiac, popularity: 21 }),
  item('Defibrillation Pads Adult', { ...cardiac, popularity: 3 }),
  item('Glucometer Test Strips', { ...cardiac, popularity: 13 }),
  item('Lancets', { ...cardiac, popularity: 13 }),
  item('Blood Pressure Cuff Adult', { ...cardiac, popularity: 2 }),
  item('Dextrose 50% Syringe', { ...cardiac, popularity: 1 }),
  item('Cervical Collar Adjustable', { ...misc, popularity: 3 }),
  item('SAM Splint', { ...misc, popularity: 2 }),
  item('Emesis Bags', { ...misc, popularity: 16 }),
  item('Chux Underpads', { ...misc, popularity: 6 }),
  item('OB Kit', { ...misc, popularity: 1 }),
  item('Cold Pack Instant', { ...misc, popularity: 4 }),
];

const shorthand = { nrb: ['non rebreather', 'non-rebreather'], '4x4': ['gauze'] };
const index = search.buildSearchIndex(items, shorthand);
const top = (query, n = 3) => search.searchItems(index, query, n).map(found => found.name);

function assertFirst(query, expected) {
  const names = top(query);
  assert.equal(names[0], expected, `"${query}" ranked ${JSON.stringify(names)}`);
}
function assertInTop(query, expected, n = 3) {
  const names = top(query, n);
  for (const name of expected) assert.ok(names.includes(name), `"${query}" top ${n} ${JSON.stringify(names)} is missing ${name}`);
}

test('shorthand and the full words find the non-rebreather', () => {
  assertFirst('nrb', 'Non-Rebreather Mask Adult');
  assertInTop('nrb', ['Non-Rebreather Mask Adult', 'Non-Rebreather Mask Pediatric'], 2);
  assertFirst('non rebreather', 'Non-Rebreather Mask Adult');
  assertFirst('NRB', 'Non-Rebreather Mask Adult');
});

test('misspellings and sizes: "cathiter 18" and "18g iv" find the 18 gauge catheter first', () => {
  assertFirst('cathiter 18', 'IV Catheter 18g');
  assertFirst('18g iv', 'IV Catheter 18g');
  assertFirst('18 gauge iv', 'IV Catheter 18g');
  assertFirst('iv 18', 'IV Catheter 18g');
  assertInTop('cathiter', ['IV Catheter 20g'], 3);
});

test('4x4, tegaderm, ez io, saline flush', () => {
  assertFirst('4x4', 'Gauze Pads 4x4 Sterile');
  assertFirst('4 x 4', 'Gauze Pads 4x4 Sterile');
  assertFirst('tegaderm', 'Tegaderm IV Dressing');
  assertFirst('iv dressing', 'Tegaderm IV Dressing');
  assertInTop('ez io', ['EZ-IO Needle 25mm', 'EZ-IO Needle 45mm'], 2);
  assertInTop('ezio', ['EZ-IO Needle 25mm', 'EZ-IO Needle 45mm'], 2);
  assertInTop('intraosseous', ['EZ-IO Needle 25mm'], 2);
  assertFirst('saline flush', 'Saline Flush 10 mL');
  assertFirst('flush', 'Saline Flush 10 mL');
});

test('a description finds the bleeding control supplies', () => {
  const names = top('something for bleeding', 5);
  const bleeding = ['CAT Tourniquet', 'Israeli Trauma Dressing 6 inch', 'QuikClot Combat Gauze', 'Gauze Pads 4x4 Sterile', 'ABD Pad 5x9', 'Kerlix Gauze Roll'];
  assert.ok(names.slice(0, 3).every(name => bleeding.includes(name)), `top 3 for bleeding: ${JSON.stringify(names)}`);
  assertInTop('tq', ['CAT Tourniquet'], 1);
  assertInTop('tourniquet', ['CAT Tourniquet'], 1);
  assertInTop('tournequet', ['CAT Tourniquet'], 1);
});

test('bvm adult and size words', () => {
  assertFirst('bvm adult', 'Bag Valve Mask Adult');
  assertFirst('bvm peds', 'Bag Valve Mask Pediatric');
  assertInTop('bvm', ['Bag Valve Mask Adult', 'Bag Valve Mask Pediatric', 'Bag Valve Mask Infant'], 3);
  assertFirst('nc pedi', 'Nasal Cannula Pediatric');
});

test('glovs large: a typo plus a size', () => {
  assertFirst('glovs large', 'Nitrile Gloves Large');
  assertFirst('gloves lg', 'Nitrile Gloves Large');
  assertInTop('glovs', ['Nitrile Gloves Large', 'Nitrile Gloves Medium'], 3);
});

test('item numbers, learned aliases and aliases', () => {
  assertFirst('381744', 'IV Catheter 18g');
  assertFirst('3817', 'IV Catheter 18g');
  assertFirst('squeeze bag', 'Pressure Infusion Bag');
  assertFirst('angiocath 18', 'IV Catheter 18g');
});

test('EMS shorthand from the built-in dictionary', () => {
  assertFirst('opa', 'Oropharyngeal Airway Kit');
  assertFirst('npa', 'Nasopharyngeal Airway 28 Fr');
  assertInTop('ett', ['Endotracheal Tube 7.5', 'Endotracheal Tube 8.0'], 2);
  assertFirst('ekg', 'ECG Electrodes');
  assertFirst('d50', 'Dextrose 50% Syringe');
  assertFirst('c collar', 'Cervical Collar Adjustable');
  assertInTop('airway', ['Oropharyngeal Airway Kit', 'Nasopharyngeal Airway 28 Fr'], 3);
  assertInTop('oxygen', ['Oxygen Regulator'], 3);
});

test('prefixes match while the member is still typing', () => {
  assertInTop('glo', ['Nitrile Gloves Large', 'Nitrile Gloves Medium'], 4);
  assertFirst('teg', 'Tegaderm IV Dressing');
  assertFirst('kerl', 'Kerlix Gauze Roll');
});

test('ranking: exact name beats word starts, aliases and category matches; ties go to popularity', () => {
  assertFirst('lancets', 'Lancets');
  const scored = search.searchScored(index, 'catheter', 6);
  assert.deepEqual(scored.slice(0, 3).map(hit => hit.item.name), ['IV Catheter 20g', 'IV Catheter 18g', 'IV Catheter 22g']);
  // An exact word in the name outranks a category-only match.
  const hits = search.searchScored(index, 'airway', 20);
  const kit = hits.find(hit => hit.item.name === 'Oropharyngeal Airway Kit');
  const bvm = hits.find(hit => hit.item.name === 'Bag Valve Mask Adult');
  assert.ok(kit && bvm && kit.score > bvm.score, 'name match outranks category match');
});

test('nothing for noise, and empty queries return nothing', () => {
  assert.deepEqual(top(''), []);
  assert.deepEqual(top('   '), []);
  assert.deepEqual(top('zzqxw'), []);
});

test('helpers: normalize sizes, edit distance, sound-alike key', () => {
  assert.equal(search.normalize('18 Gauge'), '18g');
  assert.equal(search.normalize('10 cc'), '10ml');
  assert.equal(search.normalize('4 X 4'), '4x4');
  assert.equal(search.editDistance('glovs', 'gloves', 1), 1);
  assert.equal(search.editDistance('cahteter', 'catheter', 2), 1);
  assert.equal(search.editDistance('abc', 'xyz', 1), 2);
  assert.equal(search.soundKey('cathiter'), search.soundKey('catheter'));
});

test('benchmark: a query over 300 items returns in well under 50 ms', () => {
  const big = [];
  for (let copy = 0; copy < 5; copy++) for (const source of items) big.push({ ...source, id: big.length + 1, name: `${source.name} ${copy ? `lot ${copy}` : ''}`.trim() });
  const bigIndex = search.buildSearchIndex(big.slice(0, 300), shorthand);
  const queries = ['nrb', 'cathiter 18', 'something for bleeding', 'glovs large', 'tournequet', 'bvm adult', 'saline flush', 'g', 'xyzzy plugh'];
  for (const q of queries) search.searchItems(bigIndex, q); // warm up
  let worst = 0;
  for (let round = 0; round < 5; round++) {
    for (const q of queries) {
      const start = performance.now();
      search.searchItems(bigIndex, q);
      worst = Math.max(worst, performance.now() - start);
    }
  }
  assert.ok(worst < 50, `slowest query took ${worst.toFixed(2)} ms`);
  assert.ok(worst < 16, `slowest query took ${worst.toFixed(2)} ms; one frame is 16 ms`);
});

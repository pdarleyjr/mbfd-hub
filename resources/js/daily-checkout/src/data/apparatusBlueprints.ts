export interface BlueprintZone {
  compartmentId: string;
  label: string;
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface BlueprintView {
  id: string;
  label: string;
  outline: string;
  details: string[];
  image?: string;
  canvas?: { width: number; height: number };
  wheels?: Array<{ x: number; y: number }>;
  mirror?: boolean;
  zones: BlueprintZone[];
}

export interface BlueprintProfile {
  id: string;
  label: string;
  source: string;
  views: BlueprintView[];
}

const zone = (compartmentId: string, label: string, x: number, y: number, width: number, height: number): BlueprintZone => ({ compartmentId, label, x, y, width, height });
const apparatusImages = {
  puc: {
    driver: new URL('../assets/apparatus/puc/driver-side.png', import.meta.url).href,
    officer: new URL('../assets/apparatus/puc/officer-side.png', import.meta.url).href,
    top: new URL('../assets/apparatus/puc/top.png', import.meta.url).href,
    rear: new URL('../assets/apparatus/puc/rear.png', import.meta.url).href,
    front: new URL('../assets/apparatus/puc/front.png', import.meta.url).href,
  },
  e2: {
    driver: new URL('../assets/apparatus/e2-puc/driver-side.png', import.meta.url).href,
    officer: new URL('../assets/apparatus/e2-puc/officer-side.png', import.meta.url).href,
    top: new URL('../assets/apparatus/e2-puc/top.png', import.meta.url).href,
    rear: new URL('../assets/apparatus/e2-puc/rear.png', import.meta.url).href,
    front: new URL('../assets/apparatus/e2-puc/front.png', import.meta.url).href,
  },
  ladder: {
    driver: new URL('../assets/apparatus/ladder/driver-side.png', import.meta.url).href,
    officer: new URL('../assets/apparatus/ladder/officer-side.png', import.meta.url).href,
    top: new URL('../assets/apparatus/ladder/top.png', import.meta.url).href,
    rear: new URL('../assets/apparatus/ladder/rear.png', import.meta.url).href,
    front: new URL('../assets/apparatus/ladder/front.png', import.meta.url).href,
  },
  arrowxt: {
    driver: new URL('../assets/apparatus/arrowxt/driver-side.png', import.meta.url).href,
    officer: new URL('../assets/apparatus/arrowxt/officer-side.png', import.meta.url).href,
    top: new URL('../assets/apparatus/arrowxt/top.png', import.meta.url).href,
    rear: new URL('../assets/apparatus/arrowxt/rear.png', import.meta.url).href,
    front: new URL('../assets/apparatus/arrowxt/front.png', import.meta.url).href,
  },
  rescue: {
    driver: new URL('../assets/apparatus/rescue/driver-side.png', import.meta.url).href,
    officer: new URL('../assets/apparatus/rescue/officer-side.png', import.meta.url).href,
    interior: new URL('../assets/apparatus/rescue/interior.png', import.meta.url).href,
  },
};
const photoView = (id: string, label: string, source: string, canvas: { width: number; height: number }, zones: BlueprintZone[]): BlueprintView => ({
  id, label, image: source, canvas, outline: '', details: [], zones,
});
const sideCanvas = { width: 600, height: 230 };
const topCanvas = { width: 600, height: 300 };
const endCanvas = { width: 400, height: 400 };
const frontBumper = (source: string): BlueprintView => photoView('front', 'Front', source, endCanvas, [zone('front_bumper', 'Bumper', 35, 280, 330, 65)]);
const engineTop = (source: string): BlueprintView => photoView('top', 'Top', source, topCanvas, [
  zone('top_comp_1', 'Top 1', 267, 83, 81, 30), zone('top_comp_2', 'Top 2', 352, 83, 192, 30),
  zone('top_comp_3', 'Top 3', 267, 198, 81, 26), zone('top_comp_4', 'Top 4', 417, 198, 127, 26),
]);
const engineRear = (source: string): BlueprintView => photoView('rear', 'Rear', source, endCanvas, [
  zone('rear_1', 'Rear 1', 38, 82, 67, 96), zone('rear_2', 'Rear 2', 38, 183, 67, 95),
  zone('rear_3', 'Rear 3', 295, 82, 67, 96), zone('rear_4', 'Rear 4', 295, 183, 67, 95),
  zone('rear_5', 'Rear 5', 116, 226, 168, 80), zone('tailboard', 'Tailboard', 90, 314, 220, 42),
]);
const engineCab: BlueprintView = { id: 'cab', label: 'Cab', outline: 'M95 42H507V193H95L80 175V59Z', details: ['M107 51H145V183H107', 'M151 114H493'], zones: [zone('front_cab', 'Front cab', 161, 52, 328, 61), zone('rear_cab', 'Rear cab', 161, 123, 328, 60)] };
const engineSide = (id: 'left' | 'right', source: string, prefix: 'a' | 'b' | 'l' | 'r'): BlueprintView => photoView(id, id === 'left' ? 'Driver' : 'Officer', source, sideCanvas,
  id === 'left' ? [
    zone(`comp_${prefix}4`, 'L1', 484, 68, 84, 99),
    zone(`comp_${prefix}3`, 'L2', 381, 68, 101, 50),
    zone(`comp_${prefix}2`, 'L3', 287, 68, 91, 99),
    zone(`comp_${prefix}1`, 'L4', 174, 48, 54, 88),
  ] : [
    zone(`comp_${prefix}4`, 'R1', 38, 69, 80, 98),
    zone(`comp_${prefix}3`, 'R2', 121, 69, 95, 51),
    zone(`comp_${prefix}2`, 'R3', 219, 69, 92, 98),
    zone(`comp_${prefix}1`, 'R4', 379, 48, 56, 88),
  ]);
// Photo zones identify locations only; equipment and quantities come from the issued checklist.
export const engine2Puc: BlueprintProfile = {
  id: 'engine2-puc', label: 'PUC · Engine 2', source: 'E2 PUC CHECKOUT SHEET.pdf · page 1',
  views: [
    engineSide('left', apparatusImages.e2.driver, 'l'),
    engineSide('right', apparatusImages.e2.officer, 'r'),
    engineTop(apparatusImages.e2.top), engineRear(apparatusImages.e2.rear), frontBumper(apparatusImages.e2.front), engineCab,
  ],
};

export const enginePuc: BlueprintProfile = {
  id: 'engine-puc', label: 'PUC · Engines 1, 3 and 4', source: 'E1 / E3 / E4 PUC CHECKOUT SHEET.pdf · page 1',
  views: [
    engineSide('left', apparatusImages.puc.driver, 'a'),
    engineSide('right', apparatusImages.puc.officer, 'b'),
    engineTop(apparatusImages.puc.top), engineRear(apparatusImages.puc.rear), frontBumper(apparatusImages.puc.front), engineCab,
  ],
};

const ladderCab: BlueprintView = {
  id: 'cab', label: 'Cab', outline: 'M90 42H511V193H90L74 176V60Z', details: ['M105 52H142V182H105', 'M153 115H495'],
  zones: [zone('front_cab', 'Front cab', 163, 53, 330, 60), zone('rear_cab', 'Rear cab', 163, 124, 330, 59)],
};
const ladderTop = (source: string, compartments: BlueprintZone[]): BlueprintView => photoView('top', 'Top', source, topCanvas, compartments);
const aerialView = (source: string, bucketId: string, ladderId: string): BlueprintView => photoView('aerial', 'Aerial', source, topCanvas, [
  zone(bucketId, 'Bucket', 16, 88, 71, 112), zone(ladderId, 'Ladder', 91, 111, 461, 82),
]);
const ladderRear = (source: string, rearId: string, rearLabel: string): BlueprintView => photoView('rear', 'Rear', source, endCanvas, [
  zone(rearId, rearLabel, 112, 199, 176, 119), zone('tailboard', 'Tailboard', 56, 322, 288, 50),
]);

// Side letters and separate compartments follow the paper's A/B drawings.
export const ladder1: BlueprintProfile = {
  id: 'ladder1', label: 'Ladder 1', source: 'L1 CHECKOUT SHEET.pdf · page 1 (Mar 2016)',
  views: [
    photoView('left', 'Driver', apparatusImages.ladder.driver, sideCanvas, [
      zone('a_comp_5_6', 'L1 + L2', 461, 103, 110, 64),
      zone('a_comp_4', 'L3', 383, 91, 75, 48), zone('a_comp_3', 'L4', 328, 90, 53, 77),
      // Source A1/A2 overlap the photographed pump panel; keep them in Other areas.
    ]),
    photoView('right', 'Officer', apparatusImages.ladder.officer, sideCanvas, [
      zone('b_comp_6', 'R1', 39, 106, 66, 62), zone('b_comp_5', 'R2', 107, 105, 41, 36),
      zone('b_comp_4', 'R3', 150, 92, 72, 44), zone('b_comp_3', 'R4', 224, 91, 55, 76),
      zone('b_comp_2', 'R5', 281, 82, 27, 28),
      // B1 is near the photographed pump panel without a defensible door outline.
    ]),
    ladderRear(apparatusImages.ladder.rear, 'rear_1', 'Rear'),
    ladderTop(apparatusImages.ladder.top, [zone('top_comp_driver', 'Driver top', 257, 82, 210, 27), zone('top_comp_officer', 'Officer top', 257, 181, 210, 25)]),
    frontBumper(apparatusImages.ladder.front),
    aerialView(apparatusImages.ladder.top, 'bucket', 'ladder'),
    ladderCab,
  ],
};

// L3 B1-B5 and the labeled Stokes box are separate from the unlocated officer-side panel list.
export const ladder3: BlueprintProfile = {
  id: 'ladder3', label: 'Ladder 3', source: 'L3 CHECKOUT SHEET.pdf · page 1 (revised 09/02/18)',
  views: [
    photoView('left', 'Driver', apparatusImages.arrowxt.driver, sideCanvas, [
      zone('a_comp_6', 'L1', 489, 108, 64, 59), zone('a_comp_5', 'L2', 453, 108, 34, 32),
      zone('a_comp_4', 'L3', 378, 96, 73, 43), zone('a_comp_3', 'L4', 336, 95, 39, 72),
      zone('a_comp_2', 'L5', 305, 90, 29, 77), zone('a_comp_1', 'L6', 186, 83, 57, 82),
    ]),
    photoView('right', 'Officer', apparatusImages.arrowxt.officer, sideCanvas, [
      zone('b_comp_5', 'R1', 53, 120, 48, 48), zone('b_comp_4', 'R2', 103, 110, 34, 32),
      zone('b_comp_3', 'R3', 139, 101, 82, 40), zone('b_comp_2', 'R4', 224, 105, 54, 62),
      zone('b_comp_1', 'R5', 280, 87, 29, 79),
      // The Stokes area is named on paper but not a discrete side door in this photo.
    ]),
    ladderRear(apparatusImages.arrowxt.rear, 'rear_comp', 'Rear ladders'),
    // Top storage lacks a unique visible perimeter; it stays in Other areas.
    frontBumper(apparatusImages.arrowxt.front),
    aerialView(apparatusImages.arrowxt.top, 'aerial_bucket', 'aerial_ladder'),
    ladderCab,
  ],
};

export const rescue: BlueprintProfile = {
  id: 'rescue', label: 'Rescue', source: 'updated_rescue_inventory.pdf · page 1',
  views: [
    photoView('left', 'Driver', apparatusImages.rescue.driver, sideCanvas, [
      zone('compartment_d', 'L1', 506, 68, 62, 116),
      zone('compartment_c', 'L2', 404, 121, 79, 23),
      zone('compartment_b', 'L3', 306, 115, 94, 68),
      zone('compartment_a', 'L4', 251, 42, 43, 142),
    ]),
    photoView('right', 'Officer', apparatusImages.rescue.officer, sideCanvas, [
      zone('officer_compartment_d', 'R1', 33, 68, 54, 116),
      zone('officer_compartment_c', 'R2', 116, 121, 82, 23),
      zone('officer_compartment_b', 'R3', 203, 115, 95, 68),
      zone('officer_compartment_a', 'R4', 307, 42, 43, 142),
    ]),
    photoView('interior', 'Interior', apparatusImages.rescue.interior, sideCanvas, [
      zone('patient_compartment', 'Patient', 55, 55, 175, 130),
      zone('stretcher', 'Stretcher', 250, 78, 88, 140),
    ]),
  ],
};

const boatOutline = 'M37 48H433Q526 48 577 116Q526 188 433 188H37Z';
const boatDetails = ['M50 62H433Q505 62 559 116Q505 174 433 174H50Z'];
const boatStorage = (sideName: 'port' | 'starboard'): BlueprintView => ({
  id: sideName, label: `${sideName === 'port' ? 'Port' : 'Starboard'} storage`, outline: boatOutline, details: boatDetails,
  // These are source-named storage regions, not invented longitudinal door locations.
  zones: [zone(`fb6-${sideName}-side-${sideName === 'port' ? 'deck-' : ''}upper-storage`, 'Upper storage', 68, 65, 359, 48), zone(`fb6-${sideName}-side-${sideName === 'port' ? 'deck-' : ''}lower-storage`, 'Lower storage', 68, 123, 359, 48)],
});
const boatCab = (id: string, label: string, compartmentId: string): BlueprintView => ({
  id, label, outline: 'M115 44H465L505 83V155L465 194H115Z', details: ['M462 59V180', 'M131 59H448', 'M131 179H448'],
  zones: [zone(compartmentId, label, 149, 72, 281, 95)],
});

export const fireboat6: BlueprintProfile = {
  id: 'fireboat6', label: 'Fire Boat 6', source: 'FIRE BOAT 6 DAILY 7-2026.pdf · page 1',
  views: [
    boatStorage('port'),
    boatStorage('starboard'),
    { id: 'bow', label: 'Bow anchor', outline: boatOutline, details: boatDetails, zones: [zone('fb6-front-anchor-locker', 'Anchor', 448, 86, 91, 62)] },
    boatCab('cab-checks', 'Cab checks', 'fb6-interior-cab'),
    boatCab('cab-storage', 'Cab storage', 'fb6-inside-cab-storage'),
    { id: 'seat-storage', label: 'Seat storage', outline: 'M115 44H465L505 83V155L465 194H115Z', details: ['M462 59V180'], zones: [zone('fb6-inside-seats-port', 'Port seats', 149, 59, 281, 54), zone('fb6-inside-seats-starboard', 'Starboard seats', 149, 127, 281, 54)] },
  ],
};

const profilesByChecklistType: Record<string, BlueprintProfile> = {
  engine: enginePuc,
  engine2: engine2Puc,
  ladder1,
  ladder3,
  rescue,
  fireboat6,
};

export function resolveBlueprint(checklistType: string | null | undefined): BlueprintProfile | null {
  // The server-issued checklist type is authoritative. Profiles only provide
  // visual zones; the issued checklist remains the inventory authority.
  return checklistType ? profilesByChecklistType[checklistType] ?? null : null;
}

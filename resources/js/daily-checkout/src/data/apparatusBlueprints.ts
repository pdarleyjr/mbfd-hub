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
const sideOutline = 'M24 173V106L49 69H185V59H561V175H517M458 175H162M103 175H24Z';
const sideDetails = ['M49 76H98V113H32', 'M186 68V169', 'M209 74V164', 'M29 181H562', 'M107 72V121H177V72', 'M226 65H554'];
const side = (id: string, label: string, prefix: string): BlueprintView => ({
  id, label, outline: sideOutline, details: sideDetails, mirror: id === 'right',
  wheels: [{ x: 132, y: 174 }, { x: 487, y: 174 }],
  zones: [zone(`comp_${prefix}1`, `${prefix.toUpperCase()}1`, 99, 76, 81, 80), zone(`comp_${prefix}2`, `${prefix.toUpperCase()}2`, 217, 77, 112, 90), zone(`comp_${prefix}3`, `${prefix.toUpperCase()}3`, 339, 77, 112, 75), zone(`comp_${prefix}4`, `${prefix.toUpperCase()}4`, 461, 77, 93, 75), ...(id === 'left' ? [zone('front_bumper', 'Bumper', 20, 144, 73, 54)] : [])],
});

// Diagram-relative geometry transcribed from the labeled drawings on page 1.
// Equipment and quantities remain exclusively in the server-issued checklist.
export const engine2Puc: BlueprintProfile = {
  id: 'engine2-puc', label: 'PUC · Engine 2', source: 'E2 PUC CHECKOUT SHEET.pdf · page 1',
  views: [
    side('left', 'Left side', 'l'),
    side('right', 'Right side', 'r'),
    { id: 'top', label: 'Top', outline: 'M29 50H560V191H29L18 175V66Z', details: ['M125 50V191', 'M145 50V191', 'M150 115H552'], zones: [zone('top_comp_1', 'Top 1', 160, 58, 170, 57), zone('top_comp_2', 'Top 2', 343, 58, 205, 57), zone('top_comp_3', 'Top 3', 160, 126, 170, 57), zone('top_comp_4', 'Top 4', 343, 126, 205, 57)] },
    { id: 'rear', label: 'Rear', outline: 'M131 31H470V198H131Z', details: ['M143 38H458', 'M129 204H472', 'M140 181H191', 'M410 181H462'], zones: [zone('rear_1', 'Rear 1', 140, 45, 86, 62), zone('rear_2', 'Rear 2', 140, 118, 86, 62), zone('rear_3', 'Rear 3', 376, 45, 86, 62), zone('rear_4', 'Rear 4', 376, 118, 86, 62), zone('rear_5', 'Rear 5', 241, 113, 120, 67), zone('tailboard', 'Tailboard', 140, 184, 322, 44)] },
    { id: 'cab', label: 'Cab', outline: 'M95 42H507V193H95L80 175V59Z', details: ['M107 51H145V183H107', 'M151 114H493'], zones: [zone('front_cab', 'Front cab', 161, 52, 328, 61), zone('rear_cab', 'Rear cab', 161, 123, 328, 60)] },
  ],
};

export const enginePuc: BlueprintProfile = {
  id: 'engine-puc', label: 'PUC · Engines 1, 3 and 4', source: 'E1 / E3 / E4 PUC CHECKOUT SHEET.pdf · page 1',
  views: [
    side('left', 'Side A', 'a'),
    side('right', 'Side B', 'b'),
    ...engine2Puc.views.filter(view => ['top', 'rear', 'cab'].includes(view.id)),
  ],
};

const ladderOutline = 'M24 176V106L50 78H187V69H565V176H466M335 176H151M85 176H24Z';
const ladderDetails = ['M26 60H557V70H26Z', 'M52 84H99V121H34', 'M107 84H173V127H107Z', 'M187 76V169', 'M30 183H566', 'M45 62L66 69L87 62L108 69L129 62L150 69L171 62L192 69L213 62L234 69L255 62L276 69L297 62L318 69L339 62L360 69L381 62L402 69L423 62L444 69L465 62L486 69L507 62L528 69'];
const ladderWheels = [{ x: 118, y: 176 }, { x: 366, y: 176 }, { x: 425, y: 176 }];
const ladderCab: BlueprintView = {
  id: 'cab', label: 'Cab', outline: 'M90 42H511V193H90L74 176V60Z', details: ['M105 52H142V182H105', 'M153 115H495'],
  zones: [zone('front_cab', 'Front cab', 163, 53, 330, 60), zone('rear_cab', 'Rear cab', 163, 124, 330, 59)],
};
const aerialView = (bucketId: string, ladderId: string): BlueprintView => ({
  id: 'aerial', label: 'Aerial', outline: 'M22 76H123V159H22ZM124 98H566V138H124Z', details: ['M135 105H557', 'M135 132H557'],
  zones: [zone(bucketId, 'Bucket', 28, 83, 88, 68), zone(ladderId, 'Ladder', 154, 87, 387, 62)],
});

// Side letters and separate compartments follow the paper's A/B drawings.
export const ladder1: BlueprintProfile = {
  id: 'ladder1', label: 'Ladder 1', source: 'L1 CHECKOUT SHEET.pdf · page 1 (Mar 2016)',
  views: [
    { id: 'left', label: 'Side A', outline: ladderOutline, details: ladderDetails, wheels: ladderWheels, zones: [zone('a_comp_1', 'A1', 192, 115, 64, 58), zone('a_comp_2', 'A2', 261, 85, 25, 88), zone('a_comp_3', 'A3', 292, 96, 43, 77), zone('a_comp_4', 'A4', 341, 97, 67, 40), zone('a_comp_5_6', 'A5 + A6', 413, 107, 115, 66), zone('front_bumper', 'Bumper', 20, 139, 60, 57)] },
    { id: 'right', label: 'Side B', outline: ladderOutline, details: ladderDetails, wheels: ladderWheels, mirror: true, zones: [zone('b_comp_1', 'B1', 192, 115, 64, 58), zone('b_comp_2', 'B2', 261, 85, 25, 88), zone('b_comp_3', 'B3', 292, 96, 43, 77), zone('b_comp_4', 'B4', 341, 97, 62, 40), zone('b_comp_5', 'B5', 408, 111, 36, 34), zone('b_comp_6', 'B6', 449, 107, 79, 66)] },
    { id: 'rear', label: 'Rear', outline: 'M132 34H468V194H132Z', details: ['M146 46H453', 'M123 204H477'], zones: [zone('rear_1', 'Rear 1', 146, 60, 307, 90), zone('tailboard', 'Tailboard', 146, 157, 307, 41)] },
    { id: 'top', label: 'Top storage', outline: 'M32 40H563V194H32Z', details: ['M130 40V194', 'M145 110H551V123H145'], zones: [zone('top_comp_driver', 'Driver top', 154, 48, 393, 54), zone('top_comp_officer', 'Officer top', 154, 135, 393, 51)] },
    aerialView('bucket', 'ladder'),
    ladderCab,
  ],
};

// L3 B1-B5 and the labeled Stokes box are separate from the unlocated officer-side panel list.
export const ladder3: BlueprintProfile = {
  id: 'ladder3', label: 'Ladder 3', source: 'L3 CHECKOUT SHEET.pdf · page 1 (revised 09/02/18)',
  views: [
    { id: 'left', label: 'Side A', outline: ladderOutline, details: ladderDetails, wheels: ladderWheels, zones: [zone('a_comp_1', 'A1', 108, 89, 43, 76), zone('a_comp_2', 'A2', 262, 84, 35, 82), zone('a_comp_3', 'A3', 302, 103, 34, 68), zone('a_comp_4', 'A4', 341, 91, 71, 42), zone('a_comp_5', 'A5', 417, 106, 36, 32), zone('a_comp_6', 'A6', 458, 115, 61, 58), zone('front_bumper', 'Bumper', 20, 139, 60, 57)] },
    { id: 'right', label: 'Side B', outline: ladderOutline, details: ladderDetails, wheels: ladderWheels, mirror: true, zones: [zone('b_comp_1', 'B1', 258, 79, 31, 92), zone('b_comp_2', 'B2', 294, 104, 37, 67), zone('b_comp_3', 'B3', 336, 79, 70, 48), zone('b_comp_4', 'B4', 411, 97, 38, 34), zone('b_comp_5', 'B5', 454, 106, 61, 66), zone('stokes_comp', 'Stokes', 250, 19, 121, 38)] },
    { id: 'rear', label: 'Rear', outline: 'M132 34H468V194H132Z', details: ['M146 46H453', 'M123 204H477'], zones: [zone('rear_comp', 'Rear ladders', 146, 60, 307, 90), zone('tailboard', 'Tailboard', 146, 157, 307, 41)] },
    { id: 'top', label: 'Top storage', outline: 'M32 40H563V194H32Z', details: ['M130 40V194', 'M151 109H547V124H151'], zones: [zone('top_comp', 'Top storage', 160, 48, 378, 138)] },
    aerialView('aerial_bucket', 'aerial_ladder'),
    ladderCab,
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
  fireboat6,
};

export function resolveBlueprint(checklistType: string | null | undefined): BlueprintProfile | null {
  // The server-issued checklist type is authoritative. Profiles only provide
  // schematic zones; the issued checklist remains the inventory authority.
  return checklistType ? profilesByChecklistType[checklistType] ?? null : null;
}

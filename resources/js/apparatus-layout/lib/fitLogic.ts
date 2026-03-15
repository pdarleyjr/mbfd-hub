import type {
  Compartment,
  Equipment,
  EquipmentPlacement,
  FitClassification,
  PlacementPosition,
} from '../types';

// Tolerance in inches — "tight" when within this margin
const TIGHT_MARGIN = 0.5;

/**
 * Bounding box in compartment-local inches.
 */
interface BBox {
  x: number;
  y: number;
  w: number;
  h: number;
}

/**
 * Convert a placement position (canvas pixels) back to inches within the
 * compartment, accounting for rotation.
 */
function placementToInchBBox(
  equipment: Equipment,
  position: PlacementPosition,
  scaleFactor: number,
): BBox {
  const rot = position.rotation % 360;
  const isRotated = rot === 90 || rot === 270;
  const w = isRotated ? equipment.dimensions.width : equipment.dimensions.length;
  const h = isRotated ? equipment.dimensions.length : equipment.dimensions.width;

  // Position is in pixels relative to compartment origin — convert to inches
  const xInches = position.x / scaleFactor;
  const yInches = position.y / scaleFactor;

  return {
    x: xInches - w / 2,
    y: yInches - h / 2,
    w,
    h,
  };
}

/**
 * Check whether two bounding boxes overlap.
 */
function boxesOverlap(a: BBox, b: BBox): boolean {
  return !(a.x + a.w <= b.x || b.x + b.w <= a.x || a.y + a.h <= b.y || b.y + b.h <= a.y);
}

/**
 * Classify the fit of a single equipment item within its compartment,
 * considering other already-placed items for collision.
 *
 * @param scaleFactor - pixels-per-inch used on the canvas (SCALE_FACTOR constant)
 */
export function classifyFit(
  equipment: Equipment,
  placement: EquipmentPlacement,
  compartment: Compartment,
  allPlacements: EquipmentPlacement[],
  equipmentCatalog: Equipment[],
  scaleFactor: number = 8,
): FitClassification {
  const bbox = placementToInchBBox(equipment, placement.position, scaleFactor);

  // 1. Boundary check — does the item fit within compartment dimensions?
  const cw = compartment.dimensions.width;
  const ch = compartment.dimensions.height;
  const cd = compartment.dimensions.depth;

  // Check 2D footprint (width × height of compartment face)
  if (bbox.x < -TIGHT_MARGIN || bbox.y < -TIGHT_MARGIN) return 'fail';
  if (bbox.x + bbox.w > cw + TIGHT_MARGIN || bbox.y + bbox.h > ch + TIGHT_MARGIN) return 'fail';

  // Check depth
  if (equipment.dimensions.height > cd + TIGHT_MARGIN) return 'fail';

  // 2. Collision check with other items in the same compartment + same shelf
  const siblings = allPlacements.filter(
    (p) =>
      p.id !== placement.id &&
      p.compartmentId === placement.compartmentId &&
      p.shelfIndex === placement.shelfIndex,
  );

  for (const sibling of siblings) {
    const sibEquip = equipmentCatalog.find((e) => e.id === sibling.equipmentId);
    if (!sibEquip) continue;
    const sibBBox = placementToInchBBox(sibEquip, sibling.position, scaleFactor);
    if (boxesOverlap(bbox, sibBBox)) return 'fail';
  }

  // 3. Tight vs. Fit
  const marginX = cw - (bbox.x + bbox.w);
  const marginY = ch - (bbox.y + bbox.h);
  const minMargin = Math.min(bbox.x, bbox.y, marginX, marginY);

  if (minMargin < TIGHT_MARGIN) return 'tight';

  return 'fit';
}

/**
 * Re-classify all placements for a given compartment.
 */
export function reclassifyCompartment(
  compartmentId: string,
  compartments: Compartment[],
  placements: EquipmentPlacement[],
  equipmentCatalog: Equipment[],
  scaleFactor: number = 8,
): Map<string, FitClassification> {
  const compartment = compartments.find((c) => c.id === compartmentId);
  if (!compartment) return new Map();

  const result = new Map<string, FitClassification>();
  const compPlacements = placements.filter((p) => p.compartmentId === compartmentId);

  for (const p of compPlacements) {
    const equip = equipmentCatalog.find((e) => e.id === p.equipmentId);
    if (!equip) {
      result.set(p.id, 'unknown');
      continue;
    }
    result.set(p.id, classifyFit(equip, p, compartment, placements, equipmentCatalog, scaleFactor));
  }

  return result;
}

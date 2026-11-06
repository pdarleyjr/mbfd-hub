/**
 * Station-specific form links. Pure data so `node --test` can check which station gets which link
 * (tests/Node/station-fire-boat-6-link.test.mjs). Always keyed by the station NUMBER (6), never by a database id.
 */

export const FIRE_BOAT_6_STATION_NUMBER = 6;
export const FIRE_BOAT_6_FORM_LABEL = 'Fire Boat 6 Medical Inventory Form';
/** Another app than /daily (the Employee portal), so it is a plain `href`: a full-page, same-origin navigation. */
export const FIRE_BOAT_6_FORM_HREF = '/employee/forms?form=fire-boat-6-medical-inventory';

export interface StationFormLink { label: string; href: string }

/** The Fire Boat 6 form link for Station 6's page, and nothing for any other station. */
export function fireBoat6FormLink(stationNumber: number): StationFormLink | null {
  return stationNumber === FIRE_BOAT_6_STATION_NUMBER ? { label: FIRE_BOAT_6_FORM_LABEL, href: FIRE_BOAT_6_FORM_HREF } : null;
}

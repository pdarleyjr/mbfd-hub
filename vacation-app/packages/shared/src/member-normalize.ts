/**
 * Helpers for normalizing Telestaff-style member fields into the canonical
 * shapes our schema expects. Kept in `shared` so the worker (commit-import),
 * the API, and one-shot scripts (bootstrap loader) all agree on the parse.
 */

/**
 * Split a single Telestaff `Name` column ("LASTNAME, FIRSTNAME" — sometimes
 * with multi-word lasts or suffixes like "FLORIO JR") into `firstName` and
 * `lastName`. Returns trimmed Title Case strings.
 *
 *   "ALMODOVAR, MARCO"       -> { lastName: 'Almodovar', firstName: 'Marco' }
 *   "FLORIO JR, JOSEPH"      -> { lastName: 'Florio Jr', firstName: 'Joseph' }
 *   "D'ALESSANDRO, NICOLAS"  -> { lastName: "D'Alessandro", firstName: 'Nicolas' }
 *
 * If the input doesn't contain a comma we treat the whole string as the
 * lastName so we never lose data.
 */
export function splitTelestaffName(raw: string): {
  firstName: string;
  lastName: string;
} {
  const s = String(raw ?? '').trim();
  if (!s) return { firstName: '', lastName: '' };
  const comma = s.indexOf(',');
  if (comma < 0) {
    return { firstName: '', lastName: titleCase(s) };
  }
  const last = s.slice(0, comma).trim();
  const first = s.slice(comma + 1).trim();
  return { firstName: titleCase(first), lastName: titleCase(last) };
}

function titleCase(s: string): string {
  return s
    .toLowerCase()
    .split(/(\s|-)/)
    .map((token) => {
      if (token === ' ' || token === '-' || token === '') return token;
      // Honor apostrophes (D'Alessandro), keep them lowercased + cap the
      // next char too.
      return token
        .split("'")
        .map((part, i) => (i === 0 ? cap(part) : cap(part)))
        .join("'");
    })
    .join('');
}

function cap(s: string): string {
  if (!s) return s;
  return s[0]!.toUpperCase() + s.slice(1);
}

/**
 * Map a Telestaff `Position Rank` label to the short rank code our schema
 * stores. Unknown labels fall back to an uppercased slug of the label so we
 * preserve the source value rather than collapsing everything to one bucket.
 *
 * Reasoning: V1 schema seeds {DC, CAPT, LT, FF, PROB}. Telestaff has a much
 * wider taxonomy (combat + civilian). We map the combat ranks to the seeded
 * codes and let everything else round-trip as its own code so the rank table
 * grows organically without losing fidelity.
 */
export function normalizeRankCode(label: string): string {
  const s = String(label ?? '').trim();
  if (!s) return '';
  const norm = s.toLowerCase().replace(/^zzz/, '');
  switch (norm) {
    case 'firefighter':
      return 'FF';
    case 'firefighter de':
    case 'firefighter, de':
      return 'FF-DE';
    case 'lieutenant':
    case 'combat lieutenant':
      return 'LT';
    case 'captain':
      return 'CAPT';
    case 'division chief':
      return 'DC';
    case 'deputy fire chief':
      return 'DDC';
    case 'fire chief':
      return 'CHIEF';
    case 'mechanic':
      return 'MECH';
    case 'clerk typist':
      return 'CLERK';
    case 'admin aide i':
      return 'AIDE1';
    case 'office associate iii':
      return 'OA3';
    case 'office associate iv':
      return 'OA4';
    case 'office associate v':
      return 'OA5';
    case 'plans analyst':
      return 'PLAN';
    case 'chief plans analyst':
      return 'CPLAN';
    case 'fire inspector civilian':
      return 'INSP';
    case 'financial analyst i':
      return 'FIN1';
    case 'public safety management budget analyst':
      return 'BUDG';
    case 'business intelligence engineer':
      return 'BIE';
    case 'radio/bda systems administrator':
      return 'RADIO';
    case 'community resource coordinator':
      return 'CRC';
    case 'administrative services manager':
      return 'ASM';
    case 'fleet operations supervisor':
      return 'FLEET';
    default:
      return slug(s);
  }
}

function slug(s: string): string {
  return s
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+/, '')
    .replace(/_+$/, '')
    .slice(0, 32);
}

/**
 * Friendly label kept alongside the rank code (e.g. so the board can show
 * "Firefighter DE" not "FF-DE"). For combat ranks we use the canonical title;
 * civilian and unknown ranks pass through with title-cased label.
 */
export function rankLabelFor(rawLabel: string, code: string): string {
  const map: Record<string, string> = {
    FF: 'Firefighter',
    'FF-DE': 'Firefighter DE',
    LT: 'Lieutenant',
    CAPT: 'Captain',
    DC: 'Division Chief',
    DDC: 'Deputy Fire Chief',
    CHIEF: 'Fire Chief',
  };
  return map[code] ?? titleCase(String(rawLabel ?? ''));
}

/**
 * Whether a rank code represents a combat (shift-bidding) position. Used by
 * the board to optionally filter civilians out of the staffing view.
 *
 * Currently unused by V1 (the user opted to show everyone on the board) but
 * kept here for Phase 2 features that need this distinction.
 */
export function isCombatRank(code: string): boolean {
  return ['FF', 'FF-DE', 'LT', 'CAPT', 'DC', 'DDC', 'CHIEF'].includes(code);
}

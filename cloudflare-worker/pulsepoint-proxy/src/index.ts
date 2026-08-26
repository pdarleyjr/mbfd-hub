/**
 * PulsePoint Proxy — Cloudflare Worker
 *
 * Fetches live MBFD incident data from PulsePoint's v1 API for agency X1012,
 * decrypts the AES-256-CBC response using native Web Crypto API + a pure-JS
 * MD5 implementation (needed for EVP_BytesToKey key derivation), normalises
 * field names, and returns clean JSON.
 *
 * Deliberately avoids `nodejs_compat` — relies only on standard Web APIs
 * available natively in every CF Workers runtime.
 */

// ─── Types ────────────────────────────────────────────────────────────────────

interface Env {
  ALLOWED_ORIGIN: string;
  PULSEPOINT_AGENCY: string;
  CACHE_TTL: string;
  /** CF Worker secret: set via `wrangler secret put PULSEPOINT_HASH_PASSWORD` */
  PULSEPOINT_HASH_PASSWORD?: string;
  /** Optional: set to "development" to allow localhost CORS origins */
  ENVIRONMENT?: string;
}

interface EncryptedPayload {
  ct: string; // base64 ciphertext
  iv: string; // hex IV
  s: string;  // hex salt
}

interface RawUnit {
  UnitID: string;
  PulsePointDispatchStatus: string;
  UnitClearedDateTime?: string;
}

interface RawIncident {
  ID: string;
  AgencyID?: string;
  PulsePointIncidentCallType: string;
  Latitude?: string;
  Longitude?: string;
  FullDisplayAddress: string;
  CallReceivedDateTime: string;
  ClosedDateTime?: string;
  Unit?: RawUnit[];
}

interface RawDecrypted {
  incidents: {
    active?: RawIncident[];
    recent?: RawIncident[];
  };
}

export interface NormalisedUnit {
  id: string;
  status: string;
  clearedAt: string | null;
}

export interface NormalisedIncident {
  id: string;
  callType: string;
  callTypeCode: string;
  address: string;
  receivedAt: string;
  closedAt: string | null;
  units: NormalisedUnit[];
  lat: number | null;
  lng: number | null;
}

export interface IncidentResponse {
  active: NormalisedIncident[];
  recent: NormalisedIncident[];
  fetchedAt: string;
  agency: string;
}

// ─── Pure-JS MD5 (RFC 1321) ───────────────────────────────────────────────────
// Required for EVP_BytesToKey key derivation — Web Crypto API does not expose MD5.

function md5(input: Uint8Array): Uint8Array {
  // Pre-computed T table: T[i] = floor(abs(sin(i+1)) * 2^32)
  const T: number[] = [];
  for (let i = 0; i < 64; i++) T.push((Math.abs(Math.sin(i + 1)) * 0x100000000) >>> 0);

  // Padding
  const msgLen = input.length;
  const bitLen = msgLen * 8;
  const padLen = msgLen % 64 < 56 ? 56 - (msgLen % 64) : 120 - (msgLen % 64);
  const buf = new Uint8Array(msgLen + padLen + 8);
  buf.set(input);
  buf[msgLen] = 0x80;
  // Append bit length as 64-bit LE
  const dv = new DataView(buf.buffer);
  dv.setUint32(msgLen + padLen, bitLen >>> 0, true);
  dv.setUint32(msgLen + padLen + 4, Math.floor(bitLen / 0x100000000), true);

  // Initial state
  let a = 0x67452301, b = 0xefcdab89, c = 0x98badcfe, d = 0x10325476;

  function rol(x: number, n: number): number { return (x << n) | (x >>> (32 - n)); }
  function add(x: number, y: number): number { return (x + y) | 0; }

  for (let off = 0; off < buf.length; off += 64) {
    const X: number[] = [];
    for (let j = 0; j < 16; j++) X.push(dv.getUint32(off + j * 4, true));

    let [A, B, C, D] = [a, b, c, d];

    // Round 1
    const r1 = [7,12,17,22,7,12,17,22,7,12,17,22,7,12,17,22];
    for (let j = 0; j < 16; j++) {
      const F = (B & C) | (~B & D);
      const g = j;
      const tmp = add(add(add(A, F), X[g]), T[j]);
      A = D; D = C; C = B; B = add(B, rol(tmp, r1[j]));
    }
    // Round 2
    const r2 = [5,9,14,20,5,9,14,20,5,9,14,20,5,9,14,20];
    for (let j = 0; j < 16; j++) {
      const F = (D & B) | (~D & C);
      const g = (5 * j + 1) % 16;
      const tmp = add(add(add(A, F), X[g]), T[j + 16]);
      A = D; D = C; C = B; B = add(B, rol(tmp, r2[j]));
    }
    // Round 3
    const r3 = [4,11,16,23,4,11,16,23,4,11,16,23,4,11,16,23];
    for (let j = 0; j < 16; j++) {
      const F = B ^ C ^ D;
      const g = (3 * j + 5) % 16;
      const tmp = add(add(add(A, F), X[g]), T[j + 32]);
      A = D; D = C; C = B; B = add(B, rol(tmp, r3[j]));
    }
    // Round 4
    const r4 = [6,10,15,21,6,10,15,21,6,10,15,21,6,10,15,21];
    for (let j = 0; j < 16; j++) {
      const F = C ^ (B | ~D);
      const g = (7 * j) % 16;
      const tmp = add(add(add(A, F), X[g]), T[j + 48]);
      A = D; D = C; C = B; B = add(B, rol(tmp, r4[j]));
    }

    a = add(a, A); b = add(b, B); c = add(c, C); d = add(d, D);
  }

  const digest = new Uint8Array(16);
  const ddv = new DataView(digest.buffer);
  ddv.setUint32(0, a, true); ddv.setUint32(4, b, true);
  ddv.setUint32(8, c, true); ddv.setUint32(12, d, true);
  return digest;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function hexToBytes(hex: string): Uint8Array {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) out[i / 2] = parseInt(hex.slice(i, i + 2), 16);
  return out;
}

function b64ToBytes(b64: string): Uint8Array {
  const binary = atob(b64);
  const out = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
  return out;
}

function concatBytes(...arrays: Uint8Array[]): Uint8Array {
  const total = arrays.reduce((n, a) => n + a.length, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const a of arrays) { out.set(a, offset); offset += a.length; }
  return out;
}

// ─── Decryption ───────────────────────────────────────────────────────────────

// Resolved from env at request time (see fetchIncidents). Declared here for
// module scope; the actual value is injected from the CF Worker secret.
let _hashPassword: Uint8Array | null = null;

/**
 * EVP_BytesToKey: derives a 32-byte AES key using iterated MD5 hashes with
 * password + salt, matching PulsePoint's encryption scheme.
 */
async function deriveAesKey(salt: Uint8Array, hashPassword: Uint8Array): Promise<CryptoKey> {
  let key = new Uint8Array(0);
  let prev = new Uint8Array(0);

  while (key.length < 32) {
    prev = md5(concatBytes(prev, hashPassword, salt));
    key = concatBytes(key, prev);
  }

  return crypto.subtle.importKey(
    "raw",
    key.slice(0, 32),
    { name: "AES-CBC" },
    false,
    ["decrypt"],
  );
}

async function decryptPayload(payload: EncryptedPayload, hashPassword: Uint8Array): Promise<RawDecrypted> {
  const cipherText = b64ToBytes(payload.ct);
  const iv = hexToBytes(payload.iv);
  const salt = hexToBytes(payload.s);

  const key = await deriveAesKey(salt, hashPassword);
  const decryptedBuf = await crypto.subtle.decrypt({ name: "AES-CBC", iv }, key, cipherText);

  let text = new TextDecoder().decode(decryptedBuf);
  // Strip wrapping quotes that PulsePoint adds around the JSON string
  if (text.startsWith('"') && text.endsWith('"')) {
    text = text.slice(1, -1);
  }
  text = text.replaceAll('\\"', '"').replaceAll("\\n", "");

  return JSON.parse(text) as RawDecrypted;
}

// ─── Constants ────────────────────────────────────────────────────────────────

const CALL_TYPES: Record<string, string> = {
  AA: "Auto Aid", MU: "Mutual Aid", ST: "Strike Team/Task Force",
  AC: "Aircraft Crash", AE: "Aircraft Emergency", AES: "Aircraft Emergency Standby",
  LZ: "Landing Zone", AED: "AED Alarm", OA: "Alarm", CMA: "Carbon Monoxide",
  FA: "Fire Alarm", MA: "Manual Alarm", SD: "Smoke Detector", TRBL: "Trouble Alarm",
  WFA: "Waterflow Alarm", FL: "Flooding", LR: "Ladder Request", LA: "Lift Assist",
  PA: "Police Assist", PS: "Public Service", SH: "Sheared Hydrant",
  EX: "Explosion", PE: "Pipeline Emergency", TE: "Transformer Explosion",
  AF: "Appliance Fire", CF: "Commercial Fire", WSF: "Confirmed Structure Fire",
  WVEG: "Confirmed Vegetation Fire", CB: "Controlled Burn/Prescribed Fire",
  ELF: "Electrical Fire", EF: "Extinguished Fire", FIRE: "Fire",
  FULL: "Full Assignment", IF: "Illegal Fire", MF: "Marine Fire",
  OF: "Outside Fire", PF: "Pole Fire", GF: "Refuse/Garbage Fire",
  RF: "Residential Fire", SF: "Structure Fire", VEG: "Vegetation Fire",
  VF: "Vehicle Fire", WCF: "Working Commercial Fire", WRF: "Working Residential Fire",
  BT: "Bomb Threat", EE: "Electrical Emergency", EM: "Emergency",
  ER: "Emergency Response", GAS: "Gas Leak", HC: "Hazardous Condition",
  HMR: "Hazmat Response", TD: "Tree Down", WE: "Water Emergency",
  AI: "Arson Investigation", HMI: "Hazmat Investigation", INV: "Investigation",
  OI: "Odor Investigation", SI: "Smoke Investigation", LO: "Lockout",
  CL: "Commercial Lockout", RL: "Residential Lockout", VL: "Vehicle Lockout",
  IFT: "Interfacility Transfer", ME: "Medical Emergency", MCI: "Multi Casualty",
  EQ: "Earthquake", FLW: "Flood Warning", CA: "Community Activity",
  FW: "Fire Watch", NO: "Notification", STBY: "Standby", TEST: "Test",
  TRNG: "Training", UNK: "Unknown", AR: "Animal Rescue", CR: "Cliff Rescue",
  CSR: "Confined Space", ELR: "Elevator Rescue", RES: "Rescue", RR: "Rope Rescue",
  TR: "Technical Rescue", TNR: "Trench Rescue", USAR: "Urban Search and Rescue",
  VS: "Vessel Sinking", WR: "Water Rescue", TCE: "Expanded Traffic Collision",
  RTE: "Railroad/Train Emergency", TC: "Traffic Collision",
  TCS: "Traffic Collision Involving Structure", TCT: "Traffic Collision Involving Train",
  WA: "Wires Arcing", WD: "Wires Down",
};

const UNIT_STATUSES: Record<string, string> = {
  DP: "Dispatched", AK: "Acknowledged", ER: "Enroute", OS: "On Scene",
  TR: "Transport", TA: "Transport Arrived", AQ: "Available in Quarters",
  AR: "Available on Radio", AE: "Available on Scene",
};

// ─── Normalisation ────────────────────────────────────────────────────────────

function normaliseUnit(raw: RawUnit): NormalisedUnit {
  return {
    id: raw.UnitID ?? "",
    status: UNIT_STATUSES[raw.PulsePointDispatchStatus] ?? raw.PulsePointDispatchStatus ?? "Unknown",
    clearedAt: raw.UnitClearedDateTime ?? null,
  };
}

function normaliseIncident(raw: RawIncident): NormalisedIncident {
  const code = raw.PulsePointIncidentCallType ?? "UNK";
  return {
    id: raw.ID,
    callTypeCode: code,
    callType: CALL_TYPES[code] ?? code,
    address: raw.FullDisplayAddress ?? "",
    receivedAt: raw.CallReceivedDateTime ?? "",
    closedAt: raw.ClosedDateTime ?? null,
    units: (raw.Unit ?? []).map(normaliseUnit),
    lat: raw.Latitude ? parseFloat(raw.Latitude) : null,
    lng: raw.Longitude ? parseFloat(raw.Longitude) : null,
  };
}

// ─── CORS ─────────────────────────────────────────────────────────────────────

function corsHeaders(origin: string, allowedOrigin: string, isDev: boolean): HeadersInit {
  const permitted = new Set([allowedOrigin, "https://mbfdhub.com"]);
  if (isDev) permitted.add("http://localhost:8000");

  const allowed = permitted.has(origin) ? origin : allowedOrigin;

  return {
    "Access-Control-Allow-Origin": allowed,
    "Access-Control-Allow-Methods": "GET, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type",
    "Access-Control-Max-Age": "86400",
  };
}

function jsonResponse(data: unknown, status: number, cors: HeadersInit, cacheTtl: number): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      ...cors,
      "Content-Type": "application/json;charset=UTF-8",
      "Cache-Control": cacheTtl > 0 ? `public, max-age=${cacheTtl}` : "no-store",
    },
  });
}

// ─── Fetch & cache ────────────────────────────────────────────────────────────

async function fetchIncidents(agency: string, cacheTtl: number, hashPassword: Uint8Array): Promise<IncidentResponse> {
  const apiUrl = `https://api.pulsepoint.org/v1/webapp?resource=incidents&agencyid=${agency}`;
  const cacheKey = new Request(`https://pulsepoint-cache/${agency}`, { method: "GET" });
  const cache = caches.default;

  const cached = await cache.match(cacheKey);
  if (cached) return cached.json() as Promise<IncidentResponse>;

  const response = await fetch(apiUrl, {
    headers: {
      "Accept": "application/json",
      "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    },
  });

  if (!response.ok) {
    throw new Error(`PulsePoint API returned ${response.status}`);
  }

  const text = await response.text();
  let encrypted: EncryptedPayload;
  try {
    encrypted = JSON.parse(text) as EncryptedPayload;
  } catch {
    throw new Error("PulsePoint returned unexpected response format");
  }

  const raw = await decryptPayload(encrypted, hashPassword);

  const result: IncidentResponse = {
    active: (raw.incidents.active ?? []).map(normaliseIncident),
    recent: (raw.incidents.recent ?? []).slice(0, 10).map(normaliseIncident),
    fetchedAt: new Date().toISOString(),
    agency,
  };

  // Store in CF edge cache
  await cache.put(
    cacheKey,
    new Response(JSON.stringify(result), {
      headers: { "Content-Type": "application/json", "Cache-Control": `public, max-age=${cacheTtl}` },
    }),
  );

  return result;
}

// ─── Handler ──────────────────────────────────────────────────────────────────

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const origin = request.headers.get("Origin") ?? "";
    const isDev = env.ENVIRONMENT === "development";
    const cors = corsHeaders(origin, env.ALLOWED_ORIGIN, isDev);
    const cacheTtl = Math.max(10, parseInt(env.CACHE_TTL, 10) || 30);

    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: cors });
    }
    if (request.method !== "GET") {
      return jsonResponse({ error: "Method not allowed" }, 405, cors, 0);
    }

    const { pathname } = new URL(request.url);

    if (pathname === "/") {
      return jsonResponse({ status: "ok", agency: env.PULSEPOINT_AGENCY }, 200, cors, 0);
    }
    if (pathname !== "/incidents") {
      return jsonResponse({ error: "Not found" }, 404, cors, 0);
    }

    // The decryption password is a Worker secret. Do not provide a source fallback:
    // a missing secret must fail closed without contacting the upstream provider.
    const rawPassword = env.PULSEPOINT_HASH_PASSWORD;
    if (!rawPassword) {
      console.error('[pulsepoint-proxy] PULSEPOINT_HASH_PASSWORD is not configured');
      return jsonResponse({ error: 'Service unavailable' }, 503, cors, 0);
    }
    const hashPassword = new TextEncoder().encode(rawPassword);

    try {
      const data = await fetchIncidents(env.PULSEPOINT_AGENCY, cacheTtl, hashPassword);
      return jsonResponse(data, 200, cors, cacheTtl);
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      console.error("[pulsepoint-proxy]", msg);
      // Do NOT return internal error detail to public callers
      return jsonResponse({ error: "Incident feed unavailable" }, 503, cors, 0);
    }
  },
};

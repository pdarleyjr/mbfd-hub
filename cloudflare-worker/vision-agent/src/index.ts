/**
 * MBFD Hub — Vision Agent Worker
 * Cloudflare Worker for AI-powered equipment image analysis.
 *
 * Primary model:  @cf/llava-hf/llava-1.5-7b-hf
 *   - input: { image: number[], prompt: string, max_tokens: number }
 *   - no Terms of Service gate
 *
 * Fallback model: @cf/meta/llama-3.2-11b-vision-instruct
 *   - requires prior `{ prompt: "agree" }` submission (done)
 *   - input: { messages: [...], max_tokens: number }
 *
 * Accepts:
 *   GET  /    health check
 *   POST /    { image: "base64string" }  OR  { images: ["base64", ...] }
 *
 * Returns:
 *   { brand, model, serial, confidence, notes, images_analyzed }
 */

interface Env {
  AI: any;
}

const CORS_HEADERS: Record<string, string> = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
  'Access-Control-Max-Age': '86400',
};

const PRIMARY_MODEL   = '@cf/llava-hf/llava-1.5-7b-hf';
const SECONDARY_MODEL = '@cf/meta/llama-3.2-11b-vision-instruct';

const EXTRACTION_PROMPT = `You are an equipment inventory assistant for the Miami Beach Fire Department. \
Analyze this equipment image and extract the following information:
1. Brand / Manufacturer name (e.g. Scott, MSA, Motorola, Honeywell, Hurst)
2. Model number or name (e.g. Air-Pak X3 Pro, G1, APX 6000)
3. Serial number — look on labels, data plates, or asset tags

Reply ONLY with a compact JSON object on a single line. No markdown, no code fences:
{"brand":"value or empty string","model":"value or empty string","serial":"value or empty string","confidence":"high|medium|low","notes":"brief observation"}`;

// ─── Helpers ────────────────────────────────────────────────────────────────

function jsonResp(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { ...CORS_HEADERS, 'Content-Type': 'application/json' },
  });
}

/** Strip data-URI prefix and decode base64 → Uint8Array. */
function base64ToBytes(b64: string): Uint8Array {
  const raw = b64.includes(',') ? b64.split(',')[1] : b64;
  const bin = atob(raw);
  const arr = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
  return arr;
}

/** Normalise base64 → proper data-URI (llama-3.2 needs this). */
function toDataUri(b64: string): string {
  if (b64.startsWith('data:')) return b64;
  return `data:image/jpeg;base64,${b64}`;
}

/** Extract structured fields from raw AI text. */
function parseAIOutput(raw: string): Record<string, string> {
  let text = raw.trim();
  // Remove markdown code fences if present
  text = text.replace(/^```(?:json)?\s*/i, '').replace(/```\s*$/i, '').trim();
  // Extract first JSON-ish block
  const s = text.indexOf('{');
  const e = text.lastIndexOf('}');
  if (s !== -1 && e > s) text = text.slice(s, e + 1);

  try {
    const obj = JSON.parse(text);
    return {
      brand:      String(obj.brand      ?? ''),
      model:      String(obj.model      ?? ''),
      serial:     String(obj.serial     ?? ''),
      confidence: String(obj.confidence ?? 'low'),
      notes:      String(obj.notes      ?? ''),
    };
  } catch {
    // Regex fallback
    return {
      brand:      (text.match(/"brand"\s*:\s*"([^"]*)"/i)      ?? [])[1] ?? '',
      model:      (text.match(/"model"\s*:\s*"([^"]*)"/i)      ?? [])[1] ?? '',
      serial:     (text.match(/"serial"\s*:\s*"([^"]*)"/i)     ?? [])[1] ?? '',
      confidence: (text.match(/"confidence"\s*:\s*"([^"]*)"/i) ?? [])[1] ?? 'low',
      notes:      `parse error — raw: ${text.slice(0, 200)}`,
    };
  }
}

// ─── Model calls ─────────────────────────────────────────────────────────────

/**
 * LLaVA 1.5 7B — image as byte array.
 * Ref: https://developers.cloudflare.com/workflows (ImageProcessingWorkflow example)
 */
async function callLlava(env: Env, b64: string): Promise<Record<string, string>> {
  const bytes = base64ToBytes(b64);
  const resp = await (env.AI as any).run(PRIMARY_MODEL, {
    image:      [...bytes],   // number[]
    prompt:     EXTRACTION_PROMPT,
    max_tokens: 512,
  });
  const raw = typeof resp === 'string'
    ? resp
    : (resp?.description ?? resp?.response ?? JSON.stringify(resp));
  return parseAIOutput(raw);
}

/**
 * Llama 3.2 11B Vision — messages array with image_url content part.
 * Note: ToS already accepted via { prompt: "agree" }.
 */
async function callLlama(env: Env, b64: string): Promise<Record<string, string>> {
  const dataUri = toDataUri(b64);
  const resp = await (env.AI as any).run(SECONDARY_MODEL, {
    messages: [
      {
        role: 'user',
        content: [
          { type: 'text',      text: EXTRACTION_PROMPT },
          { type: 'image_url', image_url: { url: dataUri } },
        ],
      },
    ],
    max_tokens: 512,
  });
  const raw = typeof resp === 'string'
    ? resp
    : (resp?.response ?? JSON.stringify(resp));
  return parseAIOutput(raw);
}

/** Attempt primary, then fallback. */
async function analyzeImage(env: Env, b64: string): Promise<Record<string, string>> {
  try {
    return await callLlava(env, b64);
  } catch (e1: any) {
    console.warn(`LLaVA failed (${e1.message}), trying Llama 3.2 Vision…`);
  }
  try {
    return await callLlama(env, b64);
  } catch (e2: any) {
    console.error(`Llama 3.2 Vision also failed: ${e2.message}`);
    return { brand: '', model: '', serial: '', confidence: 'low', notes: `Analysis failed: ${e2.message}` };
  }
}

/** Merge results from multiple photos (first non-empty wins per field). */
function mergeResults(results: Record<string, string>[]): Record<string, string> {
  if (!results.length) return { brand: '', model: '', serial: '', confidence: 'low', notes: '' };
  if (results.length === 1) return results[0];

  const score = (c: string) => c === 'high' ? 3 : c === 'medium' ? 2 : 1;
  const out: Record<string, string> = { brand: '', model: '', serial: '', confidence: 'low', notes: '' };

  for (const r of results) {
    if (!out.brand  && r.brand)  out.brand  = r.brand;
    if (!out.model  && r.model)  out.model  = r.model;
    if (!out.serial && r.serial) out.serial = r.serial;
    if (score(r.confidence) > score(out.confidence)) out.confidence = r.confidence;
  }
  out.notes = results
    .map((r, i) => r.notes ? `Photo ${i + 1}: ${r.notes}` : '')
    .filter(Boolean)
    .join(' | ');

  return out;
}

// ─── Entry point ─────────────────────────────────────────────────────────────

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: CORS_HEADERS });
    }

    // Health check
    if (request.method === 'GET') {
      return jsonResp({
        status:    'ok',
        worker:    'vision-agent',
        model:     PRIMARY_MODEL,
        fallback:  SECONDARY_MODEL,
        timestamp: new Date().toISOString(),
      });
    }

    if (request.method !== 'POST') {
      return jsonResp({ error: 'Method not allowed' }, 405);
    }

    try {
      const body = await request.json() as Record<string, unknown>;

      // Normalise single/multiple image inputs
      let images: string[] = [];
      if (typeof body.image === 'string' && body.image.length > 0) {
        images = [body.image as string];
      } else if (Array.isArray(body.images)) {
        images = (body.images as unknown[]).filter(
          (x): x is string => typeof x === 'string' && x.length > 0
        );
      }

      if (!images.length) {
        return jsonResp(
          { error: 'Provide "image" (base64 string) or "images" (array of base64 strings)' },
          400
        );
      }

      images = images.slice(0, 5); // limit to 5 images per request

      const results: Record<string, string>[] = [];
      for (const img of images) {
        results.push(await analyzeImage(env, img));
      }

      const merged = mergeResults(results);
      return jsonResp({ ...merged, images_analyzed: results.length });
    } catch (err: any) {
      console.error('Vision agent top-level error:', err);
      return jsonResp(
        { error: 'Vision processing failed: ' + (err?.message ?? String(err)) },
        500
      );
    }
  },
} satisfies ExportedHandler<Env>;

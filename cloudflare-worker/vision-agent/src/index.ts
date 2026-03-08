/**
 * MBFD Hub — Vision Agent Worker v2
 * ==================================
 * AI-powered equipment image analysis using
 * @cf/meta/llama-3.2-11b-vision-instruct
 * (best free-tier vision model on Cloudflare Workers AI)
 *
 * ToS accepted 2026-03-08 via: POST { "prompt": "agree" }
 *
 * API:
 *   GET  /              → health check
 *   POST /              { image: "base64string" }
 *                    or { images: ["b64", "b64", ...] }  (max 5)
 *
 * Response:
 *   { brand, model, serial, confidence, notes, images_analyzed, raw_text }
 */

interface Env {
  AI: any;
}

const CORS_HEADERS: Record<string, string> = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
};

/** The only model we use — best quality, free tier, ToS accepted */
const VISION_MODEL = '@cf/meta/llama-3.2-11b-vision-instruct';

/**
 * Extraction prompt — instructs the model to return ONLY valid JSON.
 * Uses short, direct phrasing for best results.
 */
const EXTRACTION_PROMPT = `Look at this equipment image from a fire station.
Extract EXACTLY these fields and respond with ONLY a JSON object:

{"brand":"manufacturer name","model":"model number or name","serial":"serial number","confidence":"high|medium|low","notes":"brief observation"}

Rules:
- brand: company or manufacturer (e.g. Scott, MSA, Motorola, Hurst, Honeywell, 3M, Bullard)
- model: model number or name (e.g. Air-Pak X3, APX 6000, Jaws HD-55)
- serial: serial number from label or data plate (starts with letters+digits)
- confidence: high if you can clearly read labels, medium if partially visible, low if guessing
- notes: one sentence about what you see (not more)
- If a field is not visible or readable, use empty string ""
- Output ONLY the JSON object, no explanation, no markdown`;

function jsonResp(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { ...CORS_HEADERS, 'Content-Type': 'application/json' },
  });
}

/** Ensure base64 has a data URI prefix (required by llama-3.2 vision). */
function ensureDataUri(b64: string): string {
  if (b64.startsWith('data:')) return b64;
  // Detect format from magic bytes if possible, default to JPEG
  return `data:image/jpeg;base64,${b64}`;
}

/** Robustly parse JSON from model output (handles markdown fences, nested text, etc.). */
function parseJSON(raw: string): Record<string, string> {
  let text = (raw || '').trim();

  // Strip markdown code fences
  text = text.replace(/^```(?:json)?\s*/i, '').replace(/```\s*$/i, '').trim();

  // Find JSON object
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start !== -1 && end > start) {
    text = text.slice(start, end + 1);
  }

  try {
    const obj = JSON.parse(text);
    return {
      brand:      String(obj.brand      ?? '').trim(),
      model:      String(obj.model      ?? '').trim(),
      serial:     String(obj.serial     ?? '').trim(),
      confidence: String(obj.confidence ?? 'low').trim(),
      notes:      String(obj.notes      ?? '').trim(),
    };
  } catch {
    // Regex fallback for when model output isn't clean JSON
    const extract = (key: string) =>
      (text.match(new RegExp(`"${key}"\\s*:\\s*"([^"]*)"`, 'i')) ?? [])[1]?.trim() ?? '';
    return {
      brand:      extract('brand'),
      model:      extract('model'),
      serial:     extract('serial'),
      confidence: extract('confidence') || 'low',
      notes:      extract('notes') || `Partial parse from: ${text.slice(0, 100)}`,
    };
  }
}

/**
 * Analyze a single image using llama-3.2-11b-vision-instruct.
 *
 * Correct format per Cloudflare docs (llama-vision-tutorial):
 *   messages: [system + user text], image: dataUri  (top-level)
 *
 * OR rich-content format:
 *   messages: [{ role: "user", content: [{ type: "text" }, { type: "image_url", image_url: { url } }] }]
 */
async function analyzeImage(env: Env, b64: string): Promise<{ parsed: Record<string, string>; rawText: string }> {
  const dataUri = ensureDataUri(b64);

  const response = await env.AI.run(VISION_MODEL, {
    messages: [
      {
        role: 'user',
        content: [
          { type: 'text', text: EXTRACTION_PROMPT },
          { type: 'image_url', image_url: { url: dataUri } },
        ],
      },
    ],
    max_tokens: 512,
  });

  // The model may return response.response as an OBJECT (when it correctly outputs JSON)
  // or as a STRING (when it outputs text we need to parse)
  if (response && typeof response === 'object' && response.response && typeof response.response === 'object') {
    // Model returned structured JSON directly — perfect!
    const obj = response.response as Record<string, unknown>;
    const rawText = JSON.stringify(obj);
    return {
      rawText,
      parsed: {
        brand:      String(obj.brand      ?? '').trim(),
        model:      String(obj.model      ?? '').trim(),
        serial:     String(obj.serial     ?? '').trim(),
        confidence: String(obj.confidence ?? 'low').trim(),
        notes:      String(obj.notes      ?? '').trim(),
      },
    };
  }

  // Extract raw text from various possible string response shapes
  let rawText = '';
  if (typeof response === 'string') {
    rawText = response;
  } else if (response && typeof response === 'object') {
    if (typeof response.response === 'string') {
      rawText = response.response;
    } else if (typeof response.description === 'string') {
      rawText = response.description;
    } else if (typeof response.content === 'string') {
      rawText = response.content;
    } else if (Array.isArray(response.choices) && response.choices[0]?.message?.content) {
      rawText = String(response.choices[0].message.content);
    } else if (Array.isArray(response.messages) && response.messages[0]?.content) {
      rawText = String(response.messages[0].content);
    } else {
      rawText = JSON.stringify(response);
    }
  }

  return { parsed: parseJSON(rawText || ''), rawText };
}

/**
 * Merge results from multiple images.
 * First non-empty value wins; upgrades confidence; appends notes.
 */
function merge(
  results: Array<{ parsed: Record<string, string>; rawText: string }>
): { parsed: Record<string, string>; rawText: string } {
  if (results.length === 0) {
    return { parsed: { brand: '', model: '', serial: '', confidence: 'low', notes: '' }, rawText: '' };
  }
  if (results.length === 1) return results[0];

  const score = (c: string) => c === 'high' ? 3 : c === 'medium' ? 2 : 1;
  const merged: Record<string, string> = { brand: '', model: '', serial: '', confidence: 'low', notes: '' };

  for (const { parsed } of results) {
    if (!merged.brand  && parsed.brand)  merged.brand  = parsed.brand;
    if (!merged.model  && parsed.model)  merged.model  = parsed.model;
    if (!merged.serial && parsed.serial) merged.serial = parsed.serial;
    if (score(parsed.confidence) > score(merged.confidence)) merged.confidence = parsed.confidence;
  }

  merged.notes = results
    .map((r, i) => r.parsed.notes ? `Photo ${i + 1}: ${r.parsed.notes}` : '')
    .filter(Boolean)
    .join(' | ');

  return { parsed: merged, rawText: results.map(r => r.rawText).join('\n---\n') };
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: CORS_HEADERS });
    }

    if (request.method === 'GET') {
      return jsonResp({
        status: 'ok',
        worker: 'vision-agent',
        model: VISION_MODEL,
        timestamp: new Date().toISOString(),
      });
    }

    if (request.method !== 'POST') {
      return jsonResp({ error: 'Method not allowed' }, 405);
    }

    try {
      const body = await request.json() as Record<string, unknown>;

      // Normalise to image array
      let images: string[] = [];
      if (typeof body.image === 'string' && body.image.length > 0) {
        images = [body.image as string];
      } else if (Array.isArray(body.images)) {
        images = (body.images as unknown[]).filter(
          (x): x is string => typeof x === 'string' && (x as string).length > 0
        );
      }

      if (!images.length) {
        return jsonResp(
          { error: 'Provide "image" (base64 string) or "images" (array of base64 strings)' },
          400
        );
      }

      images = images.slice(0, 5); // safety limit

      const results: Array<{ parsed: Record<string, string>; rawText: string }> = [];
      for (const img of images) {
        try {
          results.push(await analyzeImage(env, img));
        } catch (err: any) {
          console.error('Image analysis error:', err?.message);
          results.push({
            parsed: { brand: '', model: '', serial: '', confidence: 'low', notes: `Failed: ${err?.message ?? 'unknown error'}` },
            rawText: '',
          });
        }
      }

      const { parsed, rawText } = merge(results);

      return jsonResp({
        brand:  parsed.brand,
        model:  parsed.model,
        serial: parsed.serial,
        confidence: parsed.confidence,
        notes:  parsed.notes,
        images_analyzed: results.length,
        raw_text: rawText,  // include for debugging
      });
    } catch (err: any) {
      console.error('Worker error:', err);
      return jsonResp({ error: `Vision processing failed: ${err?.message ?? String(err)}` }, 500);
    }
  },
};

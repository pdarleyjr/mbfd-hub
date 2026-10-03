interface Env {
  AI: any;
  VECTORIZE: any;
  RATE_LIMIT_KV?: KVNamespace;
  ALLOWED_ORIGIN: string;
  AI_GATEWAY_URL?: string;
  // Local-LLM bridge (Ollama OpenAI-compatible, fronted by office-ai.mbfdhub.com)
  BRIDGE_URL?: string;     // var: e.g. https://office-ai.mbfdhub.com/v1
  BRIDGE_TOKEN?: string;   // secret: bearer for the bridge
  BRIDGE_MODEL?: string;   // var: e.g. qwen3.6:35b
  // Existing Hub-proxy secret required for /chat, /ingest and /delete.
  INGEST_SECRET?: string;  // secret: matches the Hub's CLOUDFLARE_WORKER_API_SECRET
  SOG_NAMESPACE: string;
  SOG_MANIFEST_SHA256: string;
  REFERENCE_NAMESPACE: string;
}

interface RateLimitEntry {
  count: number;
  resetAt: number;
}

interface ConversationMessage {
  role: 'user' | 'assistant';
  content: string;
}

interface SogCitation {
  id: string;
  namespace: string;
  manifest_sha256: string;
  asset_id: string;
  source: string;
  page: number;
  primary_ids: string;
  url: string;
  source_sha256?: string;
}

interface ReferenceCitation {
  id: string;
  namespace: string;
  source: string;
  chunk_index?: number;
}

interface CompletionMetadata {
  finish_reason?: 'stop' | 'length' | 'tool_calls' | 'content_filter' | 'function_call';
  prompt_tokens?: number;
  completion_tokens?: number;
}

const EMBEDDING_MODEL = '@cf/baai/bge-large-en-v1.5';
const DEFAULT_BRIDGE_MODEL = 'qwen3.6:35b';

// Page counts projected from the frozen 82a32 manifest's 2,160 verified corpus records.
const R2_PAGE_CHUNK_COUNTS: Record<string, number[]> = {"100.XX-GOV-R1":[2,2,2,2,2],"200-B7-R1":[2,2,2,1],"200-C1-R1":[2,2,2,2,2],"200-E3-R1":[2,2,2,2,2,2,2,2,2,2,2,2,1],"300-METHODS":[2,2,2,2,2,2,2,3,3,1],"400.XX-JHAT-R1":[2,2,2,3,3,2],"400.XX-K9-R1":[2,2,2],"400.XX-UAS-R1":[2,2,2,2,1],"400.XX-WTR-R1":[2,2,1],"500.01-R1":[2,2,3,3,3,2,3,2,2,3,2,2,2,2,2,3],"500.07-R1":[3,3,2,3,3,2],"500.10-R1":[2,2,3,2,1],"500.XX-CQM-R1":[2,3,2,3,3,1],"500.XX-MD-R1":[2,2,2,1],"600.04-R2":[2,2,2,2],"600.10-R1":[2,2,2,1],"600.XX-ADM-R2":[2,2,2],"900-CS":[3,1,2,2,1,2,2,1,2,2,1,2,3,1,2,2],"900-DE":[2,3,1,2,2,2,2,2,2,1,2,2,2,2,1,2,2,2,3,2,2,2,2,2,2,2,2,2,2,2],"900-DE-QA":[2,2,2,2,3,3],"900-DE-UNIT":[2,2,1,2,2,2,2,2,2,2,1,1,1,1,1,1,1,2,2,1,1,1,1,1,2,3,2,2,2],"900-INSTRUCTOR":[2,2,2,2,2,2,2,2],"900-SKILLS":[2,2,2,2,2,2,3,2,2,2,2,2,3,2],"900-TC":[2,1,2,1,2,1,2,1,2,1,2,1,2,2,2,1],"SECTION-100":[2,2,3,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,3,3,3,3,2,2,2,3,3,3,3,2,1,1,2,2,2,2,2,2,1,2,2,2,2,2,2],"SECTION-200":[3,3,3,3,4,3,1,3,2,3,2,3,3,2,2,3,4,2,2,3,3,3,3,1,2,2,4,3,2,3,3,3,3,2,1,2,2,3,2,3,2,1,3,3,3,3,2,1,2,4,4,3,2,2,2,2,2,2,2,1,3,3,3,3,2,1,3,3,3,2,2,2,3,3,3,4,3,2,2,3,3,3,1,4,3,2,2,4,2,4,4,3,4,3,3,2,3,3,3,1,3,3,1,3,3,2,3,2,2,4,2,3,4,3,3,3,2,3,3,2],"SECTION-300":[3,2,3,3,2,3,2,4,3,3,2,3,3,3,3,3,3,3,2,3,4,3,4,2,2,3,3,4,3,2,2,3,1,3,1,2,3,3,3,2,3,1,2,1,2,3,2,2,3,1,2,2,3,3,3,2,3,2,2,1,3,2,2,2,3,2,3,3,2,2,3,2,3,3,1,2,2,3,2,3,3,2,3,1,2,3,3,1,2,3,1,3,3,2,3,3,1,3,4,3,3,3,2,3,2,3,3,3,3,3,2,2,3],"SECTION-400":[3,2,3,3,3,4,2,2,3,2,2,3,2,2,3,2,2,3,2,3,3,2,2,2,2,2,3,1,3,3,2,2,4,3,2,4,2,3,2,2,2,3,3,3,2,3,3,3,2,3,2,2,3,3,3,3,2,3,3,3,3,3,3,3,1,3,3,2,2,2,4,3,2,3,2,2,2,3,2,3,1,2,3,2,2,2,2,3,2,3,3,2,3,2,2,3,1,2,2,3,3,1,3,3,2,3,3,1,3,3,2,3,3,3,3,2],"SECTION-500":[2,3,3,3,3,2,3,3,3,3,3,3,2,2,2,3,3,2,3,3,3,2,3,3,3,2,2,2,3,3,1,2,3,2,3,3,3,3,2,3,3,2,3,4,3,3,3,3,2,3,3,2,3,3,3,3,2,3,2,3,2,2,3,2,3,3,3,2,3,3,2,1,3,3,2,2],"SECTION-600":[3,3,3,3,3,3,3,2,3,3,3,3,2,2,2,2,3,3,3,3,2,3,3,3,3,2,3,3,3,3,2,2,2,2,2,2,2,2,3,2,3,3,3,1,3,3,3,3,3,3,2,3,3,3,2,3,3,2,3,3,2,3,3,3,3,3,1,3,3,3,2,3,3,2,1,1,1,2,3,1,3,3,2,3,3,3,3,3,3,3,2,3,3,1],"SECTION-800":[3,3,3,3,3,3,3,3,4,4,4,4,4,3,3,4,3,4,3,4,4,4,3,4,4,4,4,4,4,3,3,3,3,3,4,3,4,3,2,3,3,3,3,2,3,3,3,3,3,3,3,3,2,2,2,1,3,3,3,2,2,3,3,2],"SECTION-900":[3,3,3,2,3,3,3,3,2,3,3,2,3,4,4,2,2,3,2,3,3,3,3,3,2,2,3,2,3,3,3,2]};

const rateLimitStore = new Map<string, RateLimitEntry>();

function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  const limit = 15;
  const windowMs = 60000;
  const entry = rateLimitStore.get(ip);
  if (!entry || now > entry.resetAt) {
    rateLimitStore.set(ip, { count: 1, resetAt: now + windowMs });
    return true;
  }
  if (entry.count >= limit) return false;
  entry.count++;
  return true;
}

function getCorsHeaders(env: Env, request: Request): Record<string, string> {
  const origin = request.headers.get('Origin') || '';
  const allowed = env.ALLOWED_ORIGIN || 'https://www.mbfdhub.com';
  const isAllowed =
    origin === allowed ||
    origin.startsWith('http://localhost') ||
    origin.startsWith('http://127.0.0.1');
  return {
    'Access-Control-Allow-Origin': isAllowed ? origin : allowed,
    'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, x-api-secret',
    'Access-Control-Max-Age': '86400',
  };
}

const SYSTEM_PROMPT = `You are the MBFD Support Hub Assistant for the Miami Beach Fire Department's internal operations hub. You are professional, precise, and helpful.

DOCUMENT PRIORITY (when context is provided):
1. CURRENT SOG records from MBFD-COORDINATED-20261002-R2 — use these records for SOG, policy, reporting and departmental procedure questions.
2. "L1_L11_manual.pdf" — Authoritative for L1 through L11 apparatus operations, specifications, and procedures.
3. "PUC_Engine_manual.pdf" — Authoritative for PUC Engine apparatus operations, specifications, and procedures.
4. "L3_manual.pdf" — Authoritative for L3 apparatus operations, specifications, and procedures.
5. If multiple documents address the same topic, prefer current SOG records > specific apparatus manual. Apparatus manuals provide technical instructions within their stated model/configuration scope; they do not establish installed equipment or replace departmental policy.

RESPONSE RULES:
1. Answer ONLY using the provided context documents. Do NOT use outside knowledge.
2. If the answer is not in the context, say: "I don't have that information in my current documents. Please contact Support Services directly."
3. Cite the actual source document and physical page when providing information. For SOG records, include the supplied Library link and applicable current identity.
4. Be professional and precise. Answering the whole question takes priority over brevity; use bullet points and structured formatting where appropriate.
5. Answer policy/SOG questions only from CURRENT SOG records. Older documents and conversation history are not policy sources. History may clarify the question, but facts must come from the provided current context.
6. For safety-critical information, add a note to verify with the current published document.
7. For repair/deficiency reporting questions, use the current SOG reporting instructions in the supplied context. Never supply contact details or reporting rules from memory.
8. Address every part of the question using the supplied evidence. For policy/procedure questions, include every applicable source requirement for the requested conditions: steps, required record fields and signatures or endorsements, receiving role and acknowledgment contents, direct follow-up and escalation, contingency actions during an outage and reconciliation on restoration, conditional approvals, and after-hours limits. Omit procedure sections unrelated to the question.
9. Preserve each requirement's conditional scope and distinguish its responsible receiving owner; do not apply a condition-specific duty to every situation. Keep role identifiers exactly as written unless the supplied source explicitly defines them; never infer a job title or guess what an identifier means. Explain missing evidence for any part that the supplied records do not answer; do not fill gaps with apparatus instructions or history.`;

function isRetiredSogSource(source: string): boolean {
  const name = source.split(/[\\/]/).at(-1)?.toLowerCase() || '';
  return /(?:^|[^a-z])sogs?(?:[^a-z]|$)|standard[ _-]operating/.test(name)
    || name === 'extra_info_for_ai.pdf';
}

function isPolicyQuestion(message: string): boolean {
  return /\b(sogs?|polic(?:y|ies)|departmental|reporting|chain of command)\b|\b\d{3}[.-][a-z0-9-]+\b|\bDE-\d{2}\b/i.test(message)
    || /\b(report|notify|contact)\b.*\b(defect|deficiency|repair|damaged|equipment)\b|\b(defect|deficiency|repair)\b.*\b(report|notify|contact)\b/i.test(message)
    || /\b(after[ -]?hours|weekends?)\b.*\b(technicians?|call|contact|repair|service)\b|\b(technicians?|call|contact|repair|service)\b.*\b(after[ -]?hours|weekends?)\b/i.test(message)
    || (/\b(emergency response|preemption|drivers?|driving)\b/i.test(message)
      && /\b(red light|stop sign|green light)\b/i.test(message))
    || (/\b(FDC|standpipes?|tandem pumping)\b/i.test(message) && /\b(pressure|psi)\b/i.test(message)
      && /\b(alone|determine|choos(?:e|ing)|verify|verified|arrangement|trigger|automatically|setting|must|should|enough)\b/i.test(message));
}

function currentCitation(match: any, env: Env): SogCitation | null {
  const meta = match.metadata || {};
  if (match.namespace !== env.SOG_NAMESPACE || meta.manifest_sha256 !== env.SOG_MANIFEST_SHA256
    || typeof match.id !== 'string' || !match.id || typeof meta.asset_id !== 'string' || !/^[A-Z0-9][A-Z0-9_.-]*$/.test(meta.asset_id)
    || !Number.isSafeInteger(meta.page) || meta.page < 1 || typeof meta.source !== 'string'
    || typeof meta.primary_ids !== 'string'
    || meta.url !== `https://files.mbfdhub.com/current-sog/${meta.asset_id}?page=${meta.page}`) return null;
  return { id: match.id, namespace: match.namespace, manifest_sha256: meta.manifest_sha256,
    asset_id: meta.asset_id, source: meta.source, page: meta.page, primary_ids: meta.primary_ids, url: meta.url,
    ...(/^[a-f0-9]{64}$/.test(meta.source_sha256 || '') ? { source_sha256: meta.source_sha256 } : {}) };
}

async function completePolicyPages(seeds: any[], env: Env): Promise<any[] | null> {
  // Fetch every expected chunk, using the frozen counts and ingest.py's ID derivation.
  const frozen = env.SOG_MANIFEST_SHA256 === '82a32d7127e42604f77f591e39a26341844b2c7010e5f1b148d20472aaf20b6c';
  if (!frozen) return null;
  const records = new Map<string, any>();
  const load = async (pages: { asset_id: string; page: number }[]) => {
    if (pages.some(({ asset_id, page }) => !R2_PAGE_CHUNK_COUNTS[asset_id]?.[page - 1])) return false;
    const locations = await Promise.all(pages.flatMap(({ asset_id, page }) =>
      Array.from({ length: R2_PAGE_CHUNK_COUNTS[asset_id][page - 1] }, async (_, chunk) => {
      const bytes = new TextEncoder().encode(`${env.SOG_MANIFEST_SHA256}:${asset_id}:${page}:${chunk}`);
      const digest = await crypto.subtle.digest('SHA-256', bytes);
      const id = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
      return { id, asset_id, page, chunk };
    })));
    const ids = locations.map(location => location.id);
    for (let offset = 0; offset < ids.length; offset += 20) {
      const batch = ids.slice(offset, offset + 20);
      const returned = new Set<string>();
      for (const match of await env.VECTORIZE.getByIds(batch)) {
        const location = locations.find(location => location.id === match.id);
        if (batch.includes(match.id) && location && currentCitation(match, env)
          && match.metadata.asset_id === location.asset_id && match.metadata.page === location.page
          && match.metadata.chunk_index === location.chunk) {
          records.set(match.id, match);
          returned.add(match.id);
        }
      }
      if (batch.some(id => !returned.has(id))) return false;
    }
    return true;
  };
  const pages = [...new Map(seeds.map(match => [
    `${match.metadata.asset_id}:${match.metadata.page}`,
    { asset_id: match.metadata.asset_id, page: match.metadata.page },
  ])).values()];
  if (!await load(pages)) return null;
  // Both 800.P03 p34 and 800.P02 p13 explicitly invoke this current section.
  if ([...records.values()].some(match => /\b(?:follow|under)\s+800\.P01\s+V\b/i.test(match.metadata.text || ''))) {
    if (!await load([4, 5].map(page => ({ asset_id: 'SECTION-800', page })))) return null;
  }
  return [...records.values()].sort((a, b) =>
    a.metadata.asset_id.localeCompare(b.metadata.asset_id) || a.metadata.page - b.metadata.page
    || a.metadata.chunk_index - b.metadata.chunk_index);
}

function citationFooter(citations: SogCitation[], references: ReferenceCitation[]): string {
  const pages = [...new Map(citations.map(citation => [citation.url, citation])).values()];
  const current = pages.length ? '\n\nCurrent SOG sources:\n' + pages.map(citation =>
    `- ${citation.primary_ids ? `${citation.primary_ids} — ` : ''}${citation.source}, physical page ${citation.page}: ${citation.url}`).join('\n') : '';
  const technical = references.length ? '\n\nReference sources:\n' + references.map(citation =>
    `- ${citation.source}${citation.chunk_index !== undefined ? `, chunk ${citation.chunk_index}` : ''}`).join('\n') : '';
  return current + technical;
}

/** Chunk text into ~1500-char segments with 200-char overlap, breaking on
 *  sentence/paragraph boundaries where possible. Mirrors ingest-manuals.mjs. */
function chunkText(text: string, maxChars = 1500, overlap = 200): string[] {
  const chunks: string[] = [];
  let start = 0;
  while (start < text.length) {
    let end = start + maxChars;
    if (end < text.length) {
      const lastPeriod = text.lastIndexOf('.', end);
      const lastNewline = text.lastIndexOf('\n', end);
      const breakPoint = Math.max(lastPeriod, lastNewline);
      if (breakPoint > start + maxChars * 0.5) end = breakPoint + 1;
    }
    chunks.push(text.slice(start, Math.min(end, text.length)).trim());
    if (end >= text.length) break;
    start = end - overlap;
  }
  return chunks.filter((c) => c.length > 50);
}

function sanitizeId(source: string): string {
  return source.replace(/[^a-zA-Z0-9]/g, '_');
}

function completionMetadata(upstream: any): CompletionMetadata {
  const completion: CompletionMetadata = {};
  const reason = upstream?.choices?.[0]?.finish_reason;
  if (['stop', 'length', 'tool_calls', 'content_filter', 'function_call'].includes(reason)) {
    completion.finish_reason = reason;
  }
  for (const field of ['prompt_tokens', 'completion_tokens'] as const) {
    const count = upstream?.usage?.[field];
    if (typeof count === 'number' && Number.isSafeInteger(count) && count >= 0) completion[field] = count;
  }
  return completion;
}

/** Translate the bridge's OpenAI-style SSE into the CF-style SSE the landing
 *  page expects: `data: {"response":"<token>"}`. Buffers across chunk
 *  boundaries; emits a final `data: [DONE]`. */
function openaiToCfStream(upstream: ReadableStream, footer: string, citations: SogCitation[], references: ReferenceCitation[]): ReadableStream {
  const reader = upstream.getReader();
  const decoder = new TextDecoder();
  const encoder = new TextEncoder();
  let buffer = '';
  let terminal = false;
  const completion: CompletionMetadata = {};
  const emitLine = (line: string, controller: ReadableStreamDefaultController) => {
    const t = line.trim();
    if (!t.startsWith('data:') || terminal) return;
    const payload = t.slice(5).trim();
    if (payload === '[DONE]') { terminal = true; return; }
    if (!payload) return;
    try {
      const j = JSON.parse(payload);
      Object.assign(completion, completionMetadata(j));
      const tok = j.choices?.[0]?.delta?.content || '';
      if (tok) controller.enqueue(encoder.encode(`data: ${JSON.stringify({ response: tok })}\n\n`));
    } catch {
      /* ignore keep-alive / partial */
    }
  };
  return new ReadableStream({
    async pull(controller) {
      const { done, value } = await reader.read();
      if (done) {
        if (buffer) emitLine(buffer + decoder.decode(), controller);
        if (!terminal) {
          controller.error(new Error('AI backend stream ended before completion.'));
          return;
        }
        if (Object.keys(completion).length) controller.enqueue(encoder.encode(`data: ${JSON.stringify({ completion })}\n\n`));
        if (footer) controller.enqueue(encoder.encode(`data: ${JSON.stringify({ response: footer, citations, reference_citations: references })}\n\n`));
        controller.enqueue(encoder.encode('data: [DONE]\n\n'));
        controller.close();
        return;
      }
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) emitLine(line, controller);
    },
    cancel() {
      return reader.cancel();
    },
  });
}

/** Call the local-Ollama bridge (OpenAI-compatible). */
function callBridge(env: Env, messages: any[], stream: boolean): Promise<Response> {
  const base = (env.BRIDGE_URL || 'https://office-ai.mbfdhub.com/v1').replace(/\/$/, '');
  return fetch(`${base}/chat/completions`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${env.BRIDGE_TOKEN || ''}`,
    },
    body: JSON.stringify({
      model: env.BRIDGE_MODEL || DEFAULT_BRIDGE_MODEL,
      messages,
      max_tokens: 1024,
      temperature: 0.3,
      reasoning_effort: 'none', // qwen3.6 is a thinking model; keep answers direct
      stream,
    }),
  });
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const corsHeaders = getCorsHeaders(env, request);
    const json = (obj: any, status = 200) =>
      new Response(JSON.stringify(obj), { status, headers: { ...corsHeaders, 'Content-Type': 'application/json' } });

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders });
    }

    const url = new URL(request.url);

    // Health check
    if (url.pathname === '/health') {
      return json({
        status: 'ok',
        worker: 'mbfd-support-ai',
        model: env.BRIDGE_MODEL || DEFAULT_BRIDGE_MODEL,
        llm_backend: 'local-ollama-bridge',
        embeddings: EMBEDDING_MODEL,
        sog_namespace: env.SOG_NAMESPACE,
        sog_manifest_sha256: env.SOG_MANIFEST_SHA256,
        timestamp: new Date().toISOString(),
      });
    }

    // ── Ingest: chunk + embed + upsert into Vectorize (admin Knowledge Base) ──
    if (url.pathname === '/ingest' && request.method === 'POST') {
      if (!env.INGEST_SECRET || request.headers.get('x-api-secret') !== env.INGEST_SECRET) {
        return json({ error: 'Unauthorized' }, 401);
      }
      try {
        const body: any = await request.json();
        const source = (body.source || '').toString().trim();
        const text = (body.text || '').toString();
        if (!source || !text.trim()) return json({ error: 'source and text are required' }, 400);
        if (isRetiredSogSource(source) || /(?:^|[\\/])driver_manual(?:\.pdf)?$/i.test(source)) {
          return json({ error: 'SOG documents are maintained in the Policy Library.' }, 422);
        }

        const chunks = chunkText(text, 1500, 200);
        if (chunks.length === 0) return json({ error: 'No extractable text (after chunking)' }, 422);

        const sanitized = sanitizeId(source);
        const ids: string[] = [];
        const BATCH = 10;
        for (let i = 0; i < chunks.length; i += BATCH) {
          const batch = chunks.slice(i, i + BATCH);
          const texts = batch.map((c) => c.slice(0, 2000));
          const emb = await env.AI.run(EMBEDDING_MODEL, { text: texts });
          const vectors = batch.map((c, j) => {
            const id = `${sanitized}-chunk-${i + j}`;
            ids.push(id);
            return {
              id,
              namespace: env.REFERENCE_NAMESPACE,
              values: emb.data[j],
              metadata: { text: c.slice(0, 2000), source, chunk_index: i + j },
            };
          });
          await env.VECTORIZE.upsert(vectors);
        }
        return json({ success: true, source, chunks: ids.length, ids });
      } catch (e: any) {
        console.error('Ingest error:', e);
        return json({ error: 'Ingest failed' }, 500);
      }
    }

    // ── Delete: remove a document's vectors from Vectorize by id ──
    if (url.pathname === '/delete' && request.method === 'POST') {
      if (!env.INGEST_SECRET || request.headers.get('x-api-secret') !== env.INGEST_SECRET) {
        return json({ error: 'Unauthorized' }, 401);
      }
      try {
        const body: any = await request.json();
        const ids: string[] = Array.isArray(body.ids) ? body.ids : [];
        if (ids.length === 0) return json({ error: 'ids[] required' }, 400);
        await env.VECTORIZE.deleteByIds(ids);
        return json({ success: true, deleted: ids.length });
      } catch (e: any) {
        console.error('Delete error:', e);
        return json({ error: 'Delete failed' }, 500);
      }
    }

    // ── RAG Chat (landing page) — Vectorize retrieval + LOCAL qwen3.6 answer ──
    if (url.pathname === '/chat' && request.method === 'POST') {
      if (!env.INGEST_SECRET || request.headers.get('x-api-secret') !== env.INGEST_SECRET) {
        return json({ error: 'Unauthorized' }, 401);
      }
      if (!env.SOG_NAMESPACE || !env.REFERENCE_NAMESPACE || !/^[a-f0-9]{64}$/.test(env.SOG_MANIFEST_SHA256 || '')) {
        return json({ error: 'Current SOG knowledge is not configured.' }, 503);
      }
      const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
      if (!checkRateLimit(clientIp)) {
        return json({ error: 'Rate limit exceeded. Please wait a moment before sending another message.' }, 429);
      }

      try {
        const body: any = await request.json();
        const userMessage = body.message?.trim();
        const conversationHistory: ConversationMessage[] = body.history || [];
        const enableStreaming = body.stream === true;

        if (!userMessage) return json({ error: 'Message is required' }, 400);
        if (userMessage.length > 2000) return json({ error: 'Message too long. Please limit to 2000 characters.' }, 400);

        // Step 1: embed the query (Workers AI — must match the index's model)
        const embeddingResponse = await env.AI.run(EMBEDDING_MODEL, { text: [userMessage] });
        const queryVector = embeddingResponse.data[0];

        // Keep current SOG retrieval separate from apparatus/admin references.
        const [sogResults, referenceResults] = await Promise.all([
          env.VECTORIZE.query(queryVector, { namespace: env.SOG_NAMESPACE, topK: 6, returnMetadata: 'all' }),
          env.VECTORIZE.query(queryVector, { namespace: env.REFERENCE_NAMESPACE, topK: 6, returnMetadata: 'all' }),
        ]);
        const policyQuestion = isPolicyQuestion(userMessage);
        let currentSogs = (sogResults.matches || []).filter((match: any) =>
          (match.score || 0) >= 0.2 && currentCitation(match, env)).slice(0, 6);
        const references = (referenceResults.matches || []).filter((match: any) =>
          (match.score || 0) >= 0.2 && match.namespace === env.REFERENCE_NAMESPACE
          && !isRetiredSogSource((match.metadata?.source || '').toString()));
        const hasCurrentSog = currentSogs.length > 0;
        const unavailable = () => {
          const response = "I don't have that information in my current documents. Please contact Support Services directly.";
          if (!enableStreaming) return json({ response, sources: [], citations: [], reference_citations: [], model: env.BRIDGE_MODEL || DEFAULT_BRIDGE_MODEL });
          return new Response(`data: ${JSON.stringify({response})}\n\ndata: [DONE]\n\n`, {
            headers: {...corsHeaders, 'Content-Type':'text/event-stream', 'Cache-Control':'no-cache', 'X-Sources':'[]'},
          });
        };
        if (!hasCurrentSog && policyQuestion) return unavailable();
        if (policyQuestion) {
          const completed = await completePolicyPages(currentSogs, env);
          if (!completed) return unavailable();
          currentSogs = completed;
        }
        const matches = policyQuestion ? currentSogs : [...currentSogs, ...references];

        // Step 3: build context + sources
        let context = '';
        const sources: string[] = [];
        const citations: SogCitation[] = [];
        const referenceCitations: ReferenceCitation[] = [];
        if (matches.length > 0) {
          for (const match of matches) {
            const meta = match.metadata || {};
            const text = meta.text || '';
            const source = meta.source || 'Unknown';
            const page = meta.page ? ` (Page ${meta.page})` : '';
            const chunk = meta.chunk_index !== undefined ? ` [Chunk ${meta.chunk_index}]` : '';
            const current = match.namespace === env.SOG_NAMESPACE ? 'CURRENT SOG' : 'APPARATUS / REFERENCE';
            const link = current === 'CURRENT SOG' ? `\nLibrary: ${meta.url || ''}\nIdentities: ${meta.primary_ids || ''}` : '';
            context += `\n---\n${current}\nSource: ${source}${page}${chunk}${link}\n${text}\n`;
            if (!sources.includes(source)) sources.push(source);
            const citation = currentCitation(match, env);
            if (citation) citations.push(citation);
            else if (typeof match.id === 'string' && typeof meta.source === 'string') {
              referenceCitations.push({ id: match.id, namespace: match.namespace, source: meta.source,
                ...(Number.isSafeInteger(meta.chunk_index) && meta.chunk_index >= 0 ? { chunk_index: meta.chunk_index } : {}) });
            }
          }
        }
        if (!context) {
          return unavailable();
        }
        const footer = citationFooter(citations, referenceCitations);

        // Step 4: messages with recent history
        const recentHistory = conversationHistory.slice(-6).filter((message) =>
          (!policyQuestion && hasCurrentSog) || message.role === 'user');
        const messages: any[] = [
          { role: 'system', content: SYSTEM_PROMPT },
          ...recentHistory.map((m) => ({ role: m.role, content: m.content })),
          { role: 'user', content: `CONTEXT DOCUMENTS:\n${context}\n\nUSER QUESTION: ${userMessage}` },
        ];

        // Step 5: generate with LOCAL qwen3.6 via the bridge
        if (enableStreaming) {
          const bridgeResp = await callBridge(env, messages, true);
          if (!bridgeResp.ok || !bridgeResp.body) {
            const detail = await bridgeResp.text().catch(() => '');
            console.error('Bridge stream error', bridgeResp.status, detail);
            return json({ error: 'AI backend unavailable.' }, 502);
          }
          return new Response(openaiToCfStream(bridgeResp.body, footer, citations, referenceCitations), {
            headers: {
              ...corsHeaders,
              'Content-Type': 'text/event-stream',
              'Cache-Control': 'no-cache',
              Connection: 'keep-alive',
              'X-Sources': JSON.stringify(sources),
            },
          });
        }

        const bridgeResp = await callBridge(env, messages, false);
        if (!bridgeResp.ok) {
          const detail = await bridgeResp.text().catch(() => '');
          console.error('Bridge error', bridgeResp.status, detail);
          return json({ error: 'AI backend unavailable.' }, 502);
        }
        const aiJson: any = await bridgeResp.json();
        const answer = aiJson.choices?.[0]?.message?.content || '';
        const completion = completionMetadata(aiJson);
        return json({ response: answer + footer, sources, citations, reference_citations: referenceCitations,
          model: env.BRIDGE_MODEL || DEFAULT_BRIDGE_MODEL,
          ...(Object.keys(completion).length ? { completion } : {}) });
      } catch (error: any) {
        console.error('Chat error:', error);
        return json({ error: 'An error occurred processing your request. Please try again.' }, 500);
      }
    }

    return json({ error: 'Not found' }, 404);
  },
};

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

const EMBEDDING_MODEL = '@cf/baai/bge-large-en-v1.5';
const DEFAULT_BRIDGE_MODEL = 'qwen3.6:35b';

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
4. Be concise, professional, and precise. Use bullet points and structured formatting where appropriate.
5. Answer policy/SOG questions only from CURRENT SOG records. Older documents and conversation history are not policy sources. History may clarify the question, but facts must come from the provided current context.
6. For safety-critical information, add a note to verify with the current published document.
7. For repair/deficiency reporting questions, use the current SOG reporting instructions in the supplied context. Never supply contact details or reporting rules from memory.`;

function isRetiredSogSource(source: string): boolean {
  const name = source.split(/[\\/]/).at(-1)?.toLowerCase() || '';
  return /(?:^|[^a-z])sogs?(?:[^a-z]|$)|standard[ _-]operating/.test(name)
    || name === 'extra_info_for_ai.pdf';
}

function isPolicyQuestion(message: string): boolean {
  return /\b(sogs?|polic(?:y|ies)|departmental|reporting|chain of command)\b|\b\d{3}[.-][a-z0-9-]+\b|\bDE-\d{2}\b/i.test(message)
    || /\b(report|notify|contact)\b.*\b(defect|deficiency|repair|damaged|equipment)\b|\b(defect|deficiency|repair)\b.*\b(report|notify|contact)\b/i.test(message)
    || /\b(after[ -]?hours|weekends?)\b.*\b(technicians?|call|contact|repair|service)\b|\b(technicians?|call|contact|repair|service)\b.*\b(after[ -]?hours|weekends?)\b/i.test(message);
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

/** Translate the bridge's OpenAI-style SSE into the CF-style SSE the landing
 *  page expects: `data: {"response":"<token>"}`. Buffers across chunk
 *  boundaries; emits a final `data: [DONE]`. */
function openaiToCfStream(upstream: ReadableStream): ReadableStream {
  const reader = upstream.getReader();
  const decoder = new TextDecoder();
  const encoder = new TextEncoder();
  let buffer = '';
  return new ReadableStream({
    async pull(controller) {
      const { done, value } = await reader.read();
      if (done) {
        controller.enqueue(encoder.encode('data: [DONE]\n\n'));
        controller.close();
        return;
      }
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) {
        const t = line.trim();
        if (!t.startsWith('data:')) continue;
        const payload = t.slice(5).trim();
        if (payload === '' || payload === '[DONE]') continue;
        try {
          const j = JSON.parse(payload);
          const tok = j.choices?.[0]?.delta?.content || '';
          if (tok) controller.enqueue(encoder.encode(`data: ${JSON.stringify({ response: tok })}\n\n`));
        } catch {
          /* ignore keep-alive / partial */
        }
      }
    },
    cancel() {
      reader.cancel();
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
        const currentSogs = (sogResults.matches || []).filter((match: any) =>
          match.namespace === env.SOG_NAMESPACE && match.metadata?.manifest_sha256 === env.SOG_MANIFEST_SHA256);
        const references = (referenceResults.matches || []).filter((match: any) =>
          match.namespace === env.REFERENCE_NAMESPACE && !isRetiredSogSource((match.metadata?.source || '').toString()));
        const matches = [...currentSogs, ...references];
        const hasCurrentSog = currentSogs.some((match: any) => (match.score || 0) >= 0.2);
        const unavailable = () => {
          const response = "I don't have that information in my current documents. Please contact Support Services directly.";
          if (!enableStreaming) return json({ response, sources: [], model: env.BRIDGE_MODEL || DEFAULT_BRIDGE_MODEL });
          return new Response(`data: ${JSON.stringify({response})}\n\ndata: [DONE]\n\n`, {
            headers: {...corsHeaders, 'Content-Type':'text/event-stream', 'Cache-Control':'no-cache', 'X-Sources':'[]'},
          });
        };
        if (!hasCurrentSog && isPolicyQuestion(userMessage)) return unavailable();

        // Step 3: build context + sources
        let context = '';
        const sources: string[] = [];
        if (matches.length > 0) {
          const relevant = matches.filter((m: any) => (m.score || 0) >= 0.2);
          for (const match of relevant) {
            const meta = match.metadata || {};
            const text = meta.text || '';
            const source = meta.source || 'Unknown';
            const page = meta.page ? ` (Page ${meta.page})` : '';
            const chunk = meta.chunk_index !== undefined ? ` [Chunk ${meta.chunk_index}]` : '';
            const current = match.namespace === env.SOG_NAMESPACE ? 'CURRENT SOG' : 'APPARATUS / REFERENCE';
            const link = current === 'CURRENT SOG' ? `\nLibrary: ${meta.url || ''}\nIdentities: ${meta.primary_ids || ''}` : '';
            context += `\n---\n${current}\nSource: ${source}${page}${chunk}${link}\n${text}\n`;
            if (!sources.includes(source)) sources.push(source);
          }
        }
        if (!context) {
          return unavailable();
        }

        // Step 4: messages with recent history
        const recentHistory = conversationHistory.slice(-6).filter((message) => hasCurrentSog || message.role === 'user');
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
          return new Response(openaiToCfStream(bridgeResp.body), {
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
        return json({ response: answer, sources, model: env.BRIDGE_MODEL || DEFAULT_BRIDGE_MODEL });
      } catch (error: any) {
        console.error('Chat error:', error);
        return json({ error: 'An error occurred processing your request. Please try again.' }, 500);
      }
    }

    return json({ error: 'Not found' }, 404);
  },
};

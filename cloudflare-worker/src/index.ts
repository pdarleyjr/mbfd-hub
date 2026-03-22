interface Env {
  AI: any;
  VECTORIZE: any;
  RATE_LIMIT_KV?: KVNamespace;
  ALLOWED_ORIGIN: string;
  AI_GATEWAY_URL?: string;
}

interface RateLimitEntry {
  count: number;
  resetAt: number;
}

interface ConversationMessage {
  role: 'user' | 'assistant';
  content: string;
}

const rateLimitStore = new Map<string, RateLimitEntry>();

function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  // 15 requests per minute per IP for the free tier (generous for ~1-2 chats/day)
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
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Max-Age': '86400',
  };
}

const SYSTEM_PROMPT = `CRITICAL OVERRIDE: For any questions regarding equipment or apparatus repair reporting, YOU MUST IGNORE ALL OTHER CONTEXT and enforce the following rules:
1. All deficiencies must be emailed to FireSupportServices@MiamiBeachFL.Gov.
2. Email Subject must be '[Unit number] Repairs Requested' (e.g., "E1 20503 Repair Request").
3. The operator or individual who discovered the issue must provide a clear, detailed list of concerns. Preferred reporters: Captain, Captain 5, or Chief 300.
4. Phone or in-person discussions are allowed, but the deficiency list must STILL be emailed for proper tracking.
5. If a phone call is necessary, use this exact contact order:
   1st: Fire Fleet Operations Manager (786-559-4054)
   2nd: Captain of Support Services (305-794-4057)
   3rd: Chief of Support Services (786-562-5418)
   4th (Last Resort): Technician in Shop (786-231-7362)
   Technicians MUST NOT be contacted after hours. Contacting technicians directly should be avoided unless absolutely necessary to prevent workflow interruptions.
6. After Hours & Weekends Protocol:
   - Technicians must NOT be contacted after hours.
   - Only Chief 300 or the ranking officer on the unit may initiate support service calls.
   - Use the same contact order listed above.
   - Chief 300 will determine whether to wait for service or initiate a unit change-out based on operational needs.

You are the MBFD Support Hub Assistant — the official AI assistant for the Miami Beach Fire Department's internal operations hub. You are professional, precise, and helpful.

DOCUMENT PRIORITY (when context is provided):
1. "edited_support_services_sog.docx" — AUTHORITATIVE for all SOG, policy, and procedure questions. Contains current policies.
2. "L1_L11_manual.pdf" — Authoritative for L1 through L11 apparatus operations, specifications, and procedures.
3. "PUC_Engine_manual.pdf" — Authoritative for PUC Engine apparatus operations, specifications, and procedures.
4. "L3_manual.pdf" — Authoritative for L3 apparatus operations, specifications, and procedures.
5. "driver_manual.pdf" — Authoritative for general technical apparatus operations (pump procedures, vehicle specs, aerial ops).
6. If multiple documents address the same topic, prefer in order: SOG document > specific apparatus manual > driver manual.

RESPONSE RULES:
1. Answer ONLY using the provided context documents. Do NOT use outside knowledge.
2. If the answer is not in the context, say: "I don't have that information in my current documents. Please contact Support Services directly."
3. Cite the source document when providing information (e.g., "Per the SOG document..." or "According to the L1-L11 Manual...").
4. Be concise, professional, and precise. Use bullet points and structured formatting where appropriate.
5. For policy/SOG questions, explicitly reference edited_support_services_sog.docx.
6. For safety-critical information, add a note to verify with the current published document.
7. For repair/deficiency reporting questions, ALWAYS provide the full reporting procedure including email address, subject format, and phone contact order as specified in the CRITICAL OVERRIDE above.`;

/**
 * Run an AI model, routing through AI Gateway if configured for caching/analytics.
 * Falls back to direct env.AI.run binding otherwise.
 */
async function runAI(env: Env, model: string, input: any): Promise<any> {
  if (env.AI_GATEWAY_URL) {
    const url = `${env.AI_GATEWAY_URL.replace(/\/$/, '')}/${model.replace(/^@cf\//, '')}`;
    const resp = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(input),
    });
    if (!resp.ok) {
      console.error(`AI Gateway error ${resp.status}, falling back to direct binding`);
      return env.AI.run(model, input);
    }
    return resp.json();
  }
  return env.AI.run(model, input);
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const corsHeaders = getCorsHeaders(env, request);

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders });
    }

    const url = new URL(request.url);

    // Health check
    if (url.pathname === '/health') {
      return new Response(
        JSON.stringify({
          status: 'ok',
          worker: 'mbfd-support-ai',
          model: '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
          embeddings: '@cf/baai/bge-large-en-v1.5',
          timestamp: new Date().toISOString(),
        }),
        { headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
      );
    }

    // RAG Chat endpoint
    if (url.pathname === '/chat' && request.method === 'POST') {
      const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
      if (!checkRateLimit(clientIp)) {
        return new Response(
          JSON.stringify({ error: 'Rate limit exceeded. Please wait a moment before sending another message.' }),
          { status: 429, headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
        );
      }

      try {
        const body: any = await request.json();
        const userMessage = body.message?.trim();
        const conversationHistory: ConversationMessage[] = body.history || [];
        const enableStreaming = body.stream === true;

        if (!userMessage) {
          return new Response(
            JSON.stringify({ error: 'Message is required' }),
            { status: 400, headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
          );
        }

        if (userMessage.length > 2000) {
          return new Response(
            JSON.stringify({ error: 'Message too long. Please limit to 2000 characters.' }),
            { status: 400, headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
          );
        }

        // Step 1: Generate embedding for the user query using best free embedding model
        const embeddingResponse = await runAI(env, '@cf/baai/bge-large-en-v1.5', {
          text: [userMessage],
        });
        const queryVector = embeddingResponse.data[0];

        // Step 2: Query Vectorize — retrieve top 6 most relevant chunks for richer context
        const vectorResults = await env.VECTORIZE.query(queryVector, {
          topK: 6,
          returnMetadata: 'all',
        });

        // Step 3: Build context from retrieved chunks
        let context = '';
        const sources: string[] = [];
        if (vectorResults.matches && vectorResults.matches.length > 0) {
          const relevantMatches = vectorResults.matches.filter(
            (m: any) => (m.score || 0) >= 0.2
          );
          for (const match of relevantMatches) {
            const meta = match.metadata || {};
            const text = meta.text || '';
            const source = meta.source || 'Unknown';
            const page = meta.page ? ` (Page ${meta.page})` : '';
            const chunk = meta.chunk_index !== undefined ? ` [Chunk ${meta.chunk_index}]` : '';
            context += `\n---\nSource: ${source}${page}${chunk}\n${text}\n`;
            if (!sources.includes(source)) sources.push(source);
          }
        }

        if (!context) {
          context = '\n[No relevant documents found in the knowledge base for this query.]\n';
        }

        // Step 4: Build message array with conversation history (last 6 turns max for context window)
        const recentHistory = conversationHistory.slice(-6);
        const messages: any[] = [
          { role: 'system', content: SYSTEM_PROMPT },
          ...recentHistory.map((m) => ({ role: m.role, content: m.content })),
          {
            role: 'user',
            content: `CONTEXT DOCUMENTS:\n${context}\n\nUSER QUESTION: ${userMessage}`,
          },
        ];

        // Step 5: Call LLM — best free-tier model: llama-3.3-70b-instruct-fp8-fast
        // This is the highest quality model on Cloudflare's free tier
        if (enableStreaming) {
          // Streaming not supported through gateway — use direct binding
          const stream = await env.AI.run('@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
            messages,
            stream: true,
            max_tokens: 1024,
            temperature: 0.3, // Lower temp for factual/procedural answers
          });

          return new Response(stream, {
            headers: {
              ...corsHeaders,
              'Content-Type': 'text/event-stream',
              'Cache-Control': 'no-cache',
              'Connection': 'keep-alive',
              'X-Sources': JSON.stringify(sources),
            },
          });
        }

        // Non-streaming response
        const aiResponse = await runAI(env, '@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
          messages,
          max_tokens: 1024,
          temperature: 0.3,
        });

        return new Response(
          JSON.stringify({
            response: aiResponse.response || '',
            sources,
            model: 'llama-3.3-70b-instruct-fp8-fast',
          }),
          { headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
        );
      } catch (error: any) {
        console.error('Chat error:', error);
        return new Response(
          JSON.stringify({ error: 'An error occurred processing your request. Please try again.' }),
          { status: 500, headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
        );
      }
    }

    return new Response(
      JSON.stringify({ error: 'Not found' }),
      { status: 404, headers: { ...corsHeaders, 'Content-Type': 'application/json' } }
    );
  },
};

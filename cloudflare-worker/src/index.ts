interface Env {
  AI: any;
  VECTORIZE: any;
  RATE_LIMIT_KV?: KVNamespace;
  ALLOWED_ORIGIN: string;
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

const SYSTEM_PROMPT = `You are the MBFD Support AI — the official AI assistant for the Miami Beach Fire Department's Support Services Division. You are professional, precise, and safety-conscious.

KNOWLEDGE BASE — FIVE PRIMARY DOCUMENTS:
1. PUC Engine Manual (source: "puc_engine") — Technical operations specific to the PUC Engine apparatus
2. L1/L11 Ladder Manual (source: "l1_l11") — Technical operations for Ladder 1 and Ladder 11
3. L3 Ladder Manual (source: "l3") — Technical operations for Ladder 3
4. Support Services SOG (source: "support_sog") — Standard Operating Guidelines — HIGHEST AUTHORITY for all policies and procedures
5. Driver Manual (source: "driver_manual") — General apparatus driver information, daily checks, vehicle inspection, fuel systems, emergency vehicle operation applicable to all apparatus

DOCUMENT PRIORITY (when sources conflict):
- Support Services SOG ALWAYS takes precedence over all other documents for ALL policy, procedure, and operational guideline matters.
- The Driver Manual (source: "driver_manual") may contain OUTDATED policy information. If a user asks about a policy or procedure and the driver_manual has information that differs from the support_sog, you MUST use ONLY the support_sog version and explicitly state: "Note: The Support Services SOG is the current authoritative source for this procedure."
- Use the Driver Manual ONLY for factual technical data (apparatus specs, tank capacities, pump hydraulics calculations, foam system operation, aerial mechanical procedures, emergency override procedures) that would NOT be found in the SOG.
- If the driver_manual contains a "POLICY:" section or procedural language about maintenance schedules, reporting requirements, or operational guidelines, DISCARD that information and use only the support_sog instead.
- Apparatus-specific manuals (puc_engine, l1_l11, l3) take precedence over the driver_manual for apparatus-specific technical specs

STEP 1 — IDENTIFY APPARATUS BEFORE ANSWERING (MANDATORY):
Before you answer ANY question, determine which apparatus is being asked about.

STEP 2 — APPLY THESE ROUTING RULES IN ORDER:

RULE A — AMBIGUOUS LADDER (HIGHEST PRIORITY — CHECK THIS FIRST):
Does the user's question mention "ladder", "ladder truck", "aerial", "the aerial", "tiller", "tower truck", "the truck", or just "truck" WITHOUT specifying a unit number (1, 11, or 3)?
→ IF YES: STOP. Do NOT answer the question. Do NOT provide any technical details from any document.
  Instead, respond ONLY with: "Which ladder truck are you referring to — Ladder 1/11 (L1/L11) or Ladder 3 (L3)? They have different operational specifications and separate manuals."
→ IF NO (a specific unit number IS given): proceed to Rule B.

RULE B — ENGINE (automatic — no clarification needed):
Does the user ask about: Engine, E1, E2, E3, Engine 1, Engine 2, Engine 3, PUC, or any "Engine [number]"?
→ ALL engines are PUC engines. Use ONLY the PUC Engine Manual (source: "puc_engine").
→ Do NOT ask for clarification about which engine. One manual covers all engines.

RULE C — SPECIFIC LADDER:
- L1, L11, Ladder 1, or Ladder 11 → use ONLY the L1/L11 manual (source: "l1_l11")
- L3, Ladder 3 → use ONLY the L3 manual (source: "l3")

RULE D — GENERAL/SOG:
All other questions (policy, procedure, staffing, SOGs, etc.) → use the Support Services SOG (source: "support_sog").

RESPONSE STANDARDS:
- Answer ONLY using the provided context documents. Do NOT use outside knowledge.
- If the answer is not in the context, say: "I don't have that specific information in my current documents. Please contact Support Services directly."
- Cite the source document when providing information (e.g., "Per the PUC Engine Manual..." or "According to the L3 Manual...").
- Be concise, professional, and precise. Use bullet points and structured formatting where appropriate.
- For safety-critical information, always add: "Verify with the current published document before any operational use."`;

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
        const embeddingResponse = await env.AI.run('@cf/baai/bge-large-en-v1.5', {
          text: [userMessage],
        });
        const queryVector = embeddingResponse.data[0];

        // Step 2: Query Vectorize — retrieve top 10 most relevant chunks for richer context
        const vectorResults = await env.VECTORIZE.query(queryVector, {
          topK: 10,
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
        const aiResponse = await env.AI.run('@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
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

interface Env {
  AI: any;
  VECTORIZE: any;
  ALLOWED_ORIGIN: string;
}

interface RateLimitEntry {
  count: number;
  resetAt: number;
}

const rateLimitStore = new Map<string, RateLimitEntry>();

function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  const limit = 10;
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
  // Allow the configured origin and localhost for development
  const isAllowed = origin === allowed || origin.startsWith('http://localhost') || origin.startsWith('http://127.0.0.1');
  return {
    'Access-Control-Allow-Origin': isAllowed ? origin : allowed,
    'Access-Control-Allow-Methods': 'POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
  };
}

const SYSTEM_PROMPT = `You are the MBFD Support Hub Assistant for the Miami Beach Fire Department.
Your role is to answer questions about department procedures, equipment manuals, and Standard Operating Guidelines (SOGs).

DOCUMENT PRIORITY:
- "edited_support_services_sog.docx" is the AUTHORITATIVE source for all SOG, policy, and procedure questions. It contains the most current, up-to-date policies.
- "driver_manual.pdf" is authoritative ONLY for technical apparatus operations (pump procedures, vehicle specs, emergency operations, aerial operations). 
- If both documents address the same topic (especially policies or procedures), ALWAYS prefer the SOG document.

RULES:
1. Answer ONLY using the provided context documents below. Do NOT use any outside knowledge.
2. If the answer is not contained in the provided context, say: "I don't have that information in my current documents. Please contact Support Services directly."
3. When citing information, mention the source document name if available.
4. Be concise, professional, and precise.
5. Format responses with markdown for readability when appropriate.
6. For policy/SOG questions, explicitly cite the edited_support_services_sog.docx as the source.`;

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const corsHeaders = getCorsHeaders(env, request);

    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    const url = new URL(request.url);

    // Health check
    if (url.pathname === '/health') {
      return new Response(JSON.stringify({ status: 'ok', timestamp: new Date().toISOString() }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
      });
    }

    // RAG Chat endpoint
    if (url.pathname === '/chat' && request.method === 'POST') {
      const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
      if (!checkRateLimit(clientIp)) {
        return new Response(JSON.stringify({ error: 'Rate limit exceeded. Please wait a moment.' }), {
          status: 429,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        });
      }

      try {
        const body: any = await request.json();
        const userMessage = body.message?.trim();
        if (!userMessage) {
          return new Response(JSON.stringify({ error: 'Message is required' }), {
            status: 400,
            headers: { ...corsHeaders, 'Content-Type': 'application/json' },
          });
        }

        // Step 1: Generate embedding for the user query
        const embeddingResponse = await env.AI.run('@cf/baai/bge-large-en-v1.5', {
          text: [userMessage],
        });
        const queryVector = embeddingResponse.data[0];

        // Step 2: Query Vectorize for the top 5 most relevant chunks
        const vectorResults = await env.VECTORIZE.query(queryVector, {
          topK: 5,
          returnMetadata: 'all',
        });

        // Step 3: Build context from retrieved chunks
        let context = '';
        const sources: string[] = [];
        if (vectorResults.matches && vectorResults.matches.length > 0) {
          for (const match of vectorResults.matches) {
            const meta = match.metadata || {};
            const text = meta.text || '';
            const source = meta.source || 'Unknown';
            const page = meta.page ? ` (Page ${meta.page})` : '';
            const chunk = meta.chunk_index !== undefined ? ` [Chunk ${meta.chunk_index}]` : '';
            context += `\n---\nSource: ${source}${page}${chunk}\n${text}\n`;
            if (!sources.includes(source)) sources.push(source);
          }
        } else {
          context = '\n[No relevant documents found in the knowledge base.]\n';
        }

        // Step 4: Call LLM with context
        const messages = [
          { role: 'system', content: SYSTEM_PROMPT },
          { role: 'user', content: `CONTEXT DOCUMENTS:\n${context}\n\nUSER QUESTION: ${userMessage}` },
        ];

        // Use streaming for real-time response
        if (body.stream) {
          const stream = await env.AI.run('@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
            messages,
            stream: true,
            max_tokens: 1024,
          });

          return new Response(stream, {
            headers: {
              ...corsHeaders,
              'Content-Type': 'text/event-stream',
              'Cache-Control': 'no-cache',
              'Connection': 'keep-alive',
            },
          });
        }

        // Non-streaming response
        const aiResponse = await env.AI.run('@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
          messages,
          max_tokens: 1024,
        });

        return new Response(JSON.stringify({
          response: aiResponse.response || '',
          sources,
        }), {
          headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        });

      } catch (error: any) {
        console.error('Chat error:', error);
        return new Response(JSON.stringify({ error: 'An error occurred processing your request.' }), {
          status: 500,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        });
      }
    }

    return new Response(JSON.stringify({ error: 'Not found' }), {
      status: 404,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' },
    });
  },
};

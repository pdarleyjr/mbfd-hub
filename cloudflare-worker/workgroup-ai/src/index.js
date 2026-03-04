/**
 * MBFD Workgroup AI Worker
 * Dedicated RAG worker for workgroup evaluation intelligence.
 * Uses @cf/baai/bge-large-en-v1.5 for embeddings and
 * @cf/meta/llama-3.3-70b-instruct-fp8-fast for analysis.
 */

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // CORS headers
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization',
    };

    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    try {
      // POST /vectorize - Store document embeddings
      if (url.pathname === '/vectorize' && request.method === 'POST') {
        const { chunks, metadata } = await request.json();
        if (!chunks || !Array.isArray(chunks)) {
          return Response.json({ error: 'chunks array required' }, { status: 400, headers: corsHeaders });
        }

        const vectors = [];
        for (let i = 0; i < chunks.length; i++) {
          const embedding = await env.AI.run('@cf/baai/bge-large-en-v1.5', {
            text: [chunks[i]],
          });

          vectors.push({
            id: `${metadata?.file_id || 'doc'}-chunk-${i}-${Date.now()}`,
            values: embedding.data[0],
            metadata: {
              text: chunks[i].substring(0, 1000),
              file_id: metadata?.file_id || null,
              file_name: metadata?.file_name || null,
              session_id: metadata?.session_id || null,
              chunk_index: i,
            },
          });
        }

        await env.VECTORIZE.upsert(vectors);

        return Response.json(
          { success: true, vectors_stored: vectors.length },
          { headers: corsHeaders }
        );
      }

      // POST /analyze - Analyze evaluation with RAG context
      if (url.pathname === '/analyze' && request.method === 'POST') {
        const { evaluation_text, session_id, product_name } = await request.json();

        // Get relevant context from Vectorize
        const queryEmbed = await env.AI.run('@cf/baai/bge-large-en-v1.5', {
          text: [evaluation_text || 'evaluation summary'],
        });

        let contextText = '';
        try {
          const results = await env.VECTORIZE.query(queryEmbed.data[0], {
            topK: 5,
            filter: session_id ? { session_id: String(session_id) } : undefined,
            returnMetadata: true,
          });

          contextText = results.matches
            .map((m) => m.metadata?.text || '')
            .filter(Boolean)
            .join('\n\n');
        } catch (e) {
          contextText = 'No vendor specification context available.';
        }

        const prompt = `You are an expert fire apparatus procurement analyst for the Miami Beach Fire Department. Analyze the following evaluation data and vendor specifications to produce a concise, professional summary.

Vendor Specifications Context:
${contextText}

Evaluation Data:
${evaluation_text}

Product: ${product_name || 'Unknown'}

Provide a brief technical analysis covering:
1. Key strengths identified
2. Notable concerns or weaknesses
3. Overall recommendation based on the data`;

        const response = await env.AI.run('@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
          messages: [
            { role: 'system', content: 'You are an expert fire department procurement analyst. Be concise and professional.' },
            { role: 'user', content: prompt },
          ],
          max_tokens: 1024,
        });

        return Response.json(
          { summary: response.response, context_chunks: contextText ? 'available' : 'none' },
          { headers: corsHeaders }
        );
      }

      // GET /summary - Get current session summary
      if (url.pathname === '/summary' && request.method === 'GET') {
        const sessionId = url.searchParams.get('session_id');

        return Response.json(
          { summary: null, message: 'Summary generation triggered on evaluation submission events' },
          { headers: corsHeaders }
        );
      }

      // POST /executive-report - Generate executive report content
      if (url.pathname === '/executive-report' && request.method === 'POST') {
        const { scores_summary, session_name, products } = await request.json();

        // Get all vectorized specs for context
        let specContext = '';
        try {
          const queryEmbed = await env.AI.run('@cf/baai/bge-large-en-v1.5', {
            text: ['fire apparatus specifications evaluation summary'],
          });
          const results = await env.VECTORIZE.query(queryEmbed.data[0], {
            topK: 10,
            returnMetadata: true,
          });
          specContext = results.matches
            .map((m) => m.metadata?.text || '')
            .filter(Boolean)
            .join('\n\n');
        } catch (e) {
          specContext = 'No vendor specifications available in knowledge base.';
        }

        const prompt = `You are preparing an executive summary report for the Miami Beach Fire Department Health and Safety Committee regarding a workgroup evaluation session.

Session: ${session_name}

Products Evaluated:
${products?.map((p) => `- ${p.name} (${p.manufacturer}): Avg Score ${p.avg_score}/100, ${p.response_count} evaluations`).join('\n') || 'No products'}

Scores Summary:
${scores_summary || 'No scores available'}

Vendor Specification Context:
${specContext}

Write a professional, well-structured executive summary that includes:
1. Executive Overview (2-3 sentences)
2. Methodology (brief description of the evaluation process)
3. Product Analysis (for each product, key findings from evaluations cross-referenced with vendor specs)
4. Comparative Assessment (ranking products with justification)
5. Committee Recommendation (which product(s) should advance and why)

Use formal, technical language appropriate for a government committee report. Be specific with data points.`;

        const response = await env.AI.run('@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
          messages: [
            { role: 'system', content: 'You are a professional technical writer specializing in government procurement reports. Write concisely but thoroughly.' },
            { role: 'user', content: prompt },
          ],
          max_tokens: 2048,
        });

        return Response.json(
          { report: response.response },
          { headers: corsHeaders }
        );
      }

      // Health check
      if (url.pathname === '/health') {
        return Response.json({ status: 'ok', worker: 'mbfd-workgroup-ai' }, { headers: corsHeaders });
      }

      return Response.json({ error: 'Not found' }, { status: 404, headers: corsHeaders });
    } catch (error) {
      return Response.json(
        { error: error.message || 'Internal server error' },
        { status: 500, headers: corsHeaders }
      );
    }
  },
};

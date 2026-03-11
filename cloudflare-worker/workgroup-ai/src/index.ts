/**
 * MBFD Workgroup Evaluation AI Worker
 * =====================================
 * Separate Cloudflare Worker for evaluation data analysis.
 * Does NOT share resources with or affect the landing page chatbot (mbfd-support-ai).
 *
 * Endpoints:
 *   POST /vectorize          — Ingest a product spec/PDF chunk into the vector index
 *   POST /analyze            — Generate AI analysis for a single product's eval submissions
 *   POST /summary            — Generate summary for a session category
 *   POST /executive-report   — Generate full executive report for Health & Safety Committee
 *   GET  /health             — Health check
 *
 * Models used (best available on Cloudflare free tier):
 *   Text generation: @cf/meta/llama-3.3-70b-instruct-fp8-fast  (70B params, FP8 quantized)
 *   Embeddings:      @cf/baai/bge-large-en-v1.5                 (1024-dim, best semantic search)
 */

interface Env {
  AI: any;
  VECTORIZE: any;
  ALLOWED_ORIGIN: string;
  WORKER_ENV?: string;
  AI_GATEWAY_URL?: string;
}

interface RateLimitEntry {
  count: number;
  resetAt: number;
}

// In-memory rate limiter (resets on worker cold start — acceptable for low-usage scenario)
const rateLimitStore = new Map<string, RateLimitEntry>();

function checkRateLimit(key: string, limit = 30, windowMs = 60000): boolean {
  const now = Date.now();
  const entry = rateLimitStore.get(key);
  if (!entry || now > entry.resetAt) {
    rateLimitStore.set(key, { count: 1, resetAt: now + windowMs });
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
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
    'Access-Control-Max-Age': '86400',
  };
}

function jsonResponse(data: any, status = 200, extras: Record<string, string> = {}, corsHeaders: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      ...corsHeaders,
      'Content-Type': 'application/json',
      ...extras,
    },
  });
}

// =============================================================================
// AI GATEWAY HELPER
// =============================================================================

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

// =============================================================================
// SYSTEM PROMPTS
// =============================================================================

const EVAL_ANALYST_SYSTEM_PROMPT = `You are an expert public safety equipment analyst for the Miami Beach Fire Department. 
You specialize in evaluating fire department tools and equipment for the Mid-Mount Ladder Truck Workgroup committee.

Your analysis must be:
- Professional, objective, and data-driven
- Written for presentation to the Health & Safety Committee and Fire Chief Digna Abello
- Concise yet comprehensive — suitable for executive briefings
- Based ONLY on the provided evaluation scores, member feedback, and product specifications

Scoring Framework (SAVER Rubric):
- Capability: Functional performance and features (how well it does the job)
- Usability: Ease of use, ergonomics, training requirements
- Affordability: Cost-effectiveness, value for budget
- Maintainability: Servicing, parts availability, durability
- Deployability: Readiness for operational deployment, reliability

Rating Scale: 1 (Poor) → 5 (Excellent)

When writing your analysis, always:
1. Lead with the overall weighted score and ranking position
2. Summarize committee consensus across evaluators
3. Highlight key strengths and critical weaknesses with specific data
4. Note any deal-breakers (safety/operational disqualifiers)
5. Reference technical specifications from vendor documents when available
6. Conclude with a clear recommendation`;

const EXECUTIVE_REPORT_SYSTEM_PROMPT = `You are an expert data analyst and technical writer for the Miami Beach Fire Department.
You are creating a formal data analysis report for the Mid-Mount Ladder Truck Equipment Evaluation Workgroup.

This report will be presented to:
- The Health & Safety Committee
- Fire Chief Digna Abello
- Command Staff

Report Requirements:
- Formal, professional tone suitable for command-level review
- Focus SOLELY on analyzing the data that has been collected (uploaded files, evaluation submissions, member notes)
- Provide in-depth analysis of evaluation scores, patterns, and trends
- Reference uploaded vendor specification documents and what they reveal
- Summarize evaluator feedback and identify consensus/disagreements
- DO NOT make purchasing, procurement, or "next steps" recommendations
- DO NOT suggest what to buy or recommend specific products for purchase
- Simply present the analyzed data clearly and objectively
- Highlight interesting patterns, outliers, and notable findings in the data

Remember: Your job is to ANALYZE and PRESENT the collected data, not to advise on procurement decisions.`;

// =============================================================================
// HANDLERS
// =============================================================================

/**
 * POST /vectorize
 * Ingests a text chunk (from a product spec, PDF, or brochure) into the vector index.
 * Called by Laravel when a file is uploaded to the Files/SharedUploads pages.
 */
async function handleVectorize(request: Request, env: Env, corsHeaders: Record<string, string>): Promise<Response> {
  const body: any = await request.json();
  const { text, productName, manufacturer, category, filename, chunkIndex, fileId } = body;

  if (!text || !filename) {
    return jsonResponse({ error: 'text and filename are required' }, 400, {}, corsHeaders);
  }

  const truncatedText = text.slice(0, 2000); // Chunk size limit for embedding quality

  // Generate embedding using best free-tier model
  const embeddingResponse = await runAI(env, '@cf/baai/bge-large-en-v1.5', {
    text: [truncatedText],
  });

  const vector = embeddingResponse.data[0];

  // Create a unique, deterministic ID for this chunk
  const vectorId = `${fileId || 'file'}-chunk-${chunkIndex || 0}`;

  await env.VECTORIZE.upsert([{
    id: vectorId,
    values: vector,
    metadata: {
      text: truncatedText,
      productName: productName || '',
      manufacturer: manufacturer || '',
      category: category || '',
      filename,
      chunkIndex: chunkIndex || 0,
      fileId: fileId || '',
      indexedAt: new Date().toISOString(),
    },
  }]);

  return jsonResponse({
    success: true,
    vectorId,
    chunksIndexed: 1,
    model: 'bge-large-en-v1.5',
  }, 200, {}, corsHeaders);
}

/**
 * POST /analyze
 * Generates a professional AI analysis for a single candidate product,
 * incorporating evaluation scores from the committee and any vectorized
 * product specs/documentation.
 */
async function handleAnalyze(request: Request, env: Env, corsHeaders: Record<string, string>): Promise<Response> {
  const body: any = await request.json();
  const {
    productName,
    manufacturer,
    model: productModel,
    category,
    submissions,
    aggregateScores,
    sessionName,
  } = body;

  if (!productName || !submissions) {
    return jsonResponse({ error: 'productName and submissions are required' }, 400, {}, corsHeaders);
  }

  // Step 1: Search vector index for relevant spec documents for this product
  let specContext = '';
  try {
    const searchQuery = `${manufacturer || ''} ${productName} ${productModel || ''} ${category || ''}`.trim();
    const embeddingResponse = await runAI(env, '@cf/baai/bge-large-en-v1.5', {
      text: [searchQuery],
    });
    const queryVector = embeddingResponse.data[0];

    const vectorResults = await env.VECTORIZE.query(queryVector, {
      topK: 4,
      returnMetadata: 'all',
      filter: manufacturer ? { manufacturer } : undefined,
    });

    if (vectorResults.matches && vectorResults.matches.length > 0) {
      const relevantChunks = vectorResults.matches.filter((m: any) => (m.score || 0) >= 0.35);
      for (const match of relevantChunks) {
        const meta = match.metadata || {};
        specContext += `\n[Spec from: ${meta.filename}]\n${meta.text}\n`;
      }
    }
  } catch (e) {
    // Vectorize query failure is non-fatal — proceed with eval data only
    specContext = '[No product specifications found in document index]';
  }

  // Step 2: Format evaluation data for the LLM
  const submissionSummary = formatSubmissions(submissions);
  const aggregateSummary = formatAggregateScores(aggregateScores);

  const userPrompt = `PRODUCT EVALUATION ANALYSIS REQUEST
  
Product: ${productName}
Manufacturer: ${manufacturer || 'Not specified'}
Model: ${productModel || 'Not specified'}
Category: ${category || 'Not specified'}
Session: ${sessionName || 'Active Session'}

=== AGGREGATE EVALUATION SCORES ===
${aggregateSummary}

=== INDIVIDUAL EVALUATOR SUBMISSIONS (${submissions.length} evaluator(s)) ===
${submissionSummary}

=== VENDOR PRODUCT SPECIFICATIONS ===
${specContext || '[No specifications available in document index]'}

=== TASK ===
Generate a professional, structured product evaluation analysis. Include:
1. Executive Summary (2-3 sentences, lead with score and ranking context)
2. Score Analysis (break down SAVER categories, note high/low scores)
3. Committee Consensus (what evaluators agreed on, dissenting views if any)
4. Key Strengths (bullet points, cite specific scores/feedback)
5. Key Weaknesses/Concerns (bullet points, be objective)
6. Deal-Breakers (if any — serious safety or operational disqualifiers)
7. Technical Specification Highlights (reference vendor docs if available)
8. Recommendation (Advance as Finalist / Needs Further Review / Do Not Advance)

Format with clear section headers. Be professional, objective, and data-driven.`;

  const messages = [
    { role: 'system', content: EVAL_ANALYST_SYSTEM_PROMPT },
    { role: 'user', content: userPrompt },
  ];

  const aiResponse = await runAI(env, '@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
    messages,
    max_tokens: 1500,
    temperature: 0.2, // Low temperature for factual analysis
  });

  return jsonResponse({
    analysis: aiResponse.response || '',
    productName,
    manufacturer,
    category,
    model: 'llama-3.3-70b-instruct-fp8-fast',
    specContextFound: specContext.length > 50,
    generatedAt: new Date().toISOString(),
  }, 200, {}, corsHeaders);
}

/**
 * POST /summary
 * Generates a category-level summary ranking all products in a category.
 */
async function handleSummary(request: Request, env: Env, corsHeaders: Record<string, string>): Promise<Response> {
  const body: any = await request.json();
  const { category, products, sessionName, rankingType } = body;

  if (!category || !products) {
    return jsonResponse({ error: 'category and products are required' }, 400, {}, corsHeaders);
  }

  const isBatteryHydraulics = rankingType === 'brand' || 
    category.toLowerCase().includes('hydraulic') || 
    category.toLowerCase().includes('battery');

  const productsSummary = products.map((p: any, i: number) => {
    const rank = i + 1;
    const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : `#${rank}`;
    return `${medal} ${p.name} (${p.manufacturer || 'N/A'})
   Overall Score: ${p.averageScore?.toFixed(2) || 'N/A'}/5.0
   Capability: ${p.capabilityScore?.toFixed(2) || 'N/A'} | Usability: ${p.usabilityScore?.toFixed(2) || 'N/A'} | Affordability: ${p.affordabilityScore?.toFixed(2) || 'N/A'}
   Maintainability: ${p.maintainabilityScore?.toFixed(2) || 'N/A'} | Deployability: ${p.deployabilityScore?.toFixed(2) || 'N/A'}
   Evaluators: ${p.submissionCount || 0} | Finalists Advances: ${p.finalistVotes || 0}
   ${p.dealBreakerCount > 0 ? `⚠️ DEAL-BREAKER REPORTED BY ${p.dealBreakerCount} evaluator(s)` : ''}`;
  }).join('\n\n');

  const userPrompt = `CATEGORY SUMMARY ANALYSIS REQUEST

Category: ${category}
Session: ${sessionName || 'Active Session'}
Ranking Type: ${isBatteryHydraulics ? 'BRAND OVERALL (all tools from same brand evaluated together)' : 'Individual Product'}
Products Evaluated: ${products.length}

=== RANKED PRODUCT SCORES ===
${productsSummary}

=== TASK ===
Generate a professional category summary for the committee report. Include:
1. Category Overview (brief description of what was evaluated in this category)
2. Ranking Summary with clear #1, #2, #3 positions
3. Score Distribution Analysis (spread between products, standouts)
4. ${isBatteryHydraulics ? 'Brand Comparison (compare battery hydraulic brands overall — cutters/spreaders/rams together per brand)' : 'Product Comparison (key differentiators)'}
5. Committee Recommendation (which product(s) should advance as finalists for this category)
6. Notable Observations (any deal-breakers, unanimous agreements, etc.)

Be concise and professional. This section feeds into the executive report for Fire Chief Abello and the Health & Safety Committee.`;

  const messages = [
    { role: 'system', content: EVAL_ANALYST_SYSTEM_PROMPT },
    { role: 'user', content: userPrompt },
  ];

  const aiResponse = await runAI(env, '@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
    messages,
    max_tokens: 1200,
    temperature: 0.2,
  });

  return jsonResponse({
    summary: aiResponse.response || '',
    category,
    productCount: products.length,
    rankingType: isBatteryHydraulics ? 'brand' : 'individual',
    model: 'llama-3.3-70b-instruct-fp8-fast',
    generatedAt: new Date().toISOString(),
  }, 200, {}, corsHeaders);
}

/**
 * POST /executive-report
 * Generates the full executive evaluation report for all categories.
 * This is the final document for the Health & Safety Committee and Fire Chief.
 */
async function handleExecutiveReport(request: Request, env: Env, corsHeaders: Record<string, string>): Promise<Response> {
  const body: any = await request.json();
  const { sessionName, sessionDate, categories, overallStats } = body;

  if (!categories || !Array.isArray(categories)) {
    return jsonResponse({ error: 'categories array is required' }, 400, {}, corsHeaders);
  }

  // Format all categories and their top products
  const categoriesFormatted = categories.map((cat: any) => {
    const products = (cat.products || []).slice(0, 5); // Top 5 per category
    const productsText = products.map((p: any, i: number) => {
      const rank = i + 1;
      return `   ${rank}. ${p.name} (${p.manufacturer || 'N/A'}) — Score: ${p.averageScore?.toFixed(2) || 'N/A'}/5.0${p.isFinalist ? ' ✓ FINALIST' : ''}${p.hasDealBreaker ? ' ⚠️ HAS DEAL-BREAKER' : ''}`;
    }).join('\n');

    return `CATEGORY: ${cat.name} (${cat.rankingType === 'brand' ? 'Brand Ranking' : 'Individual Ranking'})
Evaluators Participating: ${cat.evaluatorCount || 'N/A'}
Products Evaluated: ${products.length}
${productsText}
Category Notes: ${cat.notes || cat.aiSummary || 'See individual product analyses'}`;
  }).join('\n\n---\n\n');

  const userPrompt = `EXECUTIVE DATA ANALYSIS REPORT REQUEST

Report Title: Mid-Mount Ladder Truck Equipment Evaluation — Data Analysis
Session: ${sessionName || 'Ladder Truck Procurement Evaluation'}
Date: ${sessionDate || new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
Prepared for: Health & Safety Committee, Fire Chief Digna Abello

=== SESSION STATISTICS ===
Total Products Evaluated: ${overallStats?.totalProducts || 'N/A'}
Total Evaluators: ${overallStats?.totalEvaluators || 'N/A'}
Total Submissions: ${overallStats?.totalSubmissions || 'N/A'}
Categories Evaluated: ${categories.length}

=== EVALUATION RESULTS BY CATEGORY ===
${categoriesFormatted}

=== TASK ===
Generate a complete, formal data analysis report. Structure:

1. EXECUTIVE OVERVIEW
   - Purpose of the evaluation and data collection effort
   - Summary of participation (how many evaluators, sessions, products tested)
   
2. DATA ANALYSIS BY CATEGORY (one section per category)
   - For each category: score distribution, patterns, evaluator consensus
   - For Battery Hydraulics: analyze scores by brand groupings
   - Reference any uploaded vendor specification documents and their key data points
   
3. EVALUATOR FEEDBACK ANALYSIS
   - Common themes across evaluator narratives
   - Areas of strong agreement vs. divergent opinions
   - Notable deal-breaker reports and their context
   
4. DATA PATTERNS & OBSERVATIONS
   - Score distributions and statistical observations
   - Categories with clear frontrunners vs. close competitions
   - Any data anomalies or outliers worth noting

5. SUMMARY OF COLLECTED DATA
   - High-level overview of all data gathered to date
   - Areas where more data may be needed

DO NOT include purchasing recommendations or procurement next steps.
Format professionally. Use headers and structured layout. This is a formal analytical document.`;

  const messages = [
    { role: 'system', content: EXECUTIVE_REPORT_SYSTEM_PROMPT },
    { role: 'user', content: userPrompt },
  ];

  const aiResponse = await runAI(env, '@cf/meta/llama-3.3-70b-instruct-fp8-fast', {
    messages,
    max_tokens: 2000,
    temperature: 0.15, // Very low for formal document
  });

  return jsonResponse({
    report: aiResponse.response || '',
    sessionName,
    categoriesAnalyzed: categories.length,
    model: 'llama-3.3-70b-instruct-fp8-fast',
    generatedAt: new Date().toISOString(),
  }, 200, {}, corsHeaders);
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function formatSubmissions(submissions: any[]): string {
  return submissions.map((sub: any, i: number) => {
    const lines = [
      `Evaluator ${i + 1} (${sub.evaluatorRole || 'Member'}):`,
      `  Overall Score: ${sub.overallScore?.toFixed(2) || 'N/A'}/5.0`,
      `  Capability: ${sub.capabilityScore?.toFixed(2) || 'N/A'} | Usability: ${sub.usabilityScore?.toFixed(2) || 'N/A'}`,
      `  Affordability: ${sub.affordabilityScore?.toFixed(2) || 'N/A'} | Maintainability: ${sub.maintainabilityScore?.toFixed(2) || 'N/A'}`,
      `  Deployability: ${sub.deployabilityScore?.toFixed(2) || 'N/A'}`,
      `  Recommendation: ${sub.recommendationLabel || 'N/A'}`,
      `  Confidence: ${sub.confidenceLabel || 'N/A'}`,
    ];

    if (sub.hasDealBreaker && sub.dealBreakerNote) {
      lines.push(`  ⚠️ DEAL-BREAKER: ${sub.dealBreakerNote.slice(0, 200)}`);
    }

    if (sub.narrative) {
      if (sub.narrative.strongest_advantages) {
        lines.push(`  Pros: ${sub.narrative.strongest_advantages.slice(0, 300)}`);
      }
      if (sub.narrative.biggest_weaknesses) {
        lines.push(`  Cons: ${sub.narrative.biggest_weaknesses.slice(0, 300)}`);
      }
      if (sub.narrative.safety_concerns) {
        lines.push(`  Safety Notes: ${sub.narrative.safety_concerns.slice(0, 200)}`);
      }
    }

    return lines.join('\n');
  }).join('\n\n');
}

function formatAggregateScores(scores: any): string {
  if (!scores) return 'No aggregate scores available';
  return [
    `Evaluators: ${scores.evaluatorCount || 0}`,
    `Average Overall Score: ${scores.averageOverall?.toFixed(2) || 'N/A'}/5.0`,
    `Capability Avg: ${scores.avgCapability?.toFixed(2) || 'N/A'}`,
    `Usability Avg: ${scores.avgUsability?.toFixed(2) || 'N/A'}`,
    `Affordability Avg: ${scores.avgAffordability?.toFixed(2) || 'N/A'}`,
    `Maintainability Avg: ${scores.avgMaintainability?.toFixed(2) || 'N/A'}`,
    `Deployability Avg: ${scores.avgDeployability?.toFixed(2) || 'N/A'}`,
    `Advance Recommendations: ${scores.advanceCount || 0} Yes, ${scores.maybeCount || 0} Maybe, ${scores.noCount || 0} No`,
    `Deal-Breakers Reported: ${scores.dealBreakerCount || 0}`,
  ].join('\n');
}

// =============================================================================
// MAIN FETCH HANDLER
// =============================================================================

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const corsHeaders = getCorsHeaders(env, request);

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders });
    }

    const url = new URL(request.url);
    const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';

    // Health check — no rate limiting
    if (url.pathname === '/health') {
      return jsonResponse({
        status: 'ok',
        worker: 'mbfd-workgroup-ai',
        version: '2.0.0',
        models: {
          text: '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
          embeddings: '@cf/baai/bge-large-en-v1.5',
        },
        vectorIndex: 'workgroup-specs',
        endpoints: ['/vectorize', '/analyze', '/summary', '/executive-report', '/health'],
        timestamp: new Date().toISOString(),
      }, 200, {}, corsHeaders);
    }

    if (request.method !== 'POST') {
      return jsonResponse({ error: 'Method not allowed' }, 405, {}, corsHeaders);
    }

    // Rate limiting — generous limits for low-usage scenario
    const rateLimitKey = `${url.pathname}-${clientIp}`;
    if (!checkRateLimit(rateLimitKey, 30, 60000)) {
      return jsonResponse(
        { error: 'Rate limit exceeded. Please wait a moment.' },
        429, {}, corsHeaders
      );
    }

    try {
      switch (url.pathname) {
        case '/vectorize':
          return await handleVectorize(request, env, corsHeaders);

        case '/analyze':
          return await handleAnalyze(request, env, corsHeaders);

        case '/summary':
          return await handleSummary(request, env, corsHeaders);

        case '/executive-report':
          return await handleExecutiveReport(request, env, corsHeaders);

        default:
          return jsonResponse({ error: 'Not found' }, 404, {}, corsHeaders);
      }
    } catch (error: any) {
      console.error(`[workgroup-ai] Error on ${url.pathname}:`, error);
      return jsonResponse(
        { error: 'Internal server error. Please try again.', detail: error.message },
        500, {}, corsHeaders
      );
    }
  },
};

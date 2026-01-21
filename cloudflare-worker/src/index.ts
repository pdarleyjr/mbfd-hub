interface Env {
  AI: any;
  API_SECRET: string;
  SUPPORT_HUB_URL: string;
}

interface RateLimitEntry {
  count: number;
  resetAt: number;
}

// In-memory rate limiting store (per worker instance)
const rateLimitStore = new Map<string, RateLimitEntry>();

// Rate limiting: 10 requests per minute per IP
function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  const limit = 10;
  const windowMs = 60000; // 1 minute

  const entry = rateLimitStore.get(ip);
  
  if (!entry || now > entry.resetAt) {
    rateLimitStore.set(ip, { count: 1, resetAt: now + windowMs });
    return true;
  }
  
  if (entry.count >= limit) {
    return false;
  }
  
  entry.count++;
  return true;
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    // CORS headers
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, X-API-Secret',
    };

    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    // Only allow POST requests
    if (request.method !== 'POST') {
      return new Response(JSON.stringify({ error: 'Method not allowed' }), {
        status: 405,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
      });
    }

    try {
      // Get client IP for rate limiting
      const clientIp = request.headers.get('CF-Connecting-IP') || 'unknown';
      
      // Check rate limit
      if (!checkRateLimit(clientIp)) {
        return new Response(JSON.stringify({ error: 'Rate limit exceeded. Max 10 requests per minute.' }), {
          status: 429,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        });
      }

      // Validate API secret
      const apiSecret = request.headers.get('X-API-Secret');
      if (!apiSecret || apiSecret !== env.API_SECRET) {
        return new Response(JSON.stringify({ error: 'Unauthorized' }), {
          status: 401,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        });
      }

      // Check cache first (5 minute TTL)
      const cacheKey = new Request(request.url, { method: 'GET' });
      const cache = (caches as any).default;
      let cachedResponse = await cache.match(cacheKey);
      
      if (cachedResponse) {
        const response = new Response(cachedResponse.body, cachedResponse);
        response.headers.set('X-Cache-Status', 'HIT');
        Object.entries(corsHeaders).forEach(([key, value]) => {
          response.headers.set(key, value);
        });
        return response;
      }

      // Fetch metrics from Support Hub API
      const metricsUrl = `${env.SUPPORT_HUB_URL}/api/admin/metrics`;
      const metricsResponse = await fetch(metricsUrl, {
        headers: {
          'Accept': 'application/json',
        },
      });

      if (!metricsResponse.ok) {
        throw new Error(`Failed to fetch metrics: ${metricsResponse.statusText}`);
      }

      const metrics = await metricsResponse.json();

      // Prepare prompt for AI
      const prompt = `You are an executive assistant analyzing fire department support services data. Generate a concise executive summary with actionable insights.

Data:
- Apparatuses: ${metrics.apparatuses?.total || 0} total (${metrics.apparatuses?.in_service || 0} in service, ${metrics.apparatuses?.out_of_service || 0} out of service)
- Due Inspections: ${metrics.inspections?.due_soon || 0} due soon, ${metrics.inspections?.overdue || 0} overdue
- Defects: ${metrics.defects?.open || 0} open, ${metrics.defects?.critical || 0} critical
- Shop Works: ${metrics.shop_works?.in_progress || 0} in progress, ${metrics.shop_works?.pending || 0} pending
- Capital Projects: ${metrics.capital_projects?.active || 0} active, ${metrics.capital_projects?.on_budget || 0} on budget
- Uniforms: ${metrics.uniforms?.pending || 0} pending orders

Provide:
1. A brief markdown-formatted summary (2-3 paragraphs)
2. Top 3 action items
3. Top 2-3 risks or concerns

Format your response as:
SUMMARY:
[Your summary here]

ACTION_ITEMS:
- [Item 1]
- [Item 2]
- [Item 3]

RISKS:
- [Risk 1]
- [Risk 2]`;

      // Call Workers AI
      const aiResponse = await env.AI.run('@cf/meta/llama-2-7b-chat-int8', {
        messages: [
          { role: 'system', content: 'You are a helpful executive assistant for a fire department.' },
          { role: 'user', content: prompt }
        ],
      });

      // Parse AI response
      const aiText = aiResponse.response || '';
      
      // Extract sections from AI response
      const summaryMatch = aiText.match(/SUMMARY:\s*([\s\S]*?)(?=ACTION_ITEMS:|$)/i);
      const actionItemsMatch = aiText.match(/ACTION_ITEMS:\s*([\s\S]*?)(?=RISKS:|$)/i);
      const risksMatch = aiText.match(/RISKS:\s*([\s\S]*?)$/i);

      const summary = summaryMatch ? summaryMatch[1].trim() : 'No summary generated.';
      const actionItems = actionItemsMatch 
        ? actionItemsMatch[1].trim().split('\n').filter((item: string) => item.trim()).map((item: string) => item.replace(/^-\s*/, '').trim())
        : [];
      const risks = risksMatch 
        ? risksMatch[1].trim().split('\n').filter((item: string) => item.trim()).map((item: string) => item.replace(/^-\s*/, '').trim())
        : [];

      // Build response
      const result = {
        summary_markdown: summary,
        action_items: actionItems.slice(0, 3),
        risks: risks.slice(0, 3),
        generated_at: new Date().toISOString(),
      };

      // Create response
      const response = new Response(JSON.stringify(result), {
        headers: {
          ...corsHeaders,
          'Content-Type': 'application/json',
          'Cache-Control': 'public, max-age=300', // 5 minutes
          'X-Cache-Status': 'MISS',
        },
      });

      // Cache the response
      await cache.put(cacheKey, response.clone());

      return response;

    } catch (error: any) {
      console.error('Error:', error);
      return new Response(JSON.stringify({ 
        error: 'Internal server error',
        message: error.message 
      }), {
        status: 500,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
      });
    }
  },
};

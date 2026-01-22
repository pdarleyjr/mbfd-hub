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

    const url = new URL(request.url);

    // Route: Inventory Chat Assistant
    if (url.pathname === '/ai/inventory-chat' && request.method === 'POST') {
      try {
        // Verify API secret
        const apiSecret = request.headers.get('x-api-secret');
        if (apiSecret !== env.API_SECRET) {
          return new Response('Unauthorized', { status: 401 });
        }

        // Parse request
        const body = await request.json();
        const userMessage = body.message;
        const inventoryContext = body.inventory_context || {}; // Pre-fetched inventory data from Laravel

        // Call Workers AI with function calling/JSON mode
        const aiResponse = await env.AI.run('@cf/meta/llama-2-7b-chat-int8', {
          messages: [
            {
              role: 'system',
              content: `You are an inventory management assistant for Miami Beach Fire Department. 
Current inventory context: ${JSON.stringify(inventoryContext).substring(0, 500)}

When user requests inventory adjustments, respond with STRICTLY this JSON structure:
{
  "assistant_message": "Your conversational response",
  "proposed_actions": [
    {
      "action": "decrease_stock" | "increase_stock" | "set_stock" | "move_location",
      "equipment_item_id": <number>,
      "qty": <number>,
      "location_id": <number or null>,
      "reason": "string explanation",
      "confidence": <0.0 to 1.0>
    }
  ],
  "needs_confirmation": true,
  "confirmation_prompt": "Human-readable confirmation request",
  "disambiguation": []
}

If user query is ambiguous or multiple items match, use "disambiguation" array.
NEVER directly mutate inventory - always return proposed_actions for confirmation.`,
            },
            {
              role: 'user',
              content: userMessage,
            },
          ],
          stream: false,
        });

        // Parse AI response
        let parsedResponse;
        try {
          // Try to extract JSON from response
          const responseText = aiResponse.response || JSON.stringify(aiResponse);
          const jsonMatch = responseText.match(/\{[\s\S]*\}/);
          
          if (jsonMatch) {
            parsedResponse = JSON.parse(jsonMatch[0]);
          } else {
            // Fallback: AI didn't return proper JSON
            parsedResponse = {
              assistant_message: responseText,
              proposed_actions: [],
              needs_confirmation: false,
              confirmation_prompt: null,
              disambiguation: [],
            };
          }
        } catch (parseError) {
          parsedResponse = {
            assistant_message: "I had trouble understanding that request. Could you rephrase it?",
            proposed_actions: [],
            needs_confirmation: false,
            confirmation_prompt: null,
            disambiguation: [],
          };
        }

        return new Response(JSON.stringify(parsedResponse), {
          headers: { 'content-type': 'application/json' },
        });
      } catch (error: any) {
        return new Response(JSON.stringify({ error: error.message }), {
          status: 500,
          headers: { 'content-type': 'application/json' },
        });
      }
    }

    // Route: Smart Updates (default endpoint)
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

      // Prepare prompt for AI with inventory insights
      const prompt = `You are an executive assistant analyzing Miami Beach Fire Department support services data. Generate a concise executive summary with actionable insights.

Current Status:
- Apparatuses: ${metrics.apparatuses?.total || 0} total (${metrics.apparatuses?.in_service || 0} in service, ${metrics.apparatuses?.out_of_service || 0} out of service)
- Open Defects: ${metrics.defects?.open || 0} (${metrics.defects?.critical || 0} critical/missing items)
- Inspections Today: ${metrics.inspections?.today || 0}

Fire Equipment Inventory:
- Total Active Items: ${metrics.inventory?.total_items || 0}
- Out of Stock: ${metrics.inventory?.out_of_stock || 0}
- Low Stock: ${metrics.inventory?.low_stock || 0}
- Pending Replacement Recommendations: ${metrics.inventory?.pending_recommendations || 0}
- Allocations This Week: ${metrics.inventory?.allocations_this_week || 0}

${metrics.critical_stock_items && metrics.critical_stock_items.length > 0 ? 
  `Critical Low Stock Items:\n${metrics.critical_stock_items.map((item: any) => 
    `- ${item.name}: ${item.stock}/${item.reorder_min} (Location: ${item.location})`
  ).join('\n')}` : ''}

${metrics.top_missing_items && Object.keys(metrics.top_missing_items).length > 0 ?
  `Frequently Missing Items:\n${Object.entries(metrics.top_missing_items).map(([item, freq]) =>
    `- ${item}: reported ${freq} times`
  ).join('\n')}` : ''}

Provide:
1. A brief markdown-formatted summary (2-3 paragraphs) highlighting inventory status and operational readiness
2. Top 3 action items (prioritize inventory reorders and pending defect resolutions)
3. Top 2-3 risks or concerns (focus on out-of-stock critical items)

If there are out-of-stock items that match frequently missing items, flag this as CRITICAL.

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

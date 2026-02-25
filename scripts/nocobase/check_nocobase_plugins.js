#!/usr/bin/env node
// Check what datasource types and plugins are available in NocoBase
const http = require('http');

function req(method, path, data, headers) {
  return new Promise((resolve, reject) => {
    const body = data ? JSON.stringify(data) : null;
    const opts = {
      hostname: '127.0.0.1', port: 13000, path, method,
      headers: { 'Content-Type': 'application/json', ...headers,
        ...(body ? { 'Content-Length': Buffer.byteLength(body) } : {}) }
    };
    const r = http.request(opts, (re) => {
      let d = ''; re.on('data', c => d += c);
      re.on('end', () => { try { resolve({ s: re.statusCode, b: JSON.parse(d) }); } catch (_) { resolve({ s: re.statusCode, b: d }); } });
    });
    r.on('error', reject);
    if (body) r.write(body);
    r.end();
  });
}

(async () => {
  const auth = await req('POST', '/api/auth:signIn', { email: 'admin@nocobase.com', password: 'admin123' });
  const tok = auth.b.data.token;
  const hdrs = { Authorization: 'Bearer ' + tok };

  console.log('=== Current DataSources ===');
  const ds = await req('GET', '/api/dataSources', null, hdrs);
  console.log('Status:', ds.s);
  console.log(JSON.stringify(ds.b, null, 2).slice(0, 2000));

  console.log('\n=== Available plugins (pm.plugins) ===');
  const plugins = await req('GET', '/api/pm.plugins', null, hdrs);
  console.log('Status:', plugins.s);
  const pluginData = plugins.b?.data || [];
  const dsPlugins = pluginData.filter(p => 
    JSON.stringify(p).toLowerCase().includes('datasource') || 
    JSON.stringify(p).toLowerCase().includes('http') ||
    JSON.stringify(p).toLowerCase().includes('api')
  );
  console.log('DataSource-related plugins:', JSON.stringify(dsPlugins.map(p => ({ name: p.name, status: p.status, enabled: p.enabled })), null, 2));
})().catch(console.error);

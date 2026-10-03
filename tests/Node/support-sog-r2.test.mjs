import assert from 'node:assert/strict';
import test from 'node:test';
import worker from '../../cloudflare-worker/src/index.ts';

const manifest = '82a32d7127e42604f77f591e39a26341844b2c7010e5f1b148d20472aaf20b6c';
const namespace = 'sog-r2-82a32d7127e4';
const referenceNamespace = 'mbfd-support-reference';
function request(path, body, authorized = true) {
    return new Request(`https://worker.test${path}`, {
        method: 'POST', headers: {'Content-Type':'application/json', ...(authorized ? {'x-api-secret':'fixture-secret'} : {})},
        body: JSON.stringify(body),
    });
}
function env(overrides = {}) {
    return {
        INGEST_SECRET:'fixture-secret', ALLOWED_ORIGIN:'https://www.mbfdhub.com',
        SOG_NAMESPACE:namespace, SOG_MANIFEST_SHA256:manifest,
        REFERENCE_NAMESPACE:referenceNamespace,
        AI:{run:async (_model, {text}) => ({data:text.map(() => [1, 0])})},
        VECTORIZE:{query:async () => ({matches:[]}), upsert:async () => ({mutationId:'fixture-mutation'})},
        ...overrides,
    };
}
test('direct chat requires the existing Hub proxy secret before retrieval', async () => {
    let retrieved = false;
    const response = await worker.fetch(request('/chat',{message:'policy'},false), env({
        AI:{run:async () => {retrieved=true;throw new Error('should not embed');}},
    }));
    assert.equal(response.status,401);
    assert.equal(retrieved,false);
});
test('missing current-generation configuration fails before querying the whole index', async () => {
    let retrieved = false;
    const response = await worker.fetch(request('/chat',{message:'policy'}), env({
        SOG_NAMESPACE:undefined, SOG_MANIFEST_SHA256:undefined,
        AI:{run:async () => {retrieved=true;throw new Error('should not embed');}},
    }));
    assert.equal(response.status,503);
    assert.equal(retrieved,false);
});
test('current SOG context excludes retired sources and older namespaces while preserving apparatus answers', async () => {
    const calls = [];
    const current = {id:'r2',score:0.9,namespace,metadata:{manifest_sha256:manifest,source:'Section_800_R2.pdf',page:8,primary_ids:'800.P02',url:'https://files.mbfdhub.com/current-sog/SECTION-800?page=8',text:'Use the current reporting route.'}};
    const runtime = env({VECTORIZE:{query:async (_vector, options) => {
        calls.push(options);
        return {matches:options.namespace === namespace ? [
            current,
            {...current,id:'wrong-manifest',metadata:{...current.metadata,manifest_sha256:'0'.repeat(64),text:'RETIRED GENERATION'}},
            {...current,id:'wrong-namespace',namespace:'sog-r1',metadata:{...current.metadata,text:'RETIRED NAMESPACE'}},
        ] : [
            {id:'sog-old',score:1,namespace:referenceNamespace,metadata:{source:'support_sog',text:'RETIRED POLICY'}},
            {id:'repair-old',score:1,namespace:referenceNamespace,metadata:{source:'extra_info_for_AI.pdf',text:'RETIRED REPORTING'}},
            {id:'apparatus',score:0.8,namespace:referenceNamespace,metadata:{source:'L3_manual.pdf',text:'Technical pump information.'}},
            {id:'driver-history',score:1,namespace:'mbfd-driver-legacy-history',metadata:{source:'driver_manual',text:'RETIRED DRIVER POLICY'}},
            {...current,id:'mixed-generation',namespace:'sog-r1',metadata:{...current.metadata,text:'RETIRED CROSS-NAMESPACE'}},
        ]};
    }}});
    const previousFetch = globalThis.fetch;
    let messages;
    globalThis.fetch = async (_url, options) => {
        messages = JSON.parse(options.body).messages;
        return Response.json({choices:[{message:{content:'Current source answer'}}]});
    };
    try {
        const response = await worker.fetch(request('/chat',{message:'How do I report a defect?'}),runtime);
        assert.equal(response.status,200);
        assert.deepEqual((await response.json()).sources,['Section_800_R2.pdf','L3_manual.pdf']);
        assert.deepEqual(calls.map(call => call.namespace),[namespace,referenceNamespace]);
        assert.ok(calls.every(call => call.returnMetadata === 'all'));
        const prompt = messages.map(message => message.content).join('\n');
        assert.match(prompt,/CURRENT SOG/);
        assert.match(prompt,/current-sog\/SECTION-800\?page=8/);
        assert.match(prompt,/800\.P02/);
        assert.match(prompt,/Technical pump information/);
        assert.match(prompt,/stated model\/configuration scope/);
        assert.doesNotMatch(prompt,/driver_manual\.pdf.*Authoritative/);
        assert.doesNotMatch(prompt,/RETIRED|edited_support_services_sog|CRITICAL OVERRIDE|786-559-4054/);
    } finally { globalThis.fetch = previousFetch; }
});
test('an admin upload cannot restore a retired SOG or reporting supplement', async () => {
    for (const source of ['support_sog','edited_support_services_sog.docx','Support_SOG.PDF','extra_info_for_AI.pdf','driver_manual.pdf']) {
        const response = await worker.fetch(request('/ingest',{source,text:'old policy text'}),env());
        assert.equal(response.status,422,source);
    }
});

test('policy refusal with apparatus references preserves the existing SSE contract', async () => {
    const previousFetch=globalThis.fetch;
    globalThis.fetch=async () => {throw new Error('missing current SOG must not call generation');};
    try {
        const response=await worker.fetch(request('/chat',{message:'What is the equipment reporting policy?',stream:true}),env({
            VECTORIZE:{query:async (_vector,options) => ({matches: options.namespace===referenceNamespace
                ? [{score:0.9,namespace:referenceNamespace,metadata:{source:'L3_manual.pdf',text:'Manufacturer technical reference'}}] : []})},
        }));
        assert.equal(response.headers.get('Content-Type'),'text/event-stream');
        assert.equal(response.headers.get('X-Sources'),'[]');
        const events=(await response.text()).split('\n\n').filter(Boolean).map(event=>event.slice(6));
        assert.match(JSON.parse(events[0]).response,/current documents/);
        assert.equal(events[1],'[DONE]');
    } finally {globalThis.fetch=previousFetch;}
});

test('known after-hours technician policy cannot use reference or history fallback in JSON or SSE', async () => {
    const previousFetch=globalThis.fetch;
    let bridgeCalls=0;
    globalThis.fetch=async () => {bridgeCalls++;throw new Error('no current SOG for this policy');};
    try {
        for (const stream of [false,true]) {
            const response=await worker.fetch(request('/chat',{
                message:'Who can call a technician after hours?',stream,
                history:[{role:'assistant',content:'An older unsupported technician-call policy.'}],
            }),env({VECTORIZE:{query:async (_vector,options) => ({matches:options.namespace===referenceNamespace
                ? [{score:0.9,namespace:referenceNamespace,metadata:{source:'L3_manual.pdf',text:'Technical apparatus reference.'}}] : []})}}));
            assert.equal(response.status,200);
            if (stream) {
                assert.equal(response.headers.get('Content-Type'),'text/event-stream');
                const events=(await response.text()).split('\n\n').filter(Boolean).map(event=>event.slice(6));
                assert.match(JSON.parse(events[0]).response,/current documents/);
                assert.equal(events[1],'[DONE]');
            } else {
                const body=await response.json();
                assert.match(body.response,/current documents/);
                assert.deepEqual(body.sources,[]);
            }
        }
        assert.equal(bridgeCalls,0);
    } finally {globalThis.fetch=previousFetch;}
});
test('apparatus ingestion avoids a duplicate trailing chunk and keeps the final text', async () => {
    const vectors = [];
    const text = 'Apparatus technical instructions '.repeat(100).slice(0,2690)+'FINAL-TAIL';
    const response = await worker.fetch(request('/ingest',{source:'apparatus-fixture.txt',text}),env({
        VECTORIZE:{upsert:async batch => {vectors.push(...batch);return {mutationId:'fixture-mutation'};}},
    }));
    assert.equal(response.status,200);
    assert.equal(vectors.length,2);
    assert.ok(vectors.at(-1).metadata.text.endsWith('FINAL-TAIL'));
    assert.ok(vectors.every(vector => vector.metadata.source === 'apparatus-fixture.txt'));
    assert.ok(vectors.every(vector => vector.namespace === referenceNamespace));
});

test('empty or below-threshold retrieval cannot answer from conversation history', async () => {
    const previousFetch=globalThis.fetch;
    globalThis.fetch=async () => {throw new Error('empty corpus must not call generation');};
    try {
        const response=await worker.fetch(request('/chat',{message:'old policy',history:[{role:'assistant',content:'An old unsupported policy answer.'}]}),env({
            VECTORIZE:{query:async () => ({matches:[{score:0.1,namespace,metadata:{manifest_sha256:manifest,text:'low confidence'}}]})},
        }));
        assert.equal(response.status,200);
        const result=await response.json();
        assert.match(result.response,/current documents/);
        assert.deepEqual(result.sources,[]);
    } finally {globalThis.fetch=previousFetch;}
});

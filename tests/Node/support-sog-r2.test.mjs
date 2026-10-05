import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import worker from '../../cloudflare-worker/src/index.ts';

const manifest = '82a32d7127e42604f77f591e39a26341844b2c7010e5f1b148d20472aaf20b6c';
const namespace = 'sog-r2-82a32d7127e4';
const referenceNamespace = 'mbfd-support-reference';
let requestNumber = 0;
function request(path, body, authorized = true) {
    return new Request(`https://worker.test${path}`, {
        method: 'POST', headers: {'Content-Type':'application/json', 'CF-Connecting-IP':`fixture-${++requestNumber}`, ...(authorized ? {'x-api-secret':'fixture-secret'} : {})},
        body: JSON.stringify(body),
    });
}
function env(overrides = {}) {
    return {
        INGEST_SECRET:'fixture-secret', ALLOWED_ORIGIN:'https://www.mbfdhub.com',
        SOG_NAMESPACE:namespace, SOG_MANIFEST_SHA256:manifest,
        REFERENCE_NAMESPACE:referenceNamespace,
        AI:{run:async (_model, {text}) => ({data:text.map(() => [1, 0])})},
        VECTORIZE:{query:async () => ({matches:[]}), getByIds:async () => [], upsert:async () => ({mutationId:'fixture-mutation'})},
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
test('policy passages visibly exclude apparatus references, retired generations and history', async () => {
    const calls = [];
    const completed = [];
    const current = {id:'r2',score:0.9,namespace,metadata:{manifest_sha256:manifest,asset_id:'SECTION-800',chunk_index:0,source:'Section_800_R2.pdf',page:8,primary_ids:'800.P02',url:'https://files.mbfdhub.com/current-sog/SECTION-800?page=8',text:'Use the current reporting route.'}};
    const runtime = env({VECTORIZE:{getByIds:async ids => {const chunks=ids.map((id,chunk_index)=>({...current,id,metadata:{...current.metadata,chunk_index}}));completed.push(...chunks);return chunks;},query:async (_vector, options) => {
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
    let bridgeCalls=0;
    const poison='300 (Logistics/Dispatch center) and PSCD (Premier Shift Command Director/Suppression Division Chief).';
    globalThis.fetch = async () => {
        bridgeCalls++;
        return Response.json({choices:[{message:{content:poison},finish_reason:'stop'}],usage:{prompt_tokens:1,completion_tokens:1}});
    };
    try {
        const response = await worker.fetch(request('/chat',{message:'How do I report a defect?',history:[{role:'assistant',content:'OLD POLICY FROM HISTORY'}]}),runtime);
        assert.equal(response.status,200);
        const result = await response.json();
        assert.equal(bridgeCalls,0,'policy passages must not invoke generation');
        assert.match(result.response,/current (?:policy|sog)(?: source)? passages/i);
        assert.deepEqual(result.sources,['Section_800_R2.pdf']);
        assert.deepEqual(result.reference_citations,[]);
        assert.equal('model' in result,false);
        assert.equal('completion' in result,false);
        assert.deepEqual(calls.map(call => call.namespace),[namespace,referenceNamespace]);
        assert.ok(calls.every(call => call.returnMetadata === 'all'));
        assert.deepEqual(result.citations.map(c=>c.id),completed.map(chunk=>chunk.id));
        let offset=0;
        for(const chunk of completed) {
            const position=result.response.indexOf(chunk.metadata.text,offset);
            assert.ok(position>=offset,'each completed chunk must retain its exact source wording');
            offset=position+chunk.metadata.text.length;
        }
        assert.match(result.response,/current-sog\/SECTION-800\?page=8/);
        assert.match(result.response,/800\.P02/);
        assert.doesNotMatch(result.response,/Technical pump information|L3_manual|OLD POLICY FROM HISTORY/);
        assert.doesNotMatch(result.response,/RETIRED|edited_support_services_sog|CRITICAL OVERRIDE|786-559-4054/);
        assert.ok(!result.response.includes(poison));
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

function currentChunk(page, chunk_index, text, primary_ids) {
    const asset_id = 'SECTION-800';
    return {
        id:createHash('sha256').update(`${manifest}:${asset_id}:${page}:${chunk_index}`).digest('hex'),
        namespace, score:0.9,
        metadata:{manifest_sha256:manifest,asset_id,page,chunk_index,primary_ids,text,
            source_sha256:'c8f4110d83dbe510c17a14bf714c2485eba153fc4b1f4aa2eebad2ff220a338e',
            source:'MBFD_Section_800.pdf',url:`https://files.mbfdhub.com/current-sog/${asset_id}?page=${page}`},
    };
}

test('emergency-driving and FDC arrangement questions require current policy in JSON and SSE', async () => {
    const previousFetch=globalThis.fetch;
    let bridgeCalls=0;
    globalThis.fetch=async () => {bridgeCalls++;throw new Error('apparatus reference cannot establish this policy');};
    try {
        for (const message of [
            'During an emergency response, what must the driver do at a red light or stop sign if preemption and sirens are operating? Must the driver also stop at every clear ordinary green light?',
            'Does 250 psi alone determine an FDC or standpipe pressure setting or automatically trigger tandem pumping, and what must be verified before choosing the pressure and arrangement?',
        ]) {
            for (const stream of [false,true]) {
                const response=await worker.fetch(request('/chat',{message,stream}),env({
                    VECTORIZE:{query:async (_vector,options)=>({matches:options.namespace===referenceNamespace
                        ? [{namespace:referenceNamespace,score:0.9,metadata:{source:'l3',text:'Manufacturer instructions'}}] : []})},
                }));
                assert.equal(response.status,200);
                const text=await response.text();
                assert.match(text,/current documents/);
                if(stream) assert.match(text,/data: \[DONE\]/);
            }
        }
        assert.equal(bridgeCalls,0);
    } finally {globalThis.fetch=previousFetch;}
});

test('policy page completion follows the actual 800.P01 V link and adds verified JSON/SSE citations', async () => {
    // Exact excerpts from the frozen R2 pages, including their conditional scope.
    const chunks=[
        currentChunk(34,1,'Replacement requests shall include the required Lost/Damaged/Stolen equipment record endorsed by the Company Officer and Suppression Division Chief.','800.P03'),
        currentChunk(34,2,'Use one linked authoritative history, not duplicate daily/logbook narratives. Follow 800.P01 V for acknowledgment, escalation and paper/scanned contingency.','800.P03'),
        currentChunk(13,2,'Fleet/Logistics acknowledges the defect with a named owner, priority, interim restriction and next action/review point, and provides the disposition to the Company Officer and 300. During a platform outage use the approved paper contingency record; submit a legible scan through the designated secure process on restoration and reconcile linked records under 800.P01 V. An automated ticket number is not technical acceptance. G. After hours, only 300 or the ranking officer on the unit initiates the Logistics service call through the authorized on-call Fleet/Logistics contact. If that contact is unavailable, the officer escalates through 300 to the Logistics Captain or Logistics Division Chief for authorized technical coordination. Do not independently call an off-duty technician. While assistance is arranged, 300 decides coverage/change-out and confirms the limitation with PSCD. An unanswered call or closed shop does not remove the restriction or the duty to protect people.','800.P02'),
        currentChunk(4,1,'The Company Officer reviews unresolved safety and readiness items at handoff and contacts the receiving owner directly when acknowledgment, a due action or protection is missing. Unresolved operational impact goes to 300; unresolved Logistics action goes to the Logistics Captain and then Logistics Division Chief.','800.P01'),
        currentChunk(5,2,'When the platform is unavailable, use the approved paper contingency record for that function and preserve required signatures, original event time and supporting evidence. Continue direct urgent notification. On restoration, the submitting member sends a legible scanned copy through the designated secure process; the receiving owner reconciles duplicate, missing or incomplete entries and links the paper record to the authoritative electronic history.','800.P01'),
        currentChunk(4,0,'Section 800 — Logistics\nSECTION-800; physical page 4; MBFD-COORDINATED-20261002-R2\n800.P01 — Logistics Asset Control','800.P01'),
        currentChunk(4,2,'hicle checkout - assigned Driver \nEngineer at the start of each shift, after \nuse or change-out; Company Officer \nreviews before declaring readiness.\nFleet receives linked defects;','800.P01'),
        currentChunk(5,0,'Section 800 — Logistics\nSECTION-800; physical page 5; MBFD-COORDINATED-20261002-R2\n800.P01 — Logistics Asset Control','800.P01'),
        currentChunk(5,1,'ive owner, due action, \nevidence, reinspection and unresolved items, not just a \nclosed invoice.','800.P01'),
        currentChunk(13,0,'Section 800 — Logistics\nSECTION-800; physical page 13; MBFD-COORDINATED-20261002-R2\n800.P02 — Fleet Readiness and Maintenance','800.P02'),
        currentChunk(13,1,'mmediately available, 300 shall arrange an operational coverage/resource alternative and advise \nPSCD of the limitation. Lack of a reserve does not make defective apparatus safe','800.P02'),
        currentChunk(13,3,'with PSCD. An unanswered call or \nclosed shop does not remove the restriction or the duty to protect people.','800.P02'),
        currentChunk(34,0,'Section 800 — Logistics\nSECTION-800; physical page 34; MBFD-COORDINATED-20261002-R2\n800.P03 — Equipment Maintenance and Monitoring','800.P03'),
    ];
    const previousFetch=globalThis.fetch;
    const ordered=[...chunks].sort((a,b)=>a.metadata.page-b.metadata.page||a.metadata.chunk_index-b.metadata.chunk_index);
    const responses=[];
    let bridgeCalls=0;
    const poison='300 (Logistics/Dispatch center) and PSCD (Premier Shift Command Director/Suppression Division Chief).';
    globalThis.fetch=async (_url,options)=>{
        bridgeCalls++;
        const body=JSON.parse(options.body);
        return body.stream
            ? new Response('data: '+JSON.stringify({choices:[{delta:{content:poison},finish_reason:'stop'}]})+'\n\ndata: [DONE]\n\n')
            : Response.json({choices:[{message:{content:poison},finish_reason:'stop'}],usage:{prompt_tokens:1,completion_tokens:1}});
    };
    try {
        for (const stream of [false,true]) {
            const gets=[];
            const runtime=env({VECTORIZE:{
                query:async (_vector,options)=>({matches:options.namespace===namespace ? [chunks[0],chunks[2]]
                    : [{namespace:referenceNamespace,score:1,metadata:{source:'l3',text:'NHTSA AND HIGH VOLTAGE REFERENCE'}}]}),
                getByIds:async ids=>{gets.push(ids);return chunks.filter(chunk=>ids.includes(chunk.id));},
            }});
            const response=await worker.fetch(request('/chat',{
                message:'If I find damaged suppression equipment or an urgent apparatus defect affecting response capability, whom must I notify, how do I record and hand off the problem, and what do I do if the platform or acknowledgment is unavailable?',stream,
                history:[{role:'assistant',content:'OLD POLICY FROM HISTORY'}],
            }),runtime);
            assert.equal(response.status,200);
            assert.ok(gets.length<=3 && gets.every(ids=>ids.length<=20));
            let result;
            if(stream) {
                assert.equal(response.headers.get('Content-Type'),'text/event-stream');
                assert.equal(response.headers.get('Cache-Control'),'no-cache');
                const sources=JSON.parse(response.headers.get('X-Sources'));
                const events=(await response.text()).split('\n\n').filter(Boolean).map(event=>event.slice(6));
                assert.equal(events.at(-1),'[DONE]');
                const parts=events.slice(0,-1).map(event=>JSON.parse(event));
                assert.ok(parts.every(part=>!('model' in part)&&!('completion' in part)));
                const cited=parts.find(part=>Array.isArray(part.citations));
                assert.ok(cited,'SSE must preserve the completed current citations');
                result={response:parts.map(part=>part.response||'').join(''),sources,
                    citations:cited.citations,reference_citations:cited.reference_citations};
            } else {
                const body=await response.json();
                assert.equal('model' in body,false);
                assert.equal('completion' in body,false);
                result={response:body.response,sources:body.sources,citations:body.citations,reference_citations:body.reference_citations};
            }
            assert.equal(bridgeCalls,0,'policy passages must never invoke the poisoned bridge');
            assert.match(result.response,/current (?:policy|sog)(?: source)? passages/i);
            assert.deepEqual(result.sources,['MBFD_Section_800.pdf']);
            assert.deepEqual(result.reference_citations,[]);
            assert.deepEqual(result.citations.map(c=>c.id),ordered.map(chunk=>chunk.id));
            assert.ok(result.citations.every(c=>c.namespace===namespace&&c.manifest_sha256===manifest));
            assert.ok(result.citations.every(c=>c.source_sha256===chunks[0].metadata.source_sha256));
            let offset=0;
            for(const chunk of ordered) {
                const position=result.response.indexOf(chunk.metadata.text,offset);
                assert.ok(position>=offset,'physical page '+chunk.metadata.page+', chunk '+chunk.metadata.chunk_index+' must retain exact source wording/order');
                offset=position+chunk.metadata.text.length;
            }
            assert.match(result.response,/Company Officer reviews unresolved safety and readiness items at handoff/);
            assert.match(result.response,/Logistics Captain and then Logistics Division Chief/);
            assert.match(result.response,/preserve required signatures, original event time and supporting evidence/);
            assert.match(result.response,/contacts the receiving owner directly when acknowledgment, a due action or protection is missing/);
            assert.doesNotMatch(result.response,/NHTSA AND HIGH VOLTAGE REFERENCE|OLD POLICY FROM HISTORY|RETIRED/);
            assert.ok(!result.response.includes(poison));
            assert.doesNotMatch(result.response,/Logistics\/Dispatch center|Premier Shift Command Director/);
            for(const page of [4,5,13,34]) assert.ok(result.response.includes('https://files.mbfdhub.com/current-sog/SECTION-800?page='+page));
            responses.push(result);
        }
        assert.deepEqual(responses[0],responses[1],'JSON and SSE must deliver identical passages, sources and citations');
        assert.equal(bridgeCalls,0);
    } finally {globalThis.fetch=previousFetch;}
});

test('L3 technical questions keep references without invented SOG provenance', async () => {
    const previousFetch=globalThis.fetch;
    let prompt;
    globalThis.fetch=async (_url,options)=>{
        const body=JSON.parse(options.body);prompt=body.messages.at(-1).content;
        assert.equal(body.model,'qwen3.6:35b');
        assert.equal(body.max_tokens,1024);
        assert.equal(body.temperature,0.3);
        assert.equal(body.reasoning_effort,'none');
        assert.equal('stream_options' in body,false);
        return body.stream
            ? new Response('data: {"choices":[{"delta":{"content":"MENU > MAINTENANCE; alternator, engine, transmission and pump."},"finish_reason":"length"}]}\n\ndata: {"choices":[],"usage":{"prompt_tokens":17,"completion_tokens":1024,"total_tokens":1041}}\n\ndata: [DONE]\n\n')
            : Response.json({choices:[{message:{content:'MENU > MAINTENANCE; alternator, engine, transmission and pump.'},finish_reason:'length'}],
                usage:{prompt_tokens:17,completion_tokens:1024,total_tokens:1041}});
    };
    try {
        for(const stream of [false,true]) {
        const response=await worker.fetch(request('/chat',{message:'On the L3 Pierce Command Zone III display, where is the Maintenance menu, and which vehicle systems does its prognostic information cover?',stream}),env({
            VECTORIZE:{query:async (_vector,options)=>({matches:options.namespace===referenceNamespace
                ? [{id:'l3-original',namespace:referenceNamespace,score:0.9,metadata:{source:'l3',chunk_index:63,text:'MENU > MAINTENANCE; alternator, engine, transmission and pump.'}}] : []})},
        }));
        const events=stream ? (await response.text()).split('\n\n').filter(Boolean).map(event=>event.slice(6)) : null;
        const body=stream ? JSON.parse(events.at(-2)) : await response.json();
        const completion=stream ? JSON.parse(events.at(-3)).completion : body.completion;
        assert.deepEqual(completion,{finish_reason:'length',prompt_tokens:17,completion_tokens:1024});
        if(stream) assert.equal(events.at(-1),'[DONE]');
        if(!stream) assert.deepEqual(body.sources,['l3']);
        else assert.equal(response.headers.get('X-Sources'),'["l3"]');
        assert.deepEqual(body.citations,[]);
        assert.deepEqual(body.reference_citations,[{id:'l3-original',namespace:referenceNamespace,source:'l3',chunk_index:63}]);
        assert.match(body.response,/Reference sources:\n- l3, chunk 63/);
        assert.match(prompt,/MENU > MAINTENANCE/);
        const responseCheck=prompt.split('\n\nRESPONSE CHECK:\n');
        assert.equal(responseCheck.length,2);
        assert.match(responseCheck[1],/applicable/);
        assert.match(responseCheck[1],/source role identifiers without added definitions/);
        assert.match(responseCheck[1],/state any unanswered part/);
        assert.doesNotMatch(responseCheck[1],/300|Logistics|Company Officer|800\.P/);
        assert.doesNotMatch(body.response,/current-sog|physical page/);
        }
    } finally {globalThis.fetch=previousFetch;}
});

test('frozen asset IDs containing dots retain their actual citation URLs', async () => {
    const previousFetch=globalThis.fetch;
    globalThis.fetch=async ()=>Response.json({choices:[{message:{content:'Current source response.'}}]});
    try {
        for(const asset_id of ['400.XX-UAS-R1','500.07-R1']) {
            const source=`${asset_id}.pdf`;
            const current={id:'fixture',namespace,score:0.9,metadata:{asset_id,source,page:1,chunk_index:0,primary_ids:asset_id,
                manifest_sha256:manifest,url:`https://files.mbfdhub.com/current-sog/${asset_id}?page=1`,text:'Current source excerpt.'}};
            const response=await worker.fetch(request('/chat',{message:`Explain current policy ${asset_id}.`}),env({VECTORIZE:{
                query:async (_vector,options)=>({matches:options.namespace===namespace?[current]:[]}),
                getByIds:async ids=>ids.map((id,chunk_index)=>({...current,id,metadata:{...current.metadata,chunk_index}})),
            }}));
            const body=await response.json();
            assert.deepEqual(body.sources,[source]);
            assert.ok(body.citations.every(c=>c.asset_id===asset_id&&c.url===current.metadata.url));
            assert.ok(body.response.includes(current.metadata.url));
        }
    } finally {globalThis.fetch=previousFetch;}
});

test('missing expected page chunks and mismatched getByIds locations fail before generation', async () => {
    const previousFetch=globalThis.fetch;
    let bridgeCalls=0;
    globalThis.fetch=async ()=>{bridgeCalls++;throw new Error('incomplete current page must not generate');};
    try {
        const seed=currentChunk(34,1,'Follow 800.P01 V for acknowledgment, escalation and paper/scanned contingency.','800.P03');
        for(const corruptLocation of [false,true]) {
            const response=await worker.fetch(request('/chat',{message:'What is the damaged equipment reporting policy?'}),env({VECTORIZE:{
                query:async (_vector,options)=>({matches:options.namespace===namespace?[seed]:[]}),
                getByIds:async ids=>corruptLocation ? ids.map((id,chunk_index)=>({...seed,id,metadata:{...seed.metadata,
                    chunk_index,page:35,url:'https://files.mbfdhub.com/current-sog/SECTION-800?page=35'}})) : [],
            }}));
            const body=await response.json();
            assert.match(body.response,/current documents/);
            assert.deepEqual(body.sources,[]);
        }
        assert.equal(bridgeCalls,0);
    } finally {globalThis.fetch=previousFetch;}
});

test('FDC location and green-indicator technical questions retain OEM references', async () => {
    const previousFetch=globalThis.fetch;
    let bridgeCalls=0;
    globalThis.fetch=async ()=>{bridgeCalls++;return Response.json({choices:[{message:{content:'Manufacturer reference.'}}]});};
    try {
        for(const message of ['Where is the FDC connection on the L3 apparatus?','What does the green indicator light on the L3 display mean?']) {
            const response=await worker.fetch(request('/chat',{message}),env({VECTORIZE:{
                query:async (_vector,options)=>({matches:options.namespace===referenceNamespace
                    ? [{id:'l3-original',namespace:referenceNamespace,score:0.9,metadata:{source:'l3',chunk_index:63,text:'Manufacturer reference.'}}] : []}),
            }}));
            const body=await response.json();
            assert.deepEqual(body.sources,['l3']);
            assert.equal(body.reference_citations[0].id,'l3-original');
            assert.doesNotMatch(body.response,/current documents/);
        }
        assert.equal(bridgeCalls,2);
    } finally {globalThis.fetch=previousFetch;}
});

test('upstream SSE EOF requires actual DONE before emitting citations or completion', async () => {
    const previousFetch=globalThis.fetch;
    try {
        for(const completed of [false,true]) {
            globalThis.fetch=async ()=>new Response('data: {"choices":[{"delta":{"content":"Technical answer."},"finish_reason":"stop"}],"usage":{"prompt_tokens":5,"completion_tokens":2}}\n\n'+(completed?'data: [DONE]':''));
            const response=await worker.fetch(request('/chat',{message:'Where is the L3 maintenance menu?',stream:true}),env({VECTORIZE:{
                query:async (_vector,options)=>({matches:options.namespace===referenceNamespace
                    ? [{id:'l3-original',namespace:referenceNamespace,score:0.9,metadata:{source:'l3',chunk_index:63,text:'MENU > MAINTENANCE'}}] : []}),
            }}));
            if(completed) {
                const body=await response.text();
                assert.match(body,/"completion":\{"finish_reason":"stop","prompt_tokens":5,"completion_tokens":2\}/);
                assert.match(body,/Reference sources/);
                assert.match(body,/data: \[DONE\]\n\n$/);
            } else {
                const reader=response.body.getReader();
                const first=new TextDecoder().decode((await reader.read()).value);
                assert.match(first,/Technical answer/);
                assert.doesNotMatch(first,/Reference sources|"completion"|\[DONE\]/);
                await assert.rejects(reader.read(),/before completion/);
            }
        }
    } finally {globalThis.fetch=previousFetch;}
});

test('completion metadata retains only received recognized reasons and safe token counts', async () => {
    const previousFetch=globalThis.fetch;
    const runtime=env({VECTORIZE:{query:async (_vector,options)=>({matches:options.namespace===referenceNamespace
        ? [{id:'l3-original',namespace:referenceNamespace,score:0.9,metadata:{source:'l3',text:'Technical reference.'}}] : []})}});
    try {
        globalThis.fetch=async ()=>Response.json({choices:[{message:{content:'Technical answer.'},finish_reason:'stop'}],
            usage:{prompt_tokens:0,completion_tokens:Number.MAX_SAFE_INTEGER,total_tokens:1},private_reply:'must not escape'});
        let response=await worker.fetch(request('/chat',{message:'Where is the L3 maintenance menu?'}),runtime);
        let body=await response.json();
        assert.deepEqual(body.completion,{finish_reason:'stop',prompt_tokens:0,completion_tokens:Number.MAX_SAFE_INTEGER});
        assert.equal('private_reply' in body,false);

        globalThis.fetch=async ()=>Response.json({choices:[{message:{content:'Technical answer.'},finish_reason:'private upstream value'}],
            usage:{prompt_tokens:-1,completion_tokens:1.5}});
        response=await worker.fetch(request('/chat',{message:'Where is the L3 maintenance menu?'}),runtime);
        body=await response.json();
        assert.equal('completion' in body,false);

        globalThis.fetch=async ()=>new Response('data: {"choices":[{"delta":{"content":"Technical answer."},"finish_reason":"stop"}],"usage":{"prompt_tokens":"5","completion_tokens":9007199254740992,"total_tokens":7}}\n\ndata: [DONE]\n\ndata: {"choices":[{"finish_reason":"length"}],"usage":{"prompt_tokens":9}}\n\n');
        response=await worker.fetch(request('/chat',{message:'Where is the L3 maintenance menu?',stream:true}),runtime);
        const events=(await response.text()).split('\n\n').filter(Boolean).map(event=>event.slice(6));
        assert.deepEqual(JSON.parse(events.at(-3)),{completion:{finish_reason:'stop'}});
        assert.equal(events.at(-1),'[DONE]');
    } finally {globalThis.fetch=previousFetch;}
});

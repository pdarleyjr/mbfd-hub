import http from 'node:http';
import {createReadStream} from 'node:fs';
import {readFile,stat} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const evidence = path.resolve(process.env.POLICY_LIBRARY_BROWSER_FIXTURE || 'var/reader-fixture');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const data = JSON.parse(await readFile(path.join(evidence,'native-baseline.json'),'utf8'));
const port = Number(process.env.LIBRARY_PORT || 8877);
const manuals = data.manuals.map(item => ({...item.tree.manual,active_edition_id:item.edition_id}));
const types={'.js':'text/javascript','.mjs':'text/javascript','.css':'text/css','.pdf':'application/pdf','.png':'image/png','.wasm':'application/wasm','.webp':'image/webp','.woff2':'font/woff2'};
const server=http.createServer(async (request,response)=>{
 try {
  const url=new URL(request.url,`http://127.0.0.1:${port}`);
  const json=value=>{response.writeHead(200,{'Content-Type':'application/json','Cache-Control':'private, no-store'});response.end(JSON.stringify(value));};
  if(url.pathname==='/ready')return json({ready:true});
  if(url.pathname==='/api/manuals')return json({manuals,can_manage:false,manage_url:null});
  if(url.pathname.startsWith('/api/manuals/')){const slug=url.pathname.split('/')[3];const tree=data.manuals.find(item=>item.slug===slug)?.tree;if(tree)return json(tree);}
  if(url.pathname==='/api/search')return json({results:[],has_more:false});
  if(url.pathname==='/api/viewer-errors'){response.writeHead(204);return response.end();}
  if(url.pathname==='/'){
   let html=await readFile(path.join(root,'resources/views/viewer.blade.php'),'utf8');
   html=html.replace(/@php[\s\S]*?@endphp/g,'').replace(/\{\{ asset\('([^']+)'\) \}\}/g,'/$1').replace(/\{\{ \$viewer(?:Style|Script)Version \}\}/g,'baseline').replace('{{ csrf_token() }}','local-test-only');
   response.writeHead(200,{'Content-Type':'text/html','Cache-Control':'no-store'});return response.end(html);
  }
  let filename;
  if(url.pathname.startsWith('/vendor/policy-library/')){filename=path.resolve(root,'public',decodeURIComponent(url.pathname.slice('/vendor/policy-library/'.length)));if(!filename.startsWith(path.join(root,'public')+path.sep))throw Error('Invalid path');}
  if(url.pathname.startsWith('/images/')) filename=path.join(root,'../../public',url.pathname);
  if(url.pathname.startsWith('/assets/')) filename=path.join(evidence,'assets',url.pathname.split('/')[2]+'.pdf');
  if(!filename){response.writeHead(404);return response.end();}
  const {size}=await stat(filename);
  const headers={'Content-Type':types[path.extname(filename)]||'application/octet-stream','Accept-Ranges':'bytes','Cache-Control':'private, no-store','Content-Length':size};
  const match=/^bytes=(\d+)-(\d*)$/.exec(request.headers.range||'');
  let start=0,end=size-1;
  if(match){start=Number(match[1]);end=Math.min(Number(match[2]||size-1),size-1);headers['Content-Range']=`bytes ${start}-${end}/${size}`;headers['Content-Length']=end-start+1;response.writeHead(206,headers);}else response.writeHead(200,headers);
  if(request.method==='HEAD')return response.end();
  const stream=createReadStream(filename,{start,end});response.on('close',()=>stream.destroy());stream.pipe(response);
 }catch(error){response.writeHead(error.code==='ENOENT'?404:500,{'Content-Type':'text/plain'});response.end(error.code==='ENOENT'?'Missing sample':error.message);}
});
server.listen(port,'127.0.0.1',()=>console.log(`Adapter ready on loopback ${port}`));

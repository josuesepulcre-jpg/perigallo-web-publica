import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {spawn,spawnSync} from 'node:child_process';
import {once} from 'node:events';
import {budgetedOpenAiFetch,reservationFor} from '../ops/ai-spend-guard/client.mjs';

test('real shared ledger admits before transport, serializes all apps and fails closed',async()=>{
 const dir=await fs.mkdtemp(path.join(os.tmpdir(),'eur-real-')),socket=path.join(dir,'s');
 const module=path.resolve('ops/ai-spend-guard/guard.py');
 const code=`import importlib.util,os\ns=importlib.util.spec_from_file_location('g',${JSON.stringify(module)})\ng=importlib.util.module_from_spec(s);s.loader.exec_module(g)\ng.initialize(${JSON.stringify(path.join(dir,'db'))})\nwith g.Server(${JSON.stringify(socket)},g.Handler) as server:\n server.database=${JSON.stringify(path.join(dir,'db'))}\n print('ready',flush=True)\n server.serve_forever()\n`;
 const child=spawn('python3',['-c',code],{stdio:['ignore','pipe','pipe']});
 const old=process.env.AI_SPEND_GUARD_SOCKET;process.env.AI_SPEND_GUARD_SOCKET=socket;
 let sends=0;
 const body=JSON.stringify({model:'gpt-4.1-mini',input:'Texto sintético',max_output_tokens:1000});
 const send=(app)=>budgetedOpenAiFetch(app,'https://api.openai.com/v1/responses',{method:'POST',body},async(_url,options)=>{sends++;assert.equal(JSON.parse(options.body).service_tier,'default');return {ok:true};});
 try {
  await once(child.stdout,'data');
  const php=spawnSync('php',['-r',`require ${JSON.stringify(path.resolve('api/src/AiEuroBudget.php'))}; \\Perigallo\\Ticketing\\AiEuroBudget::reserve(['model'=>'gpt-4.1-mini','input'=>'prueba','max_output_tokens'=>1000]); echo 'reserved';`],{env:process.env,encoding:'utf8'});
  assert.equal(php.status,0,php.stderr);assert.equal(php.stdout,'reserved');
  const results=await Promise.allSettled(Array.from({length:40},(_,i)=>send(['suite','lab','instagram','assistant'][i%4])));
  assert.ok(sends>0&&sends<10);assert.equal(results.filter(r=>r.status==='fulfilled').length,sends);
  const count=sends;
  await assert.rejects(send('suite'),{code:'ai_euro_budget'});assert.equal(sends,count);
  const used=spawnSync('python3',['-c',`import sqlite3; print(sqlite3.connect(${JSON.stringify(path.join(dir,'db'))}).execute('SELECT SUM(eur_micros) FROM reservations').fetchone()[0])`],{encoding:'utf8'});
  assert.ok(Number(used.stdout)<=100000);assert.ok(Number(used.stdout)>80000);
  const file=JSON.stringify({model:'gpt-5-mini',max_output_tokens:4096,input:[{type:'input_file',file_data:'data:application/pdf;base64,c3ludGhldGlj'}]});
  await assert.rejects(budgetedOpenAiFetch('lab','https://api.openai.com/v1/responses',{body:file},()=>assert.fail('blocked before provider')),{code:'ai_euro_budget'});
  await assert.rejects(budgetedOpenAiFetch('suite','https://api.openai.com/v1/responses',{body:JSON.stringify({model:'gpt-6-astra',input:'x',max_output_tokens:100})},()=>assert.fail('unknown price')),{code:'ai_euro_budget'});
  child.kill();await once(child,'exit');
  await assert.rejects(send('assistant'),{code:'ai_euro_budget'});assert.equal(sends,count);
 }finally{
  child.kill();if(old===undefined)delete process.env.AI_SPEND_GUARD_SOCKET;else process.env.AI_SPEND_GUARD_SOCKET=old;
  await fs.rm(dir,{recursive:true,force:true});
 }
});

test('bounds include Unicode bytes and schema; images use reviewed ceilings; hidden tools denied',()=>{
 const body={model:'gpt-4.1-mini',max_output_tokens:2500,input:[{type:'input_image',detail:'high',image_url:'data:image/jpeg;base64,'+'x'.repeat(1000000)},{type:'input_text',text:'á漢字'}],text:{format:{schema:{description:'x'.repeat(5000)}}}};
 const r=reservationFor('suite',JSON.stringify(body));assert.ok(r.input_tokens_bound>16000&&r.input_tokens_bound<20000);
 for(const extra of [{tools:[]},{previous_response_id:'hidden'},{background:true},{stream:true}])assert.throws(()=>reservationFor('suite',JSON.stringify({...body,...extra})),{code:'ai_euro_budget'});
 assert.throws(()=>reservationFor('suite',JSON.stringify({...body,max_output_tokens:undefined})),{code:'ai_euro_budget'});
});

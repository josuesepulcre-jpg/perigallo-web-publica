import http from 'node:http';

const message = 'La petición no cabe en el presupuesto total diario de IA de 0,10 € o no puede verificarse su coste. Continúa manualmente; se renueva a medianoche de Madrid.';
const denied = () => Object.assign(new Error(message), {status:429, code:'ai_euro_budget'});

export function reservationFor(app, body) {
  const value=JSON.parse(body);
  if (value.tools || value.previous_response_id || value.conversation || value.background || value.stream || value.service_tier && value.service_tier!=='default') throw denied();
  let imageTokens=0, files=0;
  const clean=(v)=>{
    if (v && typeof v==='object' && !Array.isArray(v)) {
      if(v.type==='input_image') {
        if(v.detail!=='high')throw denied();
        imageTokens+=value.model?.startsWith('gpt-4.1-mini')?10000:value.model?.startsWith('gpt-5.4-mini')?4000:36002;
        return {type:'input_image'};
      }
      if(v.type==='input_file') {files++;return {type:'input_file'};}
      return Object.fromEntries(Object.entries(v).map(([k,x])=>[k,clean(x)]));
    }
    return Array.isArray(v)?v.map(clean):v;
  };
  const text=clean(value);
  // UTF-8 byte count bounds text tokens; 1024 covers message framing. For the
  // supported patch-based mini models, use the reviewed maximum patch ceiling.
  // PDFs/files can expand into text+images: reserve a full context upper bound.
  const input=files?1_200_000:Buffer.byteLength(JSON.stringify(text),'utf8')+1024+imageTokens;
  if(!Number.isSafeInteger(value.max_output_tokens)||value.max_output_tokens<=0)throw denied();
  return {app,model:value.model,input_tokens_bound:input,output_tokens_max:value.max_output_tokens};
}

export async function reserveEuroBudget(app, body) {
  const reservation=JSON.stringify(reservationFor(app,body));
  const socketPath=process.env.AI_SPEND_GUARD_SOCKET || '/run/perigallo-ai-spend/guard.sock';
  await new Promise((resolve,reject)=>{
    const request=http.request({socketPath,path:'/reserve',method:'POST',headers:{'Content-Type':'application/json','Content-Length':Buffer.byteLength(reservation)}},response=>{
      let data='';
      response.on('data',chunk=>{data+=chunk;if(data.length>4096)request.destroy();});
      response.on('end',()=>{try {if(response.statusCode===200&&JSON.parse(data).allowed===true)resolve();else reject(denied());}catch {reject(denied());}});
    });
    request.setTimeout(4000,()=>{request.destroy();reject(denied());});
    request.on('error',()=>reject(denied()));request.end(reservation);
  });
}

export async function budgetedOpenAiFetch(app,url,options,transport=fetch) {
  if(url!=='https://api.openai.com/v1/responses'||typeof options.body!=='string')throw denied();
  const body=JSON.parse(options.body);body.service_tier='default';
  const serialized=JSON.stringify(body);
  await reserveEuroBudget(app,serialized);
  return transport(url,{...options,body:serialized});
}

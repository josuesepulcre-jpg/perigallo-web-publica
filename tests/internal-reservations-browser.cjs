// Run against the isolated router documented in docs/internal-event-reservations.md.
const fs=require('node:fs');const path=require('node:path');const os=require('node:os');const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_PACKAGE_PATH||path.join(os.homedir(),'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
const origin=process.env.RESERVATION_TEST_ORIGIN||'http://127.0.0.1:8098';
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(origin))throw Error('This check only operates on a local disposable fixture.');
const fixture=JSON.parse(fs.readFileSync(process.env.RESERVATION_TEST_FIXTURE||'/tmp/perigallo-internal-reservations-db/ui-fixture.json'));
const output=process.env.RESERVATION_QA_OUTPUT||'reports/internal-reservations';fs.mkdirSync(output,{recursive:true});
function quantiles(values){const sorted=[...values].sort((a,b)=>a-b);return {samples:sorted.length,p50:Math.round(sorted[Math.floor((sorted.length-1)*.5)]*10)/10,p95:Math.round(sorted[Math.floor((sorted.length-1)*.95)]*10)/10,max:Math.round(sorted.at(-1)*10)/10};}
(async()=>{
 const browser=await chromium.launch({headless:true});const context=await browser.newContext({viewport:{width:1440,height:1050},locale:'es-ES',timezoneId:'Europe/Madrid',reducedMotion:'reduce',permissions:['clipboard-read','clipboard-write']});const page=await context.newPage();
 const errors=[],networkErrors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});page.on('requestfailed',r=>networkErrors.push({url:r.url(),error:r.failure()?.errorText}));page.on('request',r=>{if(r.url().includes('/api/'))requests.push({url:new URL(r.url()).pathname,method:r.method()});});
 await page.goto(origin+'/fixtures/session');await page.goto(origin+'/admin/reservas/?event='+fixture.event_id);await page.locator('.reservation-row').first().waitFor();
 await page.screenshot({path:output+'/desktop.png'});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 const openSamples=[];
 for(let i=0;i<20;i++){
  openSamples.push(await page.evaluate(async()=>{const t=performance.now();document.querySelector('[data-new-reservation]').click();await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));return performance.now()-t;}));
  await page.locator('[data-reservation-editor] [aria-label="Cerrar"]').click();
 }
 const reservationUrl=new RegExp('/api/admin/events/'+fixture.event_id+'/reservations$');
 await page.locator('[data-new-reservation]').click();const form=page.locator('[data-reservation-form]');
 await form.locator('[data-ticket-type="'+fixture.adult_id+'"]').fill('2');await form.locator('[data-ticket-type="'+fixture.child_id+'"]').fill('1');
 await form.locator('[name="first_name"]').fill('María del Carmen');await form.locator('[name="last_name"]').fill('Fernández de la Vega · familia');await form.locator('[name="phone"]').fill('600000000');await form.locator('[name="email"]').fill('familia@example.invalid');await form.locator('[name="notes"]').fill('Seguimiento interno. Tres plazas para la familia.');
 await page.screenshot({path:output+'/desktop-form.png'});
 // The visible result must wait for the durable server response; a network failure is retryable.
 let release;const gate=new Promise(r=>release=r);let intercepted=false;
 await page.route(reservationUrl,async route=>{if(route.request().method()==='POST'&&!intercepted){intercepted=true;await gate;await route.continue();}else await route.continue();});
 const save=page.waitForResponse(r=>reservationUrl.test(r.url())&&r.request().method()==='POST');
 await form.locator('[type="submit"]').click();await page.waitForTimeout(120);assert.equal(await form.locator('[type="submit"]').isDisabled(),true);assert.match(await form.locator('[data-editor-status]').textContent(),/Guardando/);assert.equal(await page.locator('[data-reservation-detail]').isVisible(),false);
 release();const savedResponse=await save;const saved=await savedResponse.json();assert.equal(saved.ok,true);const created=saved.reservation;
 await page.locator('[data-reservation-detail][open] [data-reservation-action="request-payment"]').waitFor();assert.equal(created.quantity,3);assert.equal(created.total_cents,15800);assert.equal(created.payment_url,null);assert.equal(created.reservation_state,'held');
 await page.screenshot({path:output+'/desktop-reservation.png'});
 await page.unroute(reservationUrl);
 await page.locator('[data-reservation-action="request-payment"]').click();await page.locator('[data-reservation-action="copy-link"]').waitFor();
 const whatsapp=page.locator('.reservation-share a');assert.match(await whatsapp.getAttribute('href'),/^https:\/\/wa.me\/34600000000\?text=/);assert.match(await page.locator('#reservation-message').inputValue(),/Para confirmar la reserva/);
 await page.locator('[data-reservation-action="copy-link"]').click();await page.waitForFunction(()=>document.querySelector('[data-detail-status]').textContent.includes('copiado'));
 const clipboard=await page.evaluate(()=>navigator.clipboard.readText());assert.match(clipboard,/&pay=1$/);
 await page.screenshot({path:output+'/desktop-payment.png'});
 await page.locator('[data-reservation-action="confirm"]').click();await page.locator('[data-decision-form] [name="mode"]').selectOption('door');await page.locator('[data-decision-form] [type="submit"]').click();await page.locator('[data-reservation-action="cash"]').waitFor();assert.match(await page.locator('[data-detail-content]').textContent(),/Cobro pendiente en puerta/);
 await page.locator('[data-reservation-action="cash"]').click();await page.locator('[data-decision-form] [type="submit"]').click();await page.waitForFunction(()=>document.querySelector('[data-detail-content]').textContent.includes('Cobrado'));
 await page.locator('[data-reservation-detail] [aria-label="Cerrar"]').click();
 // Filtering must not show a paid reservation among internal holds.
 assert.equal(await page.locator('[data-open-reservation="'+created.id+'"]').count(),0);
 // Mobile and tablet use the same live data and form, with long Spanish names.
 for(const [name,width] of [['tablet',768],['mobile',390]]){
  await page.setViewportSize({width,height:950});await page.screenshot({path:output+'/'+name+'.png'});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await page.locator('[data-new-reservation]').click();await form.locator('[name="first_name"]').fill('María del Carmen');await form.locator('[name="last_name"]').fill('Fernández de la Vega');await page.screenshot({path:output+'/'+name+'-form.png'});assert.equal(await form.evaluate(n=>n.scrollWidth>n.clientWidth),false);await page.keyboard.press('Escape');
 }
 await page.locator('[data-reservation-search]').fill('ZZZsincoincidencias');await page.locator('.reservation-empty').waitFor();await page.screenshot({path:output+'/mobile-empty.png'});await page.locator('[data-reservation-search]').fill('');await page.locator('.reservation-row').first().waitFor();
 // Separate immediate feedback, durable save and the subsequent screen paint.
 await page.setViewportSize({width:1440,height:1050});
 await page.evaluate(({eventId})=>{
  const original=window.fetch;window.__savePaint={samples:[],current:null,original};
  window.fetch=async function(url,options){const current=window.__savePaint.current;const response=await original.apply(this,arguments);if(current&&options?.method==='POST'&&String(url)==='/api/admin/events/'+eventId+'/reservations'){const body=await response.clone().json();current.durable=performance.now();current.orderId=body.reservation?.id;}return response;};
  document.querySelector('[data-reservation-form]').addEventListener('submit',()=>{const current=window.__savePaint.current;if(!current)return;current.start=performance.now();requestAnimationFrame(()=>requestAnimationFrame(()=>{current.feedback=performance.now();}));},true);
  new MutationObserver(()=>{const current=window.__savePaint.current;if(current&&!current.done&&document.querySelector('[data-reservation-status]').textContent.startsWith('Reserva guardada.')){current.done=true;requestAnimationFrame(()=>requestAnimationFrame(()=>{current.screen=performance.now();current.resolve({feedback:current.feedback-current.start,durable:current.durable-current.start,repaint:current.screen-current.durable,orderId:current.orderId});}));}}).observe(document.querySelector('[data-reservation-status]'),{childList:true,subtree:true});
 },{eventId:fixture.event_id});
 const savePaint=[];
 for(let i=0;i<20;i++){
  await page.locator('[data-new-reservation]').click();
  await page.evaluate(({adult,index})=>{const f=document.querySelector('[data-reservation-form]');f.querySelector('[data-ticket-type="'+adult+'"]').value='1';f.elements.first_name.value='Medición visual';f.elements.last_name.value=String(index);f.elements.phone.value='600000000';window.__savePaint.current={};window.__lastSavePaint=new Promise(resolve=>window.__savePaint.current.resolve=resolve);f.requestSubmit();},{adult:fixture.adult_id,index:i});
  const sample=await page.evaluate(()=>window.__lastSavePaint);savePaint.push(sample);assert(sample.orderId);
  await page.locator('[data-reservation-detail] [aria-label="Cerrar"]').click();
  const cancel=await context.request.post(origin+'/api/admin/internal-reservations/'+sample.orderId+'/cancel',{headers:{'X-CSRF-Token':'local-fixture-csrf'},data:{reason:'Medición visual finalizada'}});assert.equal(cancel.ok(),true);
 }
 await page.evaluate(()=>{window.fetch=window.__savePaint.original;window.__savePaint.current=null;});
 // API measurements are local HTTP + parsing, separated from UI paint.
 const http=await page.evaluate(async({eventId,adult})=>{
  const samples={list:[],save:[],cancel:[]};let errors=0;
  async function request(url,method,data){const start=performance.now();const response=await fetch(url,{method:method||'GET',headers:method?{'Content-Type':'application/json','X-CSRF-Token':'local-fixture-csrf'}:{},body:method?JSON.stringify(data):undefined});const body=await response.json();if(!body.ok)errors++;return {body,elapsed:performance.now()-start};}
  for(let i=0;i<20;i++){
   let r=await request('/api/admin/events/'+eventId+'/reservations?state=held&page=1&meta=1');samples.list.push(r.elapsed);
   r=await request('/api/admin/events/'+eventId+'/reservations','POST',{operation_key:crypto.randomUUID(),first_name:'Medición',last_name:String(i),phone:'600000000',items:[{ticket_type_id:adult,quantity:1}]});samples.save.push(r.elapsed);
   if(r.body.reservation){r=await request('/api/admin/internal-reservations/'+r.body.reservation.id+'/cancel','POST',{reason:'Medición local terminada'});samples.cancel.push(r.elapsed);}
  }
  return {samples,errors};
 },{eventId:fixture.event_id,adult:fixture.adult_id});
 const unauthorized=await context.request.get(origin+'/fixtures/session?role=control_acceso');assert.equal(unauthorized.status(),200);const denied=await context.request.get(origin+'/api/admin/events/'+fixture.event_id+'/reservations');assert.equal(denied.status(),401);await context.request.get(origin+'/fixtures/session');const csrf=await context.request.post(origin+'/api/admin/events/'+fixture.event_id+'/reservations',{data:{}});assert.equal(csrf.status(),419);
 const report={environment:'Chromium local y PHP 8.5/MariaDB 12.3 en base desechable, zona Europe/Madrid; sin trabajadores de envío ni transacciones remotas',data:{reservations:fixture.reservations,ticketTypes:2,pageSize:20},metrics:{'editor.visible':quantiles(openSamples),'save.feedback.visible':quantiles(savePaint.map(s=>s.feedback)),'save.durable-roundtrip':quantiles(savePaint.map(s=>s.durable)),'save.screen-after-confirmation':quantiles(savePaint.map(s=>s.repaint)),'list.http':quantiles(http.samples.list),'save.durable.http':quantiles(http.samples.save),'cancel.durable.http':quantiles(http.samples.cancel)},errors,networkErrors,apiErrors:http.errors,requests:requests.reduce((a,r)=>(a[r.method+' '+r.url]=(a[r.method+' '+r.url]||0)+1,a),{}),checks:['guardar espera respuesta durable','aforo canónico','enlace manual pendiente hasta Redsys','confirmación en puerta sin fingir cobro','registro efectivo explícito','clipboard con enlace de pago','filtro de internas','desktop 1440/tablet 768/mobile 390','sin desbordamiento','acceso administrativo y CSRF']};
 fs.writeFileSync(output+'/browser-qa.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));assert.deepEqual(errors,[]);assert.deepEqual(networkErrors,[]);assert.equal(http.errors,0);await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});

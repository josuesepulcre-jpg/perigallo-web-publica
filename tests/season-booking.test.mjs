import {bookingMenuIds} from '../experiencias/group-inquiry.mjs';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {test} from 'node:test';
import assert from 'node:assert/strict';
const source=readFileSync(new URL('../assets/js/season-booking.js',import.meta.url),'utf8');
const plans={
 navidad:{title:'El día de Navidad',date:'2026-12-25',price:6500,child:2800},
 hora:{title:'Tardevieja · Hora Cero',date:'2026-12-31',price:12000,child:null},
 nuevo:{title:'El día de Año Nuevo',date:'2027-01-01',price:5000,child:2800}
};
async function checkout(mode='reserve',valid=true,plan='navidad',christmasOpen=true){
 const listeners={},html={innerHTML:''},status={textContent:''},button={},aside={},sent=[],redirects=[];
 const field=(value,checked=false)=>({value,checked,setCustomValidity(){}});
 const form={elements:{mode:field(mode),adults:field('2'),children:field('1'),first_name:field(' Ana '),last_name:field(' García '),phone:field('600000000'),email:field('qa@example.invalid'),notes:field('Menú infantil y alergia al huevo'),privacy:field('',true),adult_buyer:field('',true)},reportValidity:()=>valid,addEventListener:(name,fn)=>listeners[name]=fn};
 const nodes={'#booking-root':html,'#reserve-form':form,'#checkout-summary':aside,'#submit-reserve':button,'#form-status':status};
 const context=vm.createContext({
  URLSearchParams,Intl,Date,crypto:{randomUUID:()=> 'synthetic-operation-123456'},
  location:{pathname:'/entradas/reserva/',search:`?plan=${plan}&mode=${mode}`,origin:'https://example.test',assign:url=>redirects.push(url)},
  document:{querySelector:key=>nodes[key]},
  fetch:async(url,options)=>{
   if(options.method==='POST'){
    sent.push({url,body:JSON.parse(options.body)});
    return {ok:true,json:async()=>({booking:{token:'synthetic-token',mode:'reserve'}})};
   }
   sent.push({url});
   const selected=new URL(url,'https://example.test').searchParams.get('plan');
   if(!Object.hasOwn(plans,selected)||(!christmasOpen&&selected==='navidad')){
    return {ok:false,json:async()=>({error:'Esta experiencia no admite reservas en este momento.'})};
   }
   return {ok:true,json:async()=>({plans:{[selected]:plans[selected]}})};
  }
 });
 await vm.runInContext(source,context);
 return {form,html,button,aside,sent,redirects,submit:()=>listeners.submit({preventDefault(){}})};
}
for(const mode of ['reserve','pay'])test(`${mode}: preserves buyer, seats, notes and required acceptance; follows selected mode despite legacy API response`,async()=>{
 const c=await checkout(mode);await c.submit();assert.equal(c.sent.length,2);
 assert.equal(c.sent[0].url,'/api/season-reservations?plan=navidad');
 const {url,body}=c.sent[1];assert.equal(url,'/api/season-reservations');
 assert.equal(body.mode,mode);assert.equal(body.first_name,'Ana');assert.equal(body.last_name,'García');assert.equal(body.name,'Ana García');
 assert.equal(body.phone,'600000000');assert.equal(body.email,'qa@example.invalid');assert.equal(body.adults,2);assert.equal(body.children,1);assert.equal(body.notes,'Menú infantil y alergia al huevo');
 assert.equal(body.privacy,true);assert.equal(body.terms,true);assert.equal(body.adult_buyer,true);assert.equal(body.whatsapp_consent,false);
 assert.equal(c.redirects[0],'/entradas/reserva/pedido/?token=synthetic-token'+(mode==='pay'?'&pay=1':''));
 assert.match(c.aside.innerHTML,/158,00/);
 assert.match(c.html.innerHTML,/Contactaremos con vosotros para acordar cómo realizar el pago anticipado/);
 assert.doesNotMatch(c.html.innerHTML,/name="whatsapp_consent"|Quiero recibir|Te enviaremos un enlace/);
 for(const link of ['/politica-privacidad/','/condiciones/','/politica-cancelacion/'])assert.ok(c.html.innerHTML.includes(link));
});
test('invalid or unaccepted form never creates an order',async()=>{const c=await checkout('pay',false);await c.submit();assert.equal(c.sent.length,1);assert.equal(c.redirects.length,0);});
test('each form requests only its plan, including when Christmas is closed',async()=>{
 for(const plan of ['hora','nuevo']){
  const c=await checkout('reserve',true,plan,false);
  assert.deepEqual(c.sent,[{url:`/api/season-reservations?plan=${plan}`}]);
  assert.ok(c.html.innerHTML.includes(`<h1>${plans[plan].title}</h1>`));
  assert.match(c.html.innerHTML,/<form id="reserve-form">/);
 }
 const closed=await checkout('reserve',true,'navidad',false);
 assert.match(closed.html.innerHTML,/Esta experiencia no admite reservas/);
 assert.doesNotMatch(closed.html.innerHTML,/<form /);
 const invalid=await checkout('reserve',true,'constructor');
 assert.match(invalid.html.innerHTML,/Elige una experiencia/);
 assert.equal(invalid.sent.length,0);
});
test('all transactional experience cards and menus share one reservation entry point',()=>{
 const app=readFileSync(new URL('../experiencias/app.js',import.meta.url),'utf8');
 const fn=app.slice(app.indexOf('function onlineBookingUrl('),app.indexOf("$('#plan-grid')"));
 const c=vm.createContext({inquiryPlanIds:['diciembre','degustacion'],bookingOrigin:'',bookingMenuIds,URL,esc:s=>s.replaceAll('&','&amp;')});vm.runInContext(fn+';globalThis.actions=bookingActions;',c);
 for(const [plan, menu, selected] of [
  ['diciembre',undefined,null],['diciembre','navidad-02','perigallo-raices'],
  ['diciembre','navidad-03','perigallo-mesa'],['diciembre','navidad-04','perigallo-brindis'],
  ['degustacion',undefined,'perigallo-degustacion']
 ]){
  const html=c.actions(plan,menu);
  const url=new URL(html.match(/href="([^"]+)"/)[1].replaceAll('&amp;','&'));
  assert.equal(url.origin+url.pathname,'https://reservas.perigallo.com/reservar');
  assert.equal(url.searchParams.get('menu'),selected);
  assert.match(html,/Reservar online/);
 }
 for(const plan of ['navidad','hora','nuevo']){const html=c.actions(plan);assert.equal((html.match(/<a /g)||[]).length,1);assert.match(html,new RegExp(`plan=${plan}&amp;mode=reserve`));assert.doesNotMatch(html,/Comprar y pagar/);}
});

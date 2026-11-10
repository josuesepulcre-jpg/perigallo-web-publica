import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {test} from 'node:test';
import assert from 'node:assert/strict';
const source=readFileSync(new URL('../assets/js/season-booking.js',import.meta.url),'utf8');
function checkout(mode='reserve',valid=true){
 const listeners={},html={innerHTML:''},status={textContent:''},button={},aside={},sent=[],redirects=[];
 const field=(value,checked=false)=>({value,checked,setCustomValidity(){}});
 const form={elements:{mode:field(mode),adults:field('2'),children:field('1'),first_name:field(' Ana '),last_name:field(' García '),phone:field('600000000'),email:field('qa@example.invalid'),notes:field('Menú infantil y alergia al huevo'),privacy:field('',true),adult_buyer:field('',true)},reportValidity:()=>valid,addEventListener:(name,fn)=>listeners[name]=fn};
 const nodes={'#booking-root':html,'#reserve-form':form,'#checkout-summary':aside,'#submit-reserve':button,'#form-status':status};
 const context=vm.createContext({URLSearchParams,Intl,Date,crypto:{randomUUID:()=> 'synthetic-operation-123456'},location:{search:`?plan=navidad&mode=${mode}`,origin:'https://example.test',assign:url=>redirects.push(url)},document:{querySelector:key=>nodes[key]},fetch:async(url,options)=>{sent.push({url,body:JSON.parse(options.body)});return {ok:true,json:async()=>({booking:{token:'synthetic-token',mode:'reserve'}})};}});
 vm.runInContext(source.slice(0,source.lastIndexOf("if(location.pathname.startsWith"))+'\nrenderCheckout();',context);
 return {form,html,button,aside,sent,redirects,submit:()=>listeners.submit({preventDefault(){}})};
}
for(const mode of ['reserve','pay'])test(`${mode}: preserves buyer, seats, notes and required acceptance; follows selected mode despite legacy API response`,async()=>{
 const c=checkout(mode);await c.submit();assert.equal(c.sent.length,1);
 const {url,body}=c.sent[0];assert.equal(url,'/api/season-reservations');
 assert.equal(body.mode,mode);assert.equal(body.first_name,'Ana');assert.equal(body.last_name,'García');assert.equal(body.name,'Ana García');
 assert.equal(body.phone,'600000000');assert.equal(body.email,'qa@example.invalid');assert.equal(body.adults,2);assert.equal(body.children,1);assert.equal(body.notes,'Menú infantil y alergia al huevo');
 assert.equal(body.privacy,true);assert.equal(body.terms,true);assert.equal(body.adult_buyer,true);assert.equal(body.whatsapp_consent,false);
 assert.equal(c.redirects[0],'/entradas/reserva/pedido/?token=synthetic-token'+(mode==='pay'?'&pay=1':''));
 assert.match(c.aside.innerHTML,/158,00/);
 assert.match(c.html.innerHTML,/Contactaremos con vosotros para acordar cómo realizar el pago anticipado/);
 assert.doesNotMatch(c.html.innerHTML,/name="whatsapp_consent"|Quiero recibir|Te enviaremos un enlace/);
 for(const link of ['/politica-privacidad/','/condiciones/','/politica-cancelacion/'])assert.ok(c.html.innerHTML.includes(link));
});
test('invalid or unaccepted form never creates an order',async()=>{const c=checkout('pay',false);await c.submit();assert.equal(c.sent.length,0);assert.equal(c.redirects.length,0);});
test('all transactional experience cards and menus share one reservation entry point',()=>{
 const app=readFileSync(new URL('../experiencias/app.js',import.meta.url),'utf8');
 const fn=app.slice(app.indexOf('function bookingActions('),app.indexOf("$('#plan-grid')"));
 const c=vm.createContext({inquiryPlanIds:['diciembre','degustacion'],bookingOrigin:''});vm.runInContext(fn+';globalThis.actions=bookingActions;',c);
 for(const plan of ['navidad','hora','nuevo']){const html=c.actions(plan);assert.equal((html.match(/<a /g)||[]).length,1);assert.match(html,new RegExp(`plan=${plan}&mode=reserve`));assert.doesNotMatch(html,/Comprar y pagar/);}
});

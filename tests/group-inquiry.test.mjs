import test from 'node:test';
import assert from 'node:assert/strict';
import {validateGroupInquiry,buildGroupMessage,groupWhatsAppUrl} from '../experiencias/group-inquiry.mjs';
const menus={'navidad-02':{title:'Con buen pie',price:'44,95 €'},'navidad-03':{title:'Un paso más',price:'54,95 €'},'navidad-04':{title:'Por todo lo alto',price:'64,95 €'},'navidad-individual':{title:'Menú degustación',price:'38,00 €'}};
const complete={first_name:'María',last_name:'García',date:'2026-12-16',shift:'dinner',people:'8',menu:'navidad-03',phone:'+34 600 000 000',email:'prueba@example.invalid',privacy:true};
test('a complete inquiry includes every contact and booking detail',()=>{
 const message=buildGroupMessage(complete,menus);
 for(const value of ['María García','16 de diciembre de 2026','Cena (noche)','Personas: 8','Un paso más · 54,95 €','+34 600 000 000','prueba@example.invalid','confirmación de disponibilidad'])assert.ok(message.includes(value),value);
});
test('every requested field and privacy consent are required before preparing the message',()=>{
 for(const key of ['first_name','last_name','date','shift','people','menu','phone','email','privacy'])assert.throws(()=>buildGroupMessage({...complete,[key]:key==='privacy'?false:' '},menus),key);
});
test('inquiry rejects dates outside December, invalid shifts, impossible party sizes and unknown menus',()=>{
 for(const change of [{date:'2026-12-32'},{date:'2027-01-01'},{shift:'any'},{people:'0'},{people:'101'},{people:'1.5'},{menu:'inventado'},{phone:'123'},{email:'no-es-correo'}])assert.throws(()=>validateGroupInquiry({...complete,...change},menus));
 assert.equal(validateGroupInquiry({...complete,people:'100'},menus).people,100);
 assert.equal(validateGroupInquiry({...complete,people:'1',menu:'navidad-individual'},menus).menu,'navidad-individual');
});
test('WhatsApp always targets Perigallo and preserves accented names and the complete message',()=>{
 const message=buildGroupMessage({...complete,last_name:'García & Soler'},menus),url=new URL(groupWhatsAppUrl(message));
 assert.equal(url.origin,'https://wa.me');assert.equal(url.pathname,'/34691499985');assert.equal(url.searchParams.get('text'),message);
});

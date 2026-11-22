import test from 'node:test';
import assert from 'node:assert/strict';
import {validateGroupInquiry,buildGroupMessage,groupWhatsAppUrl} from '../experiencias/group-inquiry.mjs';
const menus={'navidad-02':{title:'Con buen pie',price:'44,95 €'},'navidad-03':{title:'Un paso más',price:'54,95 €'},'navidad-04':{title:'Por todo lo alto',price:'64,95 €'},'navidad-individual':{title:'Menú degustación',price:'38,00 €'}};
const days={
 '2026-12-04':{lunch:['perigallo-degustacion','perigallo-mesa'],dinner:['perigallo-mesa']},
 '2026-12-16':{dinner:['perigallo-mesa','perigallo-degustacion']}
};
const group={kind:'group',days},tasting={kind:'tasting',days,maxPeople:6};
const complete={first_name:'María',last_name:'García',date:'2026-12-16',shift:'dinner',people:'8',menu:'navidad-03',phone:'+34 600 000 000',email:'prueba@example.invalid',privacy:true};
test('a complete inquiry includes every contact and booking detail',()=>{
 const message=buildGroupMessage(complete,menus,group);
 for(const value of ['María García','16 de diciembre de 2026','Cena (noche)','Personas: 8','Un paso más · 54,95 €','+34 600 000 000','prueba@example.invalid','confirmación de disponibilidad'])assert.ok(message.includes(value),value);
});
test('every requested field and privacy consent are required before preparing the message',()=>{
 for(const key of ['first_name','last_name','date','shift','people','menu','phone','email','privacy'])assert.throws(()=>buildGroupMessage({...complete,[key]:key==='privacy'?false:' '},menus,group),key);
});
test('inquiry rejects dates outside December, invalid shifts, impossible party sizes and unknown menus',()=>{
 for(const change of [{date:'2026-12-32'},{date:'2027-01-01'},{shift:'any'},{people:'0'},{people:'101'},{people:'1.5'},{menu:'inventado'},{phone:'123'},{email:'no-es-correo'}])assert.throws(()=>validateGroupInquiry({...complete,...change},menus,group));
 assert.equal(validateGroupInquiry({...complete,people:'100'},menus,group).people,100);
 assert.equal(validateGroupInquiry({...complete,people:'1',menu:'navidad-individual'},menus,group).menu,'navidad-individual');
});
test('WhatsApp always targets Perigallo and preserves accented names and the complete message',()=>{
 const message=buildGroupMessage({...complete,last_name:'García & Soler'},menus,group),url=new URL(groupWhatsAppUrl(message));
 assert.equal(url.origin,'https://wa.me');assert.equal(url.pathname,'/34691499985');assert.equal(url.searchParams.get('text'),message);
});
test('tasting accepts lunch and up to six guests',()=>{
 const inquiry={...complete,date:'2026-12-04',shift:'lunch',menu:'navidad-individual',people:'6'};
 assert.equal(validateGroupInquiry(inquiry,menus,tasting).people,6);
 assert.throws(()=>buildGroupMessage({...inquiry,people:'7'},menus,tasting));
 assert.throws(()=>buildGroupMessage({...inquiry,shift:'dinner'},menus,tasting));
});
test('a day, service or menu closed in Reservas cannot produce a WhatsApp inquiry',()=>{
 for(const change of [{date:'2026-12-05'}, {date:'2026-12-05',date_request:'other'}, {shift:'lunch'}, {menu:'navidad-04'}]){
  assert.throws(()=>buildGroupMessage({...complete,...change},menus,group),/disponible/);
 }
 assert.throws(()=>buildGroupMessage(complete,menus,{kind:'group'}),/disponible/);
 assert.throws(()=>buildGroupMessage(complete,menus,{...group,days:{}}),/disponible/);
});
test('an open weekday or reopened date follows the live calendar',()=>{
 const date='2026-12-05';
 assert.match(buildGroupMessage(complete,menus,group),/16 de diciembre/);
 assert.match(buildGroupMessage({...complete,date},menus,{...group,days:{[date]:days[complete.date]}}),/5 de diciembre/);
});

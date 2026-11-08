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
test('the independent tasting experience admits six people and rejects seven',()=>{
 const tasting={'navidad-individual':menus['navidad-individual']},options={kind:'tasting',maxPeople:6};
 const inquiry={...complete,date:'2026-12-04',shift:'lunch'};
 for(const people of ['1','6'])assert.equal(validateGroupInquiry({...inquiry,people,menu:'navidad-individual'},tasting,options).people,Number(people));
 assert.throws(()=>buildGroupMessage({...inquiry,people:'7',menu:'navidad-individual'},tasting,options));
 assert.throws(()=>buildGroupMessage({...inquiry,people:'6',menu:'navidad-03'},tasting,options));
 assert.ok(buildGroupMessage({...inquiry,people:'6',menu:'navidad-individual'},tasting,options).includes('disponibilidad para el menú degustación en diciembre'));
});

test('tasting accepts only the eleven December weekend dates, excluding Christmas, at midday',()=>{
 const tasting={'navidad-individual':menus['navidad-individual']},options={kind:'tasting',maxPeople:6};
 const inquiry={...complete,shift:'lunch',people:'6',menu:'navidad-individual'};
 const available=[4,5,6,11,12,13,18,19,20,26,27];
 for(let day=1;day<=31;day++) {
   const date='2026-12-'+String(day).padStart(2,'0');
   if(available.includes(day)) {
     assert.equal(validateGroupInquiry({...inquiry,date},tasting,options).date,date);
     assert.throws(()=>buildGroupMessage({...inquiry,date,shift:'dinner'},tasting,options),/mediodía/);
   } else assert.throws(()=>buildGroupMessage({...inquiry,date},tasting,options),/excepto el 25/);
 }
 const message=buildGroupMessage({...inquiry,date:'2026-12-04'},tasting,options);
 assert.ok(message.includes('viernes, 4 de diciembre de 2026'));
 assert.ok(message.includes('Turno: Comida (mediodía)'));
 assert.equal(validateGroupInquiry({...complete,date:'2026-12-25',shift:'dinner'},menus).shift,'dinner');
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
const routes = ['','bodas-alicante','celebraciones-familiares-alicante','experiencias','comuniones-alicante','bautizos-alicante','eventos-privados-alicante','eventos-empresa-alicante','finca-la-llaguna','sobre-perigallo','contacto','mis-entradas','politica-privacidad','aviso-legal','cookies','condiciones','politica-cancelacion','politica-reembolso'];
test('public pages have one accessible navigation and working local destinations/assets',()=>{
 for (const route of routes) {
  const page = resolve(route,'index.html'); const html=readFileSync(page,'utf8');
  const header = html.match(/<header\b[^>]*>[\s\S]*?<\/header>/g) || [];
  assert.equal(header.length,1,page);
  assert.equal((header[0].match(/<nav\b/g)||[]).length,1,page);
  assert.match(header[0], /<nav\b[^>]*aria-label="Principal"/,page);
  const controlled = header[0].match(/aria-controls="([^"]+)"/);
  assert.ok(controlled && header[0].includes(`id="${controlled[1]}"`),page);
  assert.match(html,/\/(?:journey|assets\/js\/brand-navigation)\.js/,page);
  assert.ok(html.includes('/politica-privacidad/'),page);
  for(const match of html.matchAll(/(?:href|src)="([^"?#]+)(?:[^\"]*)"/g)){
   const value=match[1]; if(!value.startsWith('/')||value.startsWith('//'))continue;
   const target=value.endsWith('/')?`${value}index.html`:value;
   assert.ok(existsSync(resolve(`.${target}`)),`${page}: missing ${target}`);
  }
 }
});
test('every agenda consumer loads the date policy before the consuming script',()=>{
 for(const path of ['index.html','experiencias/index.html','eventos/index.html','eventos/evento.html']){
  const html=readFileSync(path,'utf8');const helper=html.indexOf('public-agenda.js');const consumer=html.indexOf(html.includes('live-agenda.js')?'live-agenda.js':'ticketing.js');
  assert.ok(helper>=0&&helper<consumer,path);
 }
});

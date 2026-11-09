import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {test} from 'node:test';
const source=readFileSync(new URL('../assets/js/admin-backoffice.js',import.meta.url),'utf8');
function functionSource(name){const start=source.indexOf('  function '+name+'(');const end=source.indexOf('\n  function ',start+1);return source.slice(start,end);}
const context=vm.createContext({Intl,Date,state:{session:{role:'admin'}},escapeHtml:s=>String(s),formatDate:()=> '25 dic 2026',statusLabel:s=>s,window:{location:{origin:'https://example.invalid'}}});
vm.runInContext(functionSource('eventCard')+functionSource('orderActions'),context);
test('occupancy includes held places and has a direct reservation action per event',()=>{
 const html=context.eventCard({id:42,title:'Navidad',slug:'navidad',capacity:100,sold:20,reserved:30},false);
 assert.match(html,/width:50%/);assert.match(html,/20<\/strong> confirmadas/);assert.match(html,/30<\/strong> reservadas/);assert.match(html,/href="\/admin\/reservas\/\?event=42"/);
});
test('an internal draft cannot expose the old sales payment/share actions',()=>{
 const html=context.orderActions({id:42,sales_channel:'manual_card',order_status:'draft'});
 assert.match(html,/Gestionar reserva interna/);assert.doesNotMatch(html,/copy-payment-link|send-manual-card|Ver pedido/);
});

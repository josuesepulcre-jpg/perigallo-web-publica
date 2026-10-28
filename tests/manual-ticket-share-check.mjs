import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const s=readFileSync('assets/js/admin-backoffice.js','utf8');
const c={state:{session:{role:'admin'}},window:{location:{origin:'https://perigallo.com'}}};vm.createContext(c);
for(const name of ['isClosedOrder','ticketShareData','manualTicketWhatsAppLink']){const a=s.indexOf('  function '+name+'(');const b=s.indexOf('\n  function ',a+1);vm.runInContext(s.slice(a,b),c);}
const order={id:1,phone:'0034 612 345 678',name:'Ana & Luis',public_token:'safe-token',payment_status:'paid',event_title:'Comedy'};
const d=c.ticketShareData(order);assert.equal(d.phone,'34612345678');assert.match(d.message,/Ana & Luis/);assert.equal(d.link,'https://perigallo.com/entradas/pedido/?token=safe-token');assert.match(c.manualTicketWhatsAppLink(order),/data-ticket-share="1"/);
assert.equal(c.manualTicketWhatsAppLink({...order,payment_status:'pending'}),'');assert.equal(c.manualTicketWhatsAppLink({...order,display_status:'refunded'}),'');
assert.match(c.manualTicketWhatsAppLink({...order,payment_status:'pending',sales_channel:'cash',order_status:'confirmed'}),/data-ticket-share/);
c.state.session.role='control_acceso';assert.equal(c.manualTicketWhatsAppLink(order),'');
assert.match(s,/data-share-copy/);assert.match(s,/data-share-link/);
console.log('Manual sharing: eligibility, phone normalization, secure link and message verified');

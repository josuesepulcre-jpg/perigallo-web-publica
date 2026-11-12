import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {test} from 'node:test';
import assert from 'node:assert/strict';
const source = readFileSync(new URL('../assets/js/admin-backoffice.js', import.meta.url), 'utf8');
const ctx = vm.createContext({Intl, Date, URL, window: {location: {origin:'https://example.test', hostname:'example.test'}}, document:{addEventListener:()=>{},body:{getAttribute:()=> 'manual'}}});
// Exercise the real closure functions without starting the browser UI or making API calls.
vm.runInContext(source.replace('  initTicketShare();', `  globalThis.sales = {isExpiredReservation, isPendingReservation, isSoldOrder, canOpenPayment, salesOrderMatches, orderOrigin, orderNotes, orderContactUrl, orderActions, renderOrdersRows, reservationDateText, eventCard}; state.session = {role:'admin'}; return;\n  initTicketShare();`), ctx);
const s = ctx.sales;
const pending = {id:1, name:'Cliente de prueba', status:'pending', payment_status:'pending', sales_channel:'manual_card', public_token:'test-token', phone:'+34 600 000 000', reservation_expires_at:'2099-12-25 14:00:00', cash_payment_notes:'[season-op:test-operation][request:abc] Mesa junto a la ventana', total_cents:13000, ticket_quantity:2};
const paid = {...pending, status:'paid', payment_status:'paid'};
const expired = {...pending, reservation_expires_at:'2000-01-01 12:00:00'};
const door = {...pending, sales_channel:'cash', order_status:'confirmed', reservation_expires_at:null};
test('initial view includes unpaid web reservations, paid sales and confirmed pay-at-door sales',()=>{
 for(const order of [pending, paid, door]) assert.equal(s.salesOrderMatches(order,'open'),true);
 assert.equal(s.salesOrderMatches(pending,'active'),false);
 assert.equal(s.salesOrderMatches(pending,'pending'),true);
 assert.equal(s.salesOrderMatches(door,'pending'),false);
 assert.equal(s.salesOrderMatches(door,'door-pending'),true);
});
test('expired, cancelled, refunded and test reservations do not inflate the operational view',()=>{
 for(const order of [expired,{...pending,status:'cancelled'},{...paid,status:'refunded'},{...pending,is_test:1}]) assert.equal(s.salesOrderMatches(order,'open'),false);
 assert.equal(s.salesOrderMatches(expired,'expired'),true);
 assert.equal(s.salesOrderMatches(expired,'pending'),false);
 assert.equal(s.salesOrderMatches({...paid,is_test:1},'tests'),true);
 assert.equal(s.salesOrderMatches({...paid,is_test:1},'all'),false);
 assert.equal(s.isExpiredReservation(paid),false);
});
test('expiry uses Madrid winter and summer time, including the exact expiry boundary',()=>{
 assert.equal(s.isExpiredReservation({...pending,reservation_expires_at:'2026-11-10 20:00:00'}, new Date('2026-11-10T18:59:59Z')), false);
 assert.equal(s.isExpiredReservation({...pending,reservation_expires_at:'2026-11-10 20:00:00'}, new Date('2026-11-10T19:00:00Z')), true);
 assert.equal(s.isExpiredReservation({...pending,reservation_expires_at:'2026-07-10 20:00:00'}, new Date('2026-07-10T18:00:00Z')), true);
 assert.match(s.reservationDateText('2026-11-10 20:00:00'), /20:00/);
});
test('payment links appear only for live manual-card reservations with a public token',()=>{
 assert.equal(s.canOpenPayment(pending),true);
 for(const order of [paid,expired,{...pending,status:'cancelled'},{...pending,sales_channel:'cash'},{...pending,sales_channel:'web'},{...pending,public_token:''}]) {
  assert.equal(s.canOpenPayment(order),false);
  assert.doesNotMatch(s.orderActions(order),/Abrir enlace de pago|copy-payment-link|send-manual-card/);
 }
 assert.match(s.orderActions(pending),/Abrir enlace de pago/);
 assert.match(s.orderActions(pending),/token=test-token&amp;pay=1/);
});
test('buyer contact and origin are readable, escaped and do not expose internal reservation markers',()=>{
 assert.equal(s.orderOrigin(pending),'Reserva web');
 assert.equal(s.orderNotes(pending),'Mesa junto a la ventana');
 assert.equal(s.orderNotes({...pending,cash_payment_notes:'[season-op:x][request:y]'}),'');
 const html=s.renderOrdersRows([{...pending,name:'<script>alert(1)</script>'}],false);
 assert.doesNotMatch(html,/<script>|season-op|request:abc/);
 assert.match(html,/&lt;script&gt;/);
 assert.match(html,/Contacto: \+34 600 000 000/);
 assert.match(html,/Reserva pendiente de pago/);
 assert.equal(s.orderContactUrl({phone:'0034 600 000 000'}),'https://wa.me/34600000000');
 assert.equal(s.orderContactUrl({phone:'600 000 000'}),'https://wa.me/34600000000');
 assert.equal(s.orderContactUrl({phone:'sin teléfono'}),'');
});
test('cash collection is not presented as proof of WhatsApp delivery',()=>{
 const html=s.renderOrdersRows([{...paid,sales_channel:'cash',cash_payment_status:'paid'}],false);
 assert.match(html,/envío manual sin verificar/);
 assert.doesNotMatch(html,/WhatsApp Enviado manualmente/);
 assert.doesNotMatch(s.orderActions({...expired,sales_channel:'cash'}),/Registrar cobro|Confirmar venta/);
});

test('event overview shows reserved and remaining seats and occupancy includes reservations',()=>{
 const html=s.eventCard({id:5,title:'Evento',slug:'evento',sold:2,reserved:3,available:5,capacity:10},false);
 assert.match(html,/3<\/strong> plazas reservadas · 5 disponibles/);
 assert.match(html,/50% ocupado/);
 assert.match(html,/\/admin\/ventas\/\?event=5/);
});

test('event card distinguishes ticket quota from physical capacity',()=>{const html=s.eventCard({id:4,title:'Hora Cero',capacity:100,ticket_capacity:80,available:74,sold:0,reserved:6},false);assert.match(html,/74 disponibles/);assert.match(html,/100 aforo · 80 cupo habilitado · 8% ocupado/);assert.doesNotMatch(html,/94 disponibles/);});

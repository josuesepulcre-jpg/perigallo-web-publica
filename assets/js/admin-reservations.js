(function(){
'use strict';
var money=v=>new Intl.NumberFormat('es-ES',{style:'currency',currency:'EUR'}).format(Number(v||0)/100);
var esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
var economic={pending:'Pendiente de pago',partial:'Parcialmente pagada',paid:'Pagada',partially_refunded:'Devolución parcial',refunded:'Reembolsada'};
var occupancy={confirmed:'Confirmada',pending:'Por confirmar',expired:'Caducada',cancelled:'Cancelada'};
var methods={cash:'Efectivo',card_present:'Tarjeta presencial',card_online:'Tarjeta online',bank_transfer:'Transferencia',other:'Otro método'};
var cashPurposes={deposit:'Señal',advance:'Adelanto'};
var linkStates={pending:'Pendiente',processing:'Pago en curso',paid:'Pagado',failed:'No completado',cancelled:'Cancelado',expired:'Caducado'};
function pill(o){return '<span class="reservation-badge" data-state="'+esc(o.economic_status)+'">'+esc(economic[o.economic_status]||'Pendiente')+'</span>';}
function actions(o){return '<div class="admin-order-actions"><button type="button" class="text-action" data-reservation-open="'+Number(o.id)+'">Ver reserva y pagos</button></div>';}
function table(rows){if(!rows.length)return '<div class="admin-empty"><strong>No hay reservas con estos filtros.</strong></div>';return '<div class="reservation-table-wrap"><table class="reservation-table"><thead><tr>'+['Cliente','Referencia','Personas','Total','Pagado','Pendiente','Estado','Acciones'].map(s=>'<th>'+s+'</th>').join('')+'</tr></thead><tbody>'+rows.map(o=>'<tr>'+[
 '<strong>'+esc(o.name)+'</strong><small>'+esc(o.phone)+'</small>',esc(o.redsys_order),Number(o.ticket_quantity),money(o.total_cents),money(o.paid_cents),'<strong class="reservation-pending">'+money(o.outstanding_cents)+'</strong>',pill(o)+'<small>'+esc(occupancy[o.occupancy_status])+'</small>',actions(o)
 ].map((v,i)=>'<td data-label="'+['Cliente','Referencia','Personas','Total','Pagado','Pendiente','Estado','Acciones'][i]+'">'+v+'</td>').join('')+'</tr>').join('')+'</tbody></table></div>';}
function summary(orders,h){
 var real=orders.filter(o=>!Number(o.is_test)),res=real.filter(o=>o.is_reservation),old=real.filter(o=>!o.is_reservation),active=res.filter(o=>!['cancelled','expired'].includes(o.occupancy_status)),legacyPaid=old.filter(o=>!h.isClosedOrder(o)&&(o.payment_status==='paid'||o.status==='paid'));
 var committed=old.filter(o=>h.isSoldOrder(o)||h.isPendingReservation(o)),seats=a=>a.reduce((n,o)=>n+Number(o.ticket_quantity||0),0),sum=(a,k)=>a.reduce((n,o)=>n+Number(o[k]||0),0);
 var collected=sum(res,'paid_cents')+sum(legacyPaid,'total_cents');
 var by={cash:0,card_online:0,card_present:0,bank_transfer:0,other:0};res.forEach(o=>Object.keys(by).forEach(m=>by[m]+=Number(o.payments_by_method?.[m]||0)));legacyPaid.forEach(o=>by[o.sales_channel==='cash'?'cash':'card_online']+=Number(o.total_cents));
 var pending=old.filter(o=>h.isPendingReservation(o)||h.isDoorPaymentPending(o));
 var cards=[['Plazas comprometidas',seats(active)+seats(committed),'Confirmadas o con reserva vigente'],['Total comprometido',money(sum(active,'total_cents')+sum(committed,'total_cents')),'Reservas y ventas activas'],['Cobrado neto',money(collected),'Cobros menos devoluciones registradas'],['Pendiente de cobro',money(sum(active,'outstanding_cents')+sum(pending,'total_cents')),'No se contabiliza como ingreso'],['Tarjeta online',money(by.card_online),'Incluye ventas web y enlaces'],['Tarjeta presencial',money(by.card_present),'Cobros registrados de TPV externo'],['Efectivo',money(by.cash),'Saldo de cobros y devoluciones'],['Transferencia y otros',money(by.bank_transfer+by.other),'Movimientos registrados'],['Reservas pendientes',active.filter(o=>o.economic_status==='pending').length,'Sin cobros registrados'],['Reservas parciales',active.filter(o=>o.economic_status==='partial').length,'Con saldo pendiente'],['Reservas pagadas',active.filter(o=>o.economic_status==='paid').length,'Importe completo recibido']];
 return cards.map(([l,v,n])=>'<article><span>'+l+'</span><strong>'+v+'</strong><small>'+n+'</small></article>').join('');
}
function whatsAppPhone(phone){
 var digits=String(phone||'').replace(/\D/g,'');
 if(digits.startsWith('00'))digits=digits.slice(2);
 return digits.length===9?'34'+digits:digits;
}
function whatsAppUrl(phone,text){
 return 'https://web.whatsapp.com/send?phone='+encodeURIComponent(whatsAppPhone(phone))+'&text='+encodeURIComponent(text);
}
function cashPurposePrefix(purpose){
 return Object.hasOwn(cashPurposes,purpose)?'[res-cash:'+purpose+'] ':'';
}
function cashPaymentDetails(notes){
 var text=String(notes??''),prefix=/^\[res-cash:(deposit|advance)\](?: |$)/.exec(text);
 return prefix?{purpose:prefix[1],notes:text.slice(prefix[0].length)}:{purpose:'',notes:text};
}
function isCashReceipt(payment){
 var amount=Number(payment?.amount_cents);
 return payment?.kind==='receipt'&&payment.method==='cash'&&!Number(payment.review_required)&&Number.isSafeInteger(amount)&&amount>0;
}
function cashReceiptMessage(order,payment){
 var purpose=cashPurposes[cashPaymentDetails(payment.notes).purpose];
 var date=/^(\d{4})-(\d{2})-(\d{2})(?:[ T]|$)/.exec(payment.paid_at||'');
 return 'Hola '+order.name+',\n\nHemos recibido '+money(payment.amount_cents)+' en efectivo'+(purpose?' como '+purpose.toLowerCase():'')+
  ' para tu reserva en Perigallo'+(order.event_title?' de «'+order.event_title+'»':'')+'.'+
  (date?'\nFecha del pago: '+date[3]+'/'+date[2]+'/'+date[1]:'')+
  '\nReferencia de la reserva: '+order.redsys_order+'\n\nGracias,\nEquipo Perigallo';
}
function paymentRow(payment){
 var cash=isCashReceipt(payment);
 var details=cash?cashPaymentDetails(payment.notes):{purpose:'',notes:payment.notes};
 return '<tr><td>'+esc(payment.paid_at)+'</td><td>'+esc(methods[payment.method]||payment.method)+
  (payment.kind==='refund'?' · Devolución':'')+(Number(payment.review_required)?' · Requiere revisión':'')+
  (details.purpose?'<small>'+cashPurposes[details.purpose]+'</small>':'')+
  '</td><td class="reservation-number">'+(payment.kind==='refund'?'−':'')+money(payment.amount_cents)+
  '</td><td>'+esc(payment.reference||'')+(details.notes?'<small>'+esc(details.notes)+'</small>':'')+
  (cash?'<small><button type="button" class="text-action" data-res-cash-receipt="'+esc(payment.id)+'">Justificante por WhatsApp</button></small>':'')+'</td></tr>';
}
function whatsAppMessage(data,link){
 var o=data.order,confirmed=o.occupancy_status==='confirmed',message='Hola '+o.name+',\n\n'+(confirmed?'Tu reserva está confirmada':'Tu solicitud de reserva está pendiente de confirmación')+' en Perigallo para '+o.event_title+'.'+(data.items?.[0]?.event_starts_at?'\nFecha: '+data.items[0].event_starts_at:'')+'\nReferencia: '+o.redsys_order+'\nPersonas: '+Number(o.ticket_quantity)+'\nTotal: '+money(o.total_cents)+'\nPagado: '+money(o.paid_cents)+'\nPendiente: '+money(o.outstanding_cents);
 if(data.items?.length)message+='\nMenús: '+data.items.map(i=>Number(i.quantity)+' × '+i.ticket_type_name).join(', ');
 if(link)message+='\n\nImporte de este enlace: '+money(link.amount_cents)+'\nVálido hasta: '+link.expires_at+'\nPago seguro: '+link.url;
 if(o.economic_status==='paid'&&confirmed)message+='\n\nTus entradas y códigos QR: '+new URL('/entradas/pedido/?token='+encodeURIComponent(o.public_token),location.origin).href;
 return message+'\n\nGracias por confiar en Perigallo.';
}
function init(options){
 var root=options.root,session=options.session,api=options.request,read=options.read,modal=null,detail=null,meta=null,metaPromise=null,trigger=null;
 var entry=root.querySelector('[data-new-reservation]');
 function loadMeta(){return meta?Promise.resolve(meta):(metaPromise||=read('/api/admin/reservations/meta').then(d=>{meta=d;return d;}).catch(e=>{metaPromise=null;throw e;}));}
 function close(){if(modal)modal.remove();modal=null;detail=null;if(trigger?.isConnected)trigger.focus();}
 function shell(title,content,action=''){
  if(modal)modal.remove();
  modal=document.createElement('dialog');
  modal.className='reservation-dialog';
  modal.setAttribute('aria-labelledby','reservation-dialog-title');
  modal.innerHTML='<div class="reservation-dialog-head"><div><span class="ticket-eyebrow">Reservas y cobros</span><h2 id="reservation-dialog-title">'+esc(title)+'</h2></div><div class="reservation-head-actions">'+action+'<button type="button" class="ticket-btn" data-res-close aria-label="Cerrar reserva">Cerrar</button></div></div><p class="admin-form-status" data-res-message role="status"></p><div data-res-content>'+content+'</div>';
  document.body.append(modal);
  modal.querySelector('[data-res-close]').onclick=close;
  modal.addEventListener('cancel',e=>{e.preventDefault();close();});
  modal.showModal();
 }
 function message(value,state=''){
  var status=modal?.querySelector('[data-res-edit-message]')||modal?.querySelector('[data-res-message]');
  if(status){status.textContent=value;status.dataset.state=state;}
 }
 function field(label,name,value='',extra='',type='text'){if(type==='datetime-local')extra+=' step="1"';return '<label>'+label+'<input name="'+name+'" type="'+type+'" value="'+esc(value)+'" '+extra+'></label>';}
 function methodSelect(){return '<label>Método de pago<select name="method">'+Object.entries(methods).filter(([k])=>k!=='card_online').map(([k,v])=>'<option value="'+k+'">'+v+'</option>').join('')+'</select></label>';}
 function emailResult(result){var m=result.reservation_email;if(!m)return 'Guardado.';if(m.status==='skipped')return 'Enlace creado. Puedes prepararlo para WhatsApp Web Business o copiarlo.';return m.status==='sent'?'Enlace creado. Correo aceptado para envío a '+m.recipient+'.':m.status==='failed'?'El enlace está creado, pero el correo no se ha enviado. Puedes reintentarlo desde el enlace.':'El envío está en curso o pendiente de comprobación. No se enviará un duplicado.';}
 function cached(scope){try{return JSON.parse(sessionStorage.getItem('perigallo-reservation-'+scope))||{};}catch{return {};}}
 async function submit(form,path,method,body,scope){
  var editing=method==='PUT',prior=editing?{}:cached(scope);
  body.request_id=prior.key||form.dataset.requestId||crypto.randomUUID();
  form.dataset.requestId=body.request_id;
  if(!editing)sessionStorage.setItem('perigallo-reservation-'+scope,JSON.stringify({key:body.request_id,body}));
  var button=form.querySelector('[type=submit]');
  button.disabled=true;
  message('Guardando…');
  try{
   var result=await api('/api/admin/reservations'+path,method,body);
   sessionStorage.removeItem('perigallo-reservation-'+scope);
   options.onChange(result);
   if(form.isConnected){
    var payment=scope.startsWith('payments-')&&body.method==='cash'?
     result.payments.find(p=>p.idempotency_key===body.request_id):null;
    if(isCashReceipt(payment)){
     shareWhatsApp(result,{payment});
     message('Pago en efectivo registrado.','success');
    }else{
     showDetail(result);
     message(editing?'Cambios guardados.':emailResult(result),'success');
    }
   }
   return result;
  }catch(e){
   if(form.isConnected)message(e.message,'error');
   throw e;
  }finally{
   if(button.isConnected)button.disabled=false;
  }
 }
 function editForm(current){
  var o=current?.order,oldItems=current?.items||[],selectedEvent=oldItems[0]?.event_id||options.selectedEvent()||'';
  var draft=o?null:cached('create').body;
  var contact=field('Nombre','first_name',o?.first_name||'','required maxlength="120" autocomplete="given-name"')+
   field('Apellidos','last_name',o?.last_name||'','required maxlength="160" autocomplete="family-name"')+
   field('Teléfono','phone',o?.phone||'','required maxlength="60" autocomplete="tel"','tel')+
   field('Correo electrónico (opcional)','email',o?.email||'','maxlength="190" autocomplete="email"','email');
  var body='<form class="admin-cash-order-form reservation-form" data-res-edit>'+
   (o?'<p class="admin-form-span reservation-edit-context">'+esc(o.event_title)+' · #'+esc(o.redsys_order)+'</p><input type="hidden" name="event_id" value="'+selectedEvent+'"><h3 class="admin-form-span reservation-edit-heading">Titular de la reserva</h3>'+contact:
    '<label class="admin-form-span">Evento<select name="event_id" required><option value="">Selecciona un evento</option>'+meta.events.filter(e=>!['draft','archived','cancelled'].includes(e.status)).map(e=>'<option value="'+e.id+'" '+(String(e.id)===String(selectedEvent)?'selected':'')+'>'+esc(e.title)+'</option>').join('')+'</select></label>')+
   '<section class="admin-form-span reservation-menu-section"><h3>Menús y personas</h3><div data-res-menus></div></section>'+
   '<div class="reservation-form-total admin-form-span"><span>Total de la reserva · <span data-res-persons>0 personas</span></span><strong data-res-total>'+money(o?.total_cents)+'</strong>'+
   (o?'<small>Pagado '+money(o.paid_cents)+' · Pendiente <span data-res-pending></span></small>':'')+'</div>'+
   (o?'':contact)+
   '<label>Estado de ocupación<select name="occupancy_status"><option value="confirmed">Confirmada · plazas asignadas</option><option value="pending">Pendiente de confirmar · reserva temporal</option></select></label>'+
   field('Fecha límite (opcional)','reservation_expires_at',o?.reservation_expires_at?.replace(' ','T')||'','','datetime-local')+
   (!o?'<label class="admin-form-span">Cupo de plazas<select name="inventory_mode"><option value="standard">Cupo general del evento</option><option value="manual_reserve">Cupo adicional de venta manual</option></select></label>':'')+
   '<label class="admin-form-span">Observaciones internas<textarea name="notes" maxlength="1000">'+esc(o?.cash_payment_notes||'')+'</textarea></label>'+
   '<footer class="admin-form-span'+(o?' reservation-edit-footer':'')+'"><p class="admin-form-status" data-res-edit-message role="status"></p><div class="admin-cash-form-actions">'+
   (o?'<button type="button" class="ticket-btn" data-res-edit-back>Volver a la ficha</button>':'')+
   '<button type="submit" class="ticket-btn primary">'+(o?'Guardar cambios':'Crear reserva sin pago')+'</button></div></footer></form>';
  shell(o?'Editar reserva':'Nueva reserva',body);
  var f=modal.querySelector('form'),menus=f.querySelector('[data-res-menus]');
  var people=new Map();
  var names=new Map((current?.attendees||[]).map(a=>[a.ticket_type_id+':'+a.ticket_sequence,a.attendee_name]));
  function types(saved){
   var event=meta.events.find(e=>String(e.id)===f.event_id.value);
   var available=(event?.ticket_types||[]).filter(t=>t.active&&!['draft','archived'].includes(t.status));
   oldItems.forEach(i=>{if(!available.some(t=>Number(t.id)===Number(i.ticket_type_id)))available.push({id:i.ticket_type_id,name:i.ticket_type_name,final_price_cents:i.unit_price_cents,active:false});});
   menus.innerHTML=available.length?available.map((t,index)=>{
    var old=oldItems.find(i=>Number(i.ticket_type_id)===Number(t.id)),prior=saved?.find(i=>Number(i.ticket_type_id)===Number(t.id));
    var qty=prior?.quantity??old?.quantity??(!o&&index===0?1:0),max=t.active?5000:Number(old?.quantity||0);
    var price=prior?.unit_price_euros??(Number(old?.unit_price_cents??t.final_price_cents??t.price_cents)/100).toFixed(2);
    var menuName=esc(t.name);
    if(o)people.set(String(t.id),Array.from({length:Number(qty)},(_,i)=>({position:i+1,name:names.get(t.id+':'+(i+1))||''})));
    return '<fieldset class="reservation-menu-line" data-res-menu="'+Number(t.id)+'"><legend>'+menuName+(t.active?'':' · Cerrado a nuevas plazas')+'</legend>'+
     (o?'<div class="reservation-quantity"><label for="reservation-qty-'+Number(t.id)+'">Personas</label><div>'+
      '<button type="button" data-menu-step="-1" aria-label="Quitar una plaza de '+menuName+'">−</button>'+
      '<input id="reservation-qty-'+Number(t.id)+'" type="number" inputmode="numeric" value="'+qty+'" required min="0" max="'+max+'" step="1" data-menu-quantity>'+
      '<button type="button" data-menu-step="1" aria-label="Añadir una plaza de '+menuName+'">+</button></div></div>':
      field('Personas · '+menuName,'menu_qty_'+t.id,qty,'required min="0" max="'+max+'" step="1" data-menu-quantity','number'))+
     field('Precio por persona (€)','menu_price_'+t.id,price,'required min="0" step="0.01" data-menu-price '+(session.is_owner?'':'readonly'),'number')+
     '<div class="reservation-menu-subtotal"><span>Subtotal</span><strong data-menu-total></strong></div>'+
     (o?'<details class="reservation-attendees" data-menu-people><summary>Nombres de asistentes <span>(opcional)</span></summary><div data-menu-names></div></details>':'')+
     '</fieldset>';
   }).join(''):'<p>Selecciona un evento con menús activos.</p>';
   if(o)menus.querySelectorAll('[data-res-menu]').forEach(row=>renderPeople(row,false));
   total();
  }
  function renderPeople(row,focusAfterRemoval){
   var slots=people.get(row.dataset.resMenu),details=row.querySelector('[data-menu-people]'),list=row.querySelector('[data-menu-names]');
   var qty=row.querySelector('[data-menu-quantity]'),less=row.querySelector('[data-menu-step="-1"]'),more=row.querySelector('[data-menu-step="1"]');
   less.disabled=slots.length===0;
   more.disabled=slots.length>=Number(qty.max);
   details.hidden=slots.length===0;
   if(!details.open){list.replaceChildren();return;}
   list.innerHTML=slots.map((slot,i)=>'<div class="reservation-attendee"><label><span>Persona '+(i+1)+'</span><input type="text" maxlength="190" value="'+esc(slot.name)+'" data-menu-name="'+i+'"></label><button type="button" class="text-action" data-menu-remove="'+i+'" aria-label="Quitar a '+(slot.name?esc(slot.name):'la persona '+(i+1))+'">Quitar</button></div>').join('');
   if(focusAfterRemoval!==false)(list.querySelectorAll('[data-menu-remove]')[Math.min(focusAfterRemoval,slots.length-1)]||(more.disabled?qty:more)).focus();
  }
  function resizePeople(row){
   var input=row.querySelector('[data-menu-quantity]');
   if(!input.validity.valid)return;
   var slots=people.get(row.dataset.resMenu),size=Number(input.value);
   if(size===slots.length)return;
   if(slots.length>size)slots.length=size;
   while(slots.length<size)slots.push({position:null,name:''});
   renderPeople(row,false);
  }
  function menuItems(){
   return Array.from(menus.querySelectorAll('[data-res-menu]')).map(row=>({ticket_type_id:Number(row.dataset.resMenu),quantity:Number(row.querySelector('[data-menu-quantity]').value),unit_price_euros:row.querySelector('[data-menu-price]').value})).filter(i=>i.quantity>0);
  }
  function total(){
   var sum=0,count=0;
   menus.querySelectorAll('[data-res-menu]').forEach(row=>{
    var qty=Number(row.querySelector('[data-menu-quantity]').value)||0,amount=Math.round(Number(row.querySelector('[data-menu-price]').value||0)*100)*qty;
    sum+=amount;
    count+=qty;
    row.querySelector('[data-menu-total]').textContent=money(amount);
   });
   f.querySelector('[data-res-total]').textContent=money(sum);
   f.querySelector('[data-res-persons]').textContent=count+(count===1?' persona':' personas');
   if(o)f.querySelector('[data-res-pending]').textContent=money(Math.max(0,sum-Number(o.paid_cents)));
  }
  if(o){
   f.occupancy_status.value=o.occupancy_status==='pending'?'pending':'confirmed';
   f.querySelector('[data-res-edit-back]').onclick=()=>showDetail(current);
   menus.addEventListener('toggle',e=>{
    if(e.target.matches('[data-menu-people]'))renderPeople(e.target.closest('[data-res-menu]'),false);
   },true);
   menus.addEventListener('change',e=>{
    if(e.target.matches('[data-menu-quantity]'))resizePeople(e.target.closest('[data-res-menu]'));
   });
   menus.addEventListener('click',e=>{
    var button=e.target.closest('[data-menu-step],[data-menu-remove]');
    if(!button)return;
    var row=button.closest('[data-res-menu]'),input=row.querySelector('[data-menu-quantity]'),slots=people.get(row.dataset.resMenu);
    if(button.hasAttribute('data-menu-remove')){
     var index=Number(button.dataset.menuRemove);
     slots.splice(index,1);
     input.value=slots.length;
     renderPeople(row,index);
    }else{
     input.value=slots.length+Number(button.dataset.menuStep);
     resizePeople(row);
     if(button.disabled)input.focus();
    }
    total();
   });
  }
  if(draft){
   f.event_id.value=draft.event_id;
   for(var [k,v]of Object.entries(draft)){var el=f.elements.namedItem(k);if(el&&k!=='event_id')el.value=v;}
  }
  types(draft?.items||(draft?.ticket_type_id?[draft]:null));
  if(!o)f.event_id.onchange=()=>types();
  menus.oninput=e=>{
   if(o&&e.target.matches('[data-menu-name]')){
    var index=Number(e.target.dataset.menuName);
    people.get(e.target.closest('[data-res-menu]').dataset.resMenu)[index].name=e.target.value;
    e.target.closest('.reservation-attendee').querySelector('[data-menu-remove]').setAttribute('aria-label',e.target.value?'Quitar a '+e.target.value:'Quitar a la persona '+(index+1));
   }else total();
  };
  f.onsubmit=event=>{
   event.preventDefault();
   if(!f.reportValidity())return;
   var values=Object.fromEntries(new FormData(f));
   values.items=menuItems();
   if(!values.items.length){message('Indica al menos una persona en uno de los menús.','error');return;}
   if(values.items.reduce((n,i)=>n+i.quantity,0)>5000){message('La reserva no puede superar las 5000 personas.','error');return;}
   Object.keys(values).filter(k=>k.startsWith('menu_qty_')||k.startsWith('menu_price_')).forEach(k=>delete values[k]);
   if(o){
    menus.querySelectorAll('[data-res-menu]').forEach(resizePeople);
    values.expected_revision=o.reservation_revision;
    values.people=Object.fromEntries(values.items.map(i=>[i.ticket_type_id,people.get(String(i.ticket_type_id))]));
   }
   submit(f,o?'/'+o.id:'',o?'PUT':'POST',values,o?'edit-'+o.id:'create').catch(()=>{});
  };
 }
 function shareWhatsApp(data,{link,payment}={}){
  var o=data.order,canOpen=!payment||/^[1-9]\d{6,14}$/.test(whatsAppPhone(o.phone));
  var title=payment?'Justificante de pago en efectivo':link?'Enviar enlace de pago':'Confirmación de reserva';
  var instructions=canOpen?'Revisa el mensaje y confirma el envío en tu sesión de WhatsApp Web Business.':'La reserva no tiene un teléfono válido para WhatsApp. Puedes copiar el justificante.';
  shell(title,'<p>'+instructions+'</p><label class="ticket-field">Mensaje<textarea data-res-share-text '+(payment?'rows="10" readonly':'rows="12"')+'></textarea></label>'+
   '<div class="reservation-row-actions">'+(canOpen?'<a class="ticket-btn primary" data-res-share-web target="_blank" rel="noopener noreferrer">Abrir WhatsApp Web Business</a>':'')+
   '<button type="button" class="ticket-btn" data-res-share-copy>Copiar mensaje</button><button type="button" class="ticket-btn" data-res-share-back>Volver a la reserva</button></div>');
  var text=modal.querySelector('[data-res-share-text]'),web=modal.querySelector('[data-res-share-web]');
  text.value=payment?cashReceiptMessage(o,payment):whatsAppMessage(data,link);
  if(web){var update=()=>{web.href=whatsAppUrl(o.phone,text.value);};update();text.oninput=update;}
  modal.querySelector('[data-res-share-copy]').onclick=()=>{if(!navigator.clipboard){text.focus();text.select();message('Selecciona y copia el mensaje.');return;}navigator.clipboard.writeText(text.value).then(()=>message('Mensaje copiado. Pégalo en WhatsApp Business.')).catch(()=>{text.focus();text.select();message('Selecciona y copia el mensaje.');});};
  modal.querySelector('[data-res-share-back]').onclick=()=>showDetail(data);
  message('Preparar o abrir el mensaje no lo marca como enviado.');
 }
 function showDetail(data){
  detail=data;var o=data.order,closed=['cancelled','expired'].includes(o.occupancy_status),canPay=!closed&&Number(o.outstanding_cents)>0;
  var payments=data.payments.map(paymentRow).join('');
  var links=data.links.map(l=>'<li><div><strong>'+money(l.amount_cents)+'</strong><span>'+esc(linkStates[l.status])+'</span><small>Creado '+esc(l.created_at)+' · Vence '+esc(l.expires_at)+'</small><small>'+esc(({sent:'Correo aceptado para envío',failed:'Correo no enviado',pending:'Envío en curso o pendiente de comprobación'})[l.email_status]||'Sin envío de correo')+'</small></div><div class="reservation-row-actions">'+(['pending','processing'].includes(l.status)?'<button type="button" class="ticket-btn" data-res-copy="'+l.id+'">Copiar enlace</button><a class="text-action" href="'+esc(l.url)+'" target="_blank" rel="noopener">Ver enlace</a>':'')+(l.status==='pending'?'<button type="button" class="ticket-btn" data-res-whatsapp-link="'+l.id+'">Preparar pago en WhatsApp</button>':'')+(l.status==='pending'&&o.email&&l.email_status==='failed'?'<button type="button" class="ticket-btn" data-res-email="'+l.id+'">Reintentar correo</button>':'')+(['pending','failed'].includes(l.status)?'<button type="button" class="text-action" data-res-cancel-link="'+l.id+'">Cancelar enlace</button>':'')+(l.status==='processing'&&session.is_owner?'<button type="button" class="text-action" data-res-reconcile="'+l.id+'">Revisar pago en curso</button>':'')+'</div></li>').join('');
  var editAction=o.occupancy_status==='cancelled'?'':'<button type="button" class="ticket-btn primary" data-res-edit-open>Editar reserva</button>';
  shell(o.name,'<div class="reservation-detail-meta">'+pill(o)+'<span>Plazas: '+esc(occupancy[o.occupancy_status])+'</span><span>'+esc(o.event_title)+' · '+Number(o.ticket_quantity)+' personas · #'+esc(o.redsys_order)+'</span><span>'+esc(o.phone)+(o.email?' · '+esc(o.email):'')+'</span></div>'+
   '<section class="reservation-history"><h3>Menús reservados</h3>'+data.items.map(i=>{
    var named=(data.attendees||[]).filter(a=>Number(a.ticket_type_id)===Number(i.ticket_type_id)&&a.attendee_name).map(a=>esc(a.attendee_name));
    return '<p>'+Number(i.quantity)+' × '+esc(i.ticket_type_name)+' · '+money(i.unit_price_cents)+' por persona · '+money(i.total_cents)+(named.length?'<small class="reservation-named-guests">'+named.join(' · ')+'</small>':'')+'</p>';
   }).join('')+'</section><div class="reservation-balance">'+[['Total reserva',o.total_cents],['Pagado',o.paid_cents],['Pendiente',o.outstanding_cents]].map(([l,v])=>'<div><span>'+l+'</span><strong>'+money(v)+'</strong></div>').join('')+'</div>'+
   (Number(o.refunded_cents)?'<p>Devoluciones registradas: '+money(o.refunded_cents)+'</p>':'')+(Number(o.credit_cents)||Number(o.review_cents)?'<p class="is-error">Pago que requiere revisión: '+money(o.review_cents||o.credit_cents)+'. Revisa el historial antes de registrar nuevos cobros.</p>':'')+
   (Number(data.processing_cents)?'<p class="reservation-processing">Hay '+money(data.processing_cents)+' en curso en la pasarela. Ese importe queda reservado hasta que se confirme el resultado.</p>':'')+
   '<div class="reservation-row-actions">'+(!closed?'<button type="button" class="ticket-btn" data-res-whatsapp>Confirmación por WhatsApp</button>':'')+(canPay?'<button type="button" class="ticket-btn primary" data-res-operation="payments">Registrar pago manual</button><button type="button" class="ticket-btn" data-res-operation="links">Generar enlace de pago</button>':'')+(!closed?'<button type="button" class="text-action" data-res-operation="cancel">Cancelar reserva</button>':'')+(session.is_owner&&Number(o.paid_cents)>0?'<button type="button" class="text-action" data-res-operation="refunds">Registrar devolución</button>':'')+(o.economic_status==='paid'&&!closed?'<a class="ticket-btn" target="_blank" rel="noopener" href="/entradas/pedido/?token='+encodeURIComponent(o.public_token)+'">Ver entradas</a>':'')+'<button type="button" class="text-action" data-res-refresh>Actualizar ficha</button></div><div data-res-operation-form></div>'+
   '<section class="reservation-history"><h3>Historial de pagos</h3>'+(payments?'<div class="reservation-table-wrap"><table class="reservation-table reservation-ledger"><thead><tr><th>Fecha y hora</th><th>Método</th><th>Importe</th><th>Referencia / notas</th></tr></thead><tbody>'+payments+'</tbody></table></div>':'<p>No se ha registrado ningún pago.</p>')+'</section>'+
   '<section class="reservation-history"><h3>Enlaces de pago</h3>'+(links?'<ul class="reservation-links">'+links+'</ul>':'<p>No hay enlaces de pago.</p>')+'</section>'+
   '<section class="reservation-history"><h3>Observaciones internas</h3><p>'+esc(o.cash_payment_notes||'Sin observaciones.')+'</p></section><details class="reservation-history"><summary>Actividad de la reserva</summary>'+data.history.map(h=>'<p><time>'+esc(h.created_at)+'</time> · '+esc(h.actor)+' · '+esc(({reservation_email_requested:'Correo de reserva solicitado',reservation_created:'Reserva creada',reservation_updated:'Reserva modificada',reservation_payment_recorded:'Pago manual registrado',reservation_online_payment_confirmed:'Pago online confirmado',reservation_payment_link_created:'Enlace creado',reservation_cancelled:'Reserva cancelada',reservation_refund_recorded:'Devolución registrada',reservation_payment_link_cancelled:'Enlace cancelado',reservation_payment_attempt_reconciled:'Intento cerrado tras comprobación en Redsys',reservation_online_payment_review:'Pago online pendiente de revisión'})[h.action]||h.action)+'</p>').join('')+'</details>',editAction);
  detail=data;
  modal.querySelector('[data-res-whatsapp]')?.addEventListener('click',()=>shareWhatsApp(data));
  modal.querySelectorAll('[data-res-whatsapp-link]').forEach(b=>b.onclick=()=>shareWhatsApp(data,{link:data.links.find(l=>String(l.id)===b.dataset.resWhatsappLink)}));
  modal.querySelectorAll('[data-res-cash-receipt]').forEach(b=>b.onclick=()=>{
   var payment=data.payments.find(p=>String(p.id)===b.dataset.resCashReceipt);
   if(isCashReceipt(payment))shareWhatsApp(data,{payment});
  });
  modal.querySelector('[data-res-refresh]').onclick=()=>open(o.id);
  var editButton=modal.querySelector('[data-res-edit-open]');
  if(editButton)editButton.onclick=async()=>{
   editButton.disabled=true;
   message('Cargando…');
   try{await loadMeta();if(editButton.isConnected)editForm(data);}
   catch(e){if(editButton.isConnected)message(e.message,'error');}
   finally{editButton.disabled=false;}
  };
  modal.querySelectorAll('[data-res-operation]').forEach(b=>b.onclick=()=>operation(b.dataset.resOperation,data));
  modal.querySelectorAll('[data-res-copy]').forEach(b=>b.onclick=()=>{var l=data.links.find(l=>String(l.id)===b.dataset.resCopy);navigator.clipboard.writeText(l.url).then(()=>message('Enlace copiado. Puedes enviarlo manualmente al cliente.')).catch(()=>message('No se pudo copiar. Abre «Ver enlace» y copia su dirección.'));});
  modal.querySelectorAll('[data-res-email]').forEach(b=>b.onclick=async()=>{b.disabled=true;try{var r=await api('/api/admin/reservations/'+o.id+'/links/'+b.dataset.resEmail+'/email','POST',{});showDetail(r);message(emailResult(r));}catch(e){message(e.message);b.disabled=false;}});
  modal.querySelectorAll('[data-res-reconcile]').forEach(b=>b.onclick=()=>{
    var box=modal.querySelector('[data-res-operation-form]');box.innerHTML='<form class="admin-cash-order-form reservation-operation"><h3 class="admin-form-span">Cerrar un intento sin cargo</h3><p class="admin-form-span">Comprueba primero el intento en Redsys. Esta acción no devuelve dinero ni registra un cobro.</p><label class="admin-form-span">Motivo o referencia de comprobación<textarea name="reason" required maxlength="1000"></textarea></label><label class="admin-form-span reservation-confirm"><input type="checkbox" name="confirmed_no_charge" required> He comprobado en Redsys que no se ha realizado el cargo.</label><button class="ticket-btn primary" type="submit">Cerrar intento y cancelar enlace</button></form>';
    var f=box.querySelector('form');f.onsubmit=async e=>{e.preventDefault();if(!f.reportValidity())return;f.querySelector('[type=submit]').disabled=true;try{var r=await api('/api/admin/reservations/'+o.id+'/links/'+b.dataset.resReconcile+'/cancel','POST',{expected_revision:o.reservation_revision,confirmed_no_charge:f.confirmed_no_charge.checked,reason:f.reason.value});options.onChange(r);showDetail(r);message('Intento cerrado tras la comprobación manual.');}catch(err){message(err.message);f.querySelector('[type=submit]').disabled=false;}};
  });
  modal.querySelectorAll('[data-res-cancel-link]').forEach(b=>b.onclick=async()=>{b.disabled=true;try{var r=await api('/api/admin/reservations/'+o.id+'/links/'+b.dataset.resCancelLink+'/cancel','POST',{expected_revision:o.reservation_revision});options.onChange(r);showDetail(r);message('Enlace cancelado.');}catch(e){message(e.message);b.disabled=false;}});
 }
 function operation(kind,data){
  var o=data.order,box=modal.querySelector('[data-res-operation-form]'),scope=kind+'-'+o.id,previous={...cached(scope).body};
  var label={payments:'Registrar pago manual',links:'Generar enlace de pago',refunds:'Registrar devolución',cancel:'Cancelar reserva'}[kind];
  var cashConcept=kind==='payments'?'<label data-res-cash-purpose>Concepto<select name="cash_purpose"><option value="">Pago en efectivo</option>'+
   Object.entries(cashPurposes).map(([key,label])=>'<option value="'+key+'">'+label+'</option>').join('')+'</select></label>':'';
  if(kind==='payments'&&previous.method==='cash'){
   var previousCash=cashPaymentDetails(previous.notes);
   previous.notes=previousCash.notes;
   previous.cash_purpose=previousCash.purpose;
  }
  box.innerHTML='<form class="admin-cash-order-form reservation-operation"><h3 class="admin-form-span">'+label+'</h3>'+(kind==='cancel'?'<p class="admin-form-span">Se liberarán las plazas. Los pagos recibidos se conservan y no se devuelve dinero automáticamente.</p><label class="admin-form-span">Motivo<textarea name="reason" required maxlength="1000"></textarea></label>':field('Importe (€)','amount_euros',previous.amount_euros??(kind==='payments'?'':((kind==='refunds'?Number(o.paid_cents):Math.max(0,Number(o.outstanding_cents)-Number(data.processing_cents)))/100).toFixed(2)),'required min="0.01" step="0.01"','number')+(kind==='links'?'<p class="admin-form-span">'+'Puedes enviar el enlace por WhatsApp Web Business o copiarlo. El correo es opcional.'+'</p>'+(o.email?'<label class="admin-form-span reservation-confirm"><input type="checkbox" name="send_email" checked> Enviar también por correo a '+esc(o.email)+'</label>':'')+field('Caducidad del enlace (opcional)','expires_at','','','datetime-local'):methodSelect()+cashConcept+field('Fecha y hora del movimiento','paid_at','','','datetime-local')+field('Referencia o justificante','reference','','maxlength="190"')+'<label class="admin-form-span">'+(kind==='payments'?'Observaciones internas':'Observaciones')+'<textarea name="notes" maxlength="1000"></textarea></label>')+(kind==='payments'?'<p class="admin-form-span" data-res-payment-help></p>':'')+(kind==='refunds'?'<label class="admin-form-span reservation-confirm"><input type="checkbox" name="confirmed" required> Confirmo que ya he realizado esta devolución fuera de la aplicación.</label>':''))+
   '<div class="admin-cash-form-actions admin-form-span"><button type="submit" class="ticket-btn primary">'+label+'</button><button type="button" class="ticket-btn" data-res-operation-close>Cerrar</button></div></form>';
  var f=box.querySelector('form');for(var [k,v]of Object.entries(previous)){var el=f.elements.namedItem(k);if(el){if(el.type==='checkbox')el.checked=!!v;else el.value=v;}}f.querySelector('[data-res-operation-close]').onclick=()=>box.replaceChildren();f.querySelector('input,textarea')?.focus();
  if(kind==='payments'){
   var method=f.elements.namedItem('method'),purpose=f.elements.namedItem('cash_purpose'),notes=f.elements.namedItem('notes');
   var concept=f.querySelector('[data-res-cash-purpose]'),save=f.querySelector('[type=submit]'),help=f.querySelector('[data-res-payment-help]');
   function syncPaymentMethod(){
    var cash=method.value==='cash';
    concept.style.display=cash?'':'none';
    purpose.disabled=!cash;
    notes.maxLength=1000-(cash?cashPurposePrefix(purpose.value).length:0);
    save.textContent=cash?'Guardar y preparar justificante':label;
    help.textContent=cash?'Registra solo el importe que ya has recibido. Si dejas la fecha vacía, se usará la del registro.':method.value==='card_present'?'Registra un cobro ya realizado en un TPV externo.':'';
    help.hidden=!help.textContent;
   }
   method.onchange=purpose.onchange=syncPaymentMethod;
   syncPaymentMethod();
  }
  f.onsubmit=e=>{
   e.preventDefault();
   if(!f.reportValidity())return;
   var body=Object.fromEntries(new FormData(f));
   body.expected_revision=o.reservation_revision;
   if(kind==='payments'&&body.method==='cash'){
    // Reuse the ledger notes field so historical entries and the payment API stay compatible.
    body.notes=cashPurposePrefix(body.cash_purpose)+body.notes;
    if(Array.from(body.notes).length>1000){message('Acorta las observaciones antes de guardar.','error');return;}
   }
   delete body.cash_purpose;
   if(kind==='links')body.send_email=!!f.elements.namedItem('send_email')?.checked;
   if(kind==='refunds')body.confirmed=!!f.confirmed.checked;
   submit(f,'/'+o.id+'/'+kind,'POST',body,scope).catch(()=>{});
  };
 }
 async function open(id){trigger=document.activeElement;try{var data=await read('/api/admin/reservations/'+id);showDetail(data);}catch(e){options.error(e.message);}}
 root.addEventListener('click',e=>{var b=e.target.closest('[data-reservation-open]');if(b)open(Number(b.dataset.reservationOpen));});
 entry?.addEventListener('click',async()=>{trigger=entry;entry.disabled=true;try{await loadMeta();editForm(null);}catch(e){options.error(e.message);}finally{entry.disabled=false;}});
 return {open};
}
window.PerigalloReservations={init,table,summary,actions,pill,whatsAppMessage,whatsAppUrl,cashReceiptMessage,paymentRow};
})();

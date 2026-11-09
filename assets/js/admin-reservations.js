(function () {
  'use strict';
  var labels = {held:'Reserva interna',payment_requested:'Enlace preparado',confirmed:'Confirmada',expired:'Caducada',cancelled:'Cancelada',review:'Cobro para revisar'};
  var currency = new Intl.NumberFormat('es-ES',{style:'currency',currency:'EUR'});
  function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function places(value){return Number(value)+' '+(Number(value)===1?'plaza':'plazas');}
  function normalized(value){return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('es');}
  function money(value){return currency.format(Number(value||0)/100);}
  function date(value){return value?new Intl.DateTimeFormat('es-ES',{dateStyle:'medium',timeStyle:'short'}).format(new Date(String(value).replace(' ','T'))):'Sin fecha límite';}
  function recipient(value){var digits=String(value||'').replace(/\D/g,'').replace(/^00/,'');return digits.length===9?'34'+digits:digits;}
  function operationKey(){return crypto.randomUUID?crypto.randomUUID():Array.from(crypto.getRandomValues(new Uint8Array(24)),function(n){return n.toString(16).padStart(2,'0');}).join('');}
  function price(type){return Number(type.final_price_cents!=null?type.final_price_cents:Number(type.price_cents||0)+Math.round(Number(type.price_cents||0)*Number(type.tax_rate||0)/100)+Number(type.fee_cents||0));}
  function init(root,config){
    if(root.dataset.reservationsReady)return;root.dataset.reservationsReady='1';
    var $=function(s){return root.querySelector(s);};
    var state={eventId:Number(new URLSearchParams(location.search).get('event'))||0,event:null,rows:[],filter:'held',query:'',page:1,pages:1,total:0,request:0,detailRequest:0,detail:null,decision:null,loadError:false};
    var select=$('[data-reservation-event]'),editor=$('[data-reservation-editor]'),detail=$('[data-reservation-detail]'),decision=$('[data-reservation-decision]'),form=$('[data-reservation-form]'),decisionForm=$('[data-decision-form]');
    var lastFocus=null,searchTimer=null;
    function status(message,error){var node=$('[data-reservation-status]');node.textContent=message;node.classList.toggle('is-error',!!error);}
    function api(path,method,data){return fetch('/api'+path,{method:method||'GET',credentials:'same-origin',headers:method?{'Content-Type':'application/json','X-CSRF-Token':config.csrf}:{},body:method?JSON.stringify(data||{}):undefined}).then(function(response){return response.json().then(function(result){if(!response.ok||!result.ok)throw new Error(result.error||'No se pudo completar la solicitud.');return result;});});}
    function show(dialog){lastFocus=document.activeElement;if(!dialog.open)dialog.showModal();}
    function close(dialog){if(dialog===editor&&form.dataset.saving==='1')return;if(dialog===decision&&decisionForm.dataset.saving==='1')return;dialog.close();if(lastFocus&&lastFocus.isConnected)lastFocus.focus();}
    root.querySelectorAll('[data-close-reservation]').forEach(function(button){button.addEventListener('click',function(){close(button.closest('dialog'));});});
    [editor,decision].forEach(function(dialog){dialog.addEventListener('cancel',function(e){if((dialog===editor?form:decisionForm).dataset.saving==='1')e.preventDefault();});});
    function renderInventory(){
      var e=state.event;if(!e)return;
      $('[data-reservation-stats]').innerHTML=[['Confirmadas',e.sold],['Plazas reservadas',e.reserved],['Disponibles',e.available],['Aforo',e.capacity]].map(function(v){return '<article><span>'+v[0]+'</span><strong>'+Number(v[1]||0)+'</strong></article>';}).join('');
      $('[data-reservation-stats]').setAttribute('aria-busy','false');
      $('[data-reservation-event-info]').textContent=date(e.starts_at)+(e.is_test?' · Evento de prueba':'');
      $('[data-reservation-sales]').href='/admin/ventas/?event='+state.eventId;
      $('[data-new-reservation]').disabled=['cancelled','finished','archived'].indexOf(e.status)!==-1||!e.ticket_types||!e.ticket_types.length||Number(e.available)<=0;
      var url=new URL(location.href);url.searchParams.set('event',state.eventId);history.replaceState(null,'',url);
    }
    function matches(row){return (state.filter==='all'||row.reservation_state===state.filter)&&(!state.query||normalized([row.name,row.phone,row.email,row.redsys_order].join(' ')).indexOf(normalized(state.query))!==-1);}
    function renderList(){
      var rows=state.rows.filter(matches),list=$('[data-reservation-list]');list.setAttribute('aria-busy','false');
      list.innerHTML=rows.length?rows.map(function(r){return '<article class="reservation-row"><div><h3>'+esc(r.name)+'</h3><small>Ref. '+esc(r.redsys_order)+' · '+esc(date(r.created_at))+'</small></div><div><small>'+esc(r.phone)+'</small><small>'+esc(r.email||'Sin email')+'</small></div><div><strong>'+places(r.quantity)+'</strong><small>'+money(r.total_cents)+'</small></div><div><span class="reservation-badge is-'+esc(r.reservation_state)+'">'+esc(labels[r.reservation_state])+'</span><small>'+(r.reservation_state==='confirmed'?(r.payment_status==='paid'?'Cobrado':'Cobro pendiente en puerta'):(r.reservation_state==='cancelled'?'Plazas liberadas':'Límite: '+esc(date(r.reservation_expires_at))))+'</small></div><button type="button" class="ticket-btn" data-open-reservation="'+r.id+'">Gestionar</button></article>';}).join(''):'<div class="reservation-empty"><strong>'+(state.query||state.filter!=='all'?'No hay reservas con estos filtros.':'Todavía no hay reservas internas.')+'</strong><span>'+(state.query||state.filter!=='all'?'Prueba otro estado o término de búsqueda.':'Añade la primera reserva para bloquear las plazas de este evento.')+'</span></div>';
      $('[data-reservation-pagination]').textContent='Página '+state.page+' de '+state.pages;
      $('[data-reservation-prev]').disabled=state.page<=1;$('[data-reservation-next]').disabled=state.page>=state.pages;
    }
    function load(meta){
      if(!state.eventId)return Promise.resolve();var serial=++state.request,eventId=state.eventId;
      status('Cargando reservas…');$('[data-reservation-list]').setAttribute('aria-busy','true');
      return api('/admin/events/'+eventId+'/reservations?'+new URLSearchParams({page:state.page,state:state.filter,q:state.query,meta:meta?'1':'0'})).then(function(data){
        if(serial!==state.request||eventId!==state.eventId)return;
        state.rows=data.reservations;state.page=data.page;state.pages=data.pages;state.total=data.total;state.loadError=false;
        state.event=Object.assign({},state.event&&state.event.id===eventId?state.event:{},data.event);renderInventory();renderList();status(data.total+' reservas · '+Number(data.event.reserved)+' plazas pendientes en el evento.');
      }).catch(function(error){if(serial!==state.request)return;state.loadError=true;$('[data-reservation-list]').setAttribute('aria-busy','false');$('[data-reservation-list]').innerHTML='<div class="reservation-empty"><strong>No se pudieron cargar las reservas.</strong><span>'+esc(error.message)+'</span><p><button type="button" class="ticket-btn" data-retry-reservations>Volver a intentar</button></p></div>';status(error.message,true);});
    }
    function apply(result,created){
      var r=result.reservation,old=state.rows.find(function(x){return x.id===r.id;});
      if(created){if(matches(r))state.total++;}else if(old&&matches(old)!==matches(r))state.total+=matches(r)?1:-1;
      state.pages=Math.max(1,Math.ceil(state.total/20));
      if(old)state.rows=state.rows.map(function(x){return x.id===r.id?r:x;});else if(created){state.rows.unshift(r);state.rows=state.rows.slice(0,20);}
      state.event=Object.assign({},state.event,result.event);state.detail=r;renderInventory();renderList();
      status(created?'Reserva guardada. '+r.quantity+' plazas bloqueadas en el aforo.':'Reserva actualizada y confirmada por el servidor.');
      return r;
    }
    function total(){
      var value=0,quantity=0;form.querySelectorAll('[data-ticket-type]').forEach(function(input){var type=state.event.ticket_types.find(function(t){return Number(t.id)===Number(input.dataset.ticketType);});var n=Number(input.value)||0;quantity+=n;value+=n*price(type);});
      $('[data-reservation-total]').innerHTML='<span>'+places(quantity)+'</span><strong>'+money(value)+'</strong>';
    }
    function openEditor(row){
      form.reset();form.elements.id.value=row?row.id:'';form.operation_key.value=operationKey();form.dataset.saving='0';
      $('#reservation-editor-title').textContent=row?'Editar reserva':'Nueva reserva';
      $('[data-editor-lead]').textContent=row?'Actualiza los datos de contacto y las notas internas. Las plazas y el importe se conservan.':'Se guarda para el equipo y bloquea aforo. El pago y la confirmación se preparan después.';
      $('[data-editor-status]').textContent='';$('[data-reservation-places]').hidden=!!row;$('[data-reservation-expiry-label]').hidden=!!row;
      if(row){['first_name','last_name','phone','email'].forEach(function(key){form.elements[key].value=row[key]||'';});form.notes.value=row.notes||'';}
      else{
        $('[data-reservation-ticket-inputs]').innerHTML=state.event.ticket_types.map(function(t){var available=Math.min(Number(t.online_available||0),Number(state.event.available),1000);return '<label class="reservation-ticket-input"><span>'+esc(t.name)+'<small>'+money(price(t))+' por plaza · '+available+' disponibles</small></span><input type="number" min="0" max="'+available+'" step="1" value="0" inputmode="numeric" data-ticket-type="'+Number(t.id)+'" aria-label="Plazas de '+esc(t.name)+'" '+(available?'':'disabled')+'></label>';}).join('');
        var end=new Date(String(state.event.ends_at||state.event.starts_at).replace(' ','T'));end.setDate(end.getDate()+1);var pad=function(n){return String(n).padStart(2,'0');};form.reservation_expires_at.value=end.getFullYear()+'-'+pad(end.getMonth()+1)+'-'+pad(end.getDate())+'T'+pad(end.getHours())+':'+pad(end.getMinutes());total();
      }
      form.querySelector('[type="submit"]').disabled=false;show(editor);
    }
    function shareMessage(r){
      var greeting='Hola '+r.name+',\n\n';
      if(r.payment_url)return greeting+'Tenemos reservadas '+r.quantity+' plazas para '+state.event.title+'.\n\nPara confirmar la reserva, puedes pagar aquí:\n'+r.payment_url+'\n\nImporte: '+money(r.total_cents)+'\nFecha límite: '+date(r.reservation_expires_at)+'\n\nEquipo Perigallo';
      if(r.ticket_url)return greeting+'Tu reserva de '+r.quantity+' plazas para '+state.event.title+' está confirmada.'+(r.payment_status==='paid'?'':' El cobro queda pendiente en puerta.')+'\n\nAquí tienes tus entradas:\n'+r.ticket_url+'\n\nEquipo Perigallo';
      return '';
    }
    function renderDetail(r){
      var active=['held','payment_requested'].indexOf(r.reservation_state)!==-1,confirmed=r.reservation_state==='confirmed';var actions='';
      if(active&&r.sales_channel==='manual_card'&&Number(r.total_cents)>0)actions+='<button class="ticket-btn primary" type="button" data-reservation-action="request-payment">'+(r.payment_url?'Volver a preparar enlace':'Preparar enlace de pago')+'</button>';
      if(active&&r.status!=='payment_processing')actions+='<button class="ticket-btn" type="button" data-reservation-action="confirm">Confirmar reserva</button>';
      if(confirmed&&r.payment_status!=='paid'&&r.sales_channel==='cash')actions+='<button class="ticket-btn primary" type="button" data-reservation-action="cash">Registrar cobro en efectivo</button>';
      actions+='<button class="ticket-btn" type="button" data-reservation-action="edit">Editar datos</button><button class="ticket-btn" type="button" data-reservation-action="refresh">Actualizar estado</button>';
      if((active&&r.status!=='payment_processing')||r.reservation_state==='expired')actions+='<button class="ticket-btn" type="button" data-reservation-action="cancel">Cancelar y liberar plazas</button>';
      if(confirmed||r.reservation_state==='review')actions+='<a class="ticket-btn" href="/admin/ventas/?event='+r.event_id+'">Ver en pedidos y ventas</a>';
      var message=shareMessage(r),share=message?'<section class="reservation-share"><label for="reservation-message">Mensaje para el cliente</label><textarea id="reservation-message" readonly>'+esc(message)+'</textarea><p>El mensaje se revisa y se envía desde tu WhatsApp. Se abrirá para que revises y envíes el mensaje.</p><div class="reservation-dialog-actions"><button type="button" class="ticket-btn" data-reservation-action="copy-message">Copiar mensaje</button>'+(r.payment_url?'<button type="button" class="ticket-btn" data-reservation-action="copy-link">Copiar enlace de pago</button>':'')+'<a class="ticket-btn primary" href="https://wa.me/'+recipient(r.phone)+'?text='+encodeURIComponent(message)+'" target="_blank" rel="noopener">Abrir WhatsApp</a></div></section>':'';
      $('[data-detail-content]').innerHTML='<span class="ticket-eyebrow">Ref. '+esc(r.redsys_order)+'</span><h2 id="reservation-detail-title">'+esc(r.name)+'</h2><span class="reservation-badge is-'+esc(r.reservation_state)+'">'+esc(labels[r.reservation_state])+'</span><dl class="reservation-detail-grid"><div><dt>Contacto</dt><dd>'+esc(r.phone)+'<br>'+esc(r.email||'Sin email')+'</dd></div><div><dt>Plazas e importe</dt><dd>'+r.quantity+' plazas · '+money(r.total_cents)+'<br>'+(r.payment_status==='paid'?'Cobrado':confirmed?'Cobro pendiente en puerta':'Pendiente de confirmar')+'</dd></div><div><dt>Fecha límite</dt><dd>'+esc(confirmed?'Reserva confirmada':date(r.reservation_expires_at))+'</dd></div><div><dt>Creada</dt><dd>'+esc(date(r.created_at))+'</dd></div></dl><ul class="reservation-detail-items">'+(r.items||[]).map(function(i){return '<li>'+Number(i.quantity)+' × '+esc(i.ticket_type_name)+'</li>';}).join('')+'</ul><p class="reservation-detail-notes">'+esc(r.notes||'Sin notas internas.')+'</p><div class="reservation-detail-actions">'+actions+'</div><p class="reservation-form-status" data-detail-status role="status" aria-live="polite"></p>'+share;
    }
    function openDetail(id,ready){
      var serial=++state.detailRequest;
      $('[data-detail-content]').innerHTML='<h2 id="reservation-detail-title">Reserva</h2><p class="reservation-dialog-lead">Cargando ficha…</p>';show(detail);
      var promise=ready?Promise.resolve(ready):api('/admin/internal-reservations/'+id);
      promise.then(function(result){if(serial!==state.detailRequest)return;if(!detail.open)return;state.detail=result.reservation;renderDetail(state.detail);}).catch(function(error){if(serial!==state.detailRequest)return;$('[data-detail-content]').innerHTML='<h2 id="reservation-detail-title">No se pudo abrir la reserva</h2><p>'+esc(error.message)+'</p>';});
    }
    function decisionFor(action){
      var r=state.detail;state.decision=action;decisionForm.reset();decisionForm.dataset.saving='0';decisionForm.notes.value=r.notes||'';$('[data-decision-status]').textContent='';$('[data-decision-status]').classList.remove('is-error');
      $('[data-decision-mode-label]').hidden=action!=='confirm';
      $('#reservation-decision-title').textContent=action==='cancel'?'Cancelar reserva':action==='cash'?'Registrar cobro en efectivo':'Confirmar reserva';
      $('[data-decision-description]').textContent=action==='cancel'?'Se liberarán las '+r.quantity+' plazas. La reserva conservará su historial.':action==='cash'?'Vas a registrar '+money(r.total_cents)+' ya recibidos en efectivo.':'Confirmar emite las entradas. Si se cobrará en puerta, el importe seguirá pendiente. El pago con tarjeta se confirma al recibir el resultado de Redsys.';
      decisionForm.mode.value=Number(r.total_cents)===0?'free':'door';
      decisionForm.mode.querySelector('[value="free"]').disabled=Number(r.total_cents)!==0;
      decisionForm.mode.querySelector('[value="door"]').disabled=Number(r.total_cents)===0;
      decisionForm.mode.querySelector('[value="cash"]').disabled=Number(r.total_cents)===0;
      decisionForm.querySelector('[type="submit"]').disabled=false;show(decision);
    }
    $('[data-refresh-reservations]').addEventListener('click',function(){load(true);});
    $('[data-new-reservation]').addEventListener('click',function(){if(state.event)openEditor(null);});
    $('[data-reservation-ticket-inputs]').addEventListener('input',total);
    select.addEventListener('change',function(){state.eventId=Number(select.value);state.event=null;state.page=1;state.rows=[];$('[data-new-reservation]').disabled=true;load(true);});
    root.querySelectorAll('[data-reservation-filter]').forEach(function(button){button.addEventListener('click',function(){state.filter=button.dataset.reservationFilter;state.page=1;root.querySelectorAll('[data-reservation-filter]').forEach(function(b){var selected=b===button;b.classList.toggle('is-active',selected);b.setAttribute('aria-pressed',selected?'true':'false');});load(false);});});
    $('[data-reservation-search]').addEventListener('input',function(e){state.query=e.target.value.trim();state.page=1;status('Buscando reservas…');clearTimeout(searchTimer);searchTimer=setTimeout(function(){load(false);},220);});
    $('[data-reservation-prev]').addEventListener('click',function(){if(state.page>1){state.page--;load(false);}});$('[data-reservation-next]').addEventListener('click',function(){if(state.page<state.pages){state.page++;load(false);}});
    root.addEventListener('click',function(e){var button=e.target.closest('[data-open-reservation]');if(button)openDetail(Number(button.dataset.openReservation));if(e.target.closest('[data-retry-reservations]'))load(true);});
    form.addEventListener('submit',function(e){
      e.preventDefault();if(form.dataset.saving==='1')return;var editing=Number(form.elements.id.value)||0;
      var data={first_name:form.first_name.value.trim(),last_name:form.last_name.value.trim(),phone:form.phone.value.trim(),email:form.email.value.trim(),notes:form.notes.value.trim()};
      if(!editing){data.operation_key=form.operation_key.value;data.reservation_expires_at=form.reservation_expires_at.value;data.items=Array.from(form.querySelectorAll('[data-ticket-type]')).map(function(i){return {ticket_type_id:Number(i.dataset.ticketType),quantity:Number(i.value)||0};}).filter(function(i){return i.quantity>0;});if(!data.items.length){$('[data-editor-status]').textContent='Indica al menos una plaza.';return;}}
      form.dataset.saving='1';form.querySelector('[type="submit"]').disabled=true;$('[data-editor-status]').textContent='Guardando reserva…';$('[data-editor-status]').classList.remove('is-error');
      api(editing?'/admin/internal-reservations/'+editing:'/admin/events/'+state.eventId+'/reservations',editing?'PUT':'POST',data).then(function(result){form.dataset.saving='0';apply(result,!editing);close(editor);openDetail(result.reservation.id,result);}).catch(function(error){$('[data-editor-status]').textContent=error.message;$('[data-editor-status]').classList.add('is-error');}).finally(function(){form.dataset.saving='0';form.querySelector('[type="submit"]').disabled=false;});
    });
    detail.addEventListener('click',function(e){var button=e.target.closest('[data-reservation-action]');if(!button||!state.detail)return;var action=button.dataset.reservationAction,r=state.detail;
      if(action==='edit'){close(detail);openEditor(r);return;}
      if(['confirm','cancel','cash'].indexOf(action)!==-1){decisionFor(action);return;}
      var target=$('[data-detail-status]');
      if(action==='copy-link'||action==='copy-message'){navigator.clipboard.writeText(action==='copy-link'?r.payment_url:shareMessage(r)).then(function(){target.textContent=action==='copy-link'?'Enlace de pago copiado.':'Mensaje copiado.';}).catch(function(){target.textContent='No se pudo copiar. Puedes seleccionar el texto del mensaje.';});return;}
      if(action==='refresh'){button.disabled=true;target.textContent='Actualizando estado…';api('/admin/internal-reservations/'+r.id).then(function(result){apply(result);renderDetail(result.reservation);}).catch(function(error){target.textContent=error.message;button.disabled=false;});return;}
      if(action==='request-payment'){button.disabled=true;target.textContent='Preparando enlace de pago…';api('/admin/internal-reservations/'+r.id+'/request-payment','POST',{}).then(function(result){apply(result);renderDetail(result.reservation);$('[data-detail-status]').textContent='Enlace preparado. Revisa el mensaje antes de abrir WhatsApp.';}).catch(function(error){target.textContent=error.message;target.classList.add('is-error');button.disabled=false;});}
    });
    decisionForm.addEventListener('submit',function(e){
      e.preventDefault();if(decisionForm.dataset.saving==='1')return;var r=state.detail,action=state.decision,notes=decisionForm.notes.value.trim();var path='/admin/internal-reservations/'+r.id+'/'+(action==='cancel'?'cancel':'confirm'),payload=action==='cancel'?{reason:notes}:{mode:decisionForm.mode.value,notes:notes};
      if(action==='cash'){path='/admin/orders/'+r.id+'/cash-payment';payload={cash_payment_notes:notes};}
      decisionForm.dataset.saving='1';decisionForm.querySelector('[type="submit"]').disabled=true;$('[data-decision-status]').textContent='Guardando…';
      api(path,'POST',payload).then(function(result){if(action==='cash')return api('/admin/internal-reservations/'+r.id);return result;}).then(function(result){apply(result);decisionForm.dataset.saving='0';close(decision);renderDetail(result.reservation);$('[data-detail-status]').textContent=action==='cancel'?'Reserva cancelada y plazas liberadas.':'Confirmación guardada. Revisa el mensaje para el cliente.';}).catch(function(error){$('[data-decision-status]').textContent=error.message;$('[data-decision-status]').classList.add('is-error');}).finally(function(){decisionForm.dataset.saving='0';decisionForm.querySelector('[type="submit"]').disabled=false;});
    });
    api('/admin/internal-reservations/meta').then(function(data){
      select.innerHTML='<option value="">Selecciona un evento</option>'+data.events.map(function(e){return '<option value="'+Number(e.id)+'">'+esc(e.title)+(Number(e.is_test)?' · Prueba':'')+'</option>';}).join('');select.disabled=false;
      var requested=Number(new URLSearchParams(location.search).get('reservation'));
      if(requested)return api('/admin/internal-reservations/'+requested).then(function(result){state.eventId=result.reservation.event_id;select.value=state.eventId;return load(true).then(function(){openDetail(requested,result);});});
      if(!state.eventId){var upcoming=data.events.filter(function(e){return new Date(String(e.starts_at).replace(' ','T')).getTime()>=Date.now();}).sort(function(a,b){return String(a.starts_at).localeCompare(String(b.starts_at));});state.eventId=Number((upcoming[0]||data.events[0]||{}).id)||0;}
      if(!state.eventId){status('Crea un evento para empezar a registrar reservas.');$('[data-reservation-list]').setAttribute('aria-busy','false');return;}
      select.value=state.eventId;return load(true);
    }).catch(function(error){status(error.message,true);});
  }
  window.PerigalloInternalReservations={init:init};
})();

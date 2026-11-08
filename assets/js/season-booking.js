'use strict';
const root=document.querySelector('#booking-root');
const params=new URLSearchParams(location.search);
const money=cents=>new Intl.NumberFormat('es-ES',{style:'currency',currency:'EUR'}).format(cents/100);
const safe=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const dates=value=>new Date(value+'T12:00:00').toLocaleDateString('es-ES',{day:'numeric',month:'long',year:'numeric'});
const catalog={
 navidad:{title:'El día de Navidad',date:'2026-12-25',price:6500,child:2800,copy:'Bebidas incluidas. También tenemos menú infantil.'},
 hora:{title:'Tardevieja · Hora Cero',date:'2026-12-31',price:12000,child:null,copy:'Gastronomía, bebidas, barra libre y experiencias. De 14:00 a 22:00. Apertura a las 13:30.'},
 nuevo:{title:'El día de Año Nuevo',date:'2027-01-01',price:5000,child:2800,copy:'Bebidas del menú de adultos aparte. El menú infantil incluye agua y refrescos.'}
};
async function request(url,options={}){
 const response=await fetch(url,{...options,headers:{'Content-Type':'application/json',...options.headers}});
 const data=await response.json();
 if(!response.ok)throw new Error(data.error||'No se ha podido completar la operación.');
 return data;
}
const paymentLink=booking=>location.origin+'/entradas/reserva/pedido/?token='+encodeURIComponent(booking.token)+'&pay=1';
const reference=booking=>booking.reference || booking.token.slice(0,8).toUpperCase();
function summary(booking){
 return `<div class="order-summary"><p class="eyebrow">FINCA LA LLAGUNA · CREVILLENT</p>
 <h2>${safe(booking.title)}</h2><p>${dates(booking.date)}</p><p>${booking.adults} ${booking.plan==='hora'?(booking.adults===1?'entrada':'entradas'):(booking.adults===1?'adulto':'adultos')}${booking.children?' · '+booking.children+(booking.children===1?' infantil':' infantiles'):''}</p>
 <div class="order-total"><span>Total · IVA incluido</span><strong>${money(booking.total)}</strong></div></div>`;
}
function renderCheckout(){
 const plan=catalog[params.get('plan')];
 if(!plan){root.innerHTML='<div class="booking-page"><h1>Elige una experiencia</h1><a href="/experiencias/">Volver a Experiencias ↗</a></div>';return;}
 const operation=crypto.randomUUID();
 root.innerHTML=`<div class="booking-page"><a class="back-link" href="/experiencias/?plan=${safe(params.get('plan'))}">← Volver a la experiencia</a>
 <p class="eyebrow">RESERVAS Y ENTRADAS</p><h1>${plan.title}</h1><p class="booking-date">${dates(plan.date)} · Finca La Llaguna</p>
 <div class="booking-grid"><form id="reserve-form">
 <fieldset><legend>¿Cómo quieres reservar?</legend><div class="booking-modes">
 <label><input type="radio" name="mode" value="reserve" ${params.get('mode')!=='pay'?'checked':''}><span><strong>Reservar primero</strong><small>Solicita tu reserva. Te enviaremos un enlace para completar el pago.</small></span></label>
 <label><input type="radio" name="mode" value="pay" ${params.get('mode')==='pay'?'checked':''}><span><strong>Comprar y pagar</strong><small>Completa tus datos y continúa al pago con tarjeta.</small></span></label></div></fieldset>
 <fieldset><legend>Vuestras plazas</legend><div class="field-pair">
 <label>${params.get('plan')==='hora'?'Entradas':'Adultos'} · ${money(plan.price)}<input type="number" name="adults" value="1" min="1" max="10" required></label>
 ${plan.child? `<label>Menú infantil · ${money(plan.child)}<input type="number" name="children" value="0" min="0" max="10" required></label>`:''}
 </div><p class="field-note">${plan.copy}</p></fieldset>
 <fieldset><legend>Tus datos</legend>
 <div class="field-pair"><label>Nombre<input name="first_name" autocomplete="given-name" required maxlength="120"></label><label>Apellidos<input name="last_name" autocomplete="family-name" required maxlength="160"></label></div>
 <div class="field-pair"><label>Teléfono / WhatsApp<input type="tel" name="phone" autocomplete="tel" required maxlength="30" pattern="[+0-9 ()-]{9,30}"></label>
 <label>Correo electrónico<input type="email" name="email" autocomplete="email" required maxlength="190"></label></div>
 <label>Observaciones para tu reserva <span class="optional">(opcional)</span><textarea name="notes" rows="3" maxlength="800"></textarea></label></fieldset>
 <label class="privacy-check"><input type="checkbox" name="privacy" required><span>He leído la <a href="/politica-privacidad/" target="_blank" rel="noopener">política de privacidad</a> y acepto el contacto para gestionar esta reserva.</span></label>
 <label class="privacy-check"><input type="checkbox" name="terms" required><span>Acepto las <a href="/condiciones/" target="_blank" rel="noopener">condiciones de compra</a> y la <a href="/politica-cancelacion/" target="_blank" rel="noopener">política de cancelación</a>.</span></label>
 <label class="privacy-check"><input type="checkbox" name="adult_buyer" required><span>Soy mayor de 18 años.</span></label>
 <label class="privacy-check"><input type="checkbox" name="whatsapp_consent"><span>Quiero recibir también mis entradas por WhatsApp al teléfono indicado (España: +34; otros países: incluye tu prefijo).</span></label>

 <p id="form-status" role="status"></p><button class="booking-primary" type="submit" id="submit-reserve">Solicitar reserva ↗</button>
 </form><aside id="checkout-summary" class="checkout-aside"></aside></div></div>`;
 const form=document.querySelector('#reserve-form');
 const update=()=>{
  const adults=Number(form.elements.adults.value)||0,children=Number(form.elements.children?.value)||0;
  document.querySelector('#checkout-summary').innerHTML=summary({...plan,plan:params.get('plan'),adults,children,total:adults*plan.price+children*(plan.child||0)});
  document.querySelector('#submit-reserve').textContent=form.elements.mode.value==='pay'?'Continuar al pago ↗':'Solicitar reserva ↗';
 };
 form.addEventListener('input',event=>{if(event.target.setCustomValidity)event.target.setCustomValidity('');update();});update();
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  for(const key of ['first_name','last_name']) {
   const input=form.elements[key];
   input.setCustomValidity(input.value.trim() ? '' : 'Este dato es obligatorio.');
  }
  const phoneDigits=form.elements.phone.value.replace(/\D/g,'');
  form.elements.phone.setCustomValidity(phoneDigits.length>=9 && phoneDigits.length<=15 ? '' : 'Introduce un teléfono válido, con su prefijo si es internacional.');
  if(!form.reportValidity())return;
  const button=document.querySelector('#submit-reserve'),status=document.querySelector('#form-status');
  button.disabled=true;status.textContent='Guardando tu solicitud…';
  try{
   const data=await request('/api/season-reservations',{method:'POST',body:JSON.stringify({
    operation,plan:params.get('plan'),adults:Number(form.elements.adults.value),children:Number(form.elements.children?.value)||0,
    mode:form.elements.mode.value,first_name:form.elements.first_name.value.trim(),last_name:form.elements.last_name.value.trim(),name:(form.elements.first_name.value+' '+form.elements.last_name.value).trim(),phone:form.elements.phone.value,email:form.elements.email.value,
    notes:form.elements.notes.value,privacy:form.elements.privacy.checked,terms:form.elements.terms.checked,adult_buyer:form.elements.adult_buyer.checked,whatsapp_consent:form.elements.whatsapp_consent.checked
   })});
   location.assign('/entradas/reserva/pedido/?token='+encodeURIComponent(data.booking.token)+(data.booking.mode==='pay'?'&pay=1':''));
  }catch(error){status.textContent=error.message;button.disabled=false;}
 });
}
async function renderOrder(){
 root.innerHTML='<div class="booking-page"><p>Cargando tu reserva…</p></div>';
 try{
  const {booking}=await request('/api/season-reservations/'+encodeURIComponent(params.get('token')||''));
  const paid=booking.payment_status==='paid';
  const pay=params.get('pay')==='1'&&!paid;
  root.innerHTML=`<div class="booking-page order-page"><a class="back-link" href="/experiencias/">← Experiencias</a>
  <p class="eyebrow">JUSTIFICANTE DE RESERVA</p><h1>${paid?'Tu reserva está pagada':pay?'Completa tu reserva':'Tu reserva está registrada'}</h1>
  <p>Referencia ${safe(reference(booking))} · ${paid?'Pago confirmado':booking.state==='pending_payment'?'Pendiente de pago':safe(booking.state)}</p>
  <div class="booking-grid">${summary(booking)}<div class="booking-details">
  <h2>${safe(booking.name)}</h2><p>${safe(booking.phone)}<br>${safe(booking.email)}</p>
  ${booking.notes?`<p>${safe(booking.notes)}</p>`:''}
  <p>${paid?'Pago confirmado. Puedes descargar las entradas de tu reserva.':pay?'El pago de tu reserva se realiza mediante el TPV de CaixaBank / Cyberpac Redsys.':'Tu reserva tiene un importe pendiente. Puedes completar el pago con el enlace de tu pedido.'}</p>
  ${pay?'<button class="booking-primary" id="continue-bank">Continuar al TPV de CaixaBank ↗</button><p id="bank-status" role="status"></p>':''}

  <div class="order-tools"><button type="button" id="print-order">${paid?'Imprimir justificante de pago':'Imprimir justificante de reserva'}</button>${!pay&&!paid?'<a href="'+safe(paymentLink(booking))+'">Ver el enlace de pago ↗</a>':''}
  <a href="tel:+34691499985">Llamar al 691 499 985</a></div>
  </div></div></div>`;
  document.querySelector('#print-order').addEventListener('click',()=>window.print());
  document.querySelector('#continue-bank')?.addEventListener('click',async()=>{
   const button=document.querySelector('#continue-bank'),status=document.querySelector('#bank-status');
   button.disabled=true;status.textContent='Preparando el pago seguro…';
   try {
    const {payment}=await request('/api/season-reservations/'+encodeURIComponent(booking.token)+'/start-payment',{method:'POST',body:'{}'});
    if(!payment.url.startsWith('https://sis.redsys.es/sis/'))throw new Error('No se ha podido preparar el pago seguro.');
    const form=document.createElement('form');form.method='POST';form.action=payment.url;
    for(const [name,value] of Object.entries(payment.fields)){
     const field=document.createElement('input');field.type='hidden';field.name=name;field.value=value;form.append(field);
    }
    document.body.append(form);form.submit();
   }catch(error){status.textContent=error.message;button.disabled=false;}
  });
  if(paid){
   const links=document.createElement('p');
   links.innerHTML='<a href="/api/orders/tickets/'+encodeURIComponent(booking.token)+'" target="_blank" rel="noopener">Descargar entradas ↗</a>';
   document.querySelector('.booking-details').prepend(links);
  }
  if(!paid && !pay){
   let checks=0;
   const timer=setInterval(async()=>{
    checks++;
    try {
     const data=await request('/api/season-reservations/'+encodeURIComponent(booking.token));
     if(data.booking.payment_status==='paid'){clearInterval(timer);renderOrder();}
    }catch {}
    if(checks>=20)clearInterval(timer);
   },3000);
  }


 }catch(error){root.innerHTML='<div class="booking-page"><h1>No podemos abrir la reserva</h1><p>'+safe(error.message)+'</p><a href="/experiencias/">Volver a Experiencias</a></div>';}
}

if(location.pathname.startsWith('/entradas/reserva/pedido'))renderOrder();else request('/api/season-reservations').then(data=>{for(const [key,value] of Object.entries(data.plans))if(catalog[key])Object.assign(catalog[key],value);renderCheckout();}).catch(error=>{root.innerHTML='<div class="booking-page"><h1>No hemos podido abrir las reservas</h1><p>'+safe(error.message)+'</p><a href="tel:+34691499985">Llamar al 691 499 985</a></div>';});

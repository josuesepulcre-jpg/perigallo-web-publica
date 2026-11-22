'use strict';
const $=s=>document.querySelector(s), esc=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const PLANS=[
 {id:'diciembre',date:'TODO EL MES DE DICIEMBRE',title:'Una comida<br>para juntarnos.',name:'Menús de grupo',description:'Menú Raíces, Menú La Mesa y Menú Brindis. Tres menús para compartir y celebrar en grupo.',price:'Desde 44,95 €',note:'Por persona · IVA y bebidas incluidos',cover:'navidad-01',action:'Ver los tres menús',menus:['navidad-02','navidad-03','navidad-04'],default:'navidad-02'},
 {id:'degustacion',period:'diciembre',date:'DICIEMBRE · VIERNES, SÁBADOS Y DOMINGOS',title:'Una experiencia<br>gastronómica.',name:'Menú degustación',description:'Una experiencia gastronómica para mesas de hasta seis personas. En diciembre, al mediodía. Consulta los días abiertos en el calendario.',price:'38 €',note:'Por persona · IVA incluido · Bebidas aparte',image:'assets/temporada-degustacion.webp',cover:'navidad-01',action:'Descubrir el menú degustación',menus:['navidad-individual'],default:'navidad-individual'},
 {id:'navidad',date:'25 DE DICIEMBRE · NAVIDAD',title:'A la mesa.<br>Y un rato más.',name:'El día de Navidad',description:'Una comida de Navidad con nuestro puchero, menú infantil y la actuación de Verónica Romero con «Contigo en Navidad» después de comer.',price:'65 €',note:'Por persona · IVA y bebidas incluidos',cover:'navidad-25-portada',action:'Ver los menús',menus:['navidad-25-menu','navidad-25-infantil'],default:'show'},
 {id:'hora',date:'31 DE DICIEMBRE · HORA CERO',title:'Seis destinos.<br>Un Año Nuevo.',name:'Tardevieja · Hora Cero',description:'Australia, Japón, China, Vietnam, India y Rusia. Gastronomía, lago, barra libre, Bollywood y DJ durante el recorrido.',price:'120 €',note:'Por persona · IVA incluido · Plazas limitadas',cover:'hora-cero-01',action:'Descubrir el recorrido',menus:[],default:'route'},
 {id:'nuevo',date:'1 DE ENERO · AÑO NUEVO',title:'La primera<br>mesa del año.',name:'El día de Año Nuevo',description:'Marisco, entrantes, principal a elegir y flan de turrón. Empezamos 2027 alrededor de la mesa, también con los peques.',price:'50 €',note:'Por persona · IVA y bebidas incluidos · Agua, refrescos, cerveza y vino',cover:'ano-nuevo-portada',action:'Ver los menús',menus:['ano-nuevo-menu','ano-nuevo-infantil'],default:'ano-nuevo-menu'}
];
const sceneDescriptions = {
 degustacion: 'Aperitivos de Perigallo presentados en platos individuales',
 diciembre: 'Mesa para compartir con lino verde, mandarinas y velas encendidas',
 navidad: 'Sopera de consomé sobre lino granate en la mesa de Navidad',
 hora: 'Imagen de Hora Cero con una flor luminosa y una copa junto al lago',
 nuevo: 'Dos copas de brindis sobre lino marfil a la luz de la mañana'
};
// Reservations use the production event backend.
const bookingOrigin = '';
import {bookingMenuIds, offeredMenus, buildGroupMessage, groupWhatsAppUrl} from './group-inquiry.mjs?v=disponibilidad-20261122';
const groupMenuIds=['navidad-02','navidad-03','navidad-04'];
const inquiryPlanIds=['diciembre','degustacion'];
const inquiryDrafts={};
const inquiryMenuIds=()=>activePlan.id==='degustacion'?['navidad-individual']:groupMenuIds;
const inquiryOptions=()=>({kind:activePlan.id==='degustacion'?'tasting':'group',maxPeople:activePlan.id==='degustacion'?6:100});
let groupInquiryDraft={};
const inquiryDateLabel = new Intl.DateTimeFormat('es-ES',{
 weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'
});
function inquiryCalendarHTML() {
 const weekdays=['L','M','X','J','V','S','D'];
 const cells=['<td aria-hidden="true"></td>']; // December 2026 starts on Tuesday.
 for(let day=1;day<=31;day++) {
  const date='2026-12-'+String(day).padStart(2,'0');
  const label=inquiryDateLabel.format(new Date(date+'T12:00:00Z'));
  cells.push(`<td><button type="button" data-inquiry-date="${date}" aria-label="${esc(label)}" disabled>${day}</button></td>`);
 }
 while(cells.length%7)cells.push('<td aria-hidden="true"></td>');
 const rows=[];for(let i=0;i<cells.length;i+=7)rows.push('<tr>'+cells.slice(i,i+7).join('')+'</tr>');
 return `<table><caption>Diciembre <span>2026</span></caption><thead><tr>${weekdays.map(day=>`<th scope="col">${day}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table><p class="calendar-selection" role="status">Consultando disponibilidad…</p>`;
}
function inquiryChoices(name, legend, choices) {
 return `<fieldset class="inquiry-choices"><legend>${legend}</legend><div>${choices.map(([id,label])=>
  `<label><input type="radio" name="${name}" value="${id}" required disabled><span>${esc(label)}</span></label>`
 ).join('')}</div></fieldset>`;
}
function groupInquiryHTML() {
 const value=key=>esc(groupInquiryDraft[key]||'');
 return `<article class="group-inquiry leaf">
  <p class="eyebrow">${activePlan.id==='degustacion'?'MENÚ DEGUSTACIÓN · DICIEMBRE · MEDIODÍA':'MENÚS DE GRUPO · DICIEMBRE'}</p>
  <h3>${activePlan.id==='degustacion'?'Vuestra experiencia gastronómica.':'Vuestra próxima mesa.'}</h3><p class="inquiry-intro">Cuéntanos el plan y prepararemos vuestra consulta para WhatsApp.</p>
  <form id="group-inquiry-form">
   <fieldset><legend>¿Quién nos escribe?</legend><div class="inquiry-fields">
    <label>Nombre<input name="first_name" autocomplete="given-name" required maxlength="120" value="${value('first_name')}"></label>
    <label>Apellidos<input name="last_name" autocomplete="family-name" required maxlength="160" value="${value('last_name')}"></label>
    <label>Teléfono<input name="phone" type="tel" autocomplete="tel" required maxlength="30" value="${value('phone')}"></label>
    <label>Correo electrónico<input name="email" type="email" autocomplete="email" required maxlength="190" value="${value('email')}"></label>
   </div></fieldset>
   <fieldset><legend>¿Cómo os gustaría venir?</legend><div class="inquiry-fields">
    <div class="tasting-calendar-field"><p id="inquiry-date-label" class="calendar-field-label">Día de la reserva</p>
     <input type="hidden" name="date">
     <div class="tasting-calendar" role="group" aria-labelledby="inquiry-date-label">${inquiryCalendarHTML()}</div>
     <button type="button" data-retry-calendar hidden>Volver a consultar</button>
    </div>
    ${inquiryChoices('shift','Turno',activePlan.id==='degustacion'?[['lunch','Comida']]:[['lunch','Comida'],['dinner','Cena']])}
    <label>Número de personas<input name="people" type="number" min="1" max="${inquiryOptions().maxPeople}" step="1" required value="${value('people')}" inputmode="numeric"></label>
    ${inquiryChoices('menu','Menú que os interesa',inquiryMenuIds().map(id=>[id,MENUS[id].title+' · '+MENUS[id].price]))}
   </div></fieldset>
   <label class="inquiry-privacy"><input name="privacy" type="checkbox" required ${groupInquiryDraft.privacy?'checked':''}><span>He leído la <a href="/politica-privacidad/" target="_blank" rel="noopener">política de privacidad</a> y acepto el contacto para gestionar esta consulta.</span></label>
   <p class="inquiry-status" role="status" aria-live="polite"></p>
   <button class="inquiry-submit" type="submit" disabled>Preparar mi consulta de WhatsApp <span aria-hidden="true">↗</span></button>
   <p class="inquiry-hint">La reserva queda pendiente de nuestra confirmación de disponibilidad.</p>
   <section class="inquiry-message" hidden aria-label="Tu consulta preparada"><h4>Todo listo para escribirnos.</h4><pre></pre><button class="inquiry-submit" type="button" data-open-chat>Abrir WhatsApp con mi consulta ↗</button><p>Se abrirá el chat de Perigallo con el mensaje completo. Revísalo y pulsa enviar en WhatsApp.</p></section>
  </form>
 </article>`;
}
function openGroupInquiry(menuId,planId='diciembre') {
 if(!inquiryPlanIds.includes(planId))return;
 if(!dialog.open||activePlan?.id!==planId)openPlan(planId);
 if(inquiryMenuIds().includes(menuId))groupInquiryDraft.menu=menuId;
 if(planId==='degustacion')groupInquiryDraft.menu='navidad-individual';
 selectChoice('consulta');view.focus({preventScroll:true});
}
let stopInquiryUpdates;
function bindGroupInquiry() {
 const form=$('#group-inquiry-form');
 const dateField=form.elements.date;
 const calendar=form.querySelector('.tasting-calendar');
 const caption=calendar.querySelector('.calendar-selection');
 const retry=form.querySelector('[data-retry-calendar]');
 const preview=form.querySelector('.inquiry-message');
 const status=form.querySelector('.inquiry-status');
 const submit=form.querySelector('[type="submit"]');
 const menuIds=inquiryMenuIds(), options=inquiryOptions();
 const menus=Object.fromEntries(menuIds.map(id=>[id,MENUS[id]]));
 const shiftInputs=[...form.querySelectorAll('[name="shift"]')];
 const menuInputs=[...form.querySelectorAll('[name="menu"]')];
 const snapshot=()=>({...Object.fromEntries(new FormData(form)),privacy:form.elements.privacy.checked});
 let days={}, loaded=false, disposed=false, pending, submitting=false;
 let preferredMenu=groupInquiryDraft.menu;
 dateField.value=groupInquiryDraft.date||'';
 const initialShift=shiftInputs.find(input=>input.value===groupInquiryDraft.shift);
 if(initialShift)initialShift.checked=true;

 function render() {
  for(const day of calendar.querySelectorAll('[data-inquiry-date]')){
   const date=day.dataset.inquiryDate;
   day.disabled=!shiftInputs.some(input=>offeredMenus(days,date,input.value,menuIds).length);
   if(day.disabled && dateField.value===date)dateField.value='';
   day.setAttribute('aria-pressed',String(!day.disabled && dateField.value===date));
  }
  for(const input of shiftInputs){
   input.disabled=!offeredMenus(days,dateField.value,input.value,menuIds).length;
   if(input.disabled)input.checked=false;
  }
  const availableShifts=shiftInputs.filter(input=>!input.disabled);
  if(availableShifts.length===1)availableShifts[0].checked=true;
  const shift=shiftInputs.find(input=>input.checked)?.value;
  const availableMenus=offeredMenus(days,dateField.value,shift,menuIds);
  for(const input of menuInputs){
   input.disabled=!availableMenus.includes(input.value);
   input.checked=!input.disabled && (availableMenus.length===1 || input.value===preferredMenu);
  }
  const hasDays=calendar.querySelector('button:not(:disabled)');
  caption.textContent=!loaded?'Disponibilidad pendiente de comprobar.':dateField.value
   ?inquiryDateLabel.format(new Date(dateField.value+'T12:00:00Z'))
   :hasDays?'Elige un día disponible.':'No hay fechas abiertas para esta experiencia.';
  submit.disabled=!loaded || !dateField.value || !availableMenus.length;
 }
 function refresh() {
  if(pending)return pending;
  const url=new URL('/api/public/booking/inquiry-calendar','https://reservas.perigallo.com');
  url.searchParams.set('month','2026-12');
  pending=(async()=>{
   try{
    const response=await fetch(url,{cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(12000)});
    if(!response.ok)throw new Error();
    const data=await response.json();
    if(data.month!=='2026-12'||!data.days||typeof data.days!=='object'||Array.isArray(data.days))throw new Error();
    if(disposed)return false;
    days=data.days;loaded=true;
   }catch{
    if(disposed)return false;
    days={};loaded=false;
   }
   const before=snapshot();
   render();
   const after=snapshot();
   if(!loaded || ['date','shift','menu'].some(key=>before[key]!==after[key])){
    preview.hidden=true;
    if(before.date)status.textContent='La disponibilidad ha cambiado. Revisa tu selección.';
   }
   retry.hidden=loaded;
   return loaded;
  })().finally(()=>{pending=undefined;});
  return pending;
 }
 function resume(){if(!document.hidden)refresh();}
 const timer=setInterval(resume,60000);
 document.addEventListener('visibilitychange',resume);
 window.addEventListener('focus',resume);
 stopInquiryUpdates=()=>{
  disposed=true;clearInterval(timer);
  document.removeEventListener('visibilitychange',resume);
  window.removeEventListener('focus',resume);
 };
 refresh();
 retry.addEventListener('click',refresh);
 calendar.addEventListener('click',event=>{
  const button=event.target.closest('button[data-inquiry-date]:not(:disabled)');
  if(!button)return;
  dateField.value=button.dataset.inquiryDate;
  dateField.dispatchEvent(new Event('input',{bubbles:true}));
 });
 form.addEventListener('input',event=>{
  if(event.target.setCustomValidity)event.target.setCustomValidity('');
  if(event.target.name==='menu')preferredMenu=event.target.value;
  if(['date','shift','menu'].includes(event.target.name))render();
  groupInquiryDraft=snapshot();preview.hidden=true;status.textContent='';
 });
 async function prepare(openChat) {
  if(submitting)return;
  for(const key of ['first_name','last_name'])form.elements[key].setCustomValidity(form.elements[key].value.trim()?'':'Este dato es obligatorio.');
  if(!form.reportValidity())return;
  const before=snapshot();
  submitting=true;
  try{
   if(!await refresh() || disposed)return;
   const after=snapshot();
   if(['date','shift','menu'].some(key=>before[key]!==after[key]))return;
   const message=buildGroupMessage(after,menus,{...options,days});
   groupInquiryDraft=after;
   if(openChat){window.location.assign(groupWhatsAppUrl(message));return;}
   preview.querySelector('pre').textContent=message;preview.hidden=false;
   status.textContent='Consulta preparada. Todavía no se ha enviado.';
   preview.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'nearest'});
  }catch(error){preview.hidden=true;status.textContent=error.message;}
  finally{submitting=false;}
 }
 form.addEventListener('submit',event=>{event.preventDefault();prepare(false);});
 form.querySelector('[data-open-chat]').addEventListener('click',()=>prepare(true));
}

function onlineBookingUrl(plan, menuId) {
 if (!inquiryPlanIds.includes(plan)) {
  return bookingOrigin + '/entradas/reserva/?plan=' + encodeURIComponent(plan) + '&mode=reserve';
 }
 const url = new URL('https://reservas.perigallo.com/reservar?source=web');
 const menu = plan === 'degustacion' ? bookingMenuIds['navidad-individual'] : bookingMenuIds[menuId];
 if (menu) url.searchParams.set('menu', menu);
 return url.href;
}
function bookingActions(plan, menuId) {
 const online = `<a class="reserve-link" href="${esc(onlineBookingUrl(plan, menuId))}">Reservar online <span aria-hidden="true">↗</span></a>`;
 const contact = inquiryPlanIds.includes(plan)
  ? `<a href="tel:+34691499985">Reservar por teléfono ↗</a><button type="button" data-group-inquiry="${plan}">Reservar por WhatsApp ↗</button>`
  : '';
 return `<div class="plan-booking">${online}${contact}</div>`;
}
$('#plan-grid').innerHTML=PLANS.map(p=>`<article class="plan-card" data-plan="${p.id}" data-period="${p.period||p.id}"><button class="plan-art" data-open="${p.id}" aria-label="Abrir ${esc(p.name)}"><img src="${p.image||`assets/temporada-${p.id}.webp`}" alt="${esc(sceneDescriptions[p.id])}" loading="lazy"></button><div class="card-copy"><span class="card-date">${p.date}</span><h3>${esc(p.name)}</h3><p>${esc(p.description)}</p><div class="card-price">${p.price} <small>/ persona</small></div><div class="price-note">${p.note}</div><button class="card-open" data-open="${p.id}">${p.action}<span aria-hidden="true">↗</span></button>${bookingActions(p.id)}</div></article>`).join('');
for(const b of document.querySelectorAll('[data-filter]'))b.addEventListener('click',()=>{
 const f=b.dataset.filter;
 for(const button of document.querySelectorAll('[data-filter]')){const selected=button===b;button.classList.toggle('selected',selected);button.setAttribute('aria-pressed',String(selected));}
 let count=0;for(const card of document.querySelectorAll('[data-plan]')){card.hidden=f!=='all'&&card.dataset.plan!==f&&card.dataset.period!==f;if(!card.hidden)count++;}
 $('#filter-status').textContent=count===1?'1 plan disponible para consultar':`${count} planes disponibles para consultar`;
});
let activePlan, activeChoice, opener;
const dialog = $('#menu-dialog');
const select = $('#menu-options');
const view = $('#menu-view');
function openPlan(id, choice) {
 if(id==='diciembre'&&choice==='navidad-individual')id='degustacion';
 if(activePlan&&inquiryPlanIds.includes(activePlan.id))inquiryDrafts[activePlan.id]=groupInquiryDraft;
 activePlan = PLANS.find(plan => plan.id === id);
 if(inquiryPlanIds.includes(id))groupInquiryDraft=inquiryDrafts[id]||{};
 if(id==='degustacion'){groupInquiryDraft.menu='navidad-individual';groupInquiryDraft.shift='lunch';}
 opener = document.activeElement;
 $('#dialog-date').textContent = activePlan.date;
 const options = activePlan.menus.map(key => [key, MENUS[key].title + ' · ' + MENUS[key].price]);
 if(inquiryPlanIds.includes(id))options.push(['consulta','Consultar disponibilidad']);

 if (id === 'navidad') options.unshift(['show','Actuación · Verónica Romero']);
 if (id === 'hora') options.push(
   ['route','Recorrido y gastronomía'], ['video','Vídeo de Hora Cero'],
   ...ROUTE.map(stop => [stop.id, stop.country + ' · ' + stop.time]),
   ['reservas','Precio y reservas']
 );
 select.replaceChildren(...options.map(([value,label]) => new Option(label,value)));
 selectChoice(options.some(([value]) => value === choice) ? choice : activePlan.default);
 dialog.showModal();
 document.body.style.overflow = 'hidden';
}
function menuHTML(menu) {
 const sections = menu.sections.map(section => `<section class="dish-section">
 <h4>${esc(section.title)}</h4>
 ${section.dishes.map(dish => `<div class="dish"><h5>${esc(dish.name)}</h5>
 ${dish.description ? `<p>${esc(dish.description)}</p>` : ''}</div>`).join('')}
 </section>`).join('');
 return `<article class="leaf menu-paper ${activePlan.id==='navidad'?'wine':''}">
 <p class="eyebrow">${esc(menu.date)}</p>
 <h3>${esc(menu.title)}</h3><div class="leaf-price"><strong>${esc(menu.price)}</strong><span>por persona · IVA incluido</span></div>
 <p class="leaf-drinks">${esc(menu.drinks)}</p>
 ${activePlan.id==='degustacion'?'<p class="inquiry-hint">Servicio de mediodía. Consulta las fechas abiertas en el calendario.</p>':''}
 ${sections}
 <p class="allergy-note">Si tenéis alguna alergia, consultadnos al reservar.</p>
 ${reservationHTML(menu.id)}
 <div class="reader-tools"><a href="menus/${menu.id}.png${menu.id==='navidad-individual'?'?v=mediodia-20261108':menu.id==='ano-nuevo-menu'?'?v=bebidas-incluidas-20261109':['navidad-02','navidad-03','navidad-04'].includes(menu.id)?'?v=nombres-originales-20261109':''}" download>Descargar carta ↓</a><button data-print>Imprimir / guardar PDF ↗</button></div>
 </article>`;
}
function reservationHTML(menuId) {
 if(inquiryPlanIds.includes(activePlan.id))return `<div class="leaf-reserve">${bookingActions(activePlan.id,menuId)}<p>Para más info y reservas · Cuéntanos la fecha, el turno y vuestro menú.</p></div>`;
 const message = 'Hola, me gustaría consultar disponibilidad para ' + activePlan.name + '.';
 return `<div class="leaf-reserve">${bookingActions(activePlan.id)}<p>Para más info y reservas</p><a href="https://wa.me/34691499985?text=${encodeURIComponent(message)}" target="_blank" rel="noopener">Escribir por WhatsApp ↗</a><a href="tel:+34691499985">Llamar al 691 499 985</a></div>`;
}
function showHTML() {
 return `<article class="performance-page">
  <p class="eyebrow">ACTUACIÓN NAVIDEÑA</p>
  <h3>Verónica Romero</h3>
  <div class="performance-program">
   <div class="performance-times">
    <p>Sobre las <strong>17:00</strong></p>
    <p>Duración aproximada <strong>30 minutos</strong></p>
    <p>Para amenizar la sobremesa, Verónica Romero nos acompañará con su espectáculo «Contigo en Navidad». Una ocasión para celebrar la Navidad juntos y disfrutar de un día especial con música en directo.</p>
   </div>
   <figure class="performance-clip">
    <video controls playsinline preload="metadata" poster="assets/veronica-video-poster.jpg" src="assets/veronica-contigo-navidad.mp4?v=veronica-20261109" aria-label="Fragmento de Contigo en Navidad de Verónica Romero"></video>
    <figcaption>Vídeo de @errebeene · @veronica_romero_vero</figcaption>
   </figure>
  </div>
 </article>${reservationHTML()}`;
}
function routeHTML() {
 const stops = ROUTE.map(stop => `<button data-stop="${stop.id}">
  <time>${esc(stop.time)}</time><span>${esc(stop.country)}</span><span aria-hidden="true">↗</span>
 </button>`).join('');
 return `<article class="route-intro route-index">
  <p class="eyebrow">31 DE DICIEMBRE · HORARIO PENINSULAR</p><h3>Hora Cero.<br>Seis destinos.</h3>
  <p>Abrimos a las 13:30. Elige un país para ver sus platos, bebidas y experiencias.</p>
  <nav class="country-links" aria-label="Elegir destino">${stops}</nav>
  <p>120 € por persona · IVA incluido · Plazas limitadas.<br>DJ y música constante. Cierre de la finca a las 22:30.</p>
 </article>${reservationHTML()}`;
}
function stopHTML(stop) {
 const sections = stop.sections.map(section => `<section class="stop-section">
  <h4>${esc(section.title)}</h4>
  ${section.items.map(([name,description]) => `<div><h5>${esc(name)}</h5><p>${esc(description)}</p></div>`).join('')}
 </section>`).join('');
 return `<article class="leaf stop-page">
  <p class="eyebrow">${esc(stop.kind)}</p><h3>${esc(stop.time)}</h3>
  <p class="stop-place">${esc(stop.place)}</p>
  ${stop.intro ? `<p>${esc(stop.intro)}</p>` : ''}
  ${sections}<div class="stop-note">${stop.note ? `<p>${esc(stop.note)}</p>` : ''}
  <p>DJ y música constante.</p></div></article>`;
}
function routeBookingHTML() {
 return `<article class="route-intro"><p class="eyebrow">HORA CERO · 31 DE DICIEMBRE</p>
  <h3>120 €</h3><p>Por persona · IVA incluido · Plazas limitadas.</p>
  <p>Todo el recorrido incluido. Gastronomía, bebidas, barra libre y experiencias. DJ y música constante.</p>
  <p>Finca La Llaguna · Crevillent<br>Apertura a las 13:30 · Experiencia de 14:00 a 22:00 · Cierre a las 22:30.</p></article>${reservationHTML()}`;
}
function selectChoice(id) {
 stopInquiryUpdates?.();stopInquiryUpdates=undefined;
 const previousIndex = Array.from(select.options).findIndex(option => option.value === activeChoice);
 const hadFocus = view.contains(document.activeElement);
 for (const video of view.querySelectorAll('video')) video.pause();
 select.value = id;
 if(id==='consulta'&&!groupInquiryDraft.menu&&inquiryMenuIds().includes(activeChoice))groupInquiryDraft.menu=activeChoice;
 activeChoice = id;
 $('#experience-book-online').href = onlineBookingUrl(activePlan.id, id);
 const index = select.selectedIndex;
 const count = select.options.length;
 $('#leaf-nav [data-book="prev"]').disabled = index === 0;
 $('#leaf-nav [data-book="next"]').disabled = index === count - 1;
 $('.book-counter').textContent = (index + 1) + ' / ' + count;
 const isPerformance = activePlan.id === 'navidad' && id === 'show';
 $('#dialog-heading').textContent = activePlan.name;
 const photo = $('#reader-photo');
 photo.parentElement.classList.toggle('single-cover', isPerformance);
 photo.dataset.scene = isPerformance ? 'show' : activePlan.id;
 photo.src = isPerformance ? 'assets/veronica-retrato-sin-texto.jpg?v=veronica-limpia-20261109' : (activePlan.image||`assets/temporada-${activePlan.id}.webp`);
 photo.alt = isPerformance ? 'Verónica Romero · Contigo en Navidad' : sceneDescriptions[activePlan.id];
 if(isPerformance)$('#dialog-heading').textContent = 'Verónica Romero';
 const stop = activePlan.id === 'hora' && ROUTE.find(item => item.id === id);
 photo.parentElement.classList.toggle('country-cover', Boolean(stop));
 $('#dialog-date').textContent = activePlan.date;
 if (stop) {
   $('#dialog-heading').textContent = stop.country;
   photo.src = 'assets/hora-cero/' + stop.id + '.svg';
   photo.alt = '';
   view.innerHTML = stopHTML(stop);
 }
 else if (id === 'reservas') view.innerHTML = routeBookingHTML();

 else if (id === 'show' && activePlan.id === 'navidad') view.innerHTML = showHTML();
 else if (id === 'consulta') {view.innerHTML=groupInquiryHTML();bindGroupInquiry();}
 else if (id === 'route') view.innerHTML = routeHTML();
 else if (id === 'video') view.innerHTML = `<article class="route-intro"><p class="eyebrow">31 DE DICIEMBRE / LA PERIGALLA</p><h3>Esto es Hora Cero.</h3><video controls playsinline preload="none" poster="assets/hora-cero-01.jpg" src="assets/hora-cero-final-uvas.mp4?v=final-uvas-20261109" aria-label="Presentación de Hora Cero"></video></article>${reservationHTML()}`;
 else view.innerHTML = menuHTML(MENUS[id]);
 view.scrollTop = 0;
 if (hadFocus) view.focus({preventScroll:true});
 if (dialog.open && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
   const angle = index >= previousIndex ? 10 : -10;
   view.firstElementChild.animate([
     {transform: `perspective(1200px) rotateY(${angle}deg)`, opacity: 0.5},
     {transform: 'none', opacity: 1}
   ], {duration: 250, easing: 'ease-out'});
 }
}
function turnPage(step) {
 const option = select.options[select.selectedIndex + step];
 if (option) selectChoice(option.value);
}
select.addEventListener('change', () => selectChoice(select.value));
dialog.addEventListener('keydown', event => {
 if (event.target.closest('select, video, input') || event.altKey || event.ctrlKey || event.metaKey) return;
 if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
   event.preventDefault(); turnPage(event.key === 'ArrowRight' ? 1 : -1);
 }
});
let touchStart;
view.addEventListener('pointerdown', e => {
 if (e.pointerType === 'touch' && e.target.closest('.leaf')) touchStart = [e.clientX,e.clientY];
});
view.addEventListener('pointercancel', () => {touchStart = null;});
view.addEventListener('pointerup', e => {
 if (!touchStart) return;
 const dx = e.clientX - touchStart[0], dy = e.clientY - touchStart[1];
 touchStart = null;
 if (Math.abs(dx) > 65 && Math.abs(dy) < 45) turnPage(dx < 0 ? 1 : -1);
});
document.addEventListener('click', e => {
 const inquiry=e.target.closest('[data-group-inquiry]');
 if(inquiry){const plan=inquiry.dataset.groupInquiry;openGroupInquiry(dialog.open&&activePlan?.id===plan?activeChoice:null,plan);return;}
 const open = e.target.closest('[data-open]');
 if (open) openPlan(open.dataset.open);
 const stop = e.target.closest('[data-stop]');
 if (stop) selectChoice(stop.dataset.stop);
 const book = e.target.closest('[data-book]');
 if (book) turnPage(book.dataset.book === 'next' ? 1 : -1);
 if (e.target.closest('[data-print]')) window.print();
});
$('#close-dialog').addEventListener('click',()=>dialog.close());
dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
dialog.addEventListener('close',()=>{stopInquiryUpdates?.();stopInquiryUpdates=undefined;for(const v of dialog.querySelectorAll('video'))v.pause();document.body.style.overflow='';opener?.focus({preventScroll:true});});

const params = new URLSearchParams(location.search);
const initial = params.get('plan');
if (PLANS.some(plan => plan.id === initial)) openPlan(initial, params.get('hoja'));

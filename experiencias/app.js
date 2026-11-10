'use strict';
const $=s=>document.querySelector(s), esc=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const PLANS=[
 {id:'diciembre',date:'TODO EL MES DE DICIEMBRE',title:'Una comida<br>para juntarnos.',name:'Menús de grupo',description:'Menú Raíces, Menú La Mesa y Menú Brindis. Tres menús para compartir y celebrar en grupo.',price:'Desde 44,95 €',note:'Por persona · IVA y bebidas incluidos',cover:'navidad-01',action:'Ver los tres menús',menus:['navidad-02','navidad-03','navidad-04'],default:'navidad-02'},
 {id:'degustacion',period:'diciembre',date:'DICIEMBRE · VIERNES, SÁBADOS Y DOMINGOS',title:'Una experiencia<br>gastronómica.',name:'Menú degustación',description:'Una experiencia gastronómica para mesas de hasta seis personas. Viernes, sábados y domingos de diciembre, solo al mediodía, excepto el 25.',price:'38 €',note:'Por persona · IVA incluido · Bebidas aparte',image:'assets/temporada-degustacion.webp',cover:'navidad-01',action:'Descubrir el menú degustación',menus:['navidad-individual'],default:'navidad-individual'},
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
const groupInquiryTools = import('./group-inquiry.mjs?v=grupos-otro-dia-20261108');
const groupMenuIds=['navidad-02','navidad-03','navidad-04'];
const decemberServiceDates=[4,5,6,11,12,13,18,19,20,26,27].map(day=>'2026-12-'+String(day).padStart(2,'0'));
const inquiryPlanIds=['diciembre','degustacion'];
const inquiryDrafts={};
const inquiryMenuIds=()=>activePlan.id==='degustacion'?['navidad-individual']:groupMenuIds;
const inquiryOptions=()=>({kind:activePlan.id==='degustacion'?'tasting':'group',maxPeople:activePlan.id==='degustacion'?6:100});
let groupInquiryDraft={};
function tastingCalendarHTML() {
 const weekdays=['L','M','X','J','V','S','D'];
 const selected=decemberServiceDates.includes(groupInquiryDraft.date)?groupInquiryDraft.date:'';
 const cells=['<td aria-hidden="true"></td>']; // December 2026 starts on Tuesday.
 for(let day=1;day<=31;day++) {
  const date='2026-12-'+String(day).padStart(2,'0'),available=decemberServiceDates.includes(date);
  const label=new Intl.DateTimeFormat('es-ES',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'}).format(new Date(date+'T12:00:00Z'));
  cells.push(`<td><button type="button" data-tasting-date="${date}" aria-label="${esc(label)}${available?'':day===25?' · Menú de Navidad':' · No disponible'}" ${available?`aria-pressed="${selected===date}"`:'disabled'}>${day}</button></td>`);
 }
 while(cells.length%7)cells.push('<td aria-hidden="true"></td>');
 const rows=[];for(let i=0;i<cells.length;i+=7)rows.push('<tr>'+cells.slice(i,i+7).join('')+'</tr>');
 const selectedLabel=selected?new Intl.DateTimeFormat('es-ES',{weekday:'long',day:'numeric',month:'long',timeZone:'UTC'}).format(new Date(selected+'T12:00:00Z')):'Elige una de las fechas disponibles.';
 return `<div class="tasting-calendar-field"><p id="tasting-date-label" class="calendar-field-label">Día de la reserva</p><input type="hidden" name="date" value="${selected}"><div class="tasting-calendar" role="group" aria-labelledby="tasting-date-label"><table><caption>Diciembre <span>2026</span></caption><thead><tr>${weekdays.map(day=>`<th scope="col">${day}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table><p class="calendar-selection" role="status" aria-live="polite">${esc(selectedLabel)}</p></div></div>`;
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
   <fieldset><legend>¿Cómo os gustaría venir?</legend><div class="inquiry-fields ${activePlan.id==='degustacion'?'inquiry-fields--tasting':''}">
    ${activePlan.id==='degustacion'?tastingCalendarHTML():`<label>Día de la reserva<select name="date" required><option value="">Elegid un día disponible</option>${decemberServiceDates.map(date=>`<option value="${date}" ${groupInquiryDraft.date===date&&groupInquiryDraft.date_request!=='other'?'selected':''}>${esc(new Intl.DateTimeFormat('es-ES',{weekday:'long',day:'numeric',month:'long',timeZone:'UTC'}).format(new Date(date+'T12:00:00Z')))}</option>`).join('')}${activePlan.id==='diciembre'?`<option value="other" ${groupInquiryDraft.date_request==='other'?'selected':''}>Nos gustaría otro día · a valorar</option>`:''}</select></label>`}
    ${activePlan.id==='diciembre'?`<label data-requested-date ${groupInquiryDraft.date_request==='other'?'':'hidden'}>¿Qué día os gustaría venir?<input name="requested_date" type="date" min="2026-12-01" max="2026-12-31" ${groupInquiryDraft.date_request==='other'?'required':'disabled'} value="${groupInquiryDraft.date_request==='other'?value('date'):''}"><span class="inquiry-hint">Estudiaremos vuestra propuesta. La fecha y el turno están pendientes de valoración y confirmación.</span></label>`:''}
    ${activePlan.id==='degustacion'?`<div class="fixed-shift"><span>Turno</span><p>Comida · mediodía</p><small>Único turno disponible</small><input name="shift" type="hidden" value="lunch"></div>`:`<label>Turno<select name="shift" required><option value="">Elegid el turno</option><option value="lunch" ${groupInquiryDraft.shift==='lunch'?'selected':''}>Comida · mediodía</option><option value="dinner" ${groupInquiryDraft.shift==='dinner'?'selected':''}>Cena · noche</option></select></label>`}
    <label>Número de personas<input name="people" type="number" min="1" max="${inquiryOptions().maxPeople}" step="1" required value="${value('people')}" inputmode="numeric"></label>
    <label>Menú que os interesa<select name="menu" required><option value="">Elegid un menú</option>${inquiryMenuIds().map(id=>`<option value="${id}" ${groupInquiryDraft.menu===id?'selected':''}>${esc(MENUS[id].title)} · ${esc(MENUS[id].price)}</option>`).join('')}</select></label>
   </div><p class="inquiry-hint">${activePlan.id==='degustacion'?'Hasta seis personas. Viernes, sábados y domingos de diciembre, solo al mediodía. El 25 está reservado al menú de Navidad.':'Comida o cena los viernes, sábados y domingos de diciembre, excepto el 25. Si preferís otra fecha, elegid «Nos gustaría otro día» y valoraremos vuestra propuesta.'}</p></fieldset>
   <label class="inquiry-privacy"><input name="privacy" type="checkbox" required ${groupInquiryDraft.privacy?'checked':''}><span>He leído la <a href="/politica-privacidad/" target="_blank" rel="noopener">política de privacidad</a> y acepto el contacto para gestionar esta consulta.</span></label>
   <p class="inquiry-status" role="status" aria-live="polite"></p>
   <button class="inquiry-submit" type="submit">Preparar mi consulta de WhatsApp <span aria-hidden="true">↗</span></button>
   <p class="inquiry-hint">La reserva queda pendiente de nuestra confirmación de disponibilidad.</p>
   <section class="inquiry-message" hidden aria-label="Tu consulta preparada"><h4>Todo listo para escribirnos.</h4><pre></pre><a class="inquiry-submit" target="_blank" rel="noopener">Abrir WhatsApp con mi consulta ↗</a><p>Se abrirá el chat de Perigallo con el mensaje completo. Revísalo y pulsa enviar en WhatsApp.</p></section>
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
function bindGroupInquiry() {
 const form=$('#group-inquiry-form');if(!form)return;
 const contextPlan=activePlan.id,menuIds=[...inquiryMenuIds()],options=inquiryOptions();
 const snapshot=()=>{const data=Object.fromEntries(new FormData(form));data.privacy=form.elements.privacy.checked;
  if(contextPlan==='diciembre'){data.date_request=data.date==='other'?'other':'standard';if(data.date_request==='other')data.date=data.requested_date;delete data.requested_date;}
  return data;};
 const syncRequestedDate=()=>{const field=form.querySelector('[data-requested-date]');if(!field)return;const other=form.elements.date.value==='other';field.hidden=!other;form.elements.requested_date.disabled=!other;form.elements.requested_date.required=other;};
 form.elements.date.addEventListener('change',syncRequestedDate);syncRequestedDate();
 for(const button of form.querySelectorAll('[data-tasting-date]:not(:disabled)'))button.addEventListener('click',()=>{
  form.elements.date.value=button.dataset.tastingDate;
  for(const day of form.querySelectorAll('[data-tasting-date]:not(:disabled)'))day.setAttribute('aria-pressed',String(day===button));
  form.querySelector('.calendar-selection').textContent=new Intl.DateTimeFormat('es-ES',{weekday:'long',day:'numeric',month:'long',timeZone:'UTC'}).format(new Date(button.dataset.tastingDate+'T12:00:00Z'));
  form.elements.date.dispatchEvent(new Event('input',{bubbles:true}));
 });
 form.addEventListener('input',event=>{
  if(event.target.setCustomValidity)event.target.setCustomValidity('');
  syncRequestedDate();groupInquiryDraft=snapshot();form.querySelector('.inquiry-message').hidden=true;form.querySelector('.inquiry-message a').removeAttribute('href');form.querySelector('.inquiry-status').textContent='';
 });
 form.addEventListener('submit',async event=>{
  event.preventDefault();
  for(const key of ['first_name','last_name'])form.elements[key].setCustomValidity(form.elements[key].value.trim()?'':'Este dato es obligatorio.');
  if(!form.reportValidity())return;
  if(contextPlan==='degustacion'&&!decemberServiceDates.includes(form.elements.date.value)){
   form.querySelector('.inquiry-status').textContent='Elige una fecha disponible en el calendario.';form.querySelector('[data-tasting-date]:not(:disabled)').focus();return;
  }

  const button=form.querySelector('button[type="submit"]'),status=form.querySelector('.inquiry-status');button.disabled=true;
  try {
   const tools=await groupInquiryTools,menus=Object.fromEntries(menuIds.map(id=>[id,MENUS[id]]));
   if(!form.isConnected||activePlan.id!==contextPlan)return;
   groupInquiryDraft=tools.validateGroupInquiry(snapshot(),menus,options);
   const message=tools.buildGroupMessage(groupInquiryDraft,menus,options),preview=form.querySelector('.inquiry-message');
   preview.querySelector('pre').textContent=message;preview.querySelector('a').href=tools.groupWhatsAppUrl(message);preview.hidden=false;
   status.textContent='Consulta preparada. Todavía no se ha enviado.';
   preview.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'nearest'});
  } catch(error){status.textContent=error.message;}
  finally {button.disabled=false;}
 });
}

function bookingActions(plan) {
 if (inquiryPlanIds.includes(plan)) return `<div class="plan-booking"><a href="tel:+34691499985">Reservar por teléfono ↗</a><button type="button" class="reserve-link" data-group-inquiry="${plan}">Reservar por WhatsApp ↗</button></div>`;
 const checkout = bookingOrigin + '/entradas/reserva/?plan=' + encodeURIComponent(plan);
 return `<div class="plan-booking"><a class="reserve-link" href="${checkout}&mode=reserve">Reservar ↗</a></div>`;
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
 ${activePlan.id==='degustacion'?'<p class="inquiry-hint">Viernes, sábados y domingos de diciembre, solo al mediodía. Excepto el 25, reservado al menú de Navidad.</p>':''}
 ${sections}
 <p class="allergy-note">Si tenéis alguna alergia, consultadnos al reservar.</p>
 ${reservationHTML()}
 <div class="reader-tools"><a href="menus/${menu.id}.png${menu.id==='navidad-individual'?'?v=mediodia-20261108':menu.id==='ano-nuevo-menu'?'?v=bebidas-incluidas-20261109':['navidad-02','navidad-03','navidad-04'].includes(menu.id)?'?v=nombres-originales-20261109':''}" download>Descargar carta ↓</a><button data-print>Imprimir / guardar PDF ↗</button></div>
 </article>`;
}
function reservationHTML() {
 if(inquiryPlanIds.includes(activePlan.id))return `<div class="leaf-reserve">${bookingActions(activePlan.id)}<p>Para más info y reservas · Cuéntanos la fecha, el turno y vuestro menú.</p></div>`;
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
 const previousIndex = Array.from(select.options).findIndex(option => option.value === activeChoice);
 const hadFocus = view.contains(document.activeElement);
 for (const video of view.querySelectorAll('video')) video.pause();
 select.value = id;
 if(id==='consulta'&&!groupInquiryDraft.menu&&inquiryMenuIds().includes(activeChoice))groupInquiryDraft.menu=activeChoice;
 activeChoice = id;
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
dialog.addEventListener('close',()=>{for(const v of dialog.querySelectorAll('video'))v.pause();document.body.style.overflow='';opener?.focus({preventScroll:true});});

const params = new URLSearchParams(location.search);
const initial = params.get('plan');
if (PLANS.some(plan => plan.id === initial)) openPlan(initial, params.get('hoja'));

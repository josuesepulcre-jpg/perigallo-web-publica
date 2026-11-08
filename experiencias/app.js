'use strict';
const $=s=>document.querySelector(s), esc=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const PLANS=[
 {id:'diciembre',date:'TODO EL MES DE DICIEMBRE',title:'Una comida<br>para juntarnos.',name:'Menús de grupo',description:'Tres menús de grupo y un menú degustación para mesas de menos de seis personas. Elegid el que más os apetezca.',price:'Desde 38 €',note:'Por persona · IVA incluido · Bebidas según menú',cover:'navidad-01',action:'Ver los cuatro menús',menus:['navidad-02','navidad-03','navidad-04','navidad-individual'],default:'navidad-02'},
 {id:'navidad',date:'25 DE DICIEMBRE · NAVIDAD',title:'A la mesa.<br>Y un rato más.',name:'El día de Navidad',description:'Una comida de Navidad alrededor de la mesa, con nuestro puchero y menú infantil para los peques.',price:'65 €',note:'Por persona · IVA y bebidas incluidos',cover:'navidad-25-portada',action:'Ver los menús',menus:['navidad-25-menu','navidad-25-infantil'],default:'navidad-25-menu'},
 {id:'hora',date:'31 DE DICIEMBRE · HORA CERO',title:'Seis destinos.<br>Un Año Nuevo.',name:'Tardevieja · Hora Cero',description:'Australia, Japón, China, Vietnam, India y Rusia. Gastronomía, lago, barra libre, Bollywood y DJ durante el recorrido.',price:'120 €',note:'Por persona · IVA incluido · Plazas limitadas',cover:'hora-cero-01',action:'Descubrir el recorrido',menus:[],default:'route'},
 {id:'nuevo',date:'1 DE ENERO · AÑO NUEVO',title:'La primera<br>mesa del año.',name:'El día de Año Nuevo',description:'Marisco, entrantes, principal a elegir y flan de turrón. Empezamos 2027 alrededor de la mesa, también con los peques.',price:'50 €',note:'Por persona · IVA incluido · Bebidas aparte',cover:'ano-nuevo-portada',action:'Ver los menús',menus:['ano-nuevo-menu','ano-nuevo-infantil'],default:'ano-nuevo-menu'}
];
const sceneDescriptions = {
 diciembre: 'Mesa para compartir con lino verde, mandarinas y velas encendidas',
 navidad: 'Sopera de consomé sobre lino granate en la mesa de Navidad',
 hora: 'Imagen de Hora Cero con una flor luminosa y una copa junto al lago',
 nuevo: 'Dos copas de brindis sobre lino marfil a la luz de la mañana'
};
// Reservations use the production event backend.
const bookingOrigin = '';
function bookingActions(plan) {
 if (plan === 'diciembre') return `<div class="plan-booking"><a href="tel:+34691499985">Reservar por teléfono ↗</a><a href="https://wa.me/34691499985?text=${encodeURIComponent('Hola, quiero reservar una mesa para los menús de diciembre.')}" target="_blank" rel="noopener">Reservar por WhatsApp ↗</a></div>`;
 const checkout = bookingOrigin + '/entradas/reserva/?plan=' + encodeURIComponent(plan);
 return `<div class="plan-booking"><a class="reserve-link" href="${checkout}&mode=reserve">Reservar ↗</a><a href="${checkout}&mode=pay">Comprar y pagar ↗</a></div>`;
}
$('#plan-grid').innerHTML=PLANS.map(p=>`<article class="plan-card" data-plan="${p.id}"><button class="plan-art" data-open="${p.id}" aria-label="Abrir ${esc(p.name)}"><img src="assets/temporada-${p.id}.webp" alt="${esc(sceneDescriptions[p.id])}" loading="lazy"></button><div class="card-copy"><span class="card-date">${p.date}</span><h3>${esc(p.name)}</h3><p>${esc(p.description)}</p><div class="card-price">${p.price} <small>/ persona</small></div><div class="price-note">${p.note}</div><button class="card-open" data-open="${p.id}">${p.action}<span aria-hidden="true">↗</span></button>${bookingActions(p.id)}</div></article>`).join('');
for(const b of document.querySelectorAll('[data-filter]'))b.addEventListener('click',()=>{
 const f=b.dataset.filter;
 for(const button of document.querySelectorAll('[data-filter]')){const selected=button===b;button.classList.toggle('selected',selected);button.setAttribute('aria-pressed',String(selected));}
 let count=0;for(const card of document.querySelectorAll('[data-plan]')){card.hidden=f!=='all'&&card.dataset.plan!==f;if(!card.hidden)count++;}
 $('#filter-status').textContent=count===1?'1 plan disponible para consultar':`${count} planes disponibles para consultar`;
});
let activePlan, activeChoice, opener;
const dialog = $('#menu-dialog');
const select = $('#menu-options');
const view = $('#menu-view');
function openPlan(id, choice) {
 activePlan = PLANS.find(plan => plan.id === id);
 opener = document.activeElement;
 $('#dialog-date').textContent = activePlan.date;
 const options = activePlan.menus.map(key => [key, MENUS[key].title + ' · ' + MENUS[key].price]);

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
 ${sections}
 <p class="allergy-note">Si tenéis alguna alergia, consultadnos al reservar.</p>
 ${reservationHTML()}
 <div class="reader-tools"><a href="menus/${menu.id}.png" download>Descargar carta ↓</a><button data-print>Imprimir / guardar PDF ↗</button></div>
 </article>`;
}
function reservationHTML() {
 const message = 'Hola, me gustaría consultar disponibilidad para ' + activePlan.name + '.';
 return `<div class="leaf-reserve">${bookingActions(activePlan.id)}<p>Para más info y reservas</p><a href="https://wa.me/34691499985?text=${encodeURIComponent(message)}" target="_blank" rel="noopener">Escribir por WhatsApp ↗</a><a href="tel:+34691499985">Llamar al 691 499 985</a></div>`;
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
 activeChoice = id;
 const index = select.selectedIndex;
 const count = select.options.length;
 $('#leaf-nav [data-book="prev"]').disabled = index === 0;
 $('#leaf-nav [data-book="next"]').disabled = index === count - 1;
 $('.book-counter').textContent = (index + 1) + ' / ' + count;
 const isPerformance = false;
 $('#dialog-heading').textContent = activePlan.name;
 const photo = $('#reader-photo');
 photo.parentElement.classList.toggle('single-cover', isPerformance);
 photo.dataset.scene = isPerformance ? 'show' : activePlan.id;
 photo.src = `assets/temporada-${activePlan.id}.webp`;
 photo.alt = sceneDescriptions[activePlan.id];
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

 else if (id === 'route') view.innerHTML = routeHTML();
 else if (id === 'video') view.innerHTML = `<article class="route-intro"><p class="eyebrow">31 DE DICIEMBRE / LA PERIGALLA</p><h3>Esto es Hora Cero.</h3><video controls playsinline preload="none" poster="assets/hora-cero-01.jpg" src="assets/hora-cero.mp4" aria-label="Presentación de Hora Cero"></video></article>${reservationHTML()}`;
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

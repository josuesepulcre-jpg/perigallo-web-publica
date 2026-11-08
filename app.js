document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog').close()));
document.querySelectorAll('dialog').forEach(d=>d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close()}}));
document.querySelector('[data-info]')?.addEventListener('click',()=>document.querySelector('#info').showModal());
const stories={bodas:{title:'Todo empieza con vosotros.',description:'Vuestra gente, vuestra manera de recibir y el ritmo que queréis dar al día. En Finca La Llaguna o con nuestro catering en otro espacio. Escuchamos lo que os importa para conectar gastronomía, servicio y celebración.',images:['novios','celebracion','ceremonia']},gastronomia:{title:'El gusto de sorprender.',description:'Cocina, producto y una manera de recibir. Pensamos el formato gastronómico como una parte central de la celebración.',images:['plato','cocina','mesa']},celebraciones:{title:'Que la noche siga.',description:'La luz cambia. La música empieza. La celebración encuentra otra energía.',images:['escena','invitados','celebracion']},encuentro:{title:'Vuestra gente. Vuestro día.',description:'Conversaciones, encuentros y una mesa que reúne. Es ahí donde empieza todo.',images:['invitados','mesa','novios']},finca:{title:'Finca La Llaguna.',description:'Crevillent, Alicante. Un espacio para recorrer juntos e imaginar cada momento de vuestra boda.',images:['finca','ceremonia','celebracion']}};
stories.popup={title:'La Perigalla 01.',description:'Edición celebrada el 29 de agosto de 2026. Una boda ficticia de inspiración ibicenca, con gastronomía, relato y puesta en escena en Finca La Llaguna.',images:['invitados','plato','escena']};
const story=document.querySelector('#story');
document.querySelectorAll('[data-story]').forEach(b=>b.addEventListener('click',()=>{const data=stories[b.dataset.story];document.querySelector('#story-title').textContent=data.title;document.querySelector('#story-description').textContent=data.description;const holder=document.querySelector('#story-images');holder.replaceChildren();data.images.forEach(name=>{const img=document.createElement('img');img.src='/media/archivo/'+name+'.webp';img.alt={novios:'Pareja al salir de la ceremonia',celebracion:'Espacio preparado para una ceremonia',ceremonia:'Disposición de sillas en el jardín',plato:'Detalle de cocina Perigallo',cocina:'Preparación gastronómica',mesa:'Estación gastronómica',escena:'Espectáculo durante una celebración',invitados:'Invitados conversando en el jardín',finca:'Finca La Llaguna'}[name];holder.append(img)});if(b.dataset.story==='bodas'||b.dataset.story==='encuentro')document.querySelector('#story-description').textContent+=' Imágenes de La Perigalla 01: una boda ficticia creada como experiencia de marca.';story.showModal();story.scrollTop=0}));
document.querySelector('#story-contact').addEventListener('click',()=>{story.close();location.href='/solicitud-evento/'});
// Muted, inline playback with truthful loading and manual-play states.
const video=document.querySelector('#hero-video'),motion=document.querySelector('#motion');
const reduced=matchMedia('(prefers-reduced-motion: reduce)');
const mobileFilm=matchMedia('(max-width: 900px)');
let userPaused=reduced.matches;
function updateMotion(){
 const paused=video.paused;
 const loading=!paused&&video.readyState<3;
 const label=video.error?'Reintentar vídeo':loading?'Cargando vídeo…':paused?'Reproducir vídeo':'Pausar vídeo';
 motion.setAttribute('aria-pressed',String(paused));
 motion.setAttribute('aria-label',label);
 motion.setAttribute('aria-busy',String(loading));
 motion.textContent=label+(loading?'':paused?' ▷':' Ⅱ');
}
function startFilm(){
 if(userPaused||document.hidden||document.documentElement.classList.contains('intro-active'))return;
 video.muted=true;video.defaultMuted=true;video.playsInline=true;
 const promise=video.play();
 updateMotion();
 if(promise)promise.then(updateMotion).catch(updateMotion);
}
function selectFilm(){
 const mode=mobileFilm.matches?'mobile':'desktop';
 const src=video.dataset[mode+'Src'];
 if(!src||video.getAttribute('src')===src)return;
 const previousTime=video.currentTime||0;
 video.autoplay=!userPaused;
 video.muted=true;video.defaultMuted=true;video.playsInline=true;
 video.preload='auto';video.poster=video.dataset[mode+'Poster'];video.src=src;
 video.addEventListener('loadedmetadata',()=>{
  if(previousTime>0)video.currentTime=Math.min(previousTime,Math.max(0,video.duration-.1));
  updateMotion();
 },{once:true});
 video.load();
 if(!userPaused)startFilm();else updateMotion();
}
motion.addEventListener('click',()=>{
 if(video.paused||video.error){userPaused=false;if(video.error)video.load();startFilm()}
 else{userPaused=true;video.pause()}
});
['pause','play','playing','waiting','stalled','error','loadeddata','canplay'].forEach(event=>video.addEventListener(event,updateMotion));
video.addEventListener('canplay',()=>{if(!userPaused&&video.paused)startFilm()});
mobileFilm.addEventListener('change',selectFilm);
reduced.addEventListener('change',e=>{userPaused=e.matches;video.autoplay=!userPaused;if(userPaused)video.pause();else startFilm()});
const resumeFilm=()=>{if(!document.hidden&&!userPaused&&video.paused)startFilm()};
document.addEventListener('visibilitychange',resumeFilm);
window.addEventListener('pageshow',resumeFilm);
document.addEventListener('perigallo:intro-end',resumeFilm);
selectFilm();updateMotion();


// Keep the brand readable over both the film and the light editorial sections.
const homeHeader = document.querySelector('.video-home .site-header');
if (homeHeader) {
 const updateHeader = () => homeHeader.classList.toggle('is-scrolled', window.scrollY > 40);
 window.addEventListener('scroll', updateHeader, {passive:true});
 updateHeader();
}

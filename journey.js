/* Shared navigation: one behavior for every public route. */
const menuButton=document.querySelector('.site-header .menu');
const navigation=document.querySelector('#navegacion');
if(menuButton&&navigation){
 const closeMenu=(restoreFocus=false)=>{navigation.classList.remove('open');menuButton.setAttribute('aria-expanded','false');menuButton.innerHTML='Menú <span aria-hidden="true">+</span>';if(restoreFocus)menuButton.focus()};
 menuButton.addEventListener('click',()=>{const open=menuButton.getAttribute('aria-expanded')!=='true';navigation.classList.toggle('open',open);menuButton.setAttribute('aria-expanded',String(open));menuButton.innerHTML=open?'Cerrar <span aria-hidden="true">×</span>':'Menú <span aria-hidden="true">+</span>'});
 navigation.addEventListener('click',e=>{if(e.target.closest('a'))closeMenu()});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&navigation.classList.contains('open'))closeMenu(true)});
 document.addEventListener('click',e=>{if(!e.target.closest('.site-header'))closeMenu()});
 matchMedia('(min-width:1101px)').addEventListener('change',e=>{if(e.matches)closeMenu()});
 for(const a of navigation.querySelectorAll('a')){if(new URL(a.href).pathname===location.pathname)a.setAttribute('aria-current','page')}
}

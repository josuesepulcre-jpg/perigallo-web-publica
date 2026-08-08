import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const home = readFileSync(resolve('index.html'), 'utf8');
const experienceScript = readFileSync(resolve('assets/js/home-experiences.js'), 'utf8');
const celebrationScript = readFileSync(resolve('assets/js/home-celebrations.js'), 'utf8');
const required = [
  '<h1 class="hero-title">Perigallo</h1>',
  'Gastronomía inmersiva',
  'Diseñamos cocina, servicio y puesta en escena para celebraciones irrepetibles.',
  'Quiero celebrar un evento',
  'Quiero vivir una experiencia Perigallo',
  'href="#quienes-somos" class="scroll-indicator"',
  '--color-perigallo-hero: var(--bg)',
  '<div class="hero-brand-background" aria-hidden="true"></div>',
  '.hero-brand-background::after',
  'repeating-linear-gradient(0deg,rgba(245,241,229,.018)',
  '<div class="hero-media-stage" aria-hidden="true">',
  'radial-gradient(ellipse 32% 46% at 50% 49%',
  'rgba(66,107,108,.245)',
  'class="hero-media-slot hero-media-slot--a is-visible is-overlay"',
  'class="hero-media-slot hero-media-slot--b is-underlay"',
  '<div class="hero-atmosphere" aria-hidden="true"></div>',
  'data-foreground="true"',
  'assets/images/gastronomy-carousel/foreground/fortune-cookie.webp',
  'filter:brightness(var(--hero-brightness,.96))',
  '.hero-media-slot{position:absolute;inset:0;display:grid;place-items:center;opacity:0;transition:opacity 1.6s cubic-bezier(.22,1,.36,1);will-change:opacity}',
  'var heroFadeDuration=1600;',
  'var heroDisplayDuration=5900;',
  'function preloadHeroSlide(index)',
  'image.decode().then',
  'function advanceHero()',
  "phase:'settled'",
  "heroState.phase='preloading'",
  "heroState.phase='transitioning'",
  "document.addEventListener('visibilitychange'",
  'id="heroSlideCurrent"',
  'border-radius:999px',
  'border:1px solid rgba(226,205,181,.23)',
  '.nav-button--about',
  '.nav-button--finca',
  '.nav-button--reserve',
  '.nav-button--contact',
  'left:clamp(188px,20vw,396px)',
  'https://www.instagram.com/perigallo/',
  'aria-label="Instagram de Perigallo"',
  '.nav-links-left .nav-social-link{width:36px;height:36px;min-height:36px;padding:0;',
  '.nav-links-left .nav-social-link svg{width:17px;height:17px;display:block;overflow:visible;stroke:currentColor;',
  'background:#29474d',
  'favicon.svg?v=perigallo-monogram-20260808',
  '<section class="manifest" id="quienes-somos" aria-labelledby="about-title">',
  'class="manifest-portrait manifest-portrait--josue reveal"',
  'src="assets/images/about/josue-portrait.png"',
  'class="manifest-portrait manifest-portrait--david reveal"',
  'src="assets/images/about/david-portrait.png"',
  'class="manifest-eyebrow reveal">Quiénes somos</p>',
  'id="about-title">Perigallo nace de la visión',
  'class="manifest-copy manifest-copy--closing reveal reveal-delay-3"',
  'height:calc(100svh - var(--header-anchor-offset))',
  '.manifest-portrait img{display:block;width:auto;height:100%;',
  'prefers-reduced-motion:reduce',
  'href="/#descubre-perigallo">Descubre Perigallo</a>',
  'href="/#fechas">Reservar</a>',
  'href="/#finca">La Finca</a>',
  'href="/#quienes-somos">Quiénes somos</a>',
  'href="/#contact">Contacto</a>',
  'class="mobile-menu-toggle" type="button" aria-expanded="false" aria-controls="mobile-menu-panel"',
  'class="mobile-menu-panel" id="mobile-menu-panel"',
  'function setMobileMenu(open)',
  "mobileMenuPanel.classList.toggle('is-open',open)",
  "event.key==='Escape'",
  '.mobile-menu-panel.is-open',
  '<section class="services" id="descubre-perigallo">',
  'service-card service-card--weddings reveal',
  'service-card service-card--celebrations reveal reveal-delay-1',
  'service-card service-card--experiences reveal reveal-delay-2',
  "--service-image:url('assets/images/perigallo-wedding-table-at-dusk.png')",
  "--service-image:url('assets/images/finca-la-llaguna-principal.jpg')",
  "--service-image:url('assets/images/perigallo-hero-original-02.jpg')",
  'border-radius:50% 50% 47% 48% / 18% 18% 26% 27%',
  'width:fit-content',
  'margin-bottom:clamp(42px,4vw,64px)',
  'background:rgba(245,241,229,.94)',
  '@media (min-width:901px){',
  '.services{min-height:calc(100svh - var(--header-anchor-offset));display:flex;flex-direction:column;justify-content:center;',
  '.service-card{min-height:clamp(370px,43svh,500px);',
  '@media (min-width:901px) and (max-height:760px){',
  'Bodas <em>inmersivas</em>',
  'Convertimos vuestra historia en una celebración irrepetible, cuidando la gastronomía, la estética y cada detalle.',
  'propuesta creada a medida.',
  'Otra forma de vivir Perigallo.',
  '#quienes-somos,#descubre-perigallo,#finca,#fechas,#contact{scroll-margin-top:var(--header-anchor-offset)}',
  '<section class="popup-section" id="proximas-experiencias"',
  'Agenda Perigallo',
  'Próximas <em>experiencias</em>',
  'href="#proximas-experiencias" class="service-link">Descubrir próximas experiencias</a>',
  '-webkit-mask-image:radial-gradient(ellipse 68% 69% at 47% 51%',
  'experience-carousel-microdetail',
  "font-family:'Montserrat',sans-serif;font-size:.73rem;font-weight:500;",
  '-webkit-mask-image:radial-gradient(ellipse 68% 69% at 47% 51%',
  'filter:brightness(.9) contrast(1.035) saturate(.94)',
  'agendaVisual',
  'agendaContent',
  'home-experiences.js?v=20260808-agenda-v3',
  '<section class="booking-bridge" id="fechas" aria-labelledby="booking-bridge-title">',
  'Reservas Perigallo',
  'Elige tu fecha.<em>Nosotros hacemos el resto.</em>',
  'Consulta la disponibilidad real y reserva directamente en nuestro portal oficial.',
  'https://reservas.perigallo.com/reservar?source=web',
  'Disponibilidad real</li>',
  'Confirmación directa</li>',
  'Reserva oficial</li>',
  'class="footer-contact"',
  'class="footer-map-link"',
  "font-family:'Montserrat',sans-serif;font-size:.92rem;font-weight:500;",
  "font-family:'Montserrat',sans-serif;font-size:.63rem;font-weight:400;",
  '<a class="footer-phone" href="tel:+34691499985">691 499 985</a>',
  '<span class="contact-detail-value"><a href="tel:+34691499985">691 499 985</a>',
  ".proposal-entry::after{content:'';position:absolute;",
  'Finca La Llaguna</strong>Crevillent · Alicante',
  'https://www.google.com/maps/search/?api=1&amp;query=Finca%20La%20Llaguna%2C%20Crevillent%2C%20Alicante',
  '<section class="contact" id="contact">',
  'Empezamos por <em>vuestra historia</em>',
  'No partimos de un menú cerrado. Empezamos escuchando.',
  'class="proposal-steps" aria-label="Cómo funciona la solicitud"',
  'Sin compromiso. Solo el punto de partida para imaginar algo irrepetible.',
  "document.querySelectorAll('a[href^=\"#\"],a[href^=\"/#\"]')",
  'data-celebration-carousel',
  'data-celebration-current',
  'data-celebration-total',
  'assets/js/home-celebrations.js?v=20260808-celebrations-v1',
  'clip-path:ellipse(67% 63% at 48% 52%)',
];

for (const fragment of required) {
  if (!home.includes(fragment)) {
    throw new Error(`Falta el elemento del hero: ${fragment}`);
  }
}

for (const fragment of [
  'Una boda ficticia convertida en experiencia gastronómica inmersiva.',
  'Total White · +18',
  'Descubrir la experiencia',
  'Ver todas las experiencias',
  'assets/images/perigalla-01/hero-desktop.webp',
  'function changeTo(nextIndex)',
]) {
  if (!experienceScript.includes(fragment)) {
    throw new Error(`La agenda editorial no conserva: ${fragment}`);
  }
}

if (experienceScript.includes('Código de vestimenta obligatorio') || experienceScript.includes('Valor de la experiencia')) {
  throw new Error('La agenda de la home conserva contenido operativo que solo pertenece a la ficha.');
}

for (const fragment of [
  'perigallo-wedding-table-at-dusk.png',
  'finca-la-llaguna-principal.jpg',
  'hero-desktop.webp',
  'function advance()',
  'IntersectionObserver',
  'prefers-reduced-motion',
]) {
  if (!celebrationScript.includes(fragment)) {
    throw new Error(`El carrusel de celebraciones no conserva: ${fragment}`);
  }
}

if (home.includes('class="cursor"') || home.includes('class="cursor-ring"')) {
  throw new Error('El cursor decorativo no se ha eliminado del hero.');
}

for (const obsoleteBookingBlock of [
  'booking-frame-placeholder',
  'booking-widget-area',
  'booking-aside-title',
  'Tu reserva en<br><em>tres pasos</em>',
  'Ver eventos con entrada',
]) {
  if (home.includes(obsoleteBookingBlock)) {
    throw new Error(`La portada conserva una simulación de reservas obsoleta: ${obsoleteBookingBlock}`);
  }
}

for (const obsoleteLabel of ['>Bodas</a>', '>Celebraciones</a>', '>Experiencias</a>']) {
  if (home.includes(obsoleteLabel)) {
    throw new Error(`La navegación principal conserva el enlace obsoleto: ${obsoleteLabel}`);
  }
}

const carouselSlides = (home.match(/id:'[a-z-]+',src:'assets\/images\/gastronomy-carousel\/foreground\/[a-z-]+\.webp'/g) ?? []).length;
if (carouselSlides !== 12) {
  throw new Error(`La configuración del carrusel debe incluir 12 sujetos WebP transparentes; incluye ${carouselSlides}.`);
}

if (home.includes('.hero-slide::before') || home.includes('background-blend-mode:color')) {
  throw new Error('El hero no puede recrear un fondo fotográfico por slide.');
}

if (home.includes('manifest-artwork') || home.includes('quienes-somos-perigallo.png')) {
  throw new Error('Quiénes somos debe usar retratos y contenido HTML, no una composición plana.');
}

for (const asset of ['assets/images/about/josue-portrait.png', 'assets/images/about/david-portrait.png']) {
  if (!existsSync(resolve(asset))) {
    throw new Error(`Falta el retrato independiente: ${asset}`);
  }
}

if (home.includes('josue-about-v2.jpg') || home.includes('david-about-v2.jpg')) {
  throw new Error('Quiénes somos aún carga los retratos provisionales.');
}

const foregroundAssets = [...home.matchAll(/assets\/images\/gastronomy-carousel\/foreground\/([a-z-]+)\.webp/g)].map((match) => match[0]);
for (const asset of new Set(foregroundAssets)) {
  if (!existsSync(resolve(asset))) {
    throw new Error(`Falta el sujeto transparente del hero: ${asset}`);
  }
}

console.log('Home hero static checks passed.');

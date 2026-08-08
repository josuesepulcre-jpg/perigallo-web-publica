import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const home = readFileSync(resolve('index.html'), 'utf8');
const required = [
  '<h1 class="hero-title">Perigallo</h1>',
  'Gastronomía inmersiva',
  'Diseñamos cocina, servicio y puesta en escena para celebraciones irrepetibles.',
  'Quiero celebrar un evento',
  'Quiero vivir una experiencia Perigallo',
  'href="#quienes-somos" class="scroll-indicator"',
  '--color-perigallo-hero: var(--bg)',
  '<div class="hero-brand-background" aria-hidden="true"></div>',
  '<div class="hero-media-stage" aria-hidden="true">',
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
  'href="/#quienes-somos">Quiénes somos</a>',
  '<section class="services" id="descubre-perigallo">',
  'service-card service-card--weddings reveal',
  'service-card service-card--celebrations reveal reveal-delay-1',
  'service-card service-card--experiences reveal reveal-delay-2',
  "--service-image:url('assets/images/perigalla-01/scene-18-desktop.jpg')",
  "--service-image:url('assets/images/finca-la-llaguna-principal.jpg')",
  "--service-image:url('assets/images/perigallo-hero-original-02.jpg')",
  'border-radius:50% 50% 47% 48% / 18% 18% 26% 27%',
  'width:fit-content',
  'margin-bottom:clamp(42px,4vw,64px)',
  'background:rgba(245,241,229,.94)',
  '#quienes-somos,#descubre-perigallo{scroll-margin-top:var(--header-anchor-offset)}',
  "document.querySelectorAll('a[href^=\"#\"],a[href^=\"/#\"]')",
];

for (const fragment of required) {
  if (!home.includes(fragment)) {
    throw new Error(`Falta el elemento del hero: ${fragment}`);
  }
}

if (home.includes('class="cursor"') || home.includes('class="cursor-ring"')) {
  throw new Error('El cursor decorativo no se ha eliminado del hero.');
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

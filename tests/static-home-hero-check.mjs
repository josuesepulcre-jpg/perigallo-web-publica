import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const home = readFileSync(resolve('index.html'), 'utf8');
const required = [
  '<h1 class="hero-title">Perigallo</h1>',
  'Gastronomía inmersiva',
  'Diseñamos cocina, servicio y puesta en escena para celebraciones irrepetibles.',
  'Quiero celebrar un evento',
  'Quiero vivir una experiencia Perigallo',
  'href="#about" class="scroll-indicator"',
  '--color-perigallo-hero: var(--bg)',
  '<div class="hero-brand-background" aria-hidden="true"></div>',
  '<div class="hero-media-stage" aria-hidden="true">',
  'class="hero-media-slot hero-media-slot--a is-visible is-overlay"',
  'class="hero-media-slot hero-media-slot--b is-underlay"',
  '<div class="hero-atmosphere" aria-hidden="true"></div>',
  'radial-gradient(ellipse 62% 60% at 50% 43%',
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
  '<section class="manifest" id="about" aria-labelledby="about-title">',
  'class="manifest-portrait manifest-portrait--josue reveal"',
  'src="assets/images/about/josue-about-v2.jpg"',
  'class="manifest-portrait manifest-portrait--david reveal"',
  'src="assets/images/about/david-about-v2.jpg"',
  'class="manifest-eyebrow reveal">Quiénes somos</p>',
  'id="about-title">Perigallo nace de la visión',
  'class="manifest-copy manifest-copy--closing reveal reveal-delay-3"',
  '.manifest-portrait img{display:block;width:100%;height:auto;',
  'prefers-reduced-motion:reduce',
];

for (const fragment of required) {
  if (!home.includes(fragment)) {
    throw new Error(`Falta el elemento del hero: ${fragment}`);
  }
}

if (home.includes('class="cursor"') || home.includes('class="cursor-ring"')) {
  throw new Error('El cursor decorativo no se ha eliminado del hero.');
}

const carouselSlides = (home.match(/id:'[a-z-]+',src:'assets\/images\/gastronomy-carousel\/[a-z-]+\.jpg'/g) ?? []).length;
if (carouselSlides !== 12) {
  throw new Error(`La configuración del carrusel debe incluir 12 fotografías; incluye ${carouselSlides}.`);
}

if (home.includes('.hero-slide::before') || home.includes('background-blend-mode:color')) {
  throw new Error('El hero no puede recrear un fondo fotográfico por slide.');
}

if (home.includes('manifest-artwork') || home.includes('quienes-somos-perigallo.png')) {
  throw new Error('Quiénes somos debe usar retratos y contenido HTML, no una composición plana.');
}

for (const asset of ['assets/images/about/josue-about-v2.jpg', 'assets/images/about/david-about-v2.jpg']) {
  if (!existsSync(resolve(asset))) {
    throw new Error(`Falta el retrato independiente: ${asset}`);
  }
}

console.log('Home hero static checks passed.');

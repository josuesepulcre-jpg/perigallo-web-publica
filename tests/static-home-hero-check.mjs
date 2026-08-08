import { readFileSync } from 'node:fs';
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
  '<section class="manifest" id="about" aria-label="Quiénes somos">',
  'class="manifest-artwork" src="assets/images/about/quienes-somos-perigallo.png"',
  'width="1672" height="941"',
  '.manifest-artwork{display:block;width:100%;max-width:1672px;height:auto;margin:0 auto}',
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

console.log('Home hero static checks passed.');
